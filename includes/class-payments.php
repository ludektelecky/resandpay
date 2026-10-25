<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kontrola plateb z bankovních avíz (SWD kap. 6): zpracování avíza, párování
 * s rezervací, běh nad schránkou IMAP, spouštění CRONem, log a upozornění.
 */
class Resandpay_Payments {

	const MATCHED  = 'matched';
	const REVIEW   = 'review';
	const IGNORED  = 'ignored';
	const MANUAL   = 'manual';
	const RESOLVED = 'resolved';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_cron' ), 99 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_payments';
	}

	public static function runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_runs';
	}

	public static function status_labels() {
		return array(
			self::REVIEW   => __( 'K ruční kontrole', 'reserveandpay' ),
			self::MATCHED  => __( 'Spárováno automaticky', 'reserveandpay' ),
			self::MANUAL   => __( 'Přiřazeno ručně', 'reserveandpay' ),
			self::RESOLVED => __( 'Vyřešeno bez přiřazení', 'reserveandpay' ),
			self::IGNORED  => __( 'Ignorováno', 'reserveandpay' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Heslo ke schránce – šifrovaně v DB, nebo konstanta ve wp-config.php.
	 * ------------------------------------------------------------------- */

	private static function key() {
		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		return hash( 'sha256', $salt . 'resandpay-imap', true );
	}

	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ct ? '' : 'v1:' . base64_encode( $iv . $tag . $ct );
	}

	/**
	 * Dešifrování. Opraví i heslo zašifrované omylem vícekrát (chyba verzí 0.5.0–0.5.2 –
	 * každé otevření stránky Platby přidalo vrstvu): dokud výsledek vypadá jako
	 * zašifrovaná hodnota, dešifruje se znovu.
	 */
	public static function decrypt( $stored ) {
		$plain = self::decrypt_once( $stored );
		for ( $i = 0; $i < 200 && 0 === strpos( $plain, 'v1:' ); $i++ ) {
			$inner = self::decrypt_once( $plain );
			if ( '' === $inner ) {
				break;
			}
			$plain = $inner;
		}
		return $plain;
	}

	private static function decrypt_once( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	public static function imap_password() {
		if ( defined( 'RESANDPAY_IMAP_PASSWORD' ) ) {
			return (string) RESANDPAY_IMAP_PASSWORD;
		}
		$stored = (string) Resandpay_Settings::get( 'imap_password' );
		$plain  = self::decrypt( $stored );
		// Vícevrstvé zašifrování (starší verze) jednou provždy uložit znovu jako jednu vrstvu.
		if ( '' !== $plain && 0 === strpos( self::decrypt_once( $stored ), 'v1:' ) ) {
			Resandpay_Settings::update( array( 'imap_password' => self::encrypt( $plain ) ) );
		}
		return $plain;
	}

	/** Tajný klíč pro URL CRONu (vygeneruje se při prvním použití). */
	public static function cron_key() {
		$k = (string) Resandpay_Settings::get( 'cron_key' );
		if ( strlen( $k ) < 24 ) {
			$k = wp_generate_password( 32, false, false );
			Resandpay_Settings::update( array( 'cron_key' => $k ) );
		}
		return $k;
	}

	public static function cron_url() {
		return add_query_arg( 'rap_cron', self::cron_key(), home_url( '/' ) );
	}

	/* ---------------------------------------------------------------------
	 * Zpracování jednoho avíza
	 * ------------------------------------------------------------------- */

	/**
	 * Zpracuje surový e-mail avíza.
	 *
	 * @param string $trigger cron|manual|upload|test – určuje způsob úhrady u spárované rezervace.
	 * @return array status (matched|review|ignored|duplicate|not_bank), reason, payment_id, reservation_id.
	 */
	public static function process_raw( $raw, $trigger = 'cron' ) {
		global $wpdb;
		$s    = Resandpay_Settings::all();
		$mime = Resandpay_Mime::parse( $raw );
		$h    = $mime['headers'];
		$from = Resandpay_Mime::address( Resandpay_Mime::first( $h, 'from' ) );
		if ( $from !== strtolower( (string) $s['bank_sender'] ) ) {
			return array(
				'status' => 'not_bank',
				/* translators: %s: odesílatel */
				'reason' => sprintf( __( 'E-mail není od odesílatele avíz (%s).', 'reserveandpay' ), $from ),
			);
		}
		$msgid = trim( Resandpay_Mime::first( $h, 'message-id' ), " <>\t" );
		if ( '' === $msgid ) {
			$msgid = 'sha1:' . sha1( $raw );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE message_id = %s', $msgid ) );
		if ( $existing ) {
			return array(
				'status'     => 'duplicate',
				'reason'     => __( 'Avízo už bylo zpracováno.', 'reserveandpay' ),
				'payment_id' => (int) $existing,
			);
		}

		$now  = current_time( 'mysql' );
		$date = Resandpay_Mime::first( $h, 'date' );
		$row  = array(
			'message_id'  => mb_substr( $msgid, 0, 190 ),
			'bank'        => Resandpay_Bank_RB::ID,
			'mail_date'   => $date ? wp_date( 'Y-m-d H:i:s', strtotime( $date ) ) : null,
			'subject'     => mb_substr( Resandpay_Mime::decode_header( Resandpay_Mime::first( $h, 'subject' ) ), 0, 190 ),
			'source'      => $trigger,
			'created_at'  => $now,
			'updated_at'  => $now,
		);
		$p = Resandpay_Bank_RB::parse( $mime );
		if ( is_wp_error( $p ) ) {
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => $p->get_error_message(), 'auth_ok' => 0, 'auth_detail' => '' ), null );
		}
		$auth = Resandpay_Bank_RB::verify( $mime, $s['bank_sender'] );
		$row  = $row + array(
			'booked_at'       => $p['booked_at'] ?: null,
			'amount'          => $p['amount'],
			'currency'        => $p['currency'] ?: 'CZK',
			'direction'       => $p['direction'],
			'to_account'      => $p['to_account'],
			'counter_account' => $p['counter_account'],
			'counter_name'    => mb_substr( $p['counter_name'], 0, 190 ),
			'vs'              => $p['vs'],
			'ks'              => $p['ks'],
			'ss'              => $p['ss'],
			'message'         => mb_substr( $p['message'], 0, 255 ),
			'movement'        => mb_substr( $p['movement'], 0, 100 ),
			'auth_ok'         => $auth['ok'] ? 1 : 0,
			'auth_detail'     => implode( ',', array_keys( array_filter( $auth['checks'] ) ) ),
		);
		$row['fingerprint'] = md5( implode( '|', array( $row['booked_at'], $row['amount'], $row['vs'], $row['counter_account'], $row['to_account'] ) ) );
		$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE fingerprint = %s', $row['fingerprint'] ) );
		if ( $dup ) {
			$row['fingerprint'] = null; // Unikátní otisk patří prvnímu avízu.
			// Nic neignorovat – může jít i o dvě skutečné platby se stejnými údaji.
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => __( 'Stejná platba (čas, částka, VS, protiúčet) už byla zpracována – pravděpodobně duplicitní avízo, ověřte ve výpisu z banky.', 'reserveandpay' ) ), null );
		}

		// Pravidla párování (SWD kap. 6.1, 6.5).
		if ( 'in' !== $p['direction'] ) {
			return self::store( $row + array( 'status' => self::IGNORED, 'reason' => __( 'Odchozí platba – nezpracovává se.', 'reserveandpay' ) ), null );
		}
		if ( ! Resandpay_Bank_RB::same_account( $p['to_account'], $s['bank_account'] ) ) {
			/* translators: 1: účet z avíza, 2: účet v nastavení */
			return self::store( $row + array( 'status' => self::IGNORED, 'reason' => sprintf( __( 'Platba na jiný účet (%1$s, v nastavení %2$s).', 'reserveandpay' ), $p['to_account'], $s['bank_account'] ?: '—' ) ), null );
		}
		if ( ! $auth['ok'] && ! empty( $s['bank_require_auth'] ) ) {
			$failed = array_keys( array_filter( $auth['checks'], fn( $ok ) => ! $ok ) );
			/* translators: %s: neúspěšné kontroly */
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => sprintf( __( 'Nepotvrzená pravost avíza (%s) – zkontrolujte platbu ve výpisu z banky.', 'reserveandpay' ), strtoupper( implode( ', ', $failed ) ) ) ), null );
		}
		if ( '' === $p['vs'] ) {
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => __( 'Platba bez variabilního symbolu.', 'reserveandpay' ) ), null );
		}
		$r = Resandpay_Reservations::find_by_vs_input( $p['vs'] );
		if ( ! $r ) {
			/* translators: %s: VS */
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => sprintf( __( 'Rezervace s VS %s neexistuje.', 'reserveandpay' ), $p['vs'] ) ), null );
		}
		$check = self::check_match( $r, $row );
		if ( is_wp_error( $check ) ) {
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => $check->get_error_message(), 'reservation_id' => (int) $r['id'] ), null );
		}
		$paid = Resandpay_Reservations::add_payment( $r['id'], $p['amount'], 'cron' === $trigger ? 'transfer_auto' : 'transfer_run', array( 'booked_at' => $p['booked_at'] ?: null ) );
		if ( is_wp_error( $paid ) ) {
			return self::store( $row + array( 'status' => self::REVIEW, 'reason' => $paid->get_error_message(), 'reservation_id' => (int) $r['id'] ), null );
		}
		$left = Resandpay_Reservations::remaining( $paid );
		$out  = self::store(
			$row + array(
				'status'         => self::MATCHED,
				/* translators: 1: zaplaceno, 2: celkem */
				'reason'         => $left > 0 ? sprintf( __( 'Částečná platba – zaplaceno %1$s z %2$s, zákazník upozorněn.', 'reserveandpay' ), Resandpay_Events::format_price( $paid['amount_paid'], $paid['currency'] ), Resandpay_Events::format_price( $paid['amount'], $paid['currency'] ) ) : '',
				'reservation_id' => (int) $r['id'],
				'matched_at'     => $now,
			),
			$r
		);
		if ( ! empty( $paid['ledger_id'] ) && $out['payment_id'] ) {
			$wpdb->update( Resandpay_Reservations::ledger_table(), array( 'payment_id' => $out['payment_id'] ), array( 'id' => (int) $paid['ledger_id'] ) );
		}
		return $out;
	}

	/**
	 * Lze platbu přiřadit k rezervaci? (měna, stav, přeplatek). Menší částka je
	 * v pořádku – zapíše se jako částečná platba (1. část / část platby).
	 */
	public static function check_match( array $r, array $payment ) {
		if ( strtoupper( (string) $payment['currency'] ) !== strtoupper( (string) $r['currency'] ) ) {
			/* translators: 1: měna platby, 2: měna rezervace */
			return new WP_Error( 'currency', sprintf( __( 'Měna platby %1$s neodpovídá rezervaci (%2$s).', 'reserveandpay' ), $payment['currency'], $r['currency'] ) );
		}
		if ( Resandpay_Reservations::PAID === $r['status'] || Resandpay_Reservations::remaining( $r ) <= 0 ) {
			return new WP_Error( 'paid', __( 'Rezervace už je zaplacená – možná duplicitní platba (vrátit?).', 'reserveandpay' ) );
		}
		if ( Resandpay_Reservations::CANCELLED === $r['status'] ) {
			return new WP_Error( 'cancelled', __( 'Rezervace je zrušená – platbu je potřeba vyřešit ručně (vrátit?).', 'reserveandpay' ) );
		}
		$left = Resandpay_Reservations::remaining_for_payment( $r, $payment['booked_at'] ?? null );
		if ( (float) $payment['amount'] > $left + 0.009 ) {
			/* translators: 1: přijato, 2: zbývá */
			return new WP_Error( 'overpay', sprintf( __( 'Přeplatek: přijato %1$s, zbývalo zaplatit %2$s.', 'reserveandpay' ), Resandpay_Events::format_price( $payment['amount'], $payment['currency'] ), Resandpay_Events::format_price( $left, $r['currency'] ) ) );
		}
		return true;
	}

	private static function store( array $row, $reservation ) {
		global $wpdb;
		$wpdb->insert( self::table(), $row );
		return array(
			'status'         => $row['status'],
			'reason'         => $row['reason'] ?? '',
			'payment_id'     => (int) $wpdb->insert_id,
			'reservation_id' => (int) ( $row['reservation_id'] ?? 0 ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/** Výpis avíz pro administraci. */
	public static function query( $status = '', $limit = 100 ) {
		global $wpdb;
		$where = $status && isset( self::status_labels()[ $status ] ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY id DESC LIMIT " . max( 1, (int) $limit ), ARRAY_A );
	}

	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( array_keys( self::status_labels() ), 0 );
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** Návrh rezervace pro ruční párování (VS ve zprávě pro příjemce, nebo jediná nezaplacená se stejnou částkou a jménem). */
	public static function suggest( array $p ) {
		if ( preg_match_all( '/\d{6,10}/', (string) $p['message'], $m ) ) {
			foreach ( $m[0] as $cand ) {
				$r = Resandpay_Reservations::find_by_vs_input( $cand );
				// Návrh jen při přesné shodě částky (1. část nebo celý zbytek) – VS ve zprávě je nejistý.
				$fits = $r && ( abs( (float) $p['amount'] - Resandpay_Reservations::amount_now( $r ) ) < 0.01 || abs( (float) $p['amount'] - Resandpay_Reservations::remaining( $r ) ) < 0.01 );
				if ( $fits && ! is_wp_error( self::check_match( $r, $p ) ) ) {
					return $r;
				}
			}
		}
		return null;
	}

	/**
	 * Ruční přiřazení avíza k rezervaci (podle VS).
	 *
	 * @return array|WP_Error Rezervace.
	 */
	public static function assign( $payment_id, $vs, $note = '', $force = false ) {
		global $wpdb;
		$p = self::get( $payment_id );
		if ( ! $p || ! in_array( $p['status'], array( self::REVIEW, self::IGNORED ), true ) ) {
			return new WP_Error( 'state', __( 'Avízo už je vyřešené.', 'reserveandpay' ) );
		}
		$r = Resandpay_Reservations::find_by_vs_input( $vs );
		if ( ! $r ) {
			/* translators: %s: VS */
			return new WP_Error( 'vs', sprintf( __( 'Rezervace s VS %s neexistuje.', 'reserveandpay' ), $vs ) );
		}
		$check = self::check_match( $r, $p );
		if ( is_wp_error( $check ) && ( ! $force || in_array( $check->get_error_code(), array( 'paid', 'cancelled' ), true ) ) ) {
			return $check;
		}
		$paid = Resandpay_Reservations::add_payment(
			$r['id'],
			(float) $p['amount'],
			'transfer_manual',
			array(
				'force'          => $force,
				'allow_overbook' => true,
				'payment_id'     => (int) $payment_id,
				'note'           => $note,
				'booked_at'      => $p['booked_at'] ?: null,
			)
		);
		if ( is_wp_error( $paid ) ) {
			return $paid;
		}
		$wpdb->update(
			self::table(),
			array(
				'status'         => self::MANUAL,
				'reservation_id' => (int) $r['id'],
				'matched_at'     => current_time( 'mysql' ),
				'matched_by'     => get_current_user_id(),
				'note'           => mb_substr( sanitize_text_field( $note ), 0, 255 ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => (int) $payment_id )
		);
		return $paid;
	}

	/** Odpárování spárovaného avíza od rezervace (vrátí ho k ruční kontrole). */
	public static function unassign( $payment_id, $reason, array $opts = array() ) {
		$p = self::get( $payment_id );
		if ( ! $p || ! in_array( $p['status'], array( self::MATCHED, self::MANUAL ), true ) ) {
			return new WP_Error( 'state', __( 'Avízo není spárované s rezervací.', 'reserveandpay' ) );
		}
		$l = Resandpay_Reservations::ledger_by_payment( $payment_id );
		if ( ! $l ) {
			return new WP_Error( 'ledger', __( 'Platbu se nepodařilo dohledat v evidenci plateb rezervace – odpárujte ji v detailu rezervace.', 'reserveandpay' ) );
		}
		return Resandpay_Reservations::void_payment( $l['id'], $reason, $opts );
	}

	/** Označí avízo jako vyřešené bez přiřazení (např. vrácená platba, platba mimo rezervace). */
	public static function resolve( $payment_id, $note ) {
		global $wpdb;
		$p = self::get( $payment_id );
		if ( ! $p || self::REVIEW !== $p['status'] ) {
			return new WP_Error( 'state', __( 'Avízo už je vyřešené.', 'reserveandpay' ) );
		}
		$wpdb->update(
			self::table(),
			array(
				'status'     => self::RESOLVED,
				'matched_by' => get_current_user_id(),
				'note'       => mb_substr( sanitize_text_field( $note ), 0, 255 ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $payment_id )
		);
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Běh kontroly plateb
	 * ------------------------------------------------------------------- */

	/** Je čas na automatický běh? */
	public static function due_for_run( $now = null ) {
		$s = Resandpay_Settings::all();
		if ( 'auto' !== $s['payment_mode'] ) {
			return false;
		}
		$last = (int) get_option( 'resandpay_last_run', 0 );
		// Tolerance 2 min – CRON nespouští přesně na sekundu.
		return ( $now ?? time() ) - $last >= max( 5, (int) $s['payment_interval'] ) * MINUTE_IN_SECONDS - 120;
	}

	/**
	 * Běh kontroly plateb nad schránkou.
	 *
	 * @param string $trigger cron|manual.
	 * @param object $imap    Volitelně klient (testy).
	 * @return array ok, fetched, matched, review, ignored, error.
	 */
	public static function run( $trigger = 'manual', $imap = null ) {
		$res = array(
			'ok'      => false,
			'fetched' => 0,
			'matched' => 0,
			'review'  => 0,
			'ignored' => 0,
			'error'   => '',
		);
		if ( ! Resandpay_Lock::acquire( 'payment_run', 600, 0 ) ) {
			$res['error'] = __( 'Kontrola plateb už právě běží.', 'reserveandpay' );
			return $res;
		}
		$started = current_time( 'mysql' );
		update_option( 'resandpay_last_run', time(), false );
		try {
			$s    = Resandpay_Settings::all();
			$pass = self::imap_password();
			if ( ! $imap && ( '' === $s['imap_host'] || '' === $s['imap_user'] || '' === $pass ) ) {
				$res['error'] = __( 'Není vyplněn přístup ke schránce (server, uživatel, heslo).', 'reserveandpay' );
				return $res;
			}
			$imap = $imap ?: new Resandpay_Imap();
			if ( ! $imap->connect( $s['imap_host'], (int) $s['imap_port'], $s['imap_security'] ) || ! $imap->login( $s['imap_user'], $pass ) || false === $imap->select( $s['imap_folder'] ?: 'INBOX' ) ) {
				$res['error'] = $imap->error;
				$imap->logout();
				return $res;
			}
			$uids = array_slice( $imap->search_from( $s['bank_sender'] ), 0, max( 1, (int) $s['payment_batch'] ) );
			$done = '';
			foreach ( $uids as $uid ) {
				$raw = $imap->fetch( $uid );
				if ( '' === $raw ) {
					continue;
				}
				++$res['fetched'];
				$out = self::process_raw( $raw, 'cron' === $trigger ? 'cron' : 'manual' );
				if ( self::MATCHED === $out['status'] ) {
					++$res['matched'];
				} elseif ( self::REVIEW === $out['status'] ) {
					++$res['review'];
				} elseif ( 'not_bank' !== $out['status'] ) {
					++$res['ignored'];
				}
				if ( 'not_bank' === $out['status'] || '' === (string) $s['imap_folder_done'] ) {
					continue;
				}
				if ( '' === $done ) {
					$done = $imap->ensure_folder( $s['imap_folder_done'] );
				}
				if ( $done ) {
					$imap->move( $uid, $done );
				}
			}
			$imap->logout();
			$res['ok'] = true;
			update_option( 'resandpay_last_success', time(), false );
			delete_option( 'resandpay_outage_alert' );
		} finally {
			self::log_run( $trigger, $started, $res );
			Resandpay_Lock::release( 'payment_run' );
			if ( $res['review'] > 0 ) {
				self::alert_review( $res['review'] );
			}
		}
		return $res;
	}

	/** Test připojení – nic nemění. */
	public static function test_connection() {
		$s    = Resandpay_Settings::all();
		$pass = self::imap_password();
		if ( '' === $s['imap_host'] || '' === $s['imap_user'] || '' === $pass ) {
			return new WP_Error( 'config', __( 'Vyplňte server, uživatele a heslo ke schránce a nastavení uložte.', 'reserveandpay' ) );
		}
		$imap = new Resandpay_Imap();
		if ( ! $imap->connect( $s['imap_host'], (int) $s['imap_port'], $s['imap_security'] ) || ! $imap->login( $s['imap_user'], $pass ) ) {
			$e = $imap->error;
			$imap->logout();
			return new WP_Error( 'imap', $e );
		}
		$total = $imap->select( $s['imap_folder'] ?: 'INBOX' );
		if ( false === $total ) {
			$e = $imap->error;
			$imap->logout();
			return new WP_Error( 'imap', $e );
		}
		$bank = count( $imap->search_from( $s['bank_sender'] ) );
		$imap->logout();
		return array(
			'total' => (int) $total,
			'bank'  => $bank,
		);
	}

	private static function log_run( $trigger, $started, array $res ) {
		global $wpdb;
		$wpdb->insert(
			self::runs_table(),
			array(
				'started_at'  => $started,
				'finished_at' => current_time( 'mysql' ),
				'trigger_by'  => $trigger,
				'ok'          => $res['ok'] ? 1 : 0,
				'fetched'     => $res['fetched'],
				'matched'     => $res['matched'],
				'review'      => $res['review'],
				'ignored'     => $res['ignored'],
				'error'       => mb_substr( (string) $res['error'], 0, 255 ),
			)
		);
		$days = max( 7, (int) Resandpay_Settings::get( 'payment_log_days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::runs_table() . ' WHERE started_at < %s', wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		Resandpay_Mailer::prune_log();
	}

	public static function runs( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::runs_table() . ' ORDER BY id DESC LIMIT ' . max( 1, (int) $limit ), ARRAY_A );
	}

	/* ---------------------------------------------------------------------
	 * Upozornění správkyně
	 * ------------------------------------------------------------------- */

	private static function admin_mail( $subject, $text ) {
		$to = Resandpay_Settings::get( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$html = Resandpay_Mailer::html_layout( $subject, Resandpay_Mailer::text_to_html( $text ) );
		return Resandpay_Mailer::send_raw( $to, $subject, $html, $text );
	}

	private static function alert_review( $n ) {
		/* translators: %d: počet */
		$subject = sprintf( __( 'Platby k ruční kontrole: %d', 'reserveandpay' ), $n );
		/* translators: 1: počet, 2: odkaz */
		$text = sprintf( __( "Kontrola plateb našla avíza, která nešlo automaticky spárovat s rezervací (%1\$d).\n\nZkontrolujte je a případně přiřaďte ručně:\n%2\$s", 'reserveandpay' ), $n, admin_url( 'admin.php?page=reserveandpay-payments&status=review' ) );
		self::admin_mail( $subject, $text );
	}

	/** Upozornění, pokud kontrola plateb dlouho neproběhla úspěšně (nejvýš 1× denně). */
	public static function check_outage( $now = null ) {
		$s   = Resandpay_Settings::all();
		$now = $now ?? time();
		if ( 'auto' !== $s['payment_mode'] ) {
			return false;
		}
		$last  = (int) get_option( 'resandpay_last_success', 0 );
		$limit = max( 1, (int) $s['payment_alert_factor'] ) * max( 5, (int) $s['payment_interval'] ) * MINUTE_IN_SECONDS;
		if ( ! $last || $now - $last < $limit || $now - (int) get_option( 'resandpay_outage_alert', 0 ) < DAY_IN_SECONDS ) {
			return false;
		}
		update_option( 'resandpay_outage_alert', $now, false );
		$runs = self::runs( 1 );
		/* translators: 1: čas, 2: chyba, 3: odkaz */
		$text = sprintf( __( "Kontrola plateb od %1\$s neproběhla úspěšně.\nPoslední chyba: %2\$s\n\nZkontrolujte přístup ke schránce a CRON:\n%3\$s", 'reserveandpay' ), wp_date( 'j. n. Y G:i', $last ), $runs ? ( $runs[0]['error'] ?: '—' ) : '—', admin_url( 'admin.php?page=reserveandpay-payments' ) );
		return self::admin_mail( __( 'Kontrola plateb neprobíhá', 'reserveandpay' ), $text );
	}

	/* ---------------------------------------------------------------------
	 * CRON Active24: https://web/?rap_cron=KLÍČ
	 * Při úspěchu bez výstupu (CRON pošle e-mail jen při výstupu), při chybě text.
	 * ------------------------------------------------------------------- */

	public static function maybe_cron() {
		if ( ! isset( $_GET['rap_cron'] ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		$key = (string) Resandpay_Settings::get( 'cron_key' );
		if ( strlen( $key ) < 24 || ! hash_equals( $key, sanitize_text_field( wp_unslash( $_GET['rap_cron'] ) ) ) ) {
			status_header( 403 );
			echo 'ReserveAndPay: neplatný klíč CRONu.';
			exit;
		}
		echo self::cron_tick(); // phpcs:ignore WordPress.Security.EscapeOutput -- prostý text.
		exit;
	}

	/** Jeden tik CRONu: splatnosti, (je-li čas) kontrola plateb, hlídání výpadku. Vrací text chyby nebo ''. */
	public static function cron_tick() {
		update_option( 'resandpay_last_cron', time(), false );
		Resandpay_Reservations::process_due();
		$out = '';
		if ( self::due_for_run() ) {
			$r = self::run( 'cron' );
			if ( ! $r['ok'] ) {
				$out = 'ReserveAndPay – kontrola plateb selhala: ' . $r['error'];
			}
		}
		self::check_outage();
		return $out;
	}
}
