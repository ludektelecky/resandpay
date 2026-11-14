<?php
defined( 'ABSPATH' ) || exit;

/**
 * Napojení na MailerLite (Issue #22, etapa E6b) – volitelný modul.
 *
 * Zákazník, který při rezervaci zaškrtl souhlas s novinkami, se zapíše do MailerLite (API
 * connect.mailerlite.com) do skupin zvolených administrátorem. Skupiny se vždy načítají z účtu
 * MailerLite daného webu. Výchozí skupiny jsou v Nastavení → Napojení, u akce, služby a materiálu
 * je lze změnit (termín služby přebírá nastavení služby).
 *
 * Odesílá se na pozadí (WP-Cron) – stav je u rezervace ve sloupcích ml_status, ml_groups, ml_at,
 * ml_error a ml_tries. Zákazník se jen přidává (nikdy se neodebírá ze skupin) a odhlášený
 * odběratel se znovu nepřihlašuje (API bez parametru status / resubscribe).
 */
class Resandpay_MailerLite {

	const API         = 'https://connect.mailerlite.com/api/';
	const GROUPS_OPT  = 'resandpay_ml_groups';
	const CRON        = 'resandpay_ml_sync';
	const BATCH       = 50;
	const MAX_TRIES   = 3;
	const META_MODE   = '_rap_ml_mode';
	const META_GROUPS = '_rap_ml_groups';

	// Stavy u rezervace ('' = nic se neposílalo).
	const PENDING = 'pending';
	const SENT    = 'sent';
	const ERROR   = 'error';
	const SKIPPED = 'skipped';

