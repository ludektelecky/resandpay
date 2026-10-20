<?php
defined( 'ABSPATH' ) || exit;

/**
 * Jednoduchý databázový zámek (portabilní MySQL i SQLite).
 * Atomicita je zajištěna primárním klíčem v tabulce zámků.
 */
class Resandpay_Lock {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_locks';
	}

	/**
	 * Získá zámek. Vrací true při úspěchu.
	 *
	 * @param string $name    Název zámku.
	 * @param int    $ttl     Platnost v sekundách (ochrana proti zaseknutí).
	 * @param float  $wait    Jak dlouho čekat na uvolnění (s).
	 */
	public static function acquire( $name, $ttl = 20, $wait = 8.0 ) {
		global $wpdb;
		$table    = self::table();
		$deadline = microtime( true ) + $wait;
		do {
			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->query(
				$wpdb->prepare( "INSERT INTO {$table} (lock_name, expires_at) VALUES (%s, %d)", $name, time() + $ttl )
			);
			$wpdb->suppress_errors( $suppress );
			if ( $ok ) {
				return true;
			}
			// Uvolnění prošlého zámku.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE lock_name = %s AND expires_at < %d", $name, time() ) );
			usleep( wp_rand( 30000, 90000 ) );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	public static function release( $name ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE lock_name = %s", $name ) );
	}
}
