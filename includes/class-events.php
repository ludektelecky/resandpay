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
		if ( ! $post || Resandpay_Post_Types::EVENT !== $post->post_type ) {
			return null;
		}
		$m = function ( $key, $default = '' ) use ( $event_id ) {
			$v = get_post_meta( $event_id, '_rap_' . $key, true );
			return ( '' === $v || null === $v ) ? $default : $v;
		};
		$location_id = (int) $m( 'location', 0 );
		return array(
			'id'            => (int) $event_id,
			'code'          => Resandpay_Codes::get( $event_id ),
			'title'         => get_the_title( $event_id ),
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
			'cancelled'     => (bool) $m( 'cancelled', 0 ),
			'members_only'  => (bool) $m( 'members_only', 0 ),
			'program'       => $m( 'program' ),
			'location_id'   => $location_id,
			'location'      => $location_id ? get_the_title( $location_id ) : '',
			'location_addr' => $location_id ? (string) get_post_meta( $location_id, '_rap_address', true ) : '',
			'location_map'  => $location_id ? (string) get_post_meta( $location_id, '_rap_map_url', true ) : '',
		);
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
	public static function format_date( array $event ) {
		$s = self::ts( $event['start'] );
		$e = self::ts( $event['end'] );
		if ( ! $s ) {
			return '';
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
		if ( $ev['capacity'] < 1 ) {
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
		update_post_meta( $post_id, '_rap_close_hours', max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_close_hours'] ?? 0 ) ) ) );
		update_post_meta( $post_id, '_rap_members_only', empty( $in['rap_members_only'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_rap_cancelled', empty( $in['rap_cancelled'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_rap_program', wp_kses_post( (string) ( $in['rap_program'] ?? '' ) ) );
		if ( ! in_array( get_post_status( $post_id ), array( 'auto-draft', 'trash', false ), true ) ) {
			Resandpay_Codes::assign( $post_id ); // Kód podle typu (akce / termín), jakmile je typ uložen.
		}
	}

}