	public static function init() {
		add_action( 'resandpay_reservation_created', array( __CLASS__, 'on_created' ) );
		add_action( 'resandpay_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( 'resandpay_payment_added', array( __CLASS__, 'on_paid' ) );
		add_action( self::CRON, array( __CLASS__, 'process_queue' ) );
		add_action( 'resandpay_cron_due', array( __CLASS__, 'process_queue' ), 20 );
	}

	public static function status_labels() {
		return array(
			self::PENDING => __( 'čeká na odeslání', 'reserveandpay' ),
			self::SENT    => __( 'odesláno', 'reserveandpay' ),
			self::ERROR   => __( 'chyba', 'reserveandpay' ),
			self::SKIPPED => __( 'neodesláno', 'reserveandpay' ),
		);
	}

	public static function enabled() {
		return (bool) Resandpay_Settings::get( 'ml_enabled' );
	}

	/** API klíč: konstanta RESANDPAY_MAILERLITE_API_KEY ve wp-config.php, jinak šifrovaně v nastavení. */
	public static function api_key() {
		if ( defined( 'RESANDPAY_MAILERLITE_API_KEY' ) ) {
			return (string) RESANDPAY_MAILERLITE_API_KEY;
		}
		return Resandpay_Payments::decrypt( (string) Resandpay_Settings::get( 'ml_api_key' ) );
	}

	/** Zapnuto a s klíčem – lze odesílat. */
	public static function ready() {
		return self::enabled() && '' !== self::api_key();
	}

	/* ---------------------------------------------------------------- API */

	/**
	 * Volání API MailerLite.
	 *
	 * @return array|WP_Error Dekódované tělo odpovědi.
	 */
	public static function request( $method, $path, ?array $body = null ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'ml_key', __( 'Chybí API klíč MailerLite.', 'reserveandpay' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			/* translators: %s: chybová zpráva */
			return new WP_Error( 'ml_http', sprintf( __( 'MailerLite nedostupný: %s', 'reserveandpay' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code >= 200 && $code < 300 ) {
			return $data;
		}
		if ( 401 === $code ) {
			$msg = __( 'Neplatný API klíč MailerLite.', 'reserveandpay' );
		} elseif ( 429 === $code ) {
			$msg = __( 'MailerLite: překročen limit požadavků, zkusí se znovu.', 'reserveandpay' );
		} else {
			$msg = (string) ( $data['message'] ?? '' );
			foreach ( (array) ( $data['errors'] ?? array() ) as $field => $errs ) {
				$msg .= ' ' . $field . ': ' . implode( ', ', (array) $errs );
			}
			/* translators: 1: kód HTTP, 2: zpráva */
			$msg = sprintf( __( 'MailerLite odmítl požadavek (HTTP %1$d): %2$s', 'reserveandpay' ), $code, trim( $msg ) ?: '—' );
		}
		return new WP_Error( 'ml_api_' . $code, $msg );
	}

	/**
	 * Načte všechny skupiny z účtu a uloží je do mezipaměti.
	 *
	 * @return array|WP_Error [id => název]
	 */
	public static function refresh_groups() {
		$out  = array();
		$page = 1;
		do {
			$res = self::request( 'GET', 'groups?limit=100&sort=name&page=' . $page );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( (array) ( $res['data'] ?? array() ) as $g ) {
				if ( isset( $g['id'], $g['name'] ) ) {
					$out[ (string) $g['id'] ] = (string) $g['name'];
				}
			}
			$last = (int) ( $res['meta']['last_page'] ?? 1 );
			++$page;
		} while ( $page <= $last && $page <= 50 );
		update_option(
			self::GROUPS_OPT,
			array(
				'groups' => $out,
				'at'     => time(),
			),
			false
		);
		return $out;
	}

	/** Skupiny z mezipaměti [id => název] (bez volání API). */
	public static function groups() {
		$c = get_option( self::GROUPS_OPT, array() );
		return is_array( $c['groups'] ?? null ) ? $c['groups'] : array();
	}

	/** Názvy skupin podle id (neznámé id = „#id“). */
	public static function group_names( array $ids ) {
		$all = self::groups();
		return array_map( fn( $id ) => $all[ $id ] ?? '#' . $id, $ids );
	}

	/** Seznam id skupin z uložené hodnoty (pole nebo text oddělený čárkami). */
	public static function parse_ids( $val ) {
		$ids = is_array( $val ) ? $val : explode( ',', (string) $val );
		return array_values( array_unique( array_filter( array_map( fn( $v ) => preg_replace( '/[^0-9A-Za-z_-]/', '', (string) $v ), $ids ) ) ) );
	}

	/* ------------------------------------------------- Skupiny podle položky */

	/** Nastavení položky (akce, služba, materiál): ['mode' => default|none|custom, 'groups' => []]. */
	public static function item_setting( $post_id ) {
		$mode = (string) get_post_meta( $post_id, self::META_MODE, true );
		return array(
			'mode'   => in_array( $mode, array( 'none', 'custom' ), true ) ? $mode : 'default',
			'groups' => self::parse_ids( get_post_meta( $post_id, self::META_GROUPS, true ) ),
		);
	}

	public static function save_item_setting( $post_id, $mode, $groups ) {
		$mode = in_array( $mode, array( 'none', 'custom' ), true ) ? $mode : 'default';
		if ( 'default' === $mode ) {
			delete_post_meta( $post_id, self::META_MODE );
			delete_post_meta( $post_id, self::META_GROUPS );
			return;
		}
		update_post_meta( $post_id, self::META_MODE, $mode );
		update_post_meta( $post_id, self::META_GROUPS, 'custom' === $mode ? implode( ',', self::parse_ids( $groups ) ) : '' );
	}

	/**
	 * Skupiny pro rezervaci: akce / materiál → (termín služby) služba → výchozí z nastavení.
	 *
	 * @return array|false Id skupin; false = u položky „neposílat do MailerLite“.
	 */
	public static function groups_for( array $r ) {
		$pid   = (int) $r['event_id'];
		$chain = array( $pid );
		$svc   = (int) get_post_meta( $pid, '_rap_service', true );
		if ( $svc ) {
			$chain[] = $svc;
		}
		$groups = null;
		foreach ( $chain as $id ) {
			$s = self::item_setting( $id );
			if ( 'none' === $s['mode'] ) {
				$groups = false;
				break;
			}
			if ( 'custom' === $s['mode'] ) {
				$groups = $s['groups'];
				break;
			}
		}
		if ( null === $groups ) {
			$groups = self::parse_ids( Resandpay_Settings::get( 'ml_groups' ) );
		}
		/**
		 * Skupiny MailerLite pro rezervaci (false = neposílat).
		 *
		 * @param array|false $groups Id skupin.
		 * @param array       $r      Rezervace.
		 */
		return apply_filters( 'resandpay_mailerlite_groups', $groups, $r );
	}

	/** Data odběratele pro API. */
	public static function subscriber( array $r, array $groups ) {
		$parts  = preg_split( '/\s+/u', trim( (string) $r['customer_name'] ), 2 );
		$fields = array(
			'name'      => $parts[0] ?? '',
			'last_name' => $parts[1] ?? '',
		);
		if ( Resandpay_Settings::get( 'ml_phone' ) && '' !== trim( (string) $r['phone'] ) ) {
			$fields['phone'] = trim( (string) $r['phone'] );
		}
		$data = array(
			'email'  => Resandpay_Customers::key( $r['email'] ),
			'fields' => array_filter( $fields, fn( $v ) => '' !== $v ),
		);
		if ( $groups ) {
			$data['groups'] = array_values( $groups );
		}
		/**
		 * Data odběratele odesílaná do MailerLite (POST /subscribers).
		 *
		 * @param array $data Data.
		 * @param array $r    Rezervace.
		 */
		return apply_filters( 'resandpay_mailerlite_subscriber', $data, $r );
	}

	/* -------------------------------------------------------------- Fronta */

	private static function set( $id, array $data ) {
		global $wpdb;
		$wpdb->update( Resandpay_Reservations::table(), $data, array( 'id' => (int) $id ) );
	}

	/** Má se rezervace zapsat teď (podle nastavení „Kdy zapsat“)? */
	private static function due( array $r ) {
		if ( 'created' === Resandpay_Settings::get( 'ml_when' ) ) {
			return true;
		}
		return Resandpay_Reservations::PAID === $r['status'];
	}

	/**
	 * Zařadí rezervaci do fronty k odeslání (jen se souhlasem s novinkami, jednou na rezervaci).
	 *
	 * @return bool Zařazeno.
	 */
	public static function enqueue( $r, $force = false ) {
		$r = is_array( $r ) ? $r : Resandpay_Reservations::get( (int) $r );
		if ( ! $r || ! self::enabled() || empty( $r['newsletter'] ) || '' === Resandpay_Customers::key( $r['email'] ) ) {
			return false;
		}
		if ( ! $force && in_array( $r['ml_status'] ?? '', array( self::PENDING, self::SENT ), true ) ) {
			return false;
		}
		self::set(
			$r['id'],
			array(
				'ml_status' => self::PENDING,
				'ml_error'  => '',
				'ml_tries'  => 0,
			)
		);
		self::schedule( 30 );
		return true;
	}

	/** Naplánuje zpracování fronty (jednorázová událost WP-Cron). */
	public static function schedule( $delay = 30 ) {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON );
		}
	}

	public static function on_created( $r ) {
		if ( is_array( $r ) && self::due( $r ) ) {
			self::enqueue( $r );
		}
	}

	public static function on_status_changed( $new, $old = null, $status = '' ) {
		if ( is_array( $new ) && Resandpay_Reservations::PAID === $status && 'paid' === Resandpay_Settings::get( 'ml_when' ) ) {
			self::enqueue( $new );
		}
	}

	public static function on_paid( $new ) {
		if ( is_array( $new ) && Resandpay_Reservations::PAID === $new['status'] && 'paid' === Resandpay_Settings::get( 'ml_when' ) ) {
			self::enqueue( $new );
		}
	}

	/**
	 * Odešle jednu rezervaci (bez ohledu na frontu) a zapíše výsledek.
	 *
	 * @return array|WP_Error Aktualizovaná rezervace.
	 */
	public static function send( $r ) {
		$r = is_array( $r ) ? $r : Resandpay_Reservations::get( (int) $r );
		if ( ! $r ) {
			return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
		}
		$now    = current_time( 'mysql' );
		$groups = self::groups_for( $r );
		$skip   = '';
		if ( empty( $r['newsletter'] ) ) {
			$skip = __( 'bez souhlasu s novinkami', 'reserveandpay' );
		} elseif ( false === $groups ) {
			$skip = __( 'u položky nastaveno „neposílat do MailerLite“', 'reserveandpay' );
		} elseif ( Resandpay_Settings::is_test_mode() ) {
			$skip = __( 'testovací režim – do MailerLite se neposílá', 'reserveandpay' );
		}
		if ( '' !== $skip ) {
			self::set(
				$r['id'],
				array(
					'ml_status' => self::SKIPPED,
					'ml_error'  => $skip,
					'ml_at'     => $now,
				)
			);
			return Resandpay_Reservations::get( $r['id'] );
		}
		$tries = (int) ( $r['ml_tries'] ?? 0 ) + 1;
		$res   = self::request( 'POST', 'subscribers', self::subscriber( $r, $groups ) );
		if ( is_wp_error( $res ) ) {
			self::set(
				$r['id'],
				array(
					'ml_status' => $tries >= self::MAX_TRIES || 'ml_api_401' === $res->get_error_code() ? self::ERROR : self::PENDING,
					'ml_error'  => mb_substr( $res->get_error_message(), 0, 250 ),
					'ml_tries'  => min( 255, $tries ),
					'ml_at'     => $now,
				)
			);
			return $res;
		}
		self::set(
			$r['id'],
			array(
				'ml_status' => self::SENT,
				'ml_groups' => implode( ',', $groups ),
				'ml_error'  => '',
				'ml_tries'  => min( 255, $tries ),
				'ml_at'     => $now,
			)
		);
		return Resandpay_Reservations::get( $r['id'] );
	}

	/**
	 * Zpracuje frontu (po dávkách). Spouští WP-Cron a pravidelná kontrola pluginu.
	 *
	 * @return array{sent:int, failed:int, skipped:int, left:int}
	 */
	public static function process_queue() {
		global $wpdb;
		$out = array(
			'sent'    => 0,
			'failed'  => 0,
			'skipped' => 0,
			'left'    => 0,
		);
		if ( ! self::enabled() ) {
			return $out;
		}
		$t   = Resandpay_Reservations::table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE ml_status = %s ORDER BY id ASC LIMIT %d", self::PENDING, self::BATCH ) );
		foreach ( $ids as $id ) {
			$res = self::send( (int) $id );
			if ( is_wp_error( $res ) ) {
				++$out['failed'];
				if ( 'ml_key' === $res->get_error_code() ) {
					break; // Bez klíče nemá smysl pokračovat – fronta počká.
				}
			} elseif ( self::SENT === $res['ml_status'] ) {
				++$out['sent'];
			} else {
				++$out['skipped'];
			}
		}
		$out['left'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ml_status = %s", self::PENDING ) );
		if ( $out['left'] ) {
			self::schedule( $out['failed'] ? 5 * MINUTE_IN_SECONDS : 60 );
		}
		return $out;
	}

	/** Ruční odeslání znovu (detail rezervace). */
	public static function resend( $id ) {
		$r = Resandpay_Reservations::get( (int) $id );
		if ( ! $r ) {
			return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
		}
		if ( ! self::enabled() ) {
			return new WP_Error( 'ml_off', __( 'Napojení na MailerLite je vypnuté (Nastavení → Napojení).', 'reserveandpay' ) );
		}
		self::set(
			$r['id'],
			array(
				'ml_status' => self::PENDING,
				'ml_tries'  => 0,
			)
		);
		return self::send( $r['id'] );
	}

	/**
	 * Jednorázový import stávajících zákazníků se souhlasem: u každého zákazníka, jehož poslední
	 * rezervace má souhlas s novinkami a který ještě nebyl odeslán, se do fronty zařadí poslední
	 * rezervace se souhlasem (při „po zaplacení“ poslední zaplacená). Skupiny podle této rezervace.
	 *
	 * @return int Počet zařazených zákazníků.
	 */
	public static function import_candidates() {
		global $wpdb;
		$t    = Resandpay_Reservations::table();
		$paid = 'created' !== Resandpay_Settings::get( 'ml_when' );
		$keys = wp_list_pluck( Resandpay_Customers::query( array( 'newsletter' => true, 'per_page' => 0 ) )['items'], 'email_key' );
		$done = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT LOWER(TRIM(email)) FROM {$t} WHERE ml_status IN (%s, %s)", self::SENT, self::PENDING ) ) );
		$rows = $wpdb->get_results( "SELECT id, LOWER(TRIM(email)) AS k, status FROM {$t} WHERE newsletter = 1 AND email <> '' ORDER BY id DESC", ARRAY_A );
		$pick = array();
		foreach ( $rows as $row ) {
			if ( isset( $pick[ $row['k'] ] ) || isset( $done[ $row['k'] ] ) || ( $paid && Resandpay_Reservations::PAID !== $row['status'] ) ) {
				continue;
			}
			$pick[ $row['k'] ] = (int) $row['id'];
		}
		return array_values( array_intersect_key( $pick, array_flip( $keys ) ) );
	}

	/** Spustí import (zařadí do fronty). @return int Počet zákazníků. */
	public static function import_existing() {
		$n = 0;
		foreach ( self::import_candidates() as $id ) {
			$n += self::enqueue( $id, true ) ? 1 : 0;
		}
		return $n;
	}

	/** Stav zákazníka v přehledu: sent / pending / error / none (souhlas, neodesláno) / no_consent. */
	public static function customer_status( array $c ) {
		if ( ! empty( $c['ml_sent'] ) ) {
			return self::SENT;
		}
		if ( ! empty( $c['ml_pending'] ) ) {
			return self::PENDING;
		}
		if ( ! empty( $c['ml_error'] ) ) {
			return self::ERROR;
		}
		return empty( $c['newsletter'] ) ? 'no_consent' : 'none';
	}

	/** Popisky stavů v přehledu zákazníků (filtr). */
	public static function customer_status_labels() {
		return array(
			self::SENT    => __( 'odesláno', 'reserveandpay' ),
			self::PENDING => __( 'čeká na odeslání', 'reserveandpay' ),
			self::ERROR   => __( 'chyba', 'reserveandpay' ),
			'none'        => __( 'se souhlasem, neodesláno', 'reserveandpay' ),
			'no_consent'  => __( 'bez souhlasu', 'reserveandpay' ),
		);
	}
}
