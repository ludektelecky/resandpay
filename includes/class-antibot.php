<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ochrana rezervačního formuláře proti robotům (SWD kap. 9.1).
 *
 * Vrstva 1 – neviditelná: skryté pole (honeypot) a minimální doba vyplnění ověřená podepsaným časem.
 * Vrstva 2 – limity četnosti (parametry v nastavení, 0 = bez limitu): rezervace z jedné IP adresy
 *            za okno minut a za den, aktivní rezervace jednoho e-mailu u jedné akce.
 *            IP adresa se ukládá jen jako otisk (HMAC) a po uplynutí doby uchování se maže.
 * Vrstva 3 – volitelně Cloudflare Turnstile (ověření „nejsem robot“), ve výchozím stavu vypnuto.
 *
 * Platí jen pro rezervace z webu; ruční rezervace v administraci se nekontrolují.
 */
class Resandpay_Antibot {

	const STATS = 'resandpay_antibot_stats';

	const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		if ( self::turnstile_on() ) {
			wp_register_script( 'resandpay-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
		}
	}

	public static function turnstile_on() {
		$s = Resandpay_Settings::all();
		return ! empty( $s['turnstile_enabled'] ) && '' !== trim( (string) $s['turnstile_site_key'] ) && '' !== trim( (string) $s['turnstile_secret'] );
	}

	/** Pole do formuláře: podepsaný čas zobrazení a případně widget Turnstile. */
	public static function form_fields() {
		$out = '<input type="hidden" name="rap_ts" value="' . esc_attr( self::sign_time( time() ) ) . '">';
		if ( self::turnstile_on() ) {
			wp_enqueue_script( 'resandpay-turnstile' );
			$out .= '<div class="cf-turnstile rap-turnstile" data-sitekey="' . esc_attr( Resandpay_Settings::get( 'turnstile_site_key' ) ) . '" data-language="cs"></div>';
		}
		return $out;
	}

