<?php
defined( 'ABSPATH' ) || exit;

/**
 * Práce s akcemi: čtení údajů, kapacita, rezervovatelnost, překryvy.
 */
class Resandpay_Events {

	const TYPES = array( 'single', 'multiday', 'short', 'slot' );

	/** Režimy splatnosti: od rezervace / vůči termínu / dřívější z obou. */
	const DUE_MODES = array( 'booking', 'event', 'combined' );

	public static function due_mode_labels() {
		return array(
			'booking'  => __( 'Počet dní od rezervace (nejpozději začátek termínu)', 'reserveandpay' ),
			'event'    => __( 'Vůči termínu (−1 den před, 0 v den termínu, +1 den po)', 'reserveandpay' ),
			'combined' => __( 'Kombinace – dřívější z obou', 'reserveandpay' ),
		);
	}

	public static function default_due_mode() {
		$m = Resandpay_Settings::get( 'due_mode' );
		return in_array( $m, self::DUE_MODES, true ) ? $m : 'booking';
	}

	/**
	 * Výše 1. části platby (0 = platba najednou). Zaokrouhleno na celé koruny;
	 * rozdělení nemá smysl, když 1. část pokryje celou cenu.
	 */
	public static function part1_amount( array $ev ) {
		$price = (float) $ev['price'];
		if ( $price <= 0 || empty( $ev['part1_mode'] ) || 'none' === $ev['part1_mode'] ) {
			return 0.0;
		}
		$p1 = 'percent' === $ev['part1_mode'] ? $price * min( 100, max( 0, (float) $ev['part1_value'] ) ) / 100 : (float) $ev['part1_value'];
		$p1 = round( $p1 );
		return $p1 > 0 && $p1 < $price ? $p1 : 0.0;
	}

	/** Uložení nastavení platby ve dvou částech (akce i služba). */
	public static function save_part1_meta( $post_id, array $in ) {
		$mode = (string) ( $in['rap_part1_mode'] ?? '' );
		$mode = in_array( $mode, array( 'fixed', 'percent' ), true ) ? $mode : 'none';
		$val  = max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_part1_value'] ?? 0 ) ) );
		if ( 'percent' === $mode ) {
			$val = min( 99, $val );
		}
		update_post_meta( $post_id, '_rap_part1_mode', $mode );
		update_post_meta( $post_id, '_rap_part1_value', $val );
		update_post_meta( $post_id, '_rap_part1_days', max( 0, min( 365, (int) ( $in['rap_part1_days'] ?? 7 ) ) ) );
	}

	/** Lidsky čitelné pravidlo splatnosti (pro administraci). */
	public static function due_rule_text( $mode, $days, $offset ) {
		$off = 0 === (int) $offset ? __( 'v den termínu', 'reserveandpay' ) : sprintf(
			/* translators: %s: např. -1 den / +2 dny */
			__( 'termín %s', 'reserveandpay' ),
			sprintf( '%+d', $offset ) . ' ' . ( 1 === abs( (int) $offset ) ? __( 'den', 'reserveandpay' ) : ( abs( (int) $offset ) < 5 ? __( 'dny', 'reserveandpay' ) : __( 'dní', 'reserveandpay' ) ) )
		);
		/* translators: %d: počet dní */
		$bk = sprintf( __( '%d dní od rezervace', 'reserveandpay' ), (int) $days );
		if ( 'event' === $mode ) {
			return $off;
		}
		if ( 'combined' === $mode ) {
			/* translators: 1: dny od rezervace, 2: vůči termínu */
			return sprintf( __( '%1$s, nejpozději %2$s', 'reserveandpay' ), $bk, $off );
		}
		return $bk;
	}

	/**
	 * Vypočte splatnost rezervace podle pravidla akce (SWD v2.0, kap. 3.6).
	 * Splatnost je vždy konec dne (23:59:59). Vůči termínu se počítá od data začátku.
	 * Vyjde-li splatnost před začátek termínu a rezervuje se na poslední chvíli
	 * (méně než „Hranice pro platbu na místě“ před začátkem), splatnost = začátek a lze platit na místě.
	 * Uplynula-li splatnost podle pravidla už při rezervaci (např. −30 dní a rezervace 14 dní předem),
	 * platí se celá částka do N dní od rezervace (N = „1. část splatná do“), nejpozději den před akcí.
	 *
	 * @return array{due_ts:int, last_minute:bool, late:bool} late = pozdní rezervace po splatnosti podle pravidla (platí se celá částka najednou).
	 */
	public static function compute_due( array $ev, $now_ts ) {
		$tz       = wp_timezone();
		$start_ts = self::ts( $ev['start'] );
		$now      = ( new DateTimeImmutable( '@' . (int) $now_ts ) )->setTimezone( $tz );
		$by_booking = $now->modify( '+' . max( 0, (int) $ev['due_days'] ) . ' days' )->setTime( 23, 59, 59 )->getTimestamp();
		$start_day  = date_create_immutable( substr( (string) $ev['start'], 0, 10 ) . ' 23:59:59', $tz );
		$by_event   = $start_day ? $start_day->modify( sprintf( '%+d days', (int) $ev['due_offset'] ) )->getTimestamp() : $start_ts;
		switch ( $ev['due_mode'] ) {
			case 'event':
				$due = $by_event;
				break;
			case 'combined':
				$due = min( $by_booking, $by_event );
				break;
			default:
				$due = min( $by_booking, $start_ts );
		}
		$lm_secs     = (int) round( (float) Resandpay_Settings::get( 'last_minute_hours' ) * HOUR_IN_SECONDS );
		$late        = false;
		if ( $due < $now_ts && ( $start_ts - $now_ts ) >= $lm_secs ) {
			// Pozdní rezervace (splatnost podle pravidla už uplynula, ale do začátku zbývá dost času):
			// celá částka do N dní od rezervace (N = „1. část splatná do“, výchozí 7), nejpozději den před akcí.
			$late_due   = $now->modify( '+' . max( 0, (int) ( $ev['part1_days'] ?? 7 ) ) . ' days' )->setTime( 23, 59, 59 )->getTimestamp();
			$day_before = $start_day ? $start_day->modify( '-1 day' )->getTimestamp() : $start_ts;
			$due        = min( $late_due, $day_before );
			$late       = $due >= $now_ts;
		}
		$last_minute = $due <= $start_ts && ( ( $start_ts - $now_ts ) < $lm_secs || $due < $now_ts );
		if ( $last_minute ) {
			$due  = $start_ts;
			$late = false;
		}
		return array(
			'due_ts'      => (int) $due,
			'last_minute' => $last_minute,
			'late'        => $late,
		);
	}

