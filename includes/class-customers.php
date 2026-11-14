<?php
defined( 'ABSPATH' ) || exit;

/**
 * Přehled zákazníků (Issue #19): souhrn rezervací podle e-mailu.
 *
 * Zákazník = e-mailová adresa (bez ohledu na velikost písmen a mezery na okrajích).
 * Počítá se přímo z tabulky rezervací – bez vlastní tabulky; započítávají se všechny
 * rezervace včetně zrušených a expirovaných. Jméno, telefon a souhlas s newsletterem
 * se berou z poslední rezervace zákazníka.
 */
class Resandpay_Customers {

	/** Klíč zákazníka: e-mail malými písmeny, bez mezer na okrajích. */
	public static function key( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/** Seznam sloupců, podle kterých lze řadit (klíč URL => výraz SQL). */
	public static function sortable() {
		return array(
			'name'     => 'r.customer_name',
			'email'    => 'c.email_key',
			'total'    => 'c.total',
			'first_at' => 'c.first_at',
			'last_at'  => 'c.last_at',
			'paid'     => 'c.paid',
		);
	}

	/** Podmínka WHERE nad rezervacemi (hledání v jménu, e-mailu a telefonu kterékoli rezervace zákazníka). */
	private static function where( array $args ) {
		global $wpdb;
		$t     = Resandpay_Reservations::table();
		$where = array( "email <> ''" );
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = 'LOWER(TRIM(email)) IN (' . $wpdb->prepare( "SELECT LOWER(TRIM(email)) FROM {$t} WHERE customer_name LIKE %s OR email LIKE %s OR phone LIKE %s", $like, $like, $like ) . ')';
		}
		return implode( ' AND ', $where );
	}

