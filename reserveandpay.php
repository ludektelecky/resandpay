<?php
/**
 * Plugin Name:       ReserveAndPay
 * Plugin URI:        https://github.com/ludektelecky/resandpay
 * Description:       Rezervace akcí a termínů s platbou převodem (QR) a kontrolou plateb z bankovních avíz.
 * Version:           0.9.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            INSYSTEMS
 * Text Domain:       reserveandpay
 * Domain Path:       /languages
 * License:           Proprietary
 */

defined( 'ABSPATH' ) || exit;

define( 'RESANDPAY_VERSION', '0.9.1' );
define( 'RESANDPAY_DB_VERSION', '9' );
define( 'RESANDPAY_FILE', __FILE__ );
define( 'RESANDPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'RESANDPAY_URL', plugin_dir_url( __FILE__ ) );

require_once RESANDPAY_DIR . 'includes/class-lock.php';
require_once RESANDPAY_DIR . 'includes/class-install.php';
require_once RESANDPAY_DIR . 'includes/class-settings.php';
require_once RESANDPAY_DIR . 'includes/class-post-types.php';
require_once RESANDPAY_DIR . 'includes/class-events.php';
require_once RESANDPAY_DIR . 'includes/class-reservations.php';
require_once RESANDPAY_DIR . 'includes/class-services.php';
require_once RESANDPAY_DIR . 'includes/class-codes.php';
require_once RESANDPAY_DIR . 'includes/class-qr.php';
require_once RESANDPAY_DIR . 'includes/class-pdf.php';
require_once RESANDPAY_DIR . 'includes/class-mime.php';
require_once RESANDPAY_DIR . 'includes/banks/class-bank-rb.php';
require_once RESANDPAY_DIR . 'includes/class-imap.php';
require_once RESANDPAY_DIR . 'includes/class-payments.php';
require_once RESANDPAY_DIR . 'includes/class-mailer.php';
require_once RESANDPAY_DIR . 'includes/class-antibot.php';
require_once RESANDPAY_DIR . 'includes/class-discounts.php';
require_once RESANDPAY_DIR . 'includes/class-brand.php';
require_once RESANDPAY_DIR . 'public/class-views.php';
require_once RESANDPAY_DIR . 'public/class-frontend.php';

if ( is_admin() ) {
	require_once RESANDPAY_DIR . 'admin/class-admin.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-events.php';
	require_once RESANDPAY_DIR . 'admin/class-reservations-table.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-reservations.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-settings.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-tools.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-services.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-checkin.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-payments.php';
	require_once RESANDPAY_DIR . 'admin/class-admin-coupons.php';
}

register_activation_hook( __FILE__, array( 'Resandpay_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Resandpay_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'resandpay_init' );

/**
 * Bootstrap pluginu.
 */
function resandpay_init() {
	load_plugin_textdomain( 'reserveandpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Resandpay_Install::maybe_upgrade();
	Resandpay_Post_Types::init();
	Resandpay_Mailer::init();
	Resandpay_Antibot::init();
	Resandpay_Frontend::init();
	Resandpay_Codes::init();
	Resandpay_PDF::init();
	Resandpay_Payments::init();
	add_action( 'init', array( 'Resandpay_Install', 'maybe_backfill_codes' ), 30 );
	add_action( 'resandpay_cron_due', array( 'Resandpay_Reservations', 'process_due' ) );
	if ( ! wp_next_scheduled( 'resandpay_cron_due' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'resandpay_cron_due' );
	}
	if ( is_admin() ) {
		Resandpay_Admin::init();
		Resandpay_Admin_Events::init();
		Resandpay_Admin_Reservations::init();
		Resandpay_Admin_Settings::init();
		Resandpay_Admin_Tools::init();
		Resandpay_Admin_Services::init();
		Resandpay_Admin_Checkin::init();
		Resandpay_Admin_Payments::init();
		Resandpay_Admin_Coupons::init();
	}
}

/**
 * Oprávnění pro správu rezervací (výchozí: editor a výš).
 */
function resandpay_cap_reservations() {
	return apply_filters( 'resandpay_cap_reservations', 'edit_others_posts' );
}

/**
 * Oprávnění pro nastavení a nástroje (výchozí: administrátor).
 */
function resandpay_cap_settings() {
	return apply_filters( 'resandpay_cap_settings', 'manage_options' );
}
