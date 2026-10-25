<?php
defined( 'ABSPATH' ) || exit;

/**
 * QR platba – český standard SPD 1.0 (SPAYD), SWD kap. 5.1.
 * Matici QR kódu počítá přibalená knihovna TCPDF, obrázek PNG skládá tato třída
 * sama (bez rozšíření GD/Imagick – hosting je nemusí mít).
 */
class Resandpay_QR {

	/** Text jen v ASCII (bez diakritiky a typografických znaků) a bez „*“ (oddělovač polí SPD). */
	public static function clean( $text, $max ) {
		$t = remove_accents( (string) $text );
		$t = strtr( $t, array( '–' => '-', '—' => '-', '…' => '...', '„' => '"', '“' => '"', '”' => '"', '‚' => "'", '‘' => "'", '’' => "'", ' ' => ' ' ) );
		$t = preg_replace( '/[^\x20-\x7E]/', '', $t ); // Jen ASCII – bankovní aplikace jinak znaky komolí.
		$t = preg_replace( '/[\*\r\n\t]+/', ' ', $t );
		$t = trim( preg_replace( '/\s+/', ' ', $t ) );
		return trim( mb_substr( $t, 0, $max ) );
	}

	/** Zpráva pro příjemce: „AK-2026-0012 Jana Novakova“ (max. 60 znaků). */
	public static function message( array $r ) {
		return self::clean( trim( Resandpay_Codes::get( $r['event_id'] ) . ' ' . $r['customer_name'] ), 60 );
	}