	public static function sign_time( $ts ) {
		return (int) $ts . '.' . substr( hash_hmac( 'sha256', 'rap_ts|' . (int) $ts, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/** @return int|false Čas zobrazení formuláře, nebo false při neplatném podpisu. */
	public static function verify_time( $token ) {
		if ( ! preg_match( '/^(\d{9,11})\.([0-9a-f]{20})$/', (string) $token, $m ) ) {
			return false;
		}
		return hash_equals( self::sign_time( (int) $m[1] ), $m[0] ) ? (int) $m[1] : false;
	}

	/** Otisk IP adresy (HMAC s tajným klíčem webu – z otisku adresu zpětně zjistit nelze). */
	public static function ip_hash( $ip = null ) {
		$ip = $ip ?? (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' === $ip ? '' : substr( hash_hmac( 'sha256', 'rap_ip|' . $ip, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Kontrola odeslaného formuláře.
	 *
	 * @param array  $post     Odeslaná data ($_POST).
	 * @param int    $event_id Akce / termín.
	 * @param string $email    E-mail z formuláře.
	 * @param array  $opts     now (čas), ip_hash.
	 * @return true|WP_Error Kód chyby: honeypot, too_fast, turnstile, rate_ip, rate_ip_day, rate_email.
	 */
	public static function check( array $post, $event_id, $email, array $opts = array() ) {
		$s    = Resandpay_Settings::all();
		$now  = (int) ( $opts['now'] ?? time() );
		$hash = $opts['ip_hash'] ?? self::ip_hash();
		$generic = __( 'Formulář se nepodařilo odeslat. Načtěte prosím stránku znovu a zkuste to ještě jednou.', 'reserveandpay' );

		// 1) Skryté pole a minimální doba vyplnění.
		if ( ! empty( $post['rap_website'] ) ) {
			return self::block( 'honeypot', $generic );
		}
		$min = max( 0, (int) $s['antibot_min_seconds'] );
		if ( $min > 0 ) {
			$shown = self::verify_time( $post['rap_ts'] ?? '' );
			if ( false === $shown ) {
				return self::block( 'too_fast', $generic );
			}
			if ( $now - $shown < $min ) {
				return self::block( 'too_fast', __( 'Formulář byl odeslán příliš rychle. Zkontrolujte prosím údaje a odešlete ho znovu.', 'reserveandpay' ) );
			}
		}

		// 3) Cloudflare Turnstile (volitelně).
		if ( self::turnstile_on() ) {
			$res = self::turnstile_verify( (string) ( $post['cf-turnstile-response'] ?? '' ) );
			if ( true !== $res ) {
				return self::block( 'turnstile', __( 'Nepodařilo se ověřit, že nejste robot. Zkuste to prosím znovu.', 'reserveandpay' ) );
			}
		}

		// 2) Limity četnosti.
		global $wpdb;
		$t       = Resandpay_Reservations::table();
		$contact = Resandpay_Settings::get( 'contact_email' ) ?: Resandpay_Settings::get( 'mail_from_email' );
		/* translators: %s: kontaktní e-mail */
		$later   = sprintf( __( 'Z tohoto připojení bylo v krátké době odesláno příliš mnoho rezervací. Zkuste to prosím později, nebo nám napište na %s.', 'reserveandpay' ), $contact );
		if ( '' !== $hash ) {
			$win_min = max( 0, (int) $s['rate_ip_window_minutes'] );
			$win_max = max( 0, (int) $s['rate_ip_max_window'] );
			if ( $win_min > 0 && $win_max > 0 ) {
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ip_hash = %s AND created_at >= %s", $hash, wp_date( 'Y-m-d H:i:s', $now - $win_min * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $n >= $win_max ) {
					return self::block( 'rate_ip', $later );
				}
			}
			$day_max = max( 0, (int) $s['rate_ip_max_day'] );
			if ( $day_max > 0 ) {
				$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ip_hash = %s AND created_at >= %s", $hash, wp_date( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $n >= $day_max ) {
					return self::block( 'rate_ip_day', $later );
				}
			}
		}
		$email_max = max( 0, (int) $s['rate_email_max_event'] );
		if ( $email_max > 0 && is_email( $email ) ) {
			$in = "'" . implode( "','", Resandpay_Reservations::active_statuses() ) . "'";
			$n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE event_id = %d AND email = %s AND status IN ({$in})", $event_id, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $n >= $email_max ) {
				/* translators: 1: počet, 2: kontaktní e-mail */
				return self::block( 'rate_email', sprintf( __( 'Na tento e-mail už u této akce evidujeme %1$d rezervace. Pro další místa nás prosím kontaktujte na %2$s.', 'reserveandpay' ), $n, $contact ) );
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function turnstile_verify( $response ) {
		if ( '' === $response ) {
			return new WP_Error( 'turnstile', 'missing' );
		}
		$r = wp_remote_post(
			self::TURNSTILE_VERIFY,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => Resandpay_Settings::get( 'turnstile_secret' ),
					'response' => $response,
					'remoteip' => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			// Nedostupnost Cloudflare nesmí zablokovat rezervace – ostatní vrstvy ochrany platí dál.
			error_log( 'RAP Turnstile: ' . $r->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return true;
		}
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		return ! empty( $body['success'] ) ? true : new WP_Error( 'turnstile', implode( ',', (array) ( $body['error-codes'] ?? array() ) ) );
	}

	/** Zablokovaný pokus: statistika a (u limitů četnosti) upozornění správci nejvýš 1× za hodinu. */
	private static function block( $code, $message ) {
		$st                      = get_option( self::STATS, array() );
		$st[ $code ]             = (int) ( $st[ $code ] ?? 0 ) + 1;
		$st['last']              = current_time( 'mysql' );
		$st['last_code']         = $code;
		update_option( self::STATS, $st, false );
		do_action( 'resandpay_antibot_blocked', $code );
		if ( 0 === strpos( $code, 'rate_' ) && Resandpay_Settings::get( 'rate_notify' ) && ! get_transient( 'resandpay_antibot_notified' ) ) {
			set_transient( 'resandpay_antibot_notified', 1, HOUR_IN_SECONDS );
			$to = Resandpay_Settings::get( 'admin_email' );
			if ( is_email( $to ) ) {
				$labels = self::code_labels();
				$subj   = 'ReserveAndPay – ' . __( 'zablokované rezervace (limit četnosti)', 'reserveandpay' );
				/* translators: 1: důvod, 2: odkaz */
				$body = sprintf( __( "Rezervační formulář zablokoval pokus o rezervaci: %1\$s.\n\nMůže jít o robota, nebo o zákazníka, který rezervuje víc míst – v tom případě ho prosím kontaktujte. Přehled zablokovaných pokusů a nastavení limitů: %2\$s\n\nDalší upozornění nejdříve za hodinu.", 'reserveandpay' ), $labels[ $code ] ?? $code, admin_url( 'admin.php?page=reserveandpay-tools#rap-security' ) );
				$ok   = wp_mail( $to, $subj, $body );
				Resandpay_Mailer::record( 'security', $to, $subj, $ok, 0 );
			}
		}
		return new WP_Error( 'antibot_' . $code, $message );
	}

	public static function code_labels() {
		return array(
			'honeypot'    => __( 'vyplněné skryté pole (robot)', 'reserveandpay' ),
			'too_fast'    => __( 'příliš rychlé odeslání', 'reserveandpay' ),
			'turnstile'   => __( 'neprošlo ověření Turnstile', 'reserveandpay' ),
			'rate_ip'     => __( 'limit rezervací z jedné IP adresy za okno', 'reserveandpay' ),
			'rate_ip_day' => __( 'denní limit rezervací z jedné IP adresy', 'reserveandpay' ),
			'rate_email'  => __( 'limit rezervací jednoho e-mailu u akce', 'reserveandpay' ),
		);
	}

	/** Smaže otisky IP adres starší než nastavená doba (volá se z kontroly splatností). */
	public static function prune() {
		global $wpdb;
		$days = max( 1, (int) Resandpay_Settings::get( 'ip_hash_days' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Resandpay_Reservations::table() . " SET ip_hash = '' WHERE ip_hash <> '' AND created_at < %s", wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
