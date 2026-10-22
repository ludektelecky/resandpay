<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bankovní adaptér Raiffeisenbank – avízo „Pohyb na účtě“ ze služby Informuj mě
 * (SWD kap. 6.2 a 6.5). Ostatní banky přibudou ve fázi 2 jako další adaptéry
 * se stejným rozhraním (parse, verify).
 */
class Resandpay_Bank_RB {

	const ID = 'rb';

	public static function name() {
		return 'Raiffeisenbank';
	}

	/** Výchozí odesílatel avíz. */
	public static function sender() {
		return 'info@rb.cz';
	}

	/** Doména, kterou musí potvrdit SPF/DKIM/DMARC. */
	public static function domain() {
		return 'rb.cz';
	}

	/**
	 * Vyčte údaje z avíza.
	 *
	 * @param array $mime Výsledek Resandpay_Mime::parse().
	 * @return array|WP_Error Pole: booked_at, to_account, to_name, amount, currency, direction (in|out),
	 *                        category, movement, counter_account, counter_name, vs, ks, ss, message.
	 */
	public static function parse( array $mime ) {
		$html  = $mime['html'] ?: nl2br( esc_html( $mime['text'] ) );
		$pairs = Resandpay_Mime::table_pairs( $html );
		$get   = function ( $label ) use ( $pairs ) {
			foreach ( $pairs as $k => $v ) {
				if ( 0 === strcasecmp( self::norm( $k ), self::norm( $label ) ) ) {
					return $v;
				}
			}
			return '';
		};
		$amount_raw = $get( 'Částka v měně účtu' ) ?: $get( 'Částka' );
		if ( '' === $amount_raw ) {
			return new WP_Error( 'format', __( 'Avízo nemá očekávaný formát (chybí částka) – banka mohla změnit šablonu e-mailu.', 'reserveandpay' ) );
		}
		$amount = self::amount( $amount_raw );
		$to     = explode( "\n", $get( 'Na účet' ), 2 );
		$from   = explode( "\n", $get( 'Z účtu' ), 2 );
		$type   = $get( 'Typ pohybu' );
		$dir    = $amount['value'] > 0 && ( '' === $type || 0 === stripos( self::norm( $type ), 'prichozi' ) ) ? 'in' : 'out';
		$intro  = self::norm( wp_strip_all_tags( $html ) );
		if ( false !== strpos( $intro, 'odchozi platba' ) ) {
			$dir = 'out';
		}
		return array(
			'booked_at'       => self::datetime( $get( 'Datum a čas' ) ),
			'to_account'      => self::account( $to[0] ?? '' ),
			'to_name'         => trim( $to[1] ?? '' ),
			'amount'          => $amount['value'],
			'currency'        => $amount['currency'],
			'direction'       => $dir,
			'category'        => $get( 'Kategorie pohybu' ),
			'movement'        => $type,
			'counter_account' => self::account( $from[0] ?? '' ),
			'counter_name'    => trim( $from[1] ?? '' ),
			'vs'              => preg_replace( '/\D/', '', $get( 'Variabilní symbol' ) ),
			'ks'              => preg_replace( '/\D/', '', $get( 'Konstantní symbol' ) ),
			'ss'              => preg_replace( '/\D/', '', $get( 'Specifický symbol' ) ),
			'message'         => $get( 'Zpráva pro příjemce' ),
		);
	}

	/**
	 * Ověření pravosti (SWD kap. 6.5) z hlaviček, které přidal poštovní server příjemce.
	 * - odesílatel (From i Return-Path) = info@rb.cz,
	 * - první hlavička Received-SPF = Pass pro odesílatele z rb.cz,
	 * - právě jedna hlavička X-Spamd-Result (rspamd) s DKIM a DMARC „allow“ pro rb.cz
	 *   (víc výskytů = někdo hlavičku vložil do zprávy → nedůvěřovat).
	 *
	 * @return array{ok:bool, checks:array} Výsledky jednotlivých kontrol.
	 */
	public static function verify( array $mime, $sender = '' ) {
		$h      = $mime['headers'];
		$sender = strtolower( $sender ?: self::sender() );
		$dom    = self::domain();
		$from   = Resandpay_Mime::address( Resandpay_Mime::first( $h, 'from' ) );
		$rpath  = Resandpay_Mime::address( Resandpay_Mime::first( $h, 'return-path' ) );
		$spf    = Resandpay_Mime::first( $h, 'received-spf' );
		$spamd  = Resandpay_Mime::all( $h, 'x-spamd-result' );
		$q      = preg_quote( $dom, '/' );
		$checks = array(
			'sender' => $from === $sender && ( '' === $rpath || $rpath === $sender ),
			'spf'    => (bool) preg_match( '/^pass\b/i', $spf ) && (bool) preg_match( '/envelope-from=[^;\s]*@' . $q . '\b/i', $spf ),
			'dkim'   => 1 === count( $spamd ) && (bool) preg_match( '/R_DKIM_ALLOW\([^)]*\)\[' . $q . '[:\]]/i', $spamd[0] ),
			'dmarc'  => 1 === count( $spamd ) && (bool) preg_match( '/DMARC_POLICY_ALLOW\([^)]*\)\[' . $q . '[,\]]/i', $spamd[0] ),
		);
		return array(
			'ok'     => ! in_array( false, $checks, true ),
			'checks' => $checks,
		);
	}

	/** „+5.000,00 CZK“ → 5000.00, CZK. */
	public static function amount( $raw ) {
		$raw = str_replace( array( "\xC2\xA0", ' ' ), '', (string) $raw );
		$cur = preg_match( '/([A-Z]{3})/', $raw, $m ) ? $m[1] : '';
		$num = preg_replace( '/[^0-9,.\-+]/', '', $raw );
		$num = str_replace( '.', '', $num );   // Oddělovač tisíců.
		$num = str_replace( ',', '.', $num );  // Desetinná čárka.
		return array(
			'value'    => round( (float) $num, 2 ),
			'currency' => $cur,
		);
	}

	/** „21. 10. 2026 22:18“ → 2026-10-21 22:18:00 (místní čas). */
	public static function datetime( $raw ) {
		if ( preg_match( '/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', (string) $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d %02d:%02d:00', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0 );
		}
		return '';
	}

	/** Číslo účtu bez mezer, s předčíslím: „19-2000145399/0800“. */
	public static function account( $raw ) {
		return preg_replace( '/[^0-9\-\/]/', '', (string) $raw );
	}

	/** Porovnání účtů (bez úvodních nul a mezer). */
	public static function same_account( $a, $b ) {
		$n = function ( $x ) {
			$x = self::account( $x );
			if ( ! preg_match( '#^(?:(\d+)-)?(\d+)/(\d{4})$#', $x, $m ) ) {
				return $x;
			}
			return ltrim( $m[1], '0' ) . '-' . ltrim( $m[2], '0' ) . '/' . $m[3];
		};
		return '' !== self::account( $a ) && $n( $a ) === $n( $b );
	}

	private static function norm( $s ) {
		return strtolower( trim( remove_accents( (string) $s ) ) );
	}
}