	public static function type_labels() {
		return array(
			'single'   => __( 'Jednodenní akce', 'reserveandpay' ),
			'multiday' => __( 'Vícedenní akce', 'reserveandpay' ),
			'short'    => __( 'Krátká akce od–do', 'reserveandpay' ),
			'slot'     => __( 'Individuální termín', 'reserveandpay' ),
		);
	}

	/**
	 * Načte údaje akce jako pole.
	 */
	public static function get( $event_id ) {
		$post = get_post( $event_id );
		if ( $post && Resandpay_Post_Types::MATERIAL === $post->post_type ) {
			return Resandpay_Materials::as_event( $post ); // Objednávka materiálu (SWD kap. 15.1, R11) – položka bez termínu a kapacity.
		}
		if ( ! $post || Resandpay_Post_Types::EVENT !== $post->post_type ) {
			return null;
		}
		$m = function ( $key, $default = '' ) use ( $event_id ) {
			$v = get_post_meta( $event_id, '_rap_' . $key, true );
			return ( '' === $v || null === $v ) ? $default : $v;
		};
		$location_id = (int) $m( 'location', 0 );
		$ev = array(
			'id'            => (int) $event_id,
			'code'          => Resandpay_Codes::get( $event_id ),
			'title'         => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
			'status'        => $post->post_status,
			'type'          => $m( 'type', 'single' ),
			'start'         => $m( 'start' ),
			'end'           => $m( 'end' ),
			'capacity'      => max( 0, (int) $m( 'capacity', 0 ) ),
			'price'         => (float) $m( 'price', 0 ),
			'currency'      => $m( 'currency', Resandpay_Settings::get( 'currency' ) ),
			'due_days'      => '' === $m( 'due_days' ) ? (int) Resandpay_Settings::get( 'due_days' ) : (int) $m( 'due_days' ),
			'due_mode'      => in_array( $m( 'due_mode' ), self::DUE_MODES, true ) ? $m( 'due_mode' ) : self::default_due_mode(),
			'due_offset'    => '' === $m( 'due_offset' ) ? (int) Resandpay_Settings::get( 'due_offset' ) : (int) $m( 'due_offset' ),
			'cash'          => '' === $m( 'cash' ) ? (bool) Resandpay_Settings::get( 'cash_enabled' ) : (bool) (int) $m( 'cash' ),
			'part1_mode'    => in_array( $m( 'part1_mode' ), array( 'fixed', 'percent' ), true ) ? $m( 'part1_mode' ) : 'none',
			'part1_value'   => (float) $m( 'part1_value', 0 ),
			'part1_days'    => (int) $m( 'part1_days', 7 ),
			'close_hours'   => (float) $m( 'close_hours', 0 ),
			'time_display'  => $m( 'time_display', '' ),
			'show_time'     => self::resolve_show_time( $m( 'time_display', '' ), (int) $m( 'service', 0 ) ),
			'cancelled'     => (bool) $m( 'cancelled', 0 ),
			'members_only'  => false, // Doplní se níže podle přístupu (zpětná kompatibilita).
			'access'        => self::resolve_access( $event_id, (int) $m( 'service', 0 ) ),
			'program'       => $m( 'program' ),
			'gallery'       => self::parse_gallery( $m( 'gallery' ) ),
			'location_id'   => $location_id,
			'location'      => $location_id ? html_entity_decode( get_the_title( $location_id ), ENT_QUOTES, 'UTF-8' ) : '',
			'location_addr' => $location_id ? (string) get_post_meta( $location_id, '_rap_address', true ) : '',
			'location_map'  => $location_id ? (string) get_post_meta( $location_id, '_rap_map_url', true ) : '',
			'service_id'    => (int) $m( 'service', 0 ),
			'reminder_hours' => self::resolve_reminder_hours( $m( 'reminder_hours', '' ), (int) $m( 'service', 0 ) ),
			'reminder_note'  => trim( (string) $m( 'reminder_note', '' ) ) ?: ( (int) $m( 'service', 0 ) ? trim( (string) get_post_meta( (int) $m( 'service', 0 ), '_rap_reminder_note', true ) ) : '' ),
			'external'      => self::external( $event_id, (int) $m( 'service', 0 ), (string) $m( 'start' ) ),
		);
		$ev['members_only'] = 'all' !== $ev['access']['mode'];
		return $ev;
	}

	/**
	 * Externí rezervační systém (SWD kap. 3.11): akce / termín se vypisuje v ReserveAndPay,
	 * ale tlačítko „Rezervovat“ vede na externí adresu. Termín služby přebírá nastavení ze služby,
	 * vlastní může mít jen adresu.
	 *
	 * @return array|null url (s doplněnými proměnnými, '' = nevyplněno), label, new_tab, note; null = rezervace v ReserveAndPay.
	 */
	public static function external( $event_id, $service_id = 0, $start = '' ) {
		$src = $service_id ? $service_id : $event_id;
		if ( 'external' !== get_post_meta( $src, '_rap_booking', true ) ) {
			return null;
		}
		$url = $service_id ? ( (string) get_post_meta( $event_id, '_rap_ext_url', true ) ?: (string) get_post_meta( $service_id, '_rap_ext_url', true ) ) : (string) get_post_meta( $event_id, '_rap_ext_url', true );
		$ts  = $start ? self::ts( $start ) : 0;
		$url = strtr(
			$url,
			array(
				'%DATE%' => $ts ? wp_date( 'Y-m-d', $ts ) : '',
				'%TIME%' => $ts ? wp_date( 'H:i', $ts ) : '',
				'%CODE%' => rawurlencode( (string) Resandpay_Codes::get( $event_id ) ),
			)
		);
		$tab = (string) get_post_meta( $src, '_rap_ext_new_tab', true );
		return array(
			'show_capacity' => '1' === (string) get_post_meta( $src, '_rap_ext_show_capacity', true ),
			'url'     => self::https_url( $url ),
			'label'   => trim( (string) get_post_meta( $src, '_rap_ext_label', true ) ) ?: __( 'Rezervovat', 'reserveandpay' ),
			'new_tab' => '0' !== $tab,
			'note'    => trim( (string) get_post_meta( $src, '_rap_ext_note', true ) ),
		);
	}

