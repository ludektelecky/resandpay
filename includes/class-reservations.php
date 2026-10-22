<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rezervace: vytváření (s atomickou kontrolou kapacity), stavy, dotazy.
 * Jedna rezervace = jedno místo = vlastní variabilní symbol (SWD v1.4).
 */
class Resandpay_Reservations {

	const PENDING   = 'pending';
	const PAID      = 'paid';
	const ONSITE    = 'onsite';
	const EXPIRED   = 'expired';
	const OVERDUE   = 'overdue';
	const PARTIAL   = 'partial';
	const CANCELLED = 'cancelled';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_reservations';
	}

	public static function status_labels() {
		return array(
			self::PENDING   => __( 'Čeká na platbu', 'reserveandpay' ),
			self::PAID      => __( 'Zaplaceno', 'reserveandpay' ),
			self::ONSITE    => __( 'Platba na místě', 'reserveandpay' ),
			self::PARTIAL   => __( 'Částečně zaplaceno', 'reserveandpay' ),
			self::OVERDUE   => __( 'Po splatnosti', 'reserveandpay' ),
			self::EXPIRED   => __( 'Expirováno', 'reserveandpay' ),
			self::CANCELLED => __( 'Zrušeno', 'reserveandpay' ),
		);
	}

	public static function paid_method_labels() {
		return array(
			'transfer_auto'   => __( 'Převod – automaticky', 'reserveandpay' ),
			'transfer_run'    => __( 'Převod – ruční běh kontroly', 'reserveandpay' ),
			'transfer_manual' => __( 'Převod – ručně označeno', 'reserveandpay' ),
			'cash'            => __( 'Hotově na místě', 'reserveandpay' ),
			'onsite'          => __( 'Na místě', 'reserveandpay' ),
		);
	}

	public static function pay_choice_labels() {
		return array(
			'transfer' => __( 'Převodem (QR)', 'reserveandpay' ),
			'cash'     => __( 'Hotově na místě', 'reserveandpay' ),
		);
	}

	/** Stavy, které blokují místo v kapacitě. */
	public static function active_statuses() {
		return array( self::PENDING, self::ONSITE, self::PARTIAL, self::OVERDUE, self::PAID );
	}

	private static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Vytvoří rezervaci jednoho místa.
	 *
	 * @param int   $event_id ID akce.
	 * @param array $data     customer_name, email, phone, company, company_ic, company_dic, note, newsletter, consent_gdpr, consent_terms,
	 *                        pay_choice (transfer|cash – hotovost jen je-li u akce povolena).
	 * @param array $opts     source (web|admin), ignore_close (bool), notify (bool), now (int timestamp – pro testy).
	 * @return array|WP_Error Rezervace nebo chyba.
	 */
	public static function create( $event_id, array $data, array $opts = array() ) {
		global $wpdb;
		$opts = wp_parse_args(
			$opts,
			array(
				'source'       => 'web',
				'ignore_close' => false,
				'notify'       => true,
				'now'          => time(),
			)
		);

		$name  = trim( (string) ( $data['customer_name'] ?? '' ) );
		$email = sanitize_email( $data['email'] ?? '' );
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'invalid', __( 'Vyplňte jméno a platný e-mail.', 'reserveandpay' ) );
		}

		$check = Resandpay_Events::check_bookable( $event_id, $opts['now'], $opts['ignore_close'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$ev = Resandpay_Events::get( $event_id );

		$lock = 'event_' . (int) $event_id;
		if ( ! Resandpay_Lock::acquire( $lock ) ) {
			return new WP_Error( 'busy', __( 'Systém je právě vytížen, zkuste to prosím za chvíli znovu.', 'reserveandpay' ) );
		}

		try {
			if ( self::count_active( $event_id ) >= $ev['capacity'] ) {
				return new WP_Error( 'full', __( 'Akce je bohužel již obsazena.', 'reserveandpay' ) );
			}

			$seq = self::next_sequence();
			if ( is_wp_error( $seq ) ) {
				return $seq;
			}

			$now_ts      = (int) $opts['now'];
			$due         = Resandpay_Events::compute_due( $ev, $now_ts );
			$due_ts      = $due['due_ts'];
			$last_minute = $due['last_minute'];
			$cash        = 'cash' === ( $data['pay_choice'] ?? '' ) && ( $ev['cash'] || 'admin' === $opts['source'] );
			// Platba ve dvou částech (SWD v2.6, kap. 3.9): ne u hotovosti a rezervace na poslední chvíli.
			$part1    = $cash || $last_minute || ! empty( $due['late'] ) ? 0.0 : Resandpay_Events::part1_amount( $ev ); // Pozdní rezervace: vše najednou.
			$part1_ts = 0;
			if ( $part1 > 0 ) {
				$p1       = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( wp_timezone() )->modify( '+' . max( 0, (int) $ev['part1_days'] ) . ' days' )->setTime( 23, 59, 59 );
				$part1_ts = min( $p1->getTimestamp(), $due_ts );
			}

			$now_local = wp_date( 'Y-m-d H:i:s', $now_ts );
			$row       = array(
				'event_id'         => (int) $event_id,
				'status'           => $cash ? self::ONSITE : self::PENDING,
				'pay_choice'       => $cash ? 'cash' : 'transfer',
				'vs'               => self::format_vs( $seq ),
				'proforma_no'      => self::format_proforma( $seq, $now_ts ),
				'token'            => wp_generate_password( 40, false, false ),
				'customer_name'    => mb_substr( sanitize_text_field( $name ), 0, 190 ),
				'email'            => $email,
				'phone'            => mb_substr( sanitize_text_field( $data['phone'] ?? '' ), 0, 50 ),
				'company'          => mb_substr( sanitize_text_field( $data['company'] ?? '' ), 0, 190 ),
				'company_ic'       => mb_substr( sanitize_text_field( $data['company_ic'] ?? '' ), 0, 20 ),
				'company_dic'      => mb_substr( sanitize_text_field( $data['company_dic'] ?? '' ), 0, 20 ),
				'note'             => sanitize_textarea_field( $data['note'] ?? '' ),
				'amount'           => $ev['price'],
				'currency'         => $ev['currency'],
				'last_minute'      => $last_minute ? 1 : 0,
				'due_at'           => wp_date( 'Y-m-d H:i:s', $due_ts ),
				'part1_amount'     => $part1,
				'part1_due_at'     => $part1 > 0 ? wp_date( 'Y-m-d H:i:s', $part1_ts ) : null,
				'amount_paid'      => 0,
				'consent_gdpr_at'  => ! empty( $data['consent_gdpr'] ) ? $now_local : null,
				'consent_terms_at' => ! empty( $data['consent_terms'] ) ? $now_local : null,
				'newsletter'       => ! empty( $data['newsletter'] ) ? 1 : 0,
				'user_id'          => (int) ( $data['user_id'] ?? get_current_user_id() ),
				'source'           => 'admin' === $opts['source'] ? 'admin' : 'web',
				'ip_hash'          => 'admin' === $opts['source'] ? '' : mb_substr( (string) ( $data['ip_hash'] ?? '' ), 0, 40 ),
				'created_at'       => $now_local,
				'updated_at'       => $now_local,
			);
			$ok = $wpdb->insert( self::table(), $row );
			if ( ! $ok ) {
				return new WP_Error( 'db', __( 'Rezervaci se nepodařilo uložit.', 'reserveandpay' ) );
			}
			$id = (int) $wpdb->insert_id;
		} finally {
			Resandpay_Lock::release( $lock );
		}

		$reservation = self::get( $id );
		do_action( 'resandpay_reservation_created', $reservation, $opts );
		if ( $opts['notify'] ) {
			Resandpay_Mailer::send_received( $reservation );
		}
		return $reservation;
	}

	/**
	 * Další číslo řady pro VS a číslo proformy. Řada se nikdy nevrací zpět,
	 * VS se nikdy nepoužije znovu (ani po zrušení/expiraci).
	 */
	private static function next_sequence() {
		global $wpdb;
		if ( ! Resandpay_Lock::acquire( 'sequence' ) ) {
			return new WP_Error( 'busy', __( 'Systém je právě vytížen, zkuste to prosím za chvíli znovu.', 'reserveandpay' ) );
		}
		try {
			$stored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'resandpay_sequence' ) );
			$max    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
			$next   = max( $stored, $max ) + 1;
			wp_cache_delete( 'resandpay_sequence', 'options' );
			update_option( 'resandpay_sequence', $next, false );
			return $next;
		} finally {
			Resandpay_Lock::release( 'sequence' );
		}
	}

	public static function format_vs( $seq ) {
		$prefix = preg_replace( '/\D/', '', (string) Resandpay_Settings::get( 'vs_prefix' ) );
		$prefix = substr( $prefix, 0, 4 );
		$len    = max( 6, 10 - strlen( $prefix ) - 2 );
		return substr( $prefix . str_pad( (string) $seq, $len, '0', STR_PAD_LEFT ), 0, 10 );
	}

	public static function format_proforma( $seq, $ts = null ) {
		return 'PF-' . wp_date( 'Y', $ts ?? time() ) . '-' . str_pad( (string) $seq, 6, '0', STR_PAD_LEFT );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_vs( $vs ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE vs = %s', $vs ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Najde rezervaci podle VS zadaného ručně nebo načteného z QR kódu.
	 * Přijme i celý řetězec QR platby (SPD*…*X-VS:…*) a VS bez úvodních nul.
	 */
	public static function find_by_vs_input( $input ) {
		global $wpdb;
		$input = trim( (string) $input );
		if ( preg_match( '/X-VS:(\d{1,10})/i', $input, $m ) ) {
			$input = $m[1];
		}
		$digits = preg_replace( '/\D/', '', $input );
		if ( '' === $digits || strlen( $digits ) > 10 ) {
			return null;
		}
		$r = self::get_by_vs( $digits );
		if ( ! $r ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE TRIM(LEADING '0' FROM vs) = %s", ltrim( $digits, '0' ) ), ARRAY_A );
			$r   = $row ?: null;
		}
		return $r;
	}

	/** Aktivní rezervace akce (pro odbavení na místě). */
	public static function for_event( $event_id ) {
		global $wpdb;
		$in = "'" . implode( "','", self::active_statuses() ) . "'";
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE event_id = %d AND status IN ({$in}) ORDER BY customer_name ASC", $event_id ), ARRAY_A );
	}

	/* ---------------------------------------------------------------------
	 * Platba ve dvou částech a částečné platby (SWD v2.6, kap. 3.9)
	 * ------------------------------------------------------------------- */

	/** Zbývá zaplatit (celkem − zaplaceno). */
	public static function remaining( array $r ) {
		return round( max( 0, (float) $r['amount'] - (float) $r['amount_paid'] ), 2 );
	}

	/** Je rezervace rozdělená na 1. část a doplatek? */
	public static function is_split( array $r ) {
		return (float) $r['part1_amount'] > 0 && (float) $r['part1_amount'] < (float) $r['amount'];
	}

	/** Čeká se ještě na (zbytek) 1. části platby? */
	public static function in_part1( array $r ) {
		return self::is_split( $r ) && (float) $r['amount_paid'] + 0.009 < (float) $r['part1_amount'];
	}

	/** Částka, kterou je teď potřeba zaplatit (zbytek 1. části, jinak celý zbytek). */
	public static function amount_now( array $r ) {
		return self::in_part1( $r ) ? round( (float) $r['part1_amount'] - (float) $r['amount_paid'], 2 ) : self::remaining( $r );
	}

	/** Splatnost toho, co je teď potřeba zaplatit. */
	public static function due_now( array $r ) {
		return self::in_part1( $r ) && ! empty( $r['part1_due_at'] ) ? $r['part1_due_at'] : $r['due_at'];
	}

	/**
	 * Připíše platbu k rezervaci (převod, ručně, na místě). Platby se sčítají.
	 * Celá částka → Zaplaceno; méně → Částečně zaplaceno (po splatnosti → Po splatnosti)
	 * a zákazník dostane upozornění se zbývající částkou a QR kódem.
	 *
	 * @param array $opts notify (bool), force (přijmout i přeplatek).
	 * @return array|WP_Error Rezervace po připsání.
	 */
	public static function add_payment( $id, $amount, $method = 'transfer_manual', array $opts = array() ) {
		global $wpdb;
		$opts   = wp_parse_args(
			$opts,
			array(
				'notify' => true,
				'force'  => false,
			)
		);
		$r      = self::get( $id );
		$amount = round( (float) $amount, 2 );
		if ( ! $r ) {
			return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
		}
		if ( self::CANCELLED === $r['status'] ) {
			return new WP_Error( 'cancelled', __( 'Rezervace je zrušená – platbu je potřeba vyřešit ručně (vrátit?).', 'reserveandpay' ) );
		}
		$remaining = self::remaining( $r );
		if ( self::PAID === $r['status'] || $remaining <= 0 ) {
			return new WP_Error( 'paid', __( 'Rezervace už je zaplacená – možná duplicitní platba (vrátit?).', 'reserveandpay' ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'amount', __( 'Neplatná částka.', 'reserveandpay' ) );
		}
		if ( $amount > $remaining + 0.009 && ! $opts['force'] ) {
			/* translators: 1: přijato, 2: zbývá */
			return new WP_Error( 'overpay', sprintf( __( 'Přeplatek: přijato %1$s, zbývalo zaplatit %2$s.', 'reserveandpay' ), Resandpay_Events::format_price( $amount, $r['currency'] ), Resandpay_Events::format_price( $remaining, $r['currency'] ) ) );
		}
		$old_paid = (float) $r['amount_paid'];
		$new_paid = round( $old_paid + $amount, 2 );
		$full     = $new_paid + 0.009 >= (float) $r['amount'];
		if ( $full ) {
			$target = self::PAID;
		} else {
			$after  = array_merge( $r, array( 'amount_paid' => $new_paid ) );
			$due    = Resandpay_Events::ts( self::due_now( $after ) );
			$target = $due && $due < time() ? self::OVERDUE : self::PARTIAL;
			if ( self::ONSITE === $r['status'] ) {
				$target = self::ONSITE; // Zbytek se doplatí na místě.
			}
		}
		if ( $target !== $r['status'] ) {
			$res = self::set_status(
				$id,
				$target,
				array(
					'method'         => $method,
					'notify'         => false,
					'allow_overbook' => ! empty( $opts['allow_overbook'] ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$wpdb->update(
			self::table(),
			array(
				'amount_paid' => $new_paid,
				'paid_method' => sanitize_key( $method ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		$new                 = self::get( $id );
		$new['last_payment'] = $amount;
		do_action( 'resandpay_payment_added', $new, $amount, $method );
		if ( $opts['notify'] ) {
			if ( $full ) {
				Resandpay_Mailer::send_paid( $new );
			} elseif ( self::is_split( $new ) && $old_paid + 0.009 < (float) $new['part1_amount'] && $new_paid + 0.009 >= (float) $new['part1_amount'] ) {
				Resandpay_Mailer::send_template( $new['email'], 'part1', $new ); // 1. část zaplacena – místo potvrzeno.
			} else {
				Resandpay_Mailer::send_template( $new['email'], 'partial', $new ); // Zaplaceno méně – upozornění + QR na zbytek.
			}
		}
		return $new;
	}

	/**
	 * Zpracování splatností (CRON / plánovač, SWD kap. 3.6, 7.2b):
	 * - nic nezaplaceno a splatnost (1. části) před začátkem termínu → Expirováno, místo se uvolní, e-mail,
	 * - nic nezaplaceno, splatnost = začátek termínu (poslední chvíle) → Platba na místě,
	 * - jinak (splatnost v den termínu / po něm, nebo už něco zaplaceno) → Po splatnosti + jedna připomínka;
	 *   rezervace nepropadá a rozhodne správkyně.
	 *
	 * @return int Počet změněných rezervací.
	 */
	public static function process_due( $now_ts = null ) {
		global $wpdb;
		$now_ts = $now_ts ?? time();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status IN (%s, %s) AND due_at IS NOT NULL', self::PENDING, self::PARTIAL ), ARRAY_A );
		$count  = 0;
		foreach ( $rows as $r ) {
			$due = Resandpay_Events::ts( self::due_now( $r ) );
			if ( ! $due || $due >= $now_ts ) {
				continue;
			}
			$ev = Resandpay_Events::get( $r['event_id'] );
			if ( ! $ev ) {
				continue;
			}
			$start = Resandpay_Events::ts( $ev['start'] );
			$paid  = (float) $r['amount_paid'] > 0;
			if ( ! $paid && $due < $start - 60 ) {
				$new = self::set_status( $r['id'], self::EXPIRED );
				if ( ! is_wp_error( $new ) ) {
					++$count;
					Resandpay_Mailer::send_template( $new['email'], 'expired', $new );
				}
				continue;
			}
			if ( ! $paid && $due <= $start + 60 ) {
				if ( ! is_wp_error( self::set_status( $r['id'], self::ONSITE ) ) ) {
					++$count; // Platba nestihla dorazit do začátku → platí se na místě.
				}
				continue;
			}
			$new = self::set_status( $r['id'], self::OVERDUE );
			if ( is_wp_error( $new ) ) {
				continue;
			}
			++$count;
			if ( empty( $new['reminded_at'] ) && Resandpay_Mailer::send_template( $new['email'], 'overdue', $new ) ) {
				$wpdb->update( self::table(), array( 'reminded_at' => current_time( 'mysql' ) ), array( 'id' => (int) $new['id'] ) );
			}
		}
		$count += self::remind_before_due( $now_ts );
		Resandpay_Antibot::prune();
		Resandpay_Mailer::prune_log();
		return $count;
	}

	/**
	 * Připomínka N dní před splatností (N v nastavení, 0 = vypnuto). Každá splatnost
	 * (1. část, doplatek) se připomene nejvýš jednou; ne u rezervací vzniklých až
	 * v okně připomínky, u hotovosti a u rezervací na poslední chvíli.
	 *
	 * @return int Počet odeslaných připomínek.
	 */
	public static function remind_before_due( $now_ts = null ) {
		global $wpdb;
		$days = (int) Resandpay_Settings::get( 'reminder_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$now_ts = $now_ts ?? time();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status IN (%s, %s) AND last_minute = 0', self::PENDING, self::PARTIAL ), ARRAY_A );
		$sent   = 0;
		foreach ( $rows as $r ) {
			$due_local = self::due_now( $r );
			$due       = Resandpay_Events::ts( $due_local );
			$window    = $due - $days * DAY_IN_SECONDS;
			if ( ! $due || $due <= $now_ts || $now_ts < $window || Resandpay_Events::ts( $r['created_at'] ) > $window || $r['reminded_due_at'] === $due_local ) {
				continue;
			}
			if ( Resandpay_Mailer::send_template( $r['email'], 'reminder', $r ) ) {
				$wpdb->update( self::table(), array( 'reminded_due_at' => $due_local ), array( 'id' => (int) $r['id'] ) );
				++$sent;
			}
		}
		return $sent;
	}

	public static function count_active( $event_id ) {
		global $wpdb;
		$in = "'" . implode( "','", self::active_statuses() ) . "'";
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE event_id = %d AND status IN ({$in})", $event_id ) );
	}

	public static function counts_by_status( $event_id = 0 ) {
		global $wpdb;
		$where = $event_id ? $wpdb->prepare( 'WHERE event_id = %d', $event_id ) : '';
		$rows  = $wpdb->get_results( 'SELECT status, COUNT(*) AS c FROM ' . self::table() . " {$where} GROUP BY status", ARRAY_A );
		$out   = array_fill_keys( array_keys( self::status_labels() ), 0 );
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Výpis rezervací pro administraci.
	 *
	 * @param array $args event_id, status, search, orderby, order, per_page, page.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'event_id' => 0,
				'status'   => '',
				'search'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 50,
				'page'     => 1,
			)
		);
		$where = array( '1=1' );
		if ( $args['event_id'] ) {
			$where[] = $wpdb->prepare( 'event_id = %d', $args['event_id'] );
		}
		if ( $args['status'] && isset( self::status_labels()[ $args['status'] ] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		if ( '' !== $args['search'] && Resandpay_Codes::looks_like_code( $args['search'] ) && Resandpay_Codes::find( $args['search'] ) ) {
			// Hledání podle kódu akce/termínu (AK-2026-0012) nebo služby (SL-003 → všechny její termíny).
			$pid = Resandpay_Codes::find( $args['search'] );
			$ids = Resandpay_Post_Types::SERVICE === get_post_type( $pid )
				? get_posts( array( 'post_type' => Resandpay_Post_Types::EVENT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_rap_service', 'meta_value' => $pid ) )
				: array( $pid );
			$where[] = $ids ? 'event_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : '1=0';
		} elseif ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(customer_name LIKE %s OR email LIKE %s OR vs LIKE %s OR phone LIKE %s)', $like, $like, $like, $like );
		}
		$allowed = array( 'created_at', 'customer_name', 'vs', 'status', 'due_at', 'amount' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$w       = implode( ' AND ', $where );
		$total   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$w}" );
		$limit   = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * $limit );
		$items   = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE {$w} ORDER BY {$orderby} {$order}, id DESC LIMIT {$offset}, {$limit}", ARRAY_A );
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Změna stavu rezervace.
	 *
	 * @param array $args method (pro zaplaceno), reason (pro zrušení), notify (bool).
	 */
	public static function set_status( $id, $status, array $args = array() ) {
		global $wpdb;
		$r = self::get( $id );
		if ( ! $r ) {
			return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
		}
		if ( ! isset( self::status_labels()[ $status ] ) ) {
			return new WP_Error( 'bad_status', __( 'Neplatný stav.', 'reserveandpay' ) );
		}
		$old = $r['status'];
		if ( $old === $status ) {
			return $r;
		}
		$allowed = array(
			self::PENDING   => array( self::PAID, self::PARTIAL, self::ONSITE, self::OVERDUE, self::EXPIRED, self::CANCELLED ),
			self::ONSITE    => array( self::PAID, self::PARTIAL, self::CANCELLED, self::PENDING ),
			self::PARTIAL   => array( self::PAID, self::OVERDUE, self::CANCELLED ),
			self::OVERDUE   => array( self::PAID, self::PARTIAL, self::CANCELLED ),
			self::PAID      => array( self::CANCELLED ),
			self::EXPIRED   => array( self::PAID, self::PARTIAL, self::OVERDUE, self::CANCELLED ),
			self::CANCELLED => array(),
		);
		if ( ! in_array( $status, $allowed[ $old ] ?? array(), true ) ) {
			return new WP_Error(
				'transition',
				sprintf(
					/* translators: 1: původní stav, 2: nový stav */
					__( 'Změna stavu z „%1$s“ na „%2$s“ není povolena.', 'reserveandpay' ),
					self::status_labels()[ $old ],
					self::status_labels()[ $status ]
				)
			);
		}

		// Návrat do aktivního stavu (např. expirovaná -> zaplaceno) musí respektovat kapacitu.
		$reactivate = ! in_array( $old, self::active_statuses(), true ) && in_array( $status, self::active_statuses(), true );
		$lock       = 'event_' . (int) $r['event_id'];
		if ( $reactivate && ! Resandpay_Lock::acquire( $lock ) ) {
			return new WP_Error( 'busy', __( 'Systém je právě vytížen, zkuste to prosím znovu.', 'reserveandpay' ) );
		}
		try {
			if ( $reactivate ) {
				$ev = Resandpay_Events::get( $r['event_id'] );
				if ( $ev && self::count_active( $r['event_id'] ) >= $ev['capacity'] && empty( $args['allow_overbook'] ) ) {
					return new WP_Error( 'full', __( 'Akce je již plně obsazena – rezervaci nelze znovu aktivovat.', 'reserveandpay' ) );
				}
			}
			$now    = self::now();
			$update = array(
				'status'     => $status,
				'updated_at' => $now,
			);
			if ( self::PAID === $status ) {
				$update['paid_at']     = $now;
				$update['paid_method'] = sanitize_key( $args['method'] ?? 'transfer_manual' );
				$update['paid_by']     = get_current_user_id();
				// Ruční „zaplaceno“ (převod / hotově) = uhrazen celý zbytek.
				$update['amount_paid'] = max( (float) $r['amount_paid'], (float) $r['amount'] );
			}
			if ( self::CANCELLED === $status ) {
				$update['cancelled_at']  = $now;
				$update['cancel_reason'] = mb_substr( sanitize_text_field( $args['reason'] ?? '' ), 0, 190 );
			}
			$wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
		} finally {
			if ( $reactivate ) {
				Resandpay_Lock::release( $lock );
			}
		}

		$new = self::get( $id );
		do_action( 'resandpay_status_changed', $new, $old, $status, $args );
		if ( ! empty( $args['notify'] ) ) {
			if ( self::PAID === $status ) {
				Resandpay_Mailer::send_paid( $new );
			} elseif ( self::CANCELLED === $status ) {
				Resandpay_Mailer::send_cancelled( $new );
			}
		}
		return $new;
	}

	/** Smaže všechny rezervace a vynuluje řadu VS (jen nástroj pro úklid testovacích dat). */
	public static function delete_all() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() );
		$wpdb->query( 'DELETE FROM ' . Resandpay_Mailer::log_table() . ' WHERE reservation_id > 0' );
		delete_option( 'resandpay_sequence' );
	}
}
