<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace – hlavní menu a společné prvky.
 */
class Resandpay_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'test_mode_notice' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_detail_menu' ) );
	}

	public static function menu() {
		$cap = resandpay_cap_reservations();
		add_menu_page(
			__( 'ReserveAndPay', 'reserveandpay' ),
			__( 'Rezervace', 'reserveandpay' ),
			$cap,
			'reserveandpay',
			array( 'Resandpay_Admin_Reservations', 'page_list' ),
			'dashicons-tickets-alt',
			26
		);
		add_submenu_page( 'reserveandpay', __( 'Rezervace', 'reserveandpay' ), __( 'Rezervace', 'reserveandpay' ), $cap, 'reserveandpay', array( 'Resandpay_Admin_Reservations', 'page_list' ) );
		add_submenu_page( 'reserveandpay', __( 'Nová rezervace', 'reserveandpay' ), __( 'Nová rezervace', 'reserveandpay' ), $cap, 'reserveandpay-new', array( 'Resandpay_Admin_Reservations', 'page_new' ) );
		add_submenu_page( 'reserveandpay', __( 'Kategorie akcí', 'reserveandpay' ), __( 'Kategorie akcí', 'reserveandpay' ), 'manage_categories', 'edit-tags.php?taxonomy=' . Resandpay_Post_Types::CATEGORY . '&post_type=' . Resandpay_Post_Types::EVENT );
		// Detail rezervace – skrytá stránka bez položky v menu.
		add_submenu_page( 'reserveandpay', __( 'Detail rezervace', 'reserveandpay' ), __( 'Detail rezervace', 'reserveandpay' ), $cap, 'reserveandpay-detail', array( 'Resandpay_Admin_Reservations', 'page_detail' ) );
	}

	public static function hide_detail_menu() {
		remove_submenu_page( 'reserveandpay', 'reserveandpay-detail' );
	}

	public static function parent_file( $parent ) {
		global $current_screen;
		if ( $current_screen && Resandpay_Post_Types::CATEGORY === $current_screen->taxonomy ) {
			return 'reserveandpay';
		}
		return $parent;
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		$ours   = false !== strpos( (string) $hook, 'reserveandpay' )
			|| ( $screen && in_array( $screen->post_type, array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::LOCATION ), true ) );
		if ( $ours ) {
			wp_enqueue_style( 'reserveandpay-admin', RESANDPAY_URL . 'assets/css/admin.css', array(), RESANDPAY_VERSION );
		}
	}

	public static function test_mode_notice() {
		if ( ! Resandpay_Settings::is_test_mode() || ! current_user_can( resandpay_cap_reservations() ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && ( false !== strpos( $screen->id, 'reserveandpay' ) || in_array( $screen->post_type, array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::LOCATION ), true ) );
		if ( ! $ours ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'ReserveAndPay běží v testovacím režimu.', 'reserveandpay' ) . '</strong> ';
		/* translators: %s: e-mail */
		echo esc_html( sprintf( __( 'Všechny e-maily pluginu se odesílají jen na %s.', 'reserveandpay' ), Resandpay_Settings::get( 'test_email' ) ) );
		echo '</p></div>';
	}

	/** Uloží jednorázovou zprávu pro další načtení stránky. */
	public static function flash( $message, $type = 'success' ) {
		set_transient( 'resandpay_flash_' . get_current_user_id(), array( $message, $type ), 60 );
	}

	public static function render_flash() {
		$key = 'resandpay_flash_' . get_current_user_id();
		$f   = get_transient( $key );
		if ( $f ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $f[1] ), esc_html( $f[0] ) );
		}
	}
}
