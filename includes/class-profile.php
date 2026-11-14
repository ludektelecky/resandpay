<?php
defined( 'ABSPATH' ) || exit;

/**
 * Profil zákazníka „Moje rezervace“ (Issue #24, kap. 15.1 R18).
 *
 * Jedna stránka se shortcodem [reserveandpay_my_reservations], dva způsoby přístupu:
 * - přihlášený uživatel vidí rezervace podle e-mailu svého účtu,
 * - kdokoli s osobním odkazem z e-mailu (?rap_p=TOKEN.EXPIRACE.PODPIS) – odkaz je v každém
 *   e-mailu zákazníkovi, platí nastavitelný počet dní (výchozí 30) a jde si ho nechat poslat znovu.
 *
 * Token se neukládá: obsahuje token rezervace (jednoznačný, neuhodnutelný), čas vypršení a podpis
 * HMAC. Zákazník = e-mail rezervace. V profilu jsou všechny rezervace zákazníka a změna souhlasu
 * s novinkami (záznam data a způsobu, propsání do MailerLite).
 */
class Resandpay_Profile {

	const QUERY = 'rap_p';

	public static function init() {
		add_shortcode( 'reserveandpay_my_reservations', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 5 );
	}

	/** Stránka profilu z nastavení (0 = profil není zapnutý). */
	public static function page_id() {
		$id = (int) Resandpay_Settings::get( 'profile_page' );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	public static function link_days() {
		return max( 1, min( 365, (int) Resandpay_Settings::get( 'profile_link_days' ) ) );
	}

	private static function sign( $token, $exp ) {
		return substr( hash_hmac( 'sha256', $token . '|' . $exp, wp_salt( 'auth' ) . 'resandpay_profile' ), 0, 32 );
	}

	/** Osobní token pro rezervaci (platí link_days() dní od teď). */
	public static function token_for( array $r, $now = null ) {
		$exp = ( $now ?? time() ) + self::link_days() * DAY_IN_SECONDS;
		return $r['token'] . '.' . $exp . '.' . self::sign( $r['token'], $exp );
	}

	/** Osobní odkaz na profil (prázdný, není-li nastavena stránka profilu). */
	public static function url_for( array $r ) {
		$page = self::page_id();
		if ( ! $page || empty( $r['token'] ) ) {
			return '';
		}
		return add_query_arg( self::QUERY, self::token_for( $r ), get_permalink( $page ) );
	}

	/**
	 * Ověří osobní token.
	 *
	 * @return string|WP_Error E-mail zákazníka (klíč), nebo chyba expired / invalid.
	 */
	public static function verify( $raw, $now = null ) {
		$parts = explode( '.', (string) $raw );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[1] ) || ! hash_equals( self::sign( $parts[0], $parts[1] ), $parts[2] ) ) {
			return new WP_Error( 'invalid', __( 'Odkaz na profil není platný.', 'reserveandpay' ) );
		}
		if ( (int) $parts[1] < ( $now ?? time() ) ) {
			return new WP_Error( 'expired', __( 'Platnost odkazu vypršela. Pošleme vám nový.', 'reserveandpay' ) );
		}
		$r = Resandpay_Reservations::get_by_token( $parts[0] );
		if ( ! $r || '' === Resandpay_Customers::key( $r['email'] ) ) {
			return new WP_Error( 'invalid', __( 'Odkaz na profil není platný.', 'reserveandpay' ) );
		}
		return Resandpay_Customers::key( $r['email'] );
	}

	/**
	 * Zákazník aktuálního požadavku: platný odkaz, jinak přihlášený uživatel.
	 *
	 * @return array{email:string, via:string, token:string, error:?WP_Error}
	 */
	public static function current() {
		$out = array(
			'email' => '',
			'via'   => '',
			'token' => '',
			'error' => null,
		);
		$raw = isset( $_REQUEST[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $raw ) {
			$v = self::verify( $raw );
			if ( is_wp_error( $v ) ) {
				$out['error'] = $v;
			} else {
				$out['email'] = $v;
				$out['via']   = 'link';
				$out['token'] = $raw;
				return $out;
			}
		}
		if ( is_user_logged_in() ) {
			$out['email'] = Resandpay_Customers::key( wp_get_current_user()->user_email );
			$out['via']   = 'login';
		}
		return $out;
	}

	/** Stránka profilu: bez cache a bez indexování (osobní údaje). */
	public static function protect_page() {
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'reserveandpay_my_reservations' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true ); // Token v adrese neodchází na jiné weby.
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	/* ------------------------------------------------ Souhlas s novinkami */

	/** Aktuální souhlas zákazníka (podle poslední rezervace). */
	public static function newsletter( $email ) {
		global $wpdb;
		$key = Resandpay_Customers::key( $email );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT newsletter FROM ' . Resandpay_Reservations::table() . ' WHERE LOWER(TRIM(email)) = %s ORDER BY id DESC LIMIT 1', $key ) );
	}

	/**
	 * Změna souhlasu s novinkami zákazníkem: zapíše se ke všem jeho rezervacím s datem a způsobem,
	 * udělení → zařazení do MailerLite, odvolání → odhlášení v MailerLite (je-li napojení zapnuté).
	 *
	 * @return bool Změněno.
	 */
	public static function set_newsletter( $email, $on, $source = 'profile' ) {
		global $wpdb;
		$key = Resandpay_Customers::key( $email );
		if ( '' === $key || self::newsletter( $key ) === (bool) $on ) {
			return false;
		}
		$t = Resandpay_Reservations::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET newsletter = %d, newsletter_at = %s, newsletter_src = %s WHERE LOWER(TRIM(email)) = %s", $on ? 1 : 0, current_time( 'mysql' ), sanitize_key( $source ), $key ) );
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE LOWER(TRIM(email)) = %s ORDER BY id DESC LIMIT 1", $key ) );
		if ( $on ) {
			Resandpay_MailerLite::enqueue( (int) $last, true );
		} else {
			Resandpay_MailerLite::unsubscribe_customer( $key );
		}
		do_action( 'resandpay_newsletter_changed', $key, (bool) $on, $source );
		return true;
	}

	public static function source_labels() {
		return array(
			'profile' => __( 'změněno zákazníkem v profilu', 'reserveandpay' ),
		);
	}

	/* ------------------------------------------------------- Formuláře */

	/** Zpracování formulářů profilu (PRG – po odeslání přesměrování zpět). */
	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['rap_profile_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['rap_profile_action'] ) );
		$back   = wp_get_referer() ?: home_url( '/' );
		$back   = remove_query_arg( 'rap_pmsg', $back );
		if ( ! isset( $_POST['rap_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rap_profile_nonce'] ), 'rap_profile_' . $action ) ) {
			wp_safe_redirect( add_query_arg( 'rap_pmsg', 'expired', $back ) );
			exit;
		}
		$msg = self::process( $action, wp_unslash( $_POST ) );
		wp_safe_redirect( add_query_arg( 'rap_pmsg', $msg, $back ) );
		exit;
	}

	/** Provede akci formuláře. @return string Kód zprávy. */
	public static function process( $action, array $post ) {
		if ( 'send_link' === $action ) {
			return self::send_link( sanitize_email( (string) ( $post['rap_email'] ?? '' ) ) );
		}
		if ( 'newsletter' === $action ) {
			$cur = self::current();
			if ( '' === $cur['email'] ) {
				return 'denied';
			}
			self::set_newsletter( $cur['email'], ! empty( $post['rap_newsletter'] ) );
			return ! empty( $post['rap_newsletter'] ) ? 'nl_on' : 'nl_off';
		}
		return 'denied';
	}

	/**
	 * Pošle osobní odkaz na e-mail, pokud k němu existuje rezervace. Odpověď je vždy stejná
	 * (nelze zjistit, které e-maily mají rezervace); limit 1× za 5 min na e-mail a 10× za hodinu z IP.
	 *
	 * @return string Kód zprávy.
	 */
	public static function send_link( $email ) {
		global $wpdb;
		$key = Resandpay_Customers::key( $email );
		if ( ! is_email( $key ) ) {
			return 'bad_email';
		}
		$ip    = 'rap_pl_ip_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() );
		$em    = 'rap_pl_em_' . md5( $key . wp_salt() );
		$count = (int) get_transient( $ip );
		if ( $count >= 10 || get_transient( $em ) ) {
			return 'sent'; // Stejná odpověď i při limitu.
		}
		set_transient( $ip, $count + 1, HOUR_IN_SECONDS );
		set_transient( $em, 1, 5 * MINUTE_IN_SECONDS );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Resandpay_Reservations::table() . ' WHERE LOWER(TRIM(email)) = %s ORDER BY id DESC LIMIT 1', $key ), ARRAY_A );
		if ( $r ) {
			Resandpay_Mailer::send_template( $r['email'], 'profile_link', $r );
		}
		return 'sent';
	}

	/* ---------------------------------------------------------- Výpis */

	public static function messages() {
		return array(
			'sent'      => array( 'ok', __( 'Pokud k e-mailu existují rezervace, poslali jsme na něj odkaz na váš profil. Zkontrolujte i složku s hromadnou poštou.', 'reserveandpay' ) ),
			'bad_email' => array( 'err', __( 'Zadejte platnou e-mailovou adresu.', 'reserveandpay' ) ),
			'expired'   => array( 'err', __( 'Formulář vypršel, zkuste to prosím znovu.', 'reserveandpay' ) ),
			'denied'    => array( 'err', __( 'Pro změnu otevřete profil z odkazu v e-mailu nebo se přihlaste.', 'reserveandpay' ) ),
			'nl_on'     => array( 'ok', __( 'Děkujeme, budeme vám posílat novinky e-mailem.', 'reserveandpay' ) ),
			'nl_off'    => array( 'ok', __( 'Novinky vám už posílat nebudeme.', 'reserveandpay' ) ),
		);
	}

	private static function notice( $code ) {
		$m = self::messages()[ $code ] ?? null;
		return $m ? '<p class="rap-notice' . ( 'err' === $m[0] ? ' rap-notice--error' : '' ) . '" role="status">' . esc_html( $m[1] ) . '</p>' : '';
	}

	/** Formulář „Pošlete mi odkaz“. */
	private static function link_form() {
		return '<form method="post" class="rap-profile__link-form">'
			. wp_nonce_field( 'rap_profile_send_link', 'rap_profile_nonce', true, false )
			. '<input type="hidden" name="rap_profile_action" value="send_link">'
			. '<p><label for="rap-profile-email">' . esc_html__( 'E-mail, na který jste rezervovali', 'reserveandpay' ) . '</label><br>'
			. '<input type="email" id="rap-profile-email" name="rap_email" required autocomplete="email"> '
			. '<button type="submit" class="rap-button">' . esc_html__( 'Pošlete mi odkaz', 'reserveandpay' ) . '</button></p>'
			/* translators: %d: počet dní */
			. '<p class="rap-hint">' . esc_html( sprintf( __( 'Odkaz na váš profil platí %d dní. Najdete ho také v každém e-mailu, který od nás k rezervaci dostanete.', 'reserveandpay' ), self::link_days() ) ) . '</p>'
			. '</form>';
	}

	/** Je rezervace nadcházející (platná a akce ještě neskončila)? Zrušené a expirované patří k proběhlým. */
	private static function upcoming( array $r, $ev ) {
		if ( ! $ev || empty( $ev['start'] ) || ! in_array( $r['status'], Resandpay_Reservations::active_statuses(), true ) ) {
			return false;
		}
		$end = $ev['end'] ?: $ev['start'];
		return Resandpay_Events::ts( $end ) >= time();
	}

	/** Karta jedné rezervace. */
	public static function card( array $r ) {
		$ev      = Resandpay_Events::get( $r['event_id'] );
		$labels  = Resandpay_Reservations::status_labels();
		$mat     = 'material' === ( $r['item_type'] ?? '' );
		$title   = $ev ? $ev['title'] : get_the_title( $r['event_id'] );
		$url     = get_permalink( $r['event_id'] );
		$open    = in_array( $r['status'], array( Resandpay_Reservations::PENDING, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::OVERDUE ), true );
		$out     = '<li class="rap-profile__item rap-profile__item--' . esc_attr( $r['status'] ) . '">';
		$out    .= '<h3 class="rap-profile__name">' . ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</h3>';
		$meta    = array();
		if ( ! $mat && $ev && ! empty( $ev['start'] ) ) {
			$meta[] = Resandpay_Events::format_date( $ev );
		}
		if ( ! $mat && $ev && ! empty( $ev['location'] ) ) {
			$meta[] = $ev['location'];
		}
		/* translators: %s: datum */
		$meta[] = sprintf( __( 'rezervováno %s', 'reserveandpay' ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['created_at'] ) ) );
		$out   .= '<p class="rap-profile__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		$out   .= '<p class="rap-profile__pay"><span class="rap-status rap-status--' . esc_attr( $r['status'] ) . '">' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</span> ';
		$out   .= esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) );
		if ( (float) $r['amount'] > 0 && Resandpay_Reservations::CANCELLED !== $r['status'] ) {
			/* translators: 1: uhrazeno, 2: zbývá */
			$out .= ' · ' . esc_html( sprintf( __( 'uhrazeno %1$s, zbývá %2$s', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount_paid'], $r['currency'] ), Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ) ) );
		}
		if ( $open && Resandpay_Reservations::due_now( $r ) ) {
			/* translators: %s: datum */
			$out .= ' · ' . esc_html( sprintf( __( 'splatnost %s', 'reserveandpay' ), wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ) );
		}
		$out .= ' · VS ' . esc_html( $r['vs'] ) . '</p>';
		if ( $open && Resandpay_Reservations::remaining( $r ) > 0 ) {
			$out .= '<div class="rap-profile__payment">';
			if ( Resandpay_QR::applicable( $r ) ) {
				$png = Resandpay_QR::png( Resandpay_QR::spd( $r ), 4 );
				if ( $png ) {
					$out .= '<img class="rap-profile__qr" src="data:image/png;base64,' . esc_attr( base64_encode( $png ) ) . '" width="140" height="140" alt="' . esc_attr__( 'QR platba', 'reserveandpay' ) . '">';
				}
			}
			$out .= '<p><a class="rap-button" href="' . esc_url( Resandpay_PDF::url( $r ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Platební pokyn (PDF)', 'reserveandpay' ) . '</a></p></div>';
		}
		if ( $mat && Resandpay_Reservations::PAID === $r['status'] && class_exists( 'Resandpay_Materials' ) && Resandpay_Materials::my_url() ) {
			$out .= '<p><a href="' . esc_url( Resandpay_Materials::my_url() ) . '">' . esc_html__( 'Stáhnout v Moje materiály →', 'reserveandpay' ) . '</a></p>';
		}
		return $out . '</li>';
	}

	/** Obsah profilu pro e-mail (bez formulářů nahoře). */
	public static function render_for( $email, $token = '' ) {
		$rows = Resandpay_Reservations::query(
			array(
				'email'    => $email,
				'per_page' => 1000,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		)['items'];
		$up   = array();
		$past = array();
		$mats = array();
		foreach ( $rows as $r ) {
			$ev = Resandpay_Events::get( $r['event_id'] );
			if ( 'material' === ( $r['item_type'] ?? '' ) ) {
				$mats[] = $r;
			} elseif ( self::upcoming( $r, $ev ) ) {
				$up[] = $r;
			} else {
				$past[] = $r;
			}
		}
		usort( $up, fn( $a, $b ) => strcmp( (string) ( Resandpay_Events::get( $a['event_id'] )['start'] ?? '' ), (string) ( Resandpay_Events::get( $b['event_id'] )['start'] ?? '' ) ) );
		/* translators: %s: e-mail */
		$out = '<p class="rap-profile__who">' . esc_html( sprintf( __( 'Rezervace na e-mail %s', 'reserveandpay' ), $email ) ) . '</p>';
		if ( ! $rows ) {
			$out .= '<p class="rap-notice">' . esc_html__( 'Na tento e-mail zatím nemáte žádné rezervace.', 'reserveandpay' ) . '</p>';
		}
		foreach ( array(
			array( __( 'Nadcházející', 'reserveandpay' ), $up ),
			array( __( 'Proběhlé a zrušené', 'reserveandpay' ), $past ),
			array( __( 'Objednávky materiálů', 'reserveandpay' ), $mats ),
		) as $sec ) {
			if ( ! $sec[1] ) {
				continue;
			}
			$out .= '<h2 class="rap-profile__title">' . esc_html( $sec[0] ) . '</h2><ul class="rap-profile__list">';
			foreach ( $sec[1] as $r ) {
				$out .= self::card( $r );
			}
			$out .= '</ul>';
		}
		if ( $rows ) {
			$nl   = self::newsletter( $email );
			$out .= '<h2 class="rap-profile__title">' . esc_html__( 'Novinky e-mailem', 'reserveandpay' ) . '</h2>';
			$out .= '<form method="post" class="rap-profile__newsletter">' . wp_nonce_field( 'rap_profile_newsletter', 'rap_profile_nonce', true, false )
				. '<input type="hidden" name="rap_profile_action" value="newsletter">'
				. ( $token ? '<input type="hidden" name="' . esc_attr( self::QUERY ) . '" value="' . esc_attr( $token ) . '">' : '' )
				. '<p class="rap-check"><label><input type="checkbox" name="rap_newsletter" value="1" ' . checked( $nl, true, false ) . '> ' . esc_html__( 'Chci dostávat novinky e-mailem', 'reserveandpay' ) . '</label></p>'
				. '<p><button type="submit" class="rap-button rap-button--ghost">' . esc_html__( 'Uložit', 'reserveandpay' ) . '</button></p></form>';
		}
		return $out;
	}

	/** Shortcode [reserveandpay_my_reservations]. */
	public static function shortcode() {
		$msg = isset( $_GET['rap_pmsg'] ) ? sanitize_key( wp_unslash( $_GET['rap_pmsg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$cur = self::current();
		$out = '<div class="rap-profile">' . self::notice( $msg );
		if ( $cur['error'] ) {
			$out .= '<p class="rap-notice rap-notice--error">' . esc_html( $cur['error']->get_error_message() ) . '</p>';
		}
		if ( '' === $cur['email'] ) {
			$out .= '<p>' . esc_html__( 'Své rezervace, jejich stav a platební údaje uvidíte po otevření odkazu, který vám pošleme e-mailem.', 'reserveandpay' ) . '</p>';
			return $out . self::link_form() . '</div>';
		}
		return $out . self::render_for( $cur['email'], $cur['token'] ) . '</div>';
	}
}
