<?php
defined( 'ABSPATH' ) || exit;

/**
 * Odbavení na místě (zadání kap. 4.7, Issue #30, kap. 15.1 R23) – sdílené pro administraci
 * (ResAndPay → Odbavení) a webovou stránku se shortcodem [reserveandpay_checkin].
 *
 * Přístup mají role z Nastavení → Rezervace → Odbavení na místě (výchozí Administrátor a
 * Šéfredaktor) přes oprávnění resandpay_checkin; správce webu vždy. Uživatel odbavení smí
 * najít rezervaci (VS / QR kód), označit platbu hotově, zobrazit QR kód k zaplacení převodem
 * a spustit kontrolu plateb v bance – nic nerušit ani nevracet. Ruční „Zaplaceno převodem“ má
 * jen administrace pro role s oprávněním k rezervacím.
 */
class Resandpay_Checkin {

	const CAP        = 'resandpay_checkin';
	const BANK_PAUSE = 30; // Sekund mezi kontrolami plateb z odbavení.

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_cap' ), 10, 4 );
		add_shortcode( 'reserveandpay_checkin', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_front' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
	}

	/** Oprávnění podle rolí z nastavení (nic se neukládá do rolí). */
	public static function grant_cap( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( self::CAP, (array) $caps, true ) || ! empty( $allcaps[ self::CAP ] ) ) {
			return $allcaps;
		}
		$roles = array_filter( explode( ',', (string) Resandpay_Settings::get( 'checkin_roles' ) ) );
		if ( ! empty( $allcaps['manage_options'] ) || ( $user instanceof WP_User && array_intersect( (array) $user->roles, $roles ) ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	public static function can() {
		return is_user_logged_in() && current_user_can( self::CAP );
	}

	/** Lze rezervaci na místě označit jako zaplacenou? */
	public static function payable( array $r ) {
		return in_array( $r['status'], array( Resandpay_Reservations::PENDING, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::ONSITE, Resandpay_Reservations::OVERDUE, Resandpay_Reservations::EXPIRED ), true );
	}

	/** Smí uživatel ručně označit „Zaplaceno převodem“? (jen administrace, oprávnění k rezervacím) */
	private static function can_transfer( $ctx ) {
		return 'admin' === $ctx && current_user_can( resandpay_cap_reservations() );
	}

	/**
	 * Označí platbu na místě.
	 *
	 * @param string $how cash|transfer.
	 */
	public static function mark_paid( $id, $how, $notify = false ) {
		$r = Resandpay_Reservations::get( $id );
		if ( ! $r ) {
			return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
		}
		if ( Resandpay_Reservations::PAID === $r['status'] ) {
			return new WP_Error( 'already', __( 'Rezervace už je zaplacená.', 'reserveandpay' ) );
		}
		if ( ! self::payable( $r ) ) {
			return new WP_Error( 'state', __( 'Zrušenou rezervaci nelze označit jako zaplacenou.', 'reserveandpay' ) );
		}
		return Resandpay_Reservations::set_status(
			$id,
			Resandpay_Reservations::PAID,
			array(
				'method'         => 'cash' === $how ? 'cash' : 'transfer_manual',
				'notify'         => (bool) $notify,
				'allow_overbook' => true, // Zákazník je na místě – expirovanou rezervaci přijmeme i nad kapacitu.
			)
		);
	}

	/**
	 * Kontrola plateb v bance z odbavení (načtení avíz a spárování), nejvýš 1× za BANK_PAUSE s.
	 *
	 * @param object $imap Volitelně klient IMAP (testy).
	 * @return array [zpráva, typ]
	 */
	public static function check_bank( $id = 0, $imap = null ) {
		$wait = (int) get_transient( 'rap_checkin_bank' ) - time();
		if ( $wait > 0 ) {
			/* translators: %d: sekund */
			return array( sprintf( __( 'Kontrola plateb právě proběhla – zkuste to znovu za %d s.', 'reserveandpay' ), $wait ), 'warning' );
		}
		set_transient( 'rap_checkin_bank', time() + self::BANK_PAUSE, self::BANK_PAUSE );
		$res = Resandpay_Payments::run( 'checkin', $imap );
		if ( ! $res['ok'] ) {
			/* translators: %s: chyba */
			return array( sprintf( __( 'Kontrolu plateb se nepodařilo provést: %s', 'reserveandpay' ), $res['error'] ?: '—' ), 'error' );
		}
		$r = $id ? Resandpay_Reservations::get( $id ) : null;
		if ( $r && Resandpay_Reservations::PAID === $r['status'] ) {
			/* translators: %s: jméno */
			return array( sprintf( __( 'Platba nalezena – %s má zaplaceno.', 'reserveandpay' ), $r['customer_name'] ), 'success' );
		}
		if ( $r ) {
			/* translators: %d: počet spárovaných plateb */
			return array( sprintf( __( 'Platba za tuto rezervaci zatím v bance není (spárováno jiných plateb: %d). Okamžitá platba se obvykle objeví do několika minut – zkuste to za chvíli znovu.', 'reserveandpay' ), $res['matched'] ), 'warning' );
		}
		/* translators: 1: načteno, 2: spárováno */
		return array( sprintf( __( 'Kontrola plateb proběhla: načteno avíz %1$d, spárováno %2$d.', 'reserveandpay' ), $res['fetched'], $res['matched'] ), 'success' );
	}

	/**
	 * Provede akci odbavení (sdílené pro administraci i web).
	 *
	 * @return array [zpráva, typ]
	 */
	public static function process( $do, $id, $notify, $ctx ) {
		if ( ! self::can() ) {
			return array( __( 'K odbavení nemáte oprávnění.', 'reserveandpay' ), 'error' );
		}
		if ( 'bank' === $do ) {
			return self::check_bank( $id );
		}
		if ( 'transfer' === $do && ! self::can_transfer( $ctx ) ) {
			return array( __( 'Platba převodem se páruje automaticky – použijte „Zkontrolovat platbu v bance“.', 'reserveandpay' ), 'error' );
		}
		if ( ! in_array( $do, array( 'cash', 'transfer' ), true ) ) {
			return array( __( 'Neznámá akce.', 'reserveandpay' ), 'error' );
		}
		$res = self::mark_paid( $id, $do, $notify );
		if ( is_wp_error( $res ) ) {
			return array( $res->get_error_message(), 'error' );
		}
		/* translators: 1: jméno, 2: způsob */
		return array( sprintf( __( '%1$s – zaplaceno (%2$s).', 'reserveandpay' ), $res['customer_name'], Resandpay_Reservations::paid_method_labels()[ $res['paid_method'] ] ?? '' ), 'success' );
	}

	/* ------------------------------------------------------------ Zprávy */

	public static function flash( array $msg ) {
		set_transient( 'rap_checkin_msg_' . get_current_user_id(), $msg, 120 );
	}

	private static function render_flash( $ctx ) {
		$key = 'rap_checkin_msg_' . get_current_user_id();
		$m   = get_transient( $key );
		if ( ! $m ) {
			return '';
		}
		delete_transient( $key );
		if ( 'admin' === $ctx ) {
			return '<div class="notice notice-' . esc_attr( $m[1] ) . ' is-dismissible"><p>' . esc_html( $m[0] ) . '</p></div>';
		}
		return '<p class="rap-notice' . ( 'error' === $m[1] ? ' rap-notice--error' : '' ) . '" role="status">' . esc_html( $m[0] ) . '</p>';
	}

	/* ------------------------------------------------------------- Výpis */

	public static function base_url( $ctx ) {
		return 'admin' === $ctx ? admin_url( 'admin.php?page=' . 'reserveandpay-checkin' ) : get_permalink();
	}

	public static function url( $ctx, array $args = array() ) {
		return add_query_arg( $args, self::base_url( $ctx ) );
	}

	/** Formulář akce (POST). */
	private static function action_form( array $r, $do, $label, $class, $ctx, array $keep, $extra = '' ) {
		$action = 'admin' === $ctx ? admin_url( 'admin-post.php' ) : self::base_url( $ctx );
		$out    = '<form method="post" action="' . esc_url( $action ) . '" class="rap-checkin__act">';
		$out   .= 'admin' === $ctx ? '<input type="hidden" name="action" value="resandpay_checkin">' : '';
		$out   .= '<input type="hidden" name="rap_checkin_do" value="' . esc_attr( $do ) . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
		foreach ( $keep as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$out .= wp_nonce_field( 'rap_checkin_' . $do . '_' . (int) $r['id'], '_rapnonce', false, false ) . $extra;
		return $out . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function btn( $ctx, $primary ) {
		return 'admin' === $ctx ? ( $primary ? 'button button-primary' : 'button' ) : ( $primary ? 'rap-button' : 'rap-button rap-button--ghost' );
	}

	/** Karta rezervace. */
	public static function render_card( array $r, $ctx, array $keep ) {
		$ev     = Resandpay_Events::get( $r['event_id'] );
		$labels = Resandpay_Reservations::status_labels();
		$paid   = Resandpay_Reservations::PAID === $r['status'];
		$out    = '<div class="rap-checkin__card' . ( $paid ? ' is-paid' : ( self::payable( $r ) ? '' : ' is-off' ) ) . '">';
		$out   .= '<h2>' . esc_html( $r['customer_name'] ) . '</h2><p class="rap-checkin__state"><strong>' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</strong>';
		if ( $paid && $r['paid_method'] ) {
			$out .= ' – ' . esc_html( Resandpay_Reservations::paid_method_labels()[ $r['paid_method'] ] ?? $r['paid_method'] );
		}
		$out .= '</p><dl>';
		$out .= '<dt>' . esc_html__( 'Akce', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev ? $ev['title'] . ' – ' . Resandpay_Events::format_date_full( $ev ) : '—' ) . ( $ev && $ev['code'] ? ' <code>' . esc_html( $ev['code'] ) . '</code>' : '' ) . '</dd>';
		if ( ! $paid && (float) $r['amount_paid'] > 0 ) {
			/* translators: 1: zaplaceno, 2: celkem */
			$out .= '<dt>' . esc_html__( 'Zbývá vybrat', 'reserveandpay' ) . '</dt><dd><strong>' . esc_html( Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ) ) . '</strong> <small>(' . esc_html( sprintf( __( 'zaplaceno %1$s z %2$s', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount_paid'], $r['currency'] ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) ) . ')</small></dd>';
		} else {
			$out .= '<dt>' . esc_html__( 'Částka', 'reserveandpay' ) . '</dt><dd><strong>' . esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) . '</strong></dd>';
		}
		$out .= '<dt>' . esc_html__( 'VS', 'reserveandpay' ) . '</dt><dd><code>' . esc_html( $r['vs'] ) . '</code></dd>';
		$out .= '<dt>' . esc_html__( 'Zvolená platba', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Reservations::pay_choice_labels()[ $r['pay_choice'] ?? 'transfer' ] ?? '' ) . '</dd>';
		$out .= '<dt>' . esc_html__( 'Kontakt', 'reserveandpay' ) . '</dt><dd>' . esc_html( trim( $r['email'] . ' · ' . $r['phone'], ' ·' ) ) . '</dd>';
		if ( $r['note'] ) {
			$out .= '<dt>' . esc_html__( 'Poznámka', 'reserveandpay' ) . '</dt><dd class="rap-checkin__note">' . nl2br( esc_html( $r['note'] ) ) . '</dd>';
		}
		$out .= '</dl>';
		if ( self::payable( $r ) ) {
			$notify = '<label class="rap-checkin__notify"><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'poslat zákazníkovi potvrzení e-mailem', 'reserveandpay' ) . '</label> ';
			$out   .= '<div class="rap-checkin__actions">' . self::action_form( $r, 'cash', __( 'Zaplaceno hotově', 'reserveandpay' ), self::btn( $ctx, true ), $ctx, $keep, $notify );
			if ( self::can_transfer( $ctx ) ) {
				$out .= self::action_form( $r, 'transfer', __( 'Zaplaceno převodem', 'reserveandpay' ), self::btn( $ctx, false ), $ctx, $keep );
			}
			$out .= self::action_form( $r, 'bank', __( 'Zkontrolovat platbu v bance', 'reserveandpay' ), self::btn( $ctx, false ), $ctx, $keep );
			if ( 'admin' === $ctx && current_user_can( resandpay_cap_reservations() ) ) {
				$out .= '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-detail&id=' . (int) $r['id'] ) ) . '">' . esc_html__( 'Detail rezervace', 'reserveandpay' ) . '</a>';
			}
			$out .= '</div>';
			// QR kód k zaplacení převodem na místě – platba se spáruje automaticky z avíza.
			if ( Resandpay_QR::applicable( $r ) && Resandpay_Reservations::remaining( $r ) > 0 ) {
				$spd  = Resandpay_QR::spd( $r, Resandpay_Reservations::remaining( $r ) );
				$out .= '<details class="rap-checkin__qr"><summary class="' . esc_attr( self::btn( $ctx, false ) ) . '">' . esc_html__( 'Zobrazit QR kód k zaplacení', 'reserveandpay' ) . '</summary>';
				$out .= '<figure><img src="' . esc_attr( Resandpay_QR::data_uri( $spd, 6 ) ) . '" width="220" height="220" alt="' . esc_attr__( 'QR platba', 'reserveandpay' ) . '"><figcaption>';
				/* translators: 1: částka, 2: VS */
				$out .= esc_html( sprintf( __( '%1$s · VS %2$s', 'reserveandpay' ), Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ), $r['vs'] ) ) . '<br>' . esc_html__( 'Zákazník naskenuje kód v bankovní aplikaci. Platba se spáruje automaticky – po zaplacení klikněte na „Zkontrolovat platbu v bance“.', 'reserveandpay' ) . '</figcaption></figure></details>';
			}
		}
		return $out . '</div>';
	}

	/** Přehled rezervací dne s částkou k vybrání. */
	public static function render_day( DateTimeImmutable $day, $ctx ) {
		$events = Resandpay_Views::events_between( $day, $day->modify( '+1 day' ), '', true );
		$wd     = Resandpay_Views::weekdays_short();
		$prev   = $day->modify( '-1 day' )->format( 'Y-m-d' );
		$next   = $day->modify( '+1 day' )->format( 'Y-m-d' );
		$out    = '<h2>' . esc_html( sprintf( /* translators: %s: den */ __( 'Rezervace – %s', 'reserveandpay' ), $wd[ (int) $day->format( 'w' ) ] . ' ' . $day->format( 'j. n. Y' ) ) ) . '</h2>';
		$out   .= '<p class="rap-checkin__days"><a class="' . esc_attr( self::btn( $ctx, false ) ) . '" href="' . esc_url( self::url( $ctx, array( 'rap_day' => $prev ) ) ) . '">← ' . esc_html__( 'Předchozí den', 'reserveandpay' ) . '</a> <a class="' . esc_attr( self::btn( $ctx, false ) ) . '" href="' . esc_url( self::base_url( $ctx ) ) . '">' . esc_html__( 'Dnes', 'reserveandpay' ) . '</a> <a class="' . esc_attr( self::btn( $ctx, false ) ) . '" href="' . esc_url( self::url( $ctx, array( 'rap_day' => $next ) ) ) . '">' . esc_html__( 'Další den', 'reserveandpay' ) . ' →</a></p>';
		$any    = false;
		foreach ( $events as $ev ) {
			$rows = Resandpay_Reservations::for_event( $ev['id'] );
			if ( ! $rows ) {
				continue;
			}
			$any    = true;
			$unpaid = array_filter( $rows, fn( $r ) => Resandpay_Reservations::PAID !== $r['status'] );
			$sum    = array_sum( array_map( fn( $r ) => Resandpay_Reservations::CANCELLED === $r['status'] ? 0 : Resandpay_Reservations::remaining( $r ), $unpaid ) );
			$out   .= '<h3>' . esc_html( $ev['title'] . ' – ' . Resandpay_Events::format_date_full( $ev ) ) . '</h3>';
			/* translators: 1: počet nezaplacených, 2: částka */
			$out .= '<p>' . esc_html( sprintf( __( 'Nezaplaceno: %1$d · k vybrání na místě: %2$s', 'reserveandpay' ), count( $unpaid ), Resandpay_Events::format_price( $sum, $rows[0]['currency'] ) ) ) . '</p>';
			$out .= '<div class="rap-checkin__scroll"><table class="rap-checkin__day' . ( 'admin' === $ctx ? ' widefat striped' : '' ) . '"><thead><tr><th>' . esc_html__( 'Jméno', 'reserveandpay' ) . '</th><th>VS</th><th>' . esc_html__( 'Telefon', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Poznámka', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Odbavit', 'reserveandpay' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$keep = array( 'rap_day' => $day->format( 'Y-m-d' ) );
				$out .= '<tr><td>' . esc_html( $r['customer_name'] ) . ( 'cash' === ( $r['pay_choice'] ?? '' ) ? ' <span class="rap-tag--cash">' . esc_html__( 'hotově', 'reserveandpay' ) . '</span>' : '' ) . '</td>';
				$out .= '<td><a href="' . esc_url( self::url( $ctx, array( 'rap_vs' => $r['vs'] ) ) ) . '"><code>' . esc_html( $r['vs'] ) . '</code></a></td>';
				$out .= '<td>' . ( $r['phone'] ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r['phone'] ) ) . '">' . esc_html( $r['phone'] ) . '</a>' : '' ) . '</td>';
				$out .= '<td class="rap-checkin__note">' . esc_html( (string) $r['note'] ) . '</td>';
				$out .= '<td>' . esc_html( Resandpay_Reservations::status_labels()[ $r['status'] ] ?? $r['status'] ) . '</td><td>';
				$out .= self::payable( $r ) ? self::action_form( $r, 'cash', __( 'Hotově', 'reserveandpay' ), self::btn( $ctx, true ), $ctx, $keep ) . ' <a href="' . esc_url( self::url( $ctx, array( 'rap_vs' => $r['vs'] ) ) ) . '">' . esc_html__( 'QR / banka', 'reserveandpay' ) . '</a>' : '✓';
				$out .= '</td></tr>';
			}
			$out .= '</tbody></table></div>';
		}
		return $out . ( $any ? '' : '<p>' . esc_html__( 'V tento den nejsou žádné rezervace.', 'reserveandpay' ) . '</p>' );
	}

	/** Celá stránka odbavení (administrace i web). */
	public static function render( $ctx ) {
		$tz    = wp_timezone();
		$get   = function ( $k, $old = '' ) {
			$v = $_GET[ $k ] ?? ( '' !== $old ? ( $_GET[ $old ] ?? '' ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			return sanitize_text_field( wp_unslash( (string) $v ) );
		};
		$input = $get( 'rap_vs', 'vs' );
		$dayp  = $get( 'rap_day', 'day' );
		$day   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dayp ) ? date_create_immutable( $dayp . ' 00:00:00', $tz ) : null;
		$day   = $day ?: new DateTimeImmutable( 'today', $tz );
		$out   = self::render_flash( $ctx );
		$out  .= '<form method="get" action="' . esc_url( self::base_url( $ctx ) ) . '" class="rap-checkin__form">' . ( 'admin' === $ctx ? '<input type="hidden" name="page" value="' . esc_attr( 'reserveandpay-checkin' ) . '">' : '' );
		$out  .= '<label class="screen-reader-text" for="rap-vs">' . esc_html__( 'Variabilní symbol', 'reserveandpay' ) . '</label>';
		$out  .= '<input type="text" id="rap-vs" name="rap_vs" value="' . esc_attr( $input ) . '" placeholder="' . esc_attr__( 'Variabilní symbol', 'reserveandpay' ) . '" inputmode="numeric" autocomplete="off"> ';
		$out  .= '<button type="submit" class="' . esc_attr( self::btn( $ctx, true ) ) . '">' . esc_html__( 'Najít rezervaci', 'reserveandpay' ) . '</button></form>';
		$out  .= '<p><button type="button" class="' . esc_attr( 'admin' === $ctx ? 'button button-hero' : 'rap-button rap-button--ghost rap-checkin__scan' ) . '" id="rap-scan-btn">📷 ' . esc_html__( 'Načíst QR kód kamerou', 'reserveandpay' ) . '</button></p>';
		$out  .= '<div id="rap-scan" class="rap-scan" hidden><video muted playsinline></video><p class="rap-scan__msg"></p><p><button type="button" class="' . esc_attr( self::btn( $ctx, false ) ) . ' rap-scan__stop">' . esc_html__( 'Zavřít kameru', 'reserveandpay' ) . '</button></p></div>';
		$out  .= '<p class="rap-small description">' . esc_html__( 'Zadejte VS z potvrzovacího e-mailu (stačí i bez úvodních nul), nebo načtěte QR kód z e-mailu či platebního pokynu.', 'reserveandpay' ) . '</p>';
		if ( '' !== $input ) {
			$r = Resandpay_Reservations::find_by_vs_input( $input );
			$out .= $r ? self::render_card( $r, $ctx, array( 'rap_vs' => $input ) ) : '<p class="rap-notice rap-notice--error notice notice-error inline">' . esc_html( sprintf( /* translators: %s: VS */ __( 'Rezervace s VS %s nebyla nalezena.', 'reserveandpay' ), $input ) ) . '</p>';
		}
		return $out . self::render_day( $day, $ctx );
	}

	/* ------------------------------------------------------------- Web */

	public static function shortcode() {
		Resandpay_Style::enqueue();
		if ( ! is_user_logged_in() ) {
			return '<div class="rap-checkin rap-checkin--front"><p class="rap-notice">' . esc_html__( 'Odbavení na místě je jen pro přihlášené spolupracovníky.', 'reserveandpay' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Přihlásit se', 'reserveandpay' ) . '</a></p></div>';
		}
		if ( ! self::can() ) {
			return '<div class="rap-checkin rap-checkin--front"><p class="rap-notice">' . esc_html__( 'K odbavení nemáte oprávnění.', 'reserveandpay' ) . '</p></div>';
		}
		return '<div class="rap-checkin rap-checkin--front">' . self::render( 'front' ) . '</div>';
	}

	private static function is_checkin_page() {
		$post = get_queried_object();
		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'reserveandpay_checkin' );
	}

	/** Stránka odbavení: bez cache a bez indexování (osobní údaje). */
	public static function protect_page() {
		if ( ! self::is_checkin_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	public static function front_assets() {
		if ( ! self::is_checkin_page() || ! self::can() ) {
			return;
		}
		self::enqueue_scan();
	}

	/** Čtečka QR kódu (jsQR + checkin-scan.js). */
	public static function enqueue_scan() {
		wp_enqueue_script( 'reserveandpay-jsqr', RESANDPAY_URL . 'assets/js/jsQR.js', array(), '1.4.0', true );
		wp_enqueue_script( 'reserveandpay-scan', RESANDPAY_URL . 'assets/js/checkin-scan.js', array( 'reserveandpay-jsqr' ), RESANDPAY_VERSION, true );
		wp_add_inline_script(
			'reserveandpay-scan',
			'window.rapScanText = ' . wp_json_encode(
				array(
					'aim'        => __( 'Namiřte kameru na QR kód z e-mailu nebo platebního pokynu.', 'reserveandpay' ),
					'notPayment' => __( 'Tento QR kód neobsahuje variabilní symbol – zkuste QR platbu z e-mailu.', 'reserveandpay' ),
					'noCamera'   => __( 'Kamera není v tomto prohlížeči dostupná (je potřeba zabezpečené připojení HTTPS).', 'reserveandpay' ),
					'denied'     => __( 'Přístup ke kameře byl zamítnut. Povolte ho v prohlížeči, nebo zadejte VS ručně.', 'reserveandpay' ),
				)
			) . ';',
			'before'
		);
	}

	/** Odeslaná akce na webové stránce (PRG). */
	public static function handle_front() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['rap_checkin_do'] ) || ! empty( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::handle_request( 'front' );
	}

	/** Zpracování akce (administrace i web) a návrat zpět. */
	public static function handle_request( $ctx ) {
		$do = sanitize_key( wp_unslash( $_POST['rap_checkin_do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_POST['_rapnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_rapnonce'] ), 'rap_checkin_' . $do . '_' . $id ) ) {
			$msg = array( __( 'Platnost formuláře vypršela, zkuste to prosím znovu.', 'reserveandpay' ), 'error' );
		} else {
			$msg = self::process( $do, $id, ! empty( $_POST['notify'] ), $ctx );
		}
		self::flash( $msg );
		$back = array();
		foreach ( array( 'rap_vs', 'rap_day' ) as $k ) {
			if ( ! empty( $_POST[ $k ] ) ) {
				$back[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		$base = 'admin' === $ctx ? self::base_url( 'admin' ) : remove_query_arg( array( 'rap_vs', 'rap_day' ), wp_get_referer() ?: home_url( '/' ) );
		wp_safe_redirect( add_query_arg( $back, $base ) );
		exit;
	}
}
