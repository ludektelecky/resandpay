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
		update_option( 'resandpay_codes_backfill', 1, false );
		if ( false === get_option( 'resandpay_settings' ) ) {
			add_option( 'resandpay_settings', Resandpay_Settings::defaults(), '', false );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'resandpay_cron_due' );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		$from = (int) get_option( 'resandpay_db_version' );
		if ( get_option( 'resandpay_db_version' ) !== RESANDPAY_DB_VERSION ) {
			self::create_tables();
			if ( $from && $from < 5 ) {
				// 0.6.0: u dříve zaplacených rezervací doplnit zaplacenou částku.
				global $wpdb;
				$wpdb->query( "UPDATE {$wpdb->prefix}resandpay_reservations SET amount_paid = amount WHERE status = 'paid' AND amount_paid = 0" );
			}
			if ( $from && $from < 8 ) {
				self::backfill_ledger();
			}
			update_option( 'resandpay_codes_backfill', 1, false );
		}
	}

	/** Po aktualizaci doplní kódy existujícím akcím, službám a termínům (až po registraci typů obsahu). */
	public static function maybe_backfill_codes() {
		if ( get_option( 'resandpay_codes_backfill' ) ) {
			Resandpay_Codes::backfill();
			delete_option( 'resandpay_codes_backfill' );
		}
	}

	/** 0.7.4: evidence plateb u rezervací – převede dosavadní součty „Zaplaceno“ na jednotlivé platby. */
	public static function backfill_ledger() {
		global $wpdb;
		$res  = $wpdb->prefix . 'resandpay_reservations';
		$led  = $wpdb->prefix . 'resandpay_res_payments';
		$pay  = $wpdb->prefix . 'resandpay_payments';
		$rows = $wpdb->get_results( "SELECT * FROM {$res} WHERE amount_paid > 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$led} WHERE reservation_id = %d", $r['id'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				continue;
			}
			$left = (float) $r['amount_paid'];
			$avs  = $wpdb->get_results( $wpdb->prepare( "SELECT id, amount, matched_at FROM {$pay} WHERE reservation_id = %d AND status IN ('matched','manual') ORDER BY id", $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $avs as $a ) {
				$amt = min( $left, (float) $a['amount'] );
				if ( $amt <= 0 ) {
					break;
				}
				$wpdb->insert( $led, array( 'reservation_id' => $r['id'], 'created_at' => $a['matched_at'] ?: $r['updated_at'], 'amount' => $amt, 'method' => $r['paid_method'] ?: 'transfer_auto', 'payment_id' => $a['id'], 'note' => 'převedeno při aktualizaci 0.7.4' ) );
				$left -= $amt;
			}
			if ( $left > 0.009 ) {
				$wpdb->insert( $led, array( 'reservation_id' => $r['id'], 'created_at' => $r['paid_at'] ?: $r['updated_at'], 'amount' => round( $left, 2 ), 'method' => $r['paid_method'] ?: 'transfer_manual', 'note' => 'převedeno při aktualizaci 0.7.4' ) );
			}
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
			part1_amount decimal(12,2) NOT NULL DEFAULT 0,
			part1_due_at datetime NULL,
			amount_paid decimal(12,2) NOT NULL DEFAULT 0,
			reminded_due_at datetime NULL,
			paid_at datetime NULL,
			pay_choice varchar(20) NOT NULL DEFAULT 'transfer',
			paid_method varchar(30) NOT NULL DEFAULT '',
			reminded_at datetime NULL,
			paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
			cancelled_at datetime NULL,
			cancel_reason varchar(190) NOT NULL DEFAULT '',
			consent_gdpr_at datetime NULL,
			consent_terms_at datetime NULL,
			newsletter tinyint(1) NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT 'web',
			ip_hash varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ip_hash (ip_hash),
			UNIQUE KEY vs (vs),
			UNIQUE KEY token (token),
			KEY event_status (event_id,status),
			KEY email (email),
			KEY status_due (status,due_at)
		) {$charset};
		CREATE TABLE {$wpdb->prefix}resandpay_payments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id varchar(190) NOT NULL,
			fingerprint varchar(32) NULL,
			bank varchar(20) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'review',
			reason varchar(255) NOT NULL DEFAULT '',
			mail_date datetime NULL,
			subject varchar(190) NOT NULL DEFAULT '',
			booked_at datetime NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'CZK',
			direction varchar(3) NOT NULL DEFAULT '',
			to_account varchar(40) NOT NULL DEFAULT '',
			counter_account varchar(40) NOT NULL DEFAULT '',
			counter_name varchar(190) NOT NULL DEFAULT '',
			vs varchar(10) NOT NULL DEFAULT '',
			ks varchar(10) NOT NULL DEFAULT '',
			ss varchar(10) NOT NULL DEFAULT '',
			message varchar(255) NOT NULL DEFAULT '',
			movement varchar(100) NOT NULL DEFAULT '',
			auth_ok tinyint(1) NOT NULL DEFAULT 0,
			auth_detail varchar(100) NOT NULL DEFAULT '',
			reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			matched_at datetime NULL,
			matched_by bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY message_id (message_id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY status (status),
			KEY vs (vs)
		) {$charset};
		CREATE TABLE {$wpdb->prefix}resandpay_runs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			trigger_by varchar(20) NOT NULL DEFAULT '',
			ok tinyint(1) NOT NULL DEFAULT 0,
			fetched int(11) NOT NULL DEFAULT 0,
			matched int(11) NOT NULL DEFAULT 0,
			review int(11) NOT NULL DEFAULT 0,
			ignored int(11) NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY started_at (started_at)
		) {$charset};
		CREATE TABLE {$wpdb->prefix}resandpay_res_payments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0,
			method varchar(30) NOT NULL DEFAULT '',
			payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			voided_at datetime NULL,
			voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			void_reason varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY reservation_id (reservation_id),
			KEY payment_id (payment_id)
		) {$charset};
		CREATE TABLE {$wpdb->prefix}resandpay_mails (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			reservation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			mail_key varchar(30) NOT NULL DEFAULT '',
			recipient varchar(255) NOT NULL DEFAULT '',
			sent_to varchar(255) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			ok tinyint(1) NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY reservation_id (reservation_id),
			KEY created_at (created_at)
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
