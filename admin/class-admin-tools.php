<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nástroje: úklid testovacích dat před spuštěním ostrého provozu.
 */
class Resandpay_Admin_Tools {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_resandpay_cleanup', array( __CLASS__, 'handle_cleanup' ) );
		add_action( 'admin_post_resandpay_test_mail', array( __CLASS__, 'handle_test_mail' ) );
		add_action( 'admin_post_resandpay_reset_templates', array( __CLASS__, 'handle_reset_templates' ) );
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Nástroje', 'reserveandpay' ), __( 'Nástroje', 'reserveandpay' ), resandpay_cap_settings(), 'reserveandpay-tools', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$counts = Resandpay_Reservations::counts_by_status();
		echo '<div class="wrap"><h1>' . esc_html__( 'Nástroje ReserveAndPay', 'reserveandpay' ) . '</h1>';
		Resandpay_Admin::render_flash();
		self::render_mail_section();
		self::render_security_section();
		echo '<h2>' . esc_html__( 'Texty e-mailů', 'reserveandpay' ) . '</h2>';
		echo '<p>' . esc_html__( 'Obnoví výchozí texty všech e-mailů (předměty i obsah). Platební údaje, QR kód a odkaz na platební pokyn se v e-mailech zobrazují automaticky pod textem.', 'reserveandpay' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="resandpay_reset_templates">';
		wp_nonce_field( 'resandpay_reset_templates' );
		submit_button( __( 'Obnovit výchozí texty e-mailů', 'reserveandpay' ), 'secondary', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Přepsat texty e-mailů výchozími?', 'reserveandpay' ) ) . "')" ) );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Úklid testovacích dat', 'reserveandpay' ) . '</h2>';
		/* translators: %d: počet */
		echo '<p>' . esc_html( sprintf( __( 'Smaže všechny rezervace (%d) a vynuluje řadu variabilních symbolů. Akce a lokace zůstanou zachovány.', 'reserveandpay' ), array_sum( $counts ) ) ) . '</p>';
		if ( ! Resandpay_Settings::is_test_mode() ) {
			echo '<p><strong>' . esc_html__( 'Úklid je dostupný jen v testovacím režimu – v ostrém provozu by vynulování řady VS mohlo způsobit záměnu plateb.', 'reserveandpay' ) . '</strong></p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="resandpay_cleanup">';
		wp_nonce_field( 'resandpay_cleanup' );
		echo '<p><label>' . esc_html__( 'Pro potvrzení napište SMAZAT:', 'reserveandpay' ) . ' <input type="text" name="confirm" autocomplete="off"></label></p>';
		submit_button( __( 'Smazat testovací rezervace', 'reserveandpay' ), 'delete' );
		echo '</form></div>';
	}

	/** Ochrana formuláře: aktivní vrstvy a počty zablokovaných pokusů. */
	private static function render_security_section() {
		$s  = Resandpay_Settings::all();
		$st = get_option( Resandpay_Antibot::STATS, array() );
		echo '<h2 id="rap-security">' . esc_html__( 'Ochrana formuláře proti robotům', 'reserveandpay' ) . '</h2><ul class="ul-disc">';
		echo '<li>' . esc_html__( 'Skryté pole (honeypot): zapnuto', 'reserveandpay' ) . '</li>';
		/* translators: %d: sekundy */
		echo '<li>' . esc_html( (int) $s['antibot_min_seconds'] > 0 ? sprintf( __( 'Minimální doba vyplnění: %d s', 'reserveandpay' ), (int) $s['antibot_min_seconds'] ) : __( 'Minimální doba vyplnění: vypnuto', 'reserveandpay' ) ) . '</li>';
		$lim = function ( $n ) {
			return (int) $n > 0 ? (string) (int) $n : __( 'bez limitu', 'reserveandpay' );
		};
		/* translators: 1: počet, 2: minuty, 3: počet za den */
		echo '<li>' . esc_html( sprintf( __( 'Z jedné IP adresy: %1$s za %2$d min, %3$s za 24 h', 'reserveandpay' ), $lim( $s['rate_ip_max_window'] ), (int) $s['rate_ip_window_minutes'], $lim( $s['rate_ip_max_day'] ) ) ) . '</li>';
		/* translators: %s: počet */
		echo '<li>' . esc_html( sprintf( __( 'Jeden e-mail u jedné akce: %s aktivních rezervací', 'reserveandpay' ), $lim( $s['rate_email_max_event'] ) ) ) . '</li>';
		echo '<li>' . esc_html( Resandpay_Antibot::turnstile_on() ? __( 'Cloudflare Turnstile: zapnuto', 'reserveandpay' ) : __( 'Cloudflare Turnstile: vypnuto', 'reserveandpay' ) ) . '</li></ul>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-settings' ) ) . '">' . esc_html__( 'Upravit v Nastavení → Ochrana formuláře proti robotům', 'reserveandpay' ) . '</a></p>';
		$labels = Resandpay_Antibot::code_labels();
		$any    = array_filter( array_intersect_key( $st, $labels ) );
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'Zatím nebyl zablokován žádný pokus o rezervaci.', 'reserveandpay' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Důvod zablokování', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Počet', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		foreach ( $labels as $k => $l ) {
			if ( ! empty( $st[ $k ] ) ) {
				echo '<tr><td>' . esc_html( $l ) . '</td><td>' . (int) $st[ $k ] . '</td></tr>';
			}
		}
		/* translators: 1: čas, 2: důvod */
		echo '</tbody></table><p class="description">' . esc_html( sprintf( __( 'Poslední zablokovaný pokus: %1$s (%2$s).', 'reserveandpay' ), wp_date( 'j. n. Y H:i', Resandpay_Events::ts( $st['last'] ?? '' ) ), $labels[ $st['last_code'] ?? '' ] ?? '' ) ) . '</p>';
	}

	/** Diagnostika e-mailů: zkušební odeslání, kontrola domény odesílatele, seznam odeslaných e-mailů. */
	private static function render_mail_section() {
		$s         = Resandpay_Settings::all();
		$from      = (string) $s['mail_from_email'];
		$site_host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$from_host = strtolower( substr( strrchr( $from, '@' ), 1 ) );
		echo '<h2>' . esc_html__( 'Odesílání e-mailů', 'reserveandpay' ) . '</h2>';
		/* translators: %s: e-mail */
		echo '<p>' . esc_html( sprintf( __( 'Odesílatel: %s', 'reserveandpay' ), $from ?: '—' ) ) . '</p>';
		if ( $from_host && $from_host !== $site_host ) {
			/* translators: 1: doména odesílatele, 2: doména webu */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Odesílatel je na doméně %1$s, web běží na %2$s. Hostingy takové e-maily často odmítají nebo končí ve spamu – doporučujeme odesílatele na doméně webu, případně SMTP plugin.', 'reserveandpay' ), $from_host, $site_host ) ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="resandpay_test_mail">';
		wp_nonce_field( 'resandpay_test_mail' );
		echo '<p><label>' . esc_html__( 'Poslat zkušební e-mail na:', 'reserveandpay' ) . ' <input type="email" name="to" class="regular-text" value="' . esc_attr( $s['test_email'] ) . '" required></label> ';
		submit_button( __( 'Odeslat', 'reserveandpay' ), 'secondary', 'submit', false );
		echo '</p><p class="description">' . esc_html__( 'Zkušební e-mail se posílá přímo na zadanou adresu (testovací režim ho nepřesměrovává).', 'reserveandpay' ) . '</p></form>';
		$search = sanitize_text_field( wp_unslash( $_GET['mail_q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$failed = ! empty( $_GET['mail_failed'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, absint( $_GET['mail_p'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 50;
		$log    = Resandpay_Mailer::log_rows(
			array(
				'search'      => $search,
				'only_failed' => $failed,
				'limit'       => $per,
				'offset'      => ( $paged - 1 ) * $per,
			)
		);
		echo '<h2 id="rap-maillog">' . esc_html__( 'Odeslané e-maily', 'reserveandpay' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#rap-maillog"><input type="hidden" name="page" value="reserveandpay-tools">';
		echo '<p><label>' . esc_html__( 'Hledat (e-mail, VS, předmět):', 'reserveandpay' ) . ' <input type="search" name="mail_q" value="' . esc_attr( $search ) . '"></label> ';
		echo '<label><input type="checkbox" name="mail_failed" value="1" ' . checked( $failed, true, false ) . '> ' . esc_html__( 'jen chyby', 'reserveandpay' ) . '</label> ';
		submit_button( __( 'Filtrovat', 'reserveandpay' ), 'secondary', '', false );
		echo '</p></form>';
		if ( ! $log['rows'] ) {
			echo '<p>' . esc_html( '' !== $search || $failed ? __( 'Nic nenalezeno.', 'reserveandpay' ) : __( 'Zatím nebyl odeslán žádný e-mail (seznam se vede od verze 0.7.2).', 'reserveandpay' ) ) . '</p>';
			return;
		}
		Resandpay_Mailer::render_log_table( $log['rows'] );
		$pages = (int) ceil( $log['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$url = add_query_arg( array( 'page' => 'reserveandpay-tools', 'mail_q' => $search, 'mail_failed' => $failed ? 1 : null, 'mail_p' => $p ), admin_url( 'admin.php' ) ) . '#rap-maillog';
				echo $p === $paged ? '<strong>' . (int) $p . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . (int) $p . '</a> ';
			}
			/* translators: %d: počet */
			echo '· ' . esc_html( sprintf( __( 'celkem %d', 'reserveandpay' ), $log['total'] ) ) . '</p>';
		}
	}

	public static function handle_test_mail() {
		if ( ! current_user_can( resandpay_cap_settings() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_test_mail' );
		$to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			Resandpay_Admin::flash( __( 'Zadejte platný e-mail.', 'reserveandpay' ), 'error' );
		} else {
			add_filter( 'resandpay_bypass_test_redirect', '__return_true' );
			$res = Resandpay_Mailer::send_test( $to );
			if ( is_wp_error( $res ) ) {
				/* translators: %s: chyba */
				Resandpay_Admin::flash( sprintf( __( 'Odeslání selhalo: %s', 'reserveandpay' ), $res->get_error_message() ), 'error' );
			} else {
				/* translators: %s: e-mail */
				Resandpay_Admin::flash( sprintf( __( 'WordPress e-mail předal k odeslání na %s. Pokud nedorazí ani do spamu, hosting ho zahazuje – bude potřeba SMTP.', 'reserveandpay' ), $to ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-tools' ) );
		exit;
	}

	public static function handle_reset_templates() {
		if ( ! current_user_can( resandpay_cap_settings() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_reset_templates' );
		Resandpay_Settings::reset_templates();
		Resandpay_Admin::flash( __( 'Texty e-mailů byly obnoveny na výchozí.', 'reserveandpay' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-tools' ) );
		exit;
	}

	public static function handle_cleanup() {
		if ( ! current_user_can( resandpay_cap_settings() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_cleanup' );
		if ( ! Resandpay_Settings::is_test_mode() || 'SMAZAT' !== sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) ) ) {
			Resandpay_Admin::flash( __( 'Úklid nebyl proveden – chybí potvrzení nebo není zapnutý testovací režim.', 'reserveandpay' ), 'error' );
		} else {
			Resandpay_Reservations::delete_all();
			Resandpay_Admin::flash( __( 'Testovací rezervace byly smazány a řada VS vynulována.', 'reserveandpay' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-tools' ) );
		exit;
	}
}
