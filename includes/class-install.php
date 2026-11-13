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
			if ( $from && $from < 9 ) {
				// 0.8.0: plná cena = dosavadní částka (rezervace bez slev).
				global $wpdb;
				$wpdb->query( "UPDATE {$wpdb->prefix}resandpay_reservations SET price_full = amount WHERE price_full = 0" );
			}
			if ( $from && $from < 12 ) {
				self::keep_legacy_brand();
			}
			update_option( 'resandpay_codes_backfill', 1, false );
		}
	}

	/**
	 * Vzhled a značka webu, který plugin používal před 0.17.0 (slehkouhlavou.cz): paleta grafického
	 * manuálu „s lehkou hlavou“ v1, písma Fraunces a Work Sans, logo, motto a texty poznámky.
	 */
	const LEGACY_BRAND = array(
		'style_mode'         => 'custom',
		'color_bg'           => '#faf6ef',
		'color_bg_alt'       => '#f0e8da',
		'color_ink'          => '#2e2a22',
		'color_ink_soft'     => '#5c5e52',
		'color_accent'       => '#8c6423',
		'color_accent_hover' => '#2e2a22',
		'color_accent_2'     => '#6b3f4b',
		'color_gold'         => '#b8862e',
		'color_line'         => '#d6c3a0',
		'color_error'        => '#8a2e2b',
		'font_body'          => '"Work Sans", system-ui, sans-serif',
		'font_heading'       => 'Fraunces, Georgia, serif',
		'pdf_font'           => 'brand',
		'logo_line1'         => 's lehkou',
		'logo_line2'         => 'hlavou',
		'brand_motto'        => 'nádech — výdech — prostor',
		'brand_symbol_svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 80" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="4.2"><g stroke="#8C6423"><path d="M50 62 C40 46 42 26 50 10 C58 26 60 46 50 62 Z"/><path d="M50 62 C36 56 28 42 30 24 C42 32 48 46 50 62 Z"/><path d="M50 62 C64 56 72 42 70 24 C58 32 52 46 50 62 Z"/></g><path stroke="#6B3F4B" d="M8 64 C20 56 34 56 50 64 C66 56 80 56 92 64"/></svg>',
		'note_label'         => 'Poznámka pro lektorku',
		'note_hint'          => 'Např. zdravotní omezení. Údaje vidí jen lektorka.',
	);

	/**
	 * 0.17.0 (DB 12): nové instalace přebírají vzhled ze šablony a mají neutrální výchozí hodnoty.
	 * Stávající web si ponechá dosavadní vzhled – co v uloženém nastavení chybí, doplní se z LEGACY_BRAND
	 * (uložené hodnoty se nemění).
	 */
	public static function keep_legacy_brand() {
		$saved = get_option( Resandpay_Settings::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$add   = array_diff_key( self::LEGACY_BRAND, $saved );
		if ( $add ) {
			Resandpay_Settings::update( $add );
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
			price_full decimal(12,2) NOT NULL DEFAULT 0,
			discount_amount decimal(12,2) NOT NULL DEFAULT 0,
			discount_type varchar(20) NOT NULL DEFAULT '',
			discount_label varchar(190) NOT NULL DEFAULT '',
			coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
			discount_lost_at datetime NULL,
			currency varchar(3) NOT NULL DEFAULT 'CZK',
			last_minute tinyint(1) NOT NULL DEFAULT 0,
			due_at datetime NULL,
			part1_amount decimal(12,2) NOT NULL DEFAULT 0,
			part1_due_at datetime NULL,
			amount_paid decimal(12,2) NOT NULL DEFAULT 0,
			reminded_due_at datetime NULL,
			reminded_event_at datetime NULL,
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
			item_type varchar(20) NOT NULL DEFAULT 'event',
			consent_digital_at datetime NULL,
			ip_hash varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ip_hash (ip_hash),
			UNIQUE KEY vs (vs),
			UNIQUE KEY token (token),
			KEY event_status (event_id,status),
			KEY email (email),
			KEY status_due (status,due_at),
			KEY item_user (item_type,user_id)
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
		CREATE TABLE {$wpdb->prefix}resandpay_coupons (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			guid varchar(36) NOT NULL DEFAULT '',
			code varchar(60) NOT NULL,
			code_norm varchar(60) NOT NULL,
			mode varchar(10) NOT NULL DEFAULT 'percent',
			value decimal(12,2) NOT NULL DEFAULT 0,
			valid_from date NULL,
			valid_to date NULL,
			scope_all tinyint(1) NOT NULL DEFAULT 1,
			scope_events text NULL,
			scope_services text NULL,
			scope_cats text NULL,
			max_uses int(11) NOT NULL DEFAULT 0,
			max_per_email int(11) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			batch varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_norm (code_norm),
			KEY batch (batch)
		) {$charset};
		CREATE TABLE {$wpdb->prefix}resandpay_res_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(30) NOT NULL DEFAULT '',
			message varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY reservation_id (reservation_id)
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
		CREATE TABLE {$wpdb->prefix}resandpay_grants (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			material_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			note varchar(190) NOT NULL DEFAULT '',
			granted_at datetime NOT NULL,
			granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NULL,
			revoked_at datetime NULL,
			revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
			revoke_reason varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY material_user (material_id,user_id),
			KEY user_id (user_id)
		) {$charset};
		CREATE TABLE {$wpdb->prefix}resandpay_downloads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			material_id bigint(20) unsigned NOT NULL,
			file_id varchar(40) NOT NULL DEFAULT '',
			kind varchar(10) NOT NULL DEFAULT 'download',
			result varchar(20) NOT NULL DEFAULT 'ok',
			ip_hash varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_file (user_id,file_id,created_at),
			KEY material (material_id,created_at)
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