	/**
	 * SPD řetězec pro rezervaci. Prázdný, pokud není v nastavení IBAN nebo jde o jinou měnu než CZK
	 * (SPD je tuzemský standard; EUR → EPC QR ve fázi 2).
	 */
	/**
	 * @param float|null  $amount Částka (výchozí: to, co je teď potřeba zaplatit – 1. část / zbytek).
	 * @param string|null $due    Splatnost (výchozí: splatnost té částky).
	 */
	public static function spd( array $r, $amount = null, $due = null ) {
		$s    = Resandpay_Settings::all();
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $s['iban'] ) );
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban ) || 'CZK' !== strtoupper( (string) $r['currency'] ) ) {
			return '';
		}
		$parts = array(
			'SPD',
			'1.0',
			'ACC:' . $iban,
			'AM:' . number_format( (float) ( $amount ?? Resandpay_Reservations::amount_now( $r ) ), 2, '.', '' ),
			'CC:CZK',
		);
		$due = $due ?? Resandpay_Reservations::due_now( $r );
		if ( ! empty( $due ) ) {
			$parts[] = 'DT:' . wp_date( 'Ymd', Resandpay_Events::ts( $due ) );
		}
		$msg = self::message( $r );
		if ( '' !== $msg ) {
			$parts[] = 'MSG:' . $msg;
		}
		$rn = self::clean( $s['qr_recipient_name'] ?: $s['company_name'], 35 );
		if ( '' !== $rn ) {
			$parts[] = 'RN:' . $rn;
		}
		$parts[] = 'X-VS:' . preg_replace( '/\D/', '', (string) $r['vs'] );
		return implode( '*', $parts );
	}

	/** Matice QR kódu (pole řádků s 0/1). */
	public static function matrix( $text, $ecc = 'M' ) {
		if ( ! class_exists( 'TCPDF2DBarcode' ) ) {
			require_once RESANDPAY_DIR . 'lib/tcpdf/tcpdf_barcodes_2d.php';
		}
		$bc  = new TCPDF2DBarcode( $text, 'QRCODE,' . ( in_array( $ecc, array( 'L', 'M', 'Q', 'H' ), true ) ? $ecc : 'M' ) );
		$arr = $bc->getBarcodeArray();
		return is_array( $arr ) && ! empty( $arr['bcode'] ) ? $arr['bcode'] : array();
	}

	/**
	 * PNG obrázek QR kódu (černobílý, s okrajem 4 moduly).
	 *
	 * @param int $scale Pixelů na modul.
	 */
	public static function png( $text, $scale = 6 ) {
		$m = self::matrix( $text );
		if ( ! $m ) {
			return '';
		}
		$quiet = 4;
		$n     = count( $m );
		$size  = ( $n + 2 * $quiet ) * $scale;
		$raw   = '';
		for ( $y = 0; $y < $size; $y++ ) {
			$my  = intdiv( $y, $scale ) - $quiet;
			$row = "\0"; // Filtr řádku: žádný.
			for ( $x = 0; $x < $size; $x++ ) {
				$mx   = intdiv( $x, $scale ) - $quiet;
				$dark = $my >= 0 && $my < $n && $mx >= 0 && $mx < $n && ! empty( $m[ $my ][ $mx ] );
				$row .= $dark ? "\x00" : "\xFF";
			}
			$raw .= $row;
		}
		$chunk = function ( $type, $data ) {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		return "\x89PNG\r\n\x1a\n"
			. $chunk( 'IHDR', pack( 'NNCCCCC', $size, $size, 8, 0, 0, 0, 0 ) ) // 8 bit, odstíny šedi.
			. $chunk( 'IDAT', gzcompress( $raw, 9 ) )
			. $chunk( 'IEND', '' );
	}

	/**
	 * Barevný PNG (RGB) – QR kód slevového kupónu ve zlaté barvě, odlišený od platebních QR.
	 *
	 * @param array $fg Barva modulů [r,g,b]. @param array $bg Barva podkladu.
	 */
	public static function png_color( $text, $scale = 8, array $fg = array( 140, 100, 35 ), array $bg = array( 251, 247, 239 ), $ecc = 'Q' ) {
		$m = self::matrix( $text, $ecc );
		if ( ! $m ) {
			return '';
		}
		$quiet = 4;
		$n     = count( $m );
		$size  = ( $n + 2 * $quiet ) * $scale;
		$on    = chr( $fg[0] ) . chr( $fg[1] ) . chr( $fg[2] );
		$off   = chr( $bg[0] ) . chr( $bg[1] ) . chr( $bg[2] );
		$raw   = '';
		for ( $y = 0; $y < $size; $y++ ) {
			$my  = intdiv( $y, $scale ) - $quiet;
			$row = "\0";
			for ( $x = 0; $x < $size; $x++ ) {
				$mx   = intdiv( $x, $scale ) - $quiet;
				$row .= $my >= 0 && $my < $n && $mx >= 0 && $mx < $n && ! empty( $m[ $my ][ $mx ] ) ? $on : $off;
			}
			$raw .= $row;
		}
		$chunk = function ( $type, $data ) {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		return "\x89PNG\r\n\x1a\n"
			. $chunk( 'IHDR', pack( 'NNCCCCC', $size, $size, 8, 2, 0, 0, 0 ) ) // 8 bit, RGB.
			. $chunk( 'IDAT', gzcompress( $raw, 9 ) )
			. $chunk( 'IEND', '' );
	}

	public static function data_uri( $text, $scale = 6 ) {
		$png = self::png( $text, $scale );
		return $png ? 'data:image/png;base64,' . base64_encode( $png ) : '';
	}

	/** Má se u rezervace zobrazit QR platba? (nezaplacená, aktivní, s platným SPD) */
	public static function applicable( array $r ) {
		return in_array( $r['status'], array( Resandpay_Reservations::PENDING, Resandpay_Reservations::ONSITE, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::OVERDUE, Resandpay_Reservations::EXPIRED ), true )
			&& Resandpay_Reservations::amount_now( $r ) > 0 && '' !== self::spd( $r );
	}

	/** Druhý QR kód „zaplatit vše najednou“ – jen u rozdělené platby, dokud nic nepřišlo; splatnost = splatnost 1. části (jinak by rezervace expirovala). */
	public static function spd_full( array $r ) {
		return Resandpay_Reservations::is_split( $r ) && (float) $r['amount_paid'] <= 0 ? self::spd( $r, (float) $r['amount'], Resandpay_Reservations::due_now( $r ) ) : '';
	}
}
