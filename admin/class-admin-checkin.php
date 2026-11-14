<?php
defined( 'ABSPATH' ) || exit;

/**
 * Odbavení na místě v administraci (ResAndPay → Odbavení, zadání kap. 4.7). Logika a výpis jsou
 * sdílené s webovou stránkou [reserveandpay_checkin] – třída Resandpay_Checkin (Issue #30).
 * Přístup podle rolí z Nastavení → Rezervace → Odbavení na místě (oprávnění resandpay_checkin).
 */
class Resandpay_Admin_Checkin {

	const PAGE = 'reserveandpay-checkin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_resandpay_checkin', array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			Resandpay_Checkin::enqueue_scan();
		}
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Odbavení na místě', 'reserveandpay' ), __( 'Odbavení', 'reserveandpay' ), Resandpay_Checkin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/** @deprecated 0.25.0 Resandpay_Checkin::payable(). */
	public static function payable( array $r ) {
		return Resandpay_Checkin::payable( $r );
	}

	/** @deprecated 0.25.0 Resandpay_Checkin::mark_paid(). */
	public static function mark_paid( $id, $how, $notify = false ) {
		return Resandpay_Checkin::mark_paid( $id, $how, $notify );
	}

	public static function handle() {
		if ( ! current_user_can( Resandpay_Checkin::CAP ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		Resandpay_Checkin::handle_request( 'admin' );
	}

	public static function page() {
		echo '<div class="wrap rap-checkin"><h1>' . esc_html__( 'Odbavení na místě', 'reserveandpay' ) . '</h1>';
		echo Resandpay_Checkin::render( 'admin' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
		echo '</div>';
	}
}