	/** Dotaz se souhrnem po zákaznících (FROM … JOIN poslední rezervace). */
	private static function from( array $args ) {
		$t      = Resandpay_Reservations::table();
		$active = "'" . implode( "','", Resandpay_Reservations::active_statuses() ) . "'";
		$closed = "'" . Resandpay_Reservations::CANCELLED . "','" . Resandpay_Reservations::EXPIRED . "'";
		$sql    = "(SELECT LOWER(TRIM(email)) AS email_key, COUNT(*) AS total,
				SUM(CASE WHEN status IN ({$active}) THEN 1 ELSE 0 END) AS active,
				SUM(CASE WHEN status IN ({$closed}) THEN 1 ELSE 0 END) AS closed,
				MIN(created_at) AS first_at, MAX(created_at) AS last_at,
				SUM(amount_paid) AS paid, MAX(id) AS last_id,
				SUM(CASE WHEN ml_status = 'sent' THEN 1 ELSE 0 END) AS ml_sent,
				SUM(CASE WHEN ml_status = 'pending' THEN 1 ELSE 0 END) AS ml_pending,
				SUM(CASE WHEN ml_status = 'error' THEN 1 ELSE 0 END) AS ml_error,
				SUM(CASE WHEN ml_status IN ('unsubscribe', 'unsubscribed') THEN 1 ELSE 0 END) AS ml_unsub
			FROM {$t} WHERE " . self::where( $args ) . " GROUP BY LOWER(TRIM(email))) c
			JOIN {$t} r ON r.id = c.last_id";
		$cond = array();
		if ( ! empty( $args['newsletter'] ) ) {
			$cond[] = 'r.newsletter = 1';
		}
		switch ( $args['ml'] ?? '' ) {
			case 'sent':
				$cond[] = 'c.ml_sent > 0 AND NOT (r.newsletter = 0 AND c.ml_unsub > 0)';
				break;
			case 'unsubscribed':
				$cond[] = 'r.newsletter = 0 AND c.ml_unsub > 0';
				break;
			case 'pending':
				$cond[] = 'c.ml_sent = 0 AND c.ml_pending > 0';
				break;
			case 'error':
				$cond[] = 'c.ml_sent = 0 AND c.ml_pending = 0 AND c.ml_error > 0';
				break;
			case 'none':
				$cond[] = 'c.ml_sent = 0 AND c.ml_pending = 0 AND c.ml_error = 0 AND r.newsletter = 1';
				break;
			case 'no_consent':
				$cond[] = 'c.ml_sent = 0 AND c.ml_pending = 0 AND c.ml_error = 0 AND c.ml_unsub = 0 AND r.newsletter = 0';
				break;
		}
		if ( $cond ) {
			$sql .= ' WHERE ' . implode( ' AND ', $cond );
		}
		return $sql;
	}

	/**
	 * Výpis zákazníků.
	 *
	 * @param array $args search, newsletter (jen se souhlasem), ml (stav MailerLite, viz Resandpay_MailerLite::customer_status_labels()), orderby (viz sortable()), order, per_page (0 = vše), page.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args    = wp_parse_args(
			$args,
			array(
				'search'     => '',
				'newsletter' => false,
				'ml'         => '',
				'orderby'    => 'last_at',
				'order'      => 'DESC',
				'per_page'   => 50,
				'page'       => 1,
			)
		);
		$args['search'] = trim( (string) $args['search'] );
		$sort    = self::sortable();
		$orderby = $sort[ $args['orderby'] ] ?? $sort['last_at'];
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$from    = self::from( $args );
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from}" );
		$limit   = '';
		if ( (int) $args['per_page'] > 0 ) {
			$per    = (int) $args['per_page'];
			$offset = max( 0, ( (int) $args['page'] - 1 ) * $per );
			$limit  = " LIMIT {$offset}, {$per}";
		}
		$items = $wpdb->get_results( "SELECT c.*, r.customer_name, r.email, r.phone, r.newsletter FROM {$from} ORDER BY {$orderby} {$order}, c.email_key ASC{$limit}", ARRAY_A );
		$paid  = self::paid_by_currency( wp_list_pluck( $items, 'email_key' ) );
		foreach ( $items as &$it ) {
			foreach ( array( 'total', 'active', 'closed', 'last_id', 'newsletter', 'ml_sent', 'ml_pending', 'ml_error', 'ml_unsub' ) as $k ) {
				$it[ $k ] = (int) $it[ $k ];
			}
			$it['paid']        = (float) $it['paid'];
			$it['paid_by_cur'] = $paid[ $it['email_key'] ] ?? array();
			$it['ml']          = Resandpay_MailerLite::customer_status( $it );
		}
		unset( $it );
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/** Zaplaceno po měnách pro dané zákazníky: [email_key => [měna => částka]]. */
	public static function paid_by_currency( array $keys ) {
		global $wpdb;
		if ( ! $keys ) {
			return array();
		}
		$in   = implode( ',', array_map( fn( $k ) => $wpdb->prepare( '%s', $k ), $keys ) );
		$rows = $wpdb->get_results( 'SELECT LOWER(TRIM(email)) AS k, currency, SUM(amount_paid) AS s FROM ' . Resandpay_Reservations::table() . " WHERE LOWER(TRIM(email)) IN ({$in}) GROUP BY LOWER(TRIM(email)), currency", ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( (float) $r['s'] > 0 ) {
				$out[ $r['k'] ][ $r['currency'] ] = (float) $r['s'];
			}
		}
		return $out;
	}

	/** Zaplaceno jako text („1 500 Kč“, více měn oddělených „ + “, nic = „0 Kč“ v měně z nastavení). */
	public static function format_paid( array $by_cur ) {
		if ( ! $by_cur ) {
			return Resandpay_Events::format_price( 0, Resandpay_Settings::get( 'currency' ) );
		}
		$out = array();
		foreach ( $by_cur as $cur => $sum ) {
			$out[] = Resandpay_Events::format_price( $sum, $cur );
		}
		return implode( ' + ', $out );
	}

	/** Počet rezervací zákazníka (všechny stavy). */
	public static function count_reservations( $email ) {
		global $wpdb;
		$key = self::key( $email );
		if ( '' === $key ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Resandpay_Reservations::table() . ' WHERE LOWER(TRIM(email)) = %s', $key ) );
	}

	/** Souhrn jednoho zákazníka (nebo null). */
	public static function get( $email ) {
		$key = self::key( $email );
		if ( '' === $key ) {
			return null;
		}
		foreach ( self::query( array( 'search' => $key, 'per_page' => 0 ) )['items'] as $c ) {
			if ( $c['email_key'] === $key ) {
				return $c;
			}
		}
		return null;
	}

	/** Řádky CSV (záhlaví + zákazníci) – kvůli testům oddělené od výstupu. */
	public static function csv_rows( array $args ) {
		$args['per_page'] = 0;
		$rows             = array(
			array(
				__( 'Jméno', 'reserveandpay' ),
				__( 'E-mail', 'reserveandpay' ),
				__( 'Telefon', 'reserveandpay' ),
				__( 'Rezervací', 'reserveandpay' ),
				__( 'Aktivních', 'reserveandpay' ),
				__( 'Zrušených / expirovaných', 'reserveandpay' ),
				__( 'První rezervace', 'reserveandpay' ),
				__( 'Poslední rezervace', 'reserveandpay' ),
				__( 'Zaplaceno', 'reserveandpay' ),
				__( 'Newsletter', 'reserveandpay' ),
				__( 'MailerLite', 'reserveandpay' ),
			),
		);
		$ml = Resandpay_MailerLite::customer_status_labels();
		foreach ( self::query( $args )['items'] as $c ) {
			$rows[] = array(
				$c['customer_name'],
				$c['email'],
				$c['phone'],
				$c['total'],
				$c['active'],
				$c['closed'],
				$c['first_at'],
				$c['last_at'],
				self::format_paid( $c['paid_by_cur'] ),
				$c['newsletter'] ? __( 'ano', 'reserveandpay' ) : __( 'ne', 'reserveandpay' ),
				$ml[ $c['ml'] ] ?? '',
			);
		}
		return $rows;
	}
}
