<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nástroje: úklid testovacích dat před spuštěním ostrého provozu.
 */
class Resandpay_Admin_Tools {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_resandpay_cleanup', array( __CLASS__, 'handle_cleanup' ) );
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Nástroje', 'reserveandpay' ), __( 'Nástroje', 'reserveandpay' ), resandpay_cap_settings(), 'reserveandpay-tools', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$counts = Resandpay_Reservations::counts_by_status();
		echo '<div class="wrap"><h1>' . esc_html__( 'Nástroje ReserveAndPay', 'reserveandpay' ) . '</h1>';
		Resandpay_Admin::render_flash();
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
