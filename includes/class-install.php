<?php
defined( 'ABSPATH' ) || exit;

/**
 * Instalace, databázové tabulky a migrace.
 */
class Resandpay_Install {

	public static function activate() {
		self::create_tables();
		Resandpay_Post_Types::register();
		flush_rewrite_rules();
		if ( false === get_option( 'resandpay_settings' ) ) {
			add_option( 'resandpay_settings', Resandpay_Settings::defaults(), '', false );
		}
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'resandpay_db_version' ) !== RESANDPAY_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$res     = $wpdb->prefix . 'resandpay_reservations';
		$locks   = $wpdb->prefix . 'resandpay_locks';

		$sql = "CREATE TABLE {$res} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			vs varchar(10) NOT NULL,
			proforma_no varchar(30) NOT NULL,
			token varchar(64) NOT NULL,
			customer_name varchar(190) NOT NULL,
			email varchar(190) NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			company varchar(190) NOT NULL DEFAULT '',
			company_ic varchar(20) NOT NULL DEFAULT '',
			company_dic varchar(20) NOT NULL DEFAULT '',
			note text NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'CZK',
			last_minute tinyint(1) NOT NULL DEFAULT 0,
			due_at datetime NULL,
			paid_at datetime NULL,
			paid_method varchar(30) NOT NULL DEFAULT '',
			paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
			cancelled_at datetime NULL,
			cancel_reason varchar(190) NOT NULL DEFAULT '',
			consent_gdpr_at datetime NULL,
			consent_terms_at datetime NULL,
			newsletter tinyint(1) NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT 'web',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY vs (vs),
			UNIQUE KEY token (token),
			KEY event_status (event_id,status),
			KEY email (email)
		) {$charset};
		CREATE TABLE {$locks} (
			lock_name varchar(100) NOT NULL,
			expires_at bigint(20) NOT NULL,
			PRIMARY KEY  (lock_name)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'resandpay_db_version', RESANDPAY_DB_VERSION, false );
	}
}