	/**
	 * Připomínka akce (SWD kap. 15.1, R2): kolik hodin před začátkem. Vlastní hodnota akce / termínu,
	 * jinak ze služby, jinak z nastavení; 0 = vypnuto.
	 */
	public static function resolve_reminder_hours( $own, $service_id = 0 ) {
		$v = trim( (string) $own );
		if ( '' === $v && $service_id ) {
			$v = trim( (string) get_post_meta( $service_id, '_rap_reminder_hours', true ) );
		}
		if ( '' === $v ) {
			$v = (string) Resandpay_Settings::get( 'event_reminder_hours' );
		}
		return max( 0, min( 720, (int) $v ) );
	}

	/** Uloží připomínku akce (jen když ji formulář posílá). */
	public static function save_reminder_meta( $post_id, array $in ) {
		if ( array_key_exists( 'rap_reminder_hours', $in ) ) {
			$v = trim( (string) $in['rap_reminder_hours'] );
			update_post_meta( $post_id, '_rap_reminder_hours', '' === $v ? '' : max( 0, min( 720, (int) $v ) ) );
		}
		if ( array_key_exists( 'rap_reminder_note', $in ) ) {
			update_post_meta( $post_id, '_rap_reminder_note', sanitize_textarea_field( (string) $in['rap_reminder_note'] ) );
		}
	}

	/** Pole připomínky do editoru akce / služby. */
	public static function reminder_fields( $post_id, $inherit_label = '' ) {
		$h   = (string) get_post_meta( $post_id, '_rap_reminder_hours', true );
		$def = $inherit_label ?: sprintf( /* translators: %d: hodin */ __( 'z nastavení (%d h)', 'reserveandpay' ), (int) Resandpay_Settings::get( 'event_reminder_hours' ) );
		echo '<p><label for="rap_reminder_hours">' . esc_html__( 'Připomínka akce zákazníkům (hodin před začátkem)', 'reserveandpay' ) . '</label> <input type="number" min="0" max="720" step="1" id="rap_reminder_hours" name="rap_reminder_hours" value="' . esc_attr( $h ) . '" placeholder="' . esc_attr( $def ) . '" style="width:8em"> <span class="rap-hint">' . esc_html( sprintf( /* translators: %s: výchozí */ __( 'Prázdné = %s, 0 = nepřipomínat.', 'reserveandpay' ), $def ) ) . '</span></p>';
		echo '<p><label for="rap_reminder_note">' . esc_html__( 'Text do připomínky (např. co si vzít s sebou)', 'reserveandpay' ) . '</label> <textarea class="widefat" rows="2" id="rap_reminder_note" name="rap_reminder_note">' . esc_textarea( (string) get_post_meta( $post_id, '_rap_reminder_note', true ) ) . '</textarea></p>';
	}

	/**
	 * Přístup k rezervaci (SWD kap. 15.1, R4–R5): kdo a od kdy může rezervovat.
	 * Termín služby přebírá nastavení ze služby; prázdná volba = výchozí z nastavení.
	 *
	 * @return array mode (all|logged|roles), roles (pole rolí), open_at, priority_at (Y-m-d H:i:s nebo '').
	 */
	public static function resolve_access( $event_id, $service_id = 0 ) {
		$src  = $service_id ? $service_id : $event_id;
		$mode = (string) get_post_meta( $src, '_rap_access', true );
		if ( '' === $mode && ! $service_id && get_post_meta( $event_id, '_rap_members_only', true ) ) {
			$mode = 'logged'; // Dřívější volba „Jen pro přihlášené uživatele“.
		}
		$roles = array_filter( explode( ',', (string) get_post_meta( $src, '_rap_access_roles', true ) ) );
		if ( ! in_array( $mode, array( 'all', 'logged', 'roles' ), true ) ) {
			$mode  = (string) Resandpay_Settings::get( 'booking_access' );
			$roles = array_filter( explode( ',', (string) Resandpay_Settings::get( 'booking_access_roles' ) ) );
		}
		if ( ! in_array( $mode, array( 'all', 'logged', 'roles' ), true ) ) {
			$mode = 'all';
		}
		$dt = function ( $k ) use ( $src ) {
			$v = (string) get_post_meta( $src, $k, true );
			return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v ) ? $v : '';
		};
		return array(
			'mode'        => $mode,
			'roles'       => array_values( $roles ),
			'open_at'     => $dt( '_rap_open_at' ),
			'priority_at' => $dt( '_rap_priority_at' ),
		);
	}

	/** Role uživatele (prázdné pole pro nepřihlášeného). */
	private static function user_roles( $user_id ) {
		$u = $user_id ? get_userdata( $user_id ) : null;
		return $u ? (array) $u->roles : array();
	}

	/**
	 * Smí uživatel termín rezervovat teď (přístup podle role a zahájení rezervací)?
	 * Kapacitu, uzávěrku apod. řeší check_bookable() / is_open().
	 *
	 * @return true|WP_Error not_open (data: open_at, priority_at), login, role.
	 */
	public static function gate( array $ev, $user_id = null, $now = null ) {
		$a       = $ev['access'] ?? array( 'mode' => 'all', 'roles' => array(), 'open_at' => '', 'priority_at' => '' );
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$now     = $now ?? time();
		$roles   = self::user_roles( $user_id );
		$s       = Resandpay_Settings::all();
		// Nejdřív zahájení rezervací (informace „Rezervace od …“ platí pro všechny), pak přístup podle role.
		if ( '' !== $a['open_at'] && $now < self::ts( $a['open_at'] ) ) {
			$prio = array_filter( explode( ',', (string) $s['priority_roles'] ) );
			$is_p = $user_id && array_intersect( $roles, $prio );
			if ( ! ( '' !== $a['priority_at'] && $now >= self::ts( $a['priority_at'] ) && $is_p ) ) {
				return new WP_Error( 'not_open', self::opens_text( $a, $is_p ? array( 1 ) : array() ), $a );
			}
		}
		if ( 'all' !== $a['mode'] && ! $user_id ) {
			return new WP_Error( 'login', $s['access_text'] );
		}
		if ( 'roles' === $a['mode'] && ! array_intersect( $roles, $a['roles'] ) ) {
			return new WP_Error( 'role', $s['access_text'] );
		}
		return true;
	}

	/** „Rezervace od 1. 12. 2026 v 10:00“ (+ přednostní termín pro členy). */
	public static function opens_text( array $a, $is_priority_user = array() ) {
		$f   = fn( $v ) => wp_date( 'j. n. Y', self::ts( $v ) ) . ' ' . __( 'v', 'reserveandpay' ) . ' ' . wp_date( 'G:i', self::ts( $v ) );
		/* translators: %s: datum a čas */
		$out = sprintf( __( 'Rezervace od %s', 'reserveandpay' ), $f( $a['open_at'] ) );
		if ( '' !== $a['priority_at'] && self::ts( $a['priority_at'] ) < self::ts( $a['open_at'] ) && time() < self::ts( $a['priority_at'] ) ) {
			$out .= ' · ' . sprintf( (string) Resandpay_Settings::get( 'priority_text' ), $f( $a['priority_at'] ) );
		} elseif ( '' !== $a['priority_at'] && $is_priority_user && time() < self::ts( $a['priority_at'] ) ) {
			$out .= ' · ' . sprintf( (string) Resandpay_Settings::get( 'priority_text' ), $f( $a['priority_at'] ) );
		}
		return $out;
	}

	/** Lze termín teď rezervovat tímto uživatelem (otevřený, volno / adresa, přístup a zahájení)? */
	public static function can_book_now( array $ev ) {
		return self::is_open( $ev ) && true === self::gate( $ev );
	}

	/** Uloží přístup a zahájení rezervací (akce bez služby nebo služba). */
	public static function save_access_meta( $post_id, array $in ) {
		if ( ! array_key_exists( 'rap_access', $in ) ) {
			if ( ! empty( $in['rap_members_only'] ) ) {
				update_post_meta( $post_id, '_rap_access', 'logged' ); // Starší formulář / generátor.
			}
			return;
		}
		$mode = in_array( $in['rap_access'], array( 'all', 'logged', 'roles' ), true ) ? $in['rap_access'] : '';
		update_post_meta( $post_id, '_rap_access', $mode );
		$known = array_keys( wp_roles()->get_names() );
		update_post_meta( $post_id, '_rap_access_roles', implode( ',', array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['rap_access_roles'] ?? array() ) ), $known ) ) ) );
		foreach ( array( 'rap_open_at' => '_rap_open_at', 'rap_priority_at' => '_rap_priority_at' ) as $k => $meta ) {
			$v  = trim( (string) ( $in[ $k ] ?? '' ) );
			$dt = '' === $v ? false : date_create_immutable( str_replace( 'T', ' ', $v ), wp_timezone() );
			update_post_meta( $post_id, $meta, $dt ? $dt->format( 'Y-m-d H:i:00' ) : '' );
		}
		update_post_meta( $post_id, '_rap_members_only', 0 );
	}

	/** Pole „Kdo a od kdy může rezervovat“ do editoru akce / služby. */
	public static function access_fields( $post_id ) {
		$s     = Resandpay_Settings::all();
		$mode  = (string) get_post_meta( $post_id, '_rap_access', true );
		if ( '' === $mode && get_post_meta( $post_id, '_rap_members_only', true ) ) {
			$mode = 'logged';
		}
		$roles = array_filter( explode( ',', (string) get_post_meta( $post_id, '_rap_access_roles', true ) ) );
		$names = wp_roles()->get_names();
		$labels = self::access_labels();
		$defl  = $labels[ $s['booking_access'] ] ?? $labels['all'];
		if ( 'roles' === $s['booking_access'] ) {
			$defl .= ': ' . implode( ', ', array_map( fn( $r ) => isset( $names[ $r ] ) ? translate_user_role( $names[ $r ] ) : $r, array_filter( explode( ',', (string) $s['booking_access_roles'] ) ) ) );
		}
		$in = fn( $k ) => ( $v = (string) get_post_meta( $post_id, $k, true ) ) ? str_replace( ' ', 'T', substr( $v, 0, 16 ) ) : '';
		echo '<div class="rap-access">';
		echo '<p><label for="rap_access">' . esc_html__( 'Kdo může rezervovat', 'reserveandpay' ) . '</label> <select id="rap_access" name="rap_access">';
		/* translators: %s: výchozí volba */
		echo '<option value="">' . esc_html( sprintf( __( 'Výchozí z nastavení (%s)', 'reserveandpay' ), $defl ) ) . '</option>';
		foreach ( $labels as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $mode, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p class="rap-access__roles"' . ( 'roles' === $mode ? '' : ' hidden' ) . '>';
		foreach ( $names as $rk => $rl ) {
			echo '<label class="rap-inline" style="margin-right:1em"><input type="checkbox" name="rap_access_roles[]" value="' . esc_attr( $rk ) . '" ' . checked( in_array( $rk, $roles, true ), true, false ) . '> ' . esc_html( translate_user_role( $rl ) ) . '</label> ';
		}
		echo '</p>';
		echo '<p><label for="rap_open_at">' . esc_html__( 'Zahájení rezervací (od kdy)', 'reserveandpay' ) . '</label> <input type="datetime-local" id="rap_open_at" name="rap_open_at" value="' . esc_attr( $in( '_rap_open_at' ) ) . '"> <span class="rap-hint">' . esc_html__( 'Prázdné = rezervovat lze hned. Do té doby je termín na webu vidět s textem „Rezervace od …“ a rezervace se otevře sama.', 'reserveandpay' ) . '</span></p>';
		echo '<p><label for="rap_priority_at">' . esc_html__( 'Přednostní rezervace od', 'reserveandpay' ) . '</label> <input type="datetime-local" id="rap_priority_at" name="rap_priority_at" value="' . esc_attr( $in( '_rap_priority_at' ) ) . '"> <span class="rap-hint">' . esc_html__( 'Nepovinné, dřívější než zahájení. Rezervovat mohou dříve uživatelé s rolemi z Nastavení → Rezervace a platby → Přednostní rezervace.', 'reserveandpay' ) . '</span></p>';
		echo '</div>';
		echo "<script>(function(){var s=document.getElementById('rap_access');if(!s)return;var r=s.closest('.rap-access').querySelector('.rap-access__roles');s.addEventListener('change',function(){r.hidden=s.value!=='roles';});})();</script>";
	}

	public static function access_labels() {
		return array(
			'all'    => __( 'Všichni', 'reserveandpay' ),
			'logged' => __( 'Jen přihlášení', 'reserveandpay' ),
			'roles'  => __( 'Jen vybrané role', 'reserveandpay' ),
		);
	}

	/** Kapacita externího termínu k zobrazení (0 = nezobrazovat). */
	public static function external_capacity( array $ev ) {
		return ! empty( $ev['external']['show_capacity'] ) ? max( 0, (int) $ev['capacity'] ) : 0;
	}

	/** „Kapacita: 1 místo / 3 místa / 20 míst“. */
	public static function capacity_label( $n ) {
		$n = (int) $n;
		/* translators: %d: počet míst */
		$f = 1 === $n ? __( 'Kapacita: %d místo', 'reserveandpay' ) : ( $n >= 2 && $n <= 4 ? __( 'Kapacita: %d místa', 'reserveandpay' ) : __( 'Kapacita: %d míst', 'reserveandpay' ) );
		return sprintf( $f, $n );
	}

	/** Jen adresa https:// (jinak prázdný řetězec). */
	public static function https_url( $url ) {
		$url = trim( (string) $url );
		return preg_match( '#^https://[^\s/$.?\#][^\s]*$#i', $url ) ? esc_url_raw( $url, array( 'https' ) ) : '';
	}

	/** Uloží nastavení způsobu rezervace (akce nebo služba; u termínu služby jen vlastní adresu). */
	public static function save_booking_meta( $post_id, array $in, $url_only = false ) {
		if ( array_key_exists( 'rap_ext_url', $in ) ) {
			$raw = trim( (string) $in['rap_ext_url'] );
			// Proměnné %DATE% apod. projdou kontrolou adresy až po doplnění – ověřit s ukázkovými hodnotami.
			$ok  = '' === $raw || '' !== self::https_url( strtr( $raw, array( '%DATE%' => '2027-01-01', '%TIME%' => '10:00', '%CODE%' => 'X' ) ) );
			update_post_meta( $post_id, '_rap_ext_url', $ok ? $raw : '' );
			if ( ! $ok ) {
				set_transient( 'resandpay_ext_url_bad_' . get_current_user_id(), 1, 60 );
			}
		}
		if ( $url_only || ! array_key_exists( 'rap_booking', $in ) ) {
			return;
		}
		update_post_meta( $post_id, '_rap_booking', 'external' === $in['rap_booking'] ? 'external' : '' );
		update_post_meta( $post_id, '_rap_ext_label', sanitize_text_field( (string) ( $in['rap_ext_label'] ?? '' ) ) );
		update_post_meta( $post_id, '_rap_ext_new_tab', empty( $in['rap_ext_new_tab'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_rap_ext_show_capacity', empty( $in['rap_ext_show_capacity'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_rap_ext_note', sanitize_textarea_field( (string) ( $in['rap_ext_note'] ?? '' ) ) );
	}

	/**
	 * Pole „Způsob rezervace“ do editoru akce / služby.
	 *
	 * @param int  $post_id  Akce nebo služba.
	 * @param bool $url_only Termín služby – jen vlastní adresa (ostatní ze služby).
	 */
	public static function booking_fields( $post_id, $url_only = false, $inherited = null ) {
		$url = (string) get_post_meta( $post_id, '_rap_ext_url', true );
		$h   = __( 'Jen adresa https://. Proměnné termínu: %DATE% (RRRR-MM-DD), %TIME% (HH:MM), %CODE% (kód termínu). Žádné osobní údaje se nepředávají.', 'reserveandpay' );
		if ( $url_only ) {
			echo '<p class="rap-hint"><strong>' . esc_html__( 'Rezervace v externím systému', 'reserveandpay' ) . '</strong> – ' . esc_html__( 'nastaveno u služby.', 'reserveandpay' ) . ( $inherited && $inherited['url'] ? ' ' . esc_html( $inherited['url'] ) : '' ) . '</p>';
			echo '<p><label for="rap_ext_url">' . esc_html__( 'Vlastní adresa pro tento termín (nepovinné)', 'reserveandpay' ) . '</label> <input type="text" class="widefat" id="rap_ext_url" name="rap_ext_url" value="' . esc_attr( $url ) . '" placeholder="https://"><span class="rap-hint">' . esc_html( $h ) . '</span></p>';
			return;
		}
		$mode = (string) get_post_meta( $post_id, '_rap_booking', true );
		$tab  = (string) get_post_meta( $post_id, '_rap_ext_new_tab', true );
		echo '<div class="rap-booking-mode"><p><label for="rap_booking">' . esc_html__( 'Způsob rezervace', 'reserveandpay' ) . '</label> <select id="rap_booking" name="rap_booking">';
		echo '<option value="">' . esc_html__( 'Rezervace v ReserveAndPay', 'reserveandpay' ) . '</option>';
		echo '<option value="external"' . selected( $mode, 'external', false ) . '>' . esc_html__( 'Externí rezervační systém', 'reserveandpay' ) . '</option></select></p>';
		echo '<div class="rap-ext-fields"' . ( 'external' === $mode ? '' : ' hidden' ) . '>';
		echo '<p><label for="rap_ext_url">' . esc_html__( 'Adresa (URL)', 'reserveandpay' ) . ' *</label> <input type="text" class="widefat" id="rap_ext_url" name="rap_ext_url" value="' . esc_attr( $url ) . '" placeholder="https://"><span class="rap-hint">' . esc_html( $h ) . '</span></p>';
		echo '<p><label for="rap_ext_label">' . esc_html__( 'Text tlačítka', 'reserveandpay' ) . '</label> <input type="text" class="widefat" id="rap_ext_label" name="rap_ext_label" value="' . esc_attr( (string) get_post_meta( $post_id, '_rap_ext_label', true ) ) . '" placeholder="' . esc_attr__( 'Rezervovat', 'reserveandpay' ) . '"></p>';
		echo '<p><label class="rap-inline"><input type="checkbox" name="rap_ext_new_tab" value="1"' . checked( '0' !== $tab, true, false ) . '> ' . esc_html__( 'Otevřít v novém okně', 'reserveandpay' ) . '</label></p>';
		echo '<p><label class="rap-inline"><input type="checkbox" name="rap_ext_show_capacity" value="1"' . checked( '1' === (string) get_post_meta( $post_id, '_rap_ext_show_capacity', true ), true, false ) . '> ' . esc_html__( 'Zobrazit celkovou kapacitu', 'reserveandpay' ) . '</label> <span class="rap-hint">' . esc_html__( 'Na webu se ukáže „Kapacita: N míst“ z pole Kapacita – volná místa ne (zná je jen externí systém).', 'reserveandpay' ) . '</span></p>';
		echo '<p><label for="rap_ext_note">' . esc_html__( 'Poznámka pro zákazníka', 'reserveandpay' ) . '</label> <textarea class="widefat" rows="2" id="rap_ext_note" name="rap_ext_note" placeholder="' . esc_attr__( 'Např. Rezervace a platba probíhá v systému XY.', 'reserveandpay' ) . '">' . esc_textarea( (string) get_post_meta( $post_id, '_rap_ext_note', true ) ) . '</textarea></p>';
		echo '<p class="rap-hint">' . esc_html__( 'ReserveAndPay u externích termínů nevytváří rezervace, nepočítá obsazenost (volná místa se nezobrazují), neposílá e-maily ani platební údaje. Cenu a slevu na webu zobrazí – soulad s externím systémem si ohlídejte.', 'reserveandpay' ) . '</p>';
		$active = $post_id && Resandpay_Post_Types::EVENT === get_post_type( $post_id ) ? Resandpay_Reservations::count_active( $post_id ) : 0;
		if ( $active ) {
			/* translators: %d: počet */
			echo '<p class="rap-missing">' . esc_html( sprintf( __( 'Akce má %d aktivních rezervací v ReserveAndPay – zůstávají platné a vyřizují se zde (platby, e-maily).', 'reserveandpay' ), $active ) ) . '</p>';
		}
		echo '</div></div>';
		echo "<script>(function(){var s=document.getElementById('rap_booking');if(!s)return;var f=s.closest('.rap-booking-mode').querySelector('.rap-ext-fields');s.addEventListener('change',function(){f.hidden=s.value!=='external';});})();</script>";
	}

	/** Galerie akce: seznam ID příloh (obrázků) bez duplicit, nejvýše 12. */
	public static function parse_gallery( $raw ) {
		$ids = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		$ids = array_values( array_filter( $ids, fn( $id ) => wp_attachment_is_image( $id ) ) );
		return array_slice( $ids, 0, 12 );
	}

	/**
	 * Fotky pro detail akce: náhledový obrázek (je-li) a za ním galerie.
	 * U termínu služby bez vlastních fotek se použijí fotky služby.
	 */
	public static function photos( $event_id ) {
		$ev = self::get( $event_id );
		if ( ! $ev ) {
			return array();
		}
		$ids   = $ev['gallery'];
		$thumb = (int) get_post_thumbnail_id( $event_id );
		if ( ! $ids && ! $thumb && 'slot' === $ev['type'] ) {
			$sid   = (int) get_post_meta( $event_id, '_rap_service', true );
			$thumb = $sid ? (int) get_post_thumbnail_id( $sid ) : 0;
		}
		if ( $thumb ) {
			array_unshift( $ids, $thumb );
		}
		return array_values( array_unique( $ids ) );
	}

	/** Převod lokálního data a času (Y-m-d H:i:s) na timestamp. */
	public static function ts( $local ) {
		if ( empty( $local ) ) {
			return 0;
		}
		$dt = date_create_immutable( $local, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/** Lidsky čitelný termín akce. */
	/**
	 * Zobrazovat u akce / termínu čas? Volba u akce (u termínu služby, není-li u termínu, ze služby),
	 * jinak výchozí z nastavení. show = zobrazovat, date = jen datum.
	 */
	public static function resolve_show_time( $own, $service_id = 0 ) {
		$v = (string) $own;
		if ( '' === $v && $service_id ) {
			$v = (string) get_post_meta( $service_id, '_rap_time_display', true );
		}
		if ( '' === $v ) {
			$v = (string) Resandpay_Settings::get( 'time_display_default' );
		}
		return 'date' !== $v;
	}

	/** Uloží volbu zobrazení času (jen když ji formulář posílá). */
	public static function save_time_display_meta( $post_id, array $in ) {
		if ( ! array_key_exists( 'rap_time_display', $in ) ) {
			return;
		}
		$v = (string) $in['rap_time_display'];
		update_post_meta( $post_id, '_rap_time_display', in_array( $v, array( 'show', 'date' ), true ) ? $v : '' );
	}

	public static function time_display_labels( $default_label = '' ) {
		return array(
			''     => $default_label ?: sprintf( /* translators: %s: výchozí volba */ __( 'Výchozí z nastavení (%s)', 'reserveandpay' ), 'date' === Resandpay_Settings::get( 'time_display_default' ) ? __( 'jen datum', 'reserveandpay' ) : __( 'zobrazovat čas', 'reserveandpay' ) ),
			'show' => __( 'Zobrazovat čas', 'reserveandpay' ),
			'date' => __( 'Jen datum (bez času)', 'reserveandpay' ),
		);
	}

	/** Výběr „Zobrazení času“ pro editaci akce a služby. */
	public static function time_display_field( $value, $hint = '' ) {
		echo '<p><label for="rap_time_display">' . esc_html__( 'Zobrazení času', 'reserveandpay' ) . '</label> <select id="rap_time_display" name="rap_time_display">';
		foreach ( self::time_display_labels() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <span class="rap-hint">' . esc_html( $hint ?: __( 'Jen datum: na webu, v e-mailech a na platebním pokynu se čas nezobrazí (např. „12.–14. 3. 2027“). V administraci a pro výpočty (splatnost, uzávěrka, řazení) se čas používá dál – zadávejte skutečný začátek.', 'reserveandpay' ) ) . '</span></p>';
	}

	/** Datum (i s časem) pro administraci – čas vždy. */
	public static function format_date_full( array $event ) {
		return self::format_date( $event, true );
	}

	/** Jen datum: „12. 3. 2027“, „12.–14. 3. 2027“, „28. 2. – 2. 3. 2027“, „30. 12. 2026 – 2. 1. 2027“. */
	public static function format_date_only( $s, $e ) {
		if ( ! $e || wp_date( 'Y-m-d', $s ) === wp_date( 'Y-m-d', $e ) ) {
			return wp_date( 'j. n. Y', $s );
		}
		if ( wp_date( 'Y-m', $s ) === wp_date( 'Y-m', $e ) ) {
			return wp_date( 'j.', $s ) . '–' . wp_date( 'j. n. Y', $e );
		}
		if ( wp_date( 'Y', $s ) === wp_date( 'Y', $e ) ) {
			return wp_date( 'j. n.', $s ) . ' – ' . wp_date( 'j. n. Y', $e );
		}
		return wp_date( 'j. n. Y', $s ) . ' – ' . wp_date( 'j. n. Y', $e );
	}

	public static function format_date( array $event, $with_time = null ) {
		$s = self::ts( $event['start'] );
		$e = self::ts( $event['end'] );
		if ( ! $s ) {
			return '';
		}
		if ( ! ( $with_time ?? ( $event['show_time'] ?? true ) ) ) {
			return self::format_date_only( $s, $e );
		}
		$df = 'j. n. Y';
		$tf = 'G:i';
		if ( ! $e ) {
			return wp_date( $df . ' ' . $tf, $s );
		}
		if ( wp_date( 'Y-m-d', $s ) === wp_date( 'Y-m-d', $e ) ) {
			return wp_date( $df, $s ) . ', ' . wp_date( $tf, $s ) . '–' . wp_date( $tf, $e );
		}
		return wp_date( $df . ' ' . $tf, $s ) . ' – ' . wp_date( $df . ' ' . $tf, $e );
	}

	public static function format_price( $amount, $currency ) {
		$num = number_format( (float) $amount, ( floor( $amount ) == $amount ) ? 0 : 2, ',', ' ' );
		return 'CZK' === $currency ? $num . ' Kč' : $num . ' ' . $currency;
	}

	public static function booked_count( $event_id ) {
		return Resandpay_Reservations::count_active( $event_id );
	}

	/**
	 * Lze termín teď rezervovat (zobrazit tlačítko „Rezervovat“)? Externí: jen otevřený a s adresou,
	 * bez ohledu na obsazenost; v ReserveAndPay: otevřený a s volným místem.
	 */
	public static function is_open( $event_id, $now = null ) {
		$ev = is_array( $event_id ) ? $event_id : self::get( $event_id );
		if ( ! $ev || is_wp_error( self::check_bookable( $ev['id'], $now ) ) ) {
			return false;
		}
		return $ev['external'] ? '' !== $ev['external']['url'] : self::free_places( $ev['id'] ) > 0;
	}

	public static function free_places( $event_id ) {
		$ev = self::get( $event_id );
		if ( ! $ev ) {
			return 0;
		}
		return max( 0, $ev['capacity'] - self::booked_count( $event_id ) );
	}

	/**
	 * Lze akci aktuálně rezervovat? Vrací true nebo WP_Error s důvodem.
	 */
	public static function check_bookable( $event_id, $now = null, $ignore_close = false ) {
		$ev  = self::get( $event_id );
		$now = $now ?? time();
		if ( ! $ev || 'publish' !== $ev['status'] ) {
			return new WP_Error( 'not_found', __( 'Akce není k dispozici.', 'reserveandpay' ) );
		}
		if ( $ev['cancelled'] ) {
			return new WP_Error( 'cancelled', __( 'Akce byla zrušena.', 'reserveandpay' ) );
		}
		$start = self::ts( $ev['start'] );
		if ( ! $start ) {
			return new WP_Error( 'no_date', __( 'Akce nemá nastavený termín.', 'reserveandpay' ) );
		}
		if ( $now >= $start ) {
			return new WP_Error( 'started', __( 'Rezervace již není možná – akce už začala.', 'reserveandpay' ) );
		}
		if ( ! $ignore_close && $ev['close_hours'] > 0 && $now > $start - (int) round( $ev['close_hours'] * HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'closed', __( 'Rezervace na tuto akci jsou již uzavřeny.', 'reserveandpay' ) );
		}
		if ( $ev['capacity'] < 1 && ! $ev['external'] ) { // Externí termíny kapacitu v ReserveAndPay nemají.
			return new WP_Error( 'no_capacity', __( 'Akce nemá nastavenou kapacitu.', 'reserveandpay' ) );
		}
		return true;
	}

	/**
	 * Najde publikované akce, které se časově překrývají se zadanou akcí.
	 * Překryv je pouze informativní (SWD v1.4) – nebrání uložení ani rezervaci.
	 */
	public static function overlaps( $event_id, $start = null, $end = null ) {
		$ev    = self::get( $event_id );
		$start = $start ?? ( $ev['start'] ?? '' );
		$end   = $end ?? ( $ev['end'] ?? '' );
		$s     = self::ts( $start );
		$e     = self::ts( $end ) ?: $s;
		if ( ! $s ) {
			return array();
		}
		$candidates = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => -1,
				'post__not_in'   => array( (int) $event_id ),
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_rap_start',
						'value'   => wp_date( 'Y-m-d H:i:s', $e ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => '_rap_end',
						'value'   => wp_date( 'Y-m-d H:i:s', $s ),
						'compare' => '>',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$out = array();
		foreach ( $candidates as $id ) {
			$o = self::get( $id );
			if ( $o && ! $o['cancelled'] ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/** Nadcházející publikované akce. */
	public static function upcoming( $args = array() ) {
		$args  = wp_parse_args( $args, array( 'limit' => 20, 'category' => '', 'include_slots' => false ) );
		$query = array(
			'post_type'      => Resandpay_Post_Types::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['limit'],
			'fields'         => 'ids',
			'meta_key'       => '_rap_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_rap_end',
					'value'   => wp_date( 'Y-m-d H:i:s' ),
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			),
		);
		if ( ! $args['include_slots'] ) {
			$query['meta_query'][] = array(
				'key'     => '_rap_type',
				'value'   => 'slot',
				'compare' => '!=',
			);
		}
		if ( $args['category'] ) {
			$query['tax_query'] = array(
				array(
					'taxonomy' => Resandpay_Post_Types::CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $args['category'] ) ),
				),
			);
		}
		return array_filter( array_map( array( __CLASS__, 'get' ), get_posts( $query ) ) );
	}

	/** Uložení údajů akce z formulářových hodnot (rap_*). Sdílí administrace, generátor termínů i testy. */
	public static function save_meta( $post_id, array $in ) {
		$to_local = function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			if ( '' === $v ) {
				return '';
			}
			$dt = date_create_immutable( str_replace( 'T', ' ', $v ), wp_timezone() );
			return $dt ? $dt->format( 'Y-m-d H:i:s' ) : '';
		};
		$type  = in_array( $in['rap_type'] ?? '', Resandpay_Events::TYPES, true ) ? $in['rap_type'] : 'single';
		$start = $to_local( $in['rap_start'] ?? '' );
		$end   = $to_local( $in['rap_end'] ?? '' );
		if ( $start && ( ! $end || Resandpay_Events::ts( $end ) < Resandpay_Events::ts( $start ) ) ) {
			$end = $start;
		}
		$capacity = max( 0, (int) ( $in['rap_capacity'] ?? 0 ) );
		if ( 'slot' === $type && $capacity < 1 ) {
			$capacity = 1;
		}
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['rap_currency'] ?? '' ) ) );
		update_post_meta( $post_id, '_rap_type', $type );
		update_post_meta( $post_id, '_rap_start', $start );
		update_post_meta( $post_id, '_rap_end', $end );
		update_post_meta( $post_id, '_rap_location', absint( $in['rap_location'] ?? 0 ) );
		update_post_meta( $post_id, '_rap_capacity', $capacity );
		update_post_meta( $post_id, '_rap_price', round( max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_price'] ?? 0 ) ) ), 2 ) );
		update_post_meta( $post_id, '_rap_currency', strlen( $currency ) === 3 ? $currency : Resandpay_Settings::get( 'currency' ) );
		$due = (string) ( $in['rap_due_days'] ?? '' );
		update_post_meta( $post_id, '_rap_due_days', '' === trim( $due ) ? '' : max( 0, (int) $due ) );
		$mode = (string) ( $in['rap_due_mode'] ?? '' );
		update_post_meta( $post_id, '_rap_due_mode', in_array( $mode, self::DUE_MODES, true ) ? $mode : '' );
		$off = trim( (string) ( $in['rap_due_offset'] ?? '' ) );
		update_post_meta( $post_id, '_rap_due_offset', '' === $off ? '' : max( -365, min( 365, (int) $off ) ) );
		$cash = (string) ( $in['rap_cash'] ?? '' );
		update_post_meta( $post_id, '_rap_cash', in_array( $cash, array( '0', '1' ), true ) ? $cash : '' );
		self::save_part1_meta( $post_id, $in );
		self::save_time_display_meta( $post_id, $in );
		self::save_booking_meta( $post_id, $in, 'slot' === $type && (int) get_post_meta( $post_id, '_rap_service', true ) );
		self::save_reminder_meta( $post_id, $in );
		Resandpay_Discounts::save_early_meta( $post_id, $in );
		update_post_meta( $post_id, '_rap_close_hours', max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_close_hours'] ?? 0 ) ) ) );
		if ( ! ( 'slot' === $type && (int) get_post_meta( $post_id, '_rap_service', true ) ) ) {
			self::save_access_meta( $post_id, $in );
		}
		update_post_meta( $post_id, '_rap_cancelled', empty( $in['rap_cancelled'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_rap_program', wp_kses_post( (string) ( $in['rap_program'] ?? '' ) ) );
		if ( array_key_exists( 'rap_gallery', $in ) ) { // Generátor termínů galerii neposílá – ponechat.
			update_post_meta( $post_id, '_rap_gallery', implode( ',', self::parse_gallery( $in['rap_gallery'] ) ) );
		}
		if ( ! in_array( get_post_status( $post_id ), array( 'auto-draft', 'trash', false ), true ) ) {
			Resandpay_Codes::assign( $post_id ); // Kód podle typu (akce / termín), jakmile je typ uložen.
		}
	}

}
