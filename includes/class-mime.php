<?php
defined( 'ABSPATH' ) || exit;

/**
 * Jednoduchý parser e-mailu (RFC 822 / MIME) pro bankovní avíza – bez závislosti
 * na rozšíření imap/mailparse (na PHP 8.4+ nejsou standardně k dispozici).
 */
class Resandpay_Mime {

	/**
	 * Rozloží e-mail.
	 *
	 * @return array headers (seznam [jméno, hodnota] v pořadí), html, text.
	 */
	public static function parse( $raw ) {
		$raw = str_replace( "\r\n", "\n", (string) $raw );
		list( $head, $body ) = self::split( $raw );
		$headers = self::headers( $head );
		$out     = array(
			'headers' => $headers,
			'html'    => '',
			'text'    => '',
		);
		self::walk( $headers, $body, $out, 0 );
		return $out;
	}

	private static function split( $raw ) {
		$pos = strpos( $raw, "\n\n" );
		return false === $pos ? array( $raw, '' ) : array( substr( $raw, 0, $pos ), substr( $raw, $pos + 2 ) );
	}

	/** Hlavičky jako seznam dvojic (zachová pořadí a opakování). */
	public static function headers( $head ) {
		$head  = preg_replace( "/\n[ \t]+/", ' ', $head ); // Rozbalení pokračovacích řádků.
		$out   = array();
		foreach ( explode( "\n", $head ) as $line ) {
			$p = strpos( $line, ':' );
			if ( false === $p ) {
				continue;
			}
			$out[] = array( strtolower( trim( substr( $line, 0, $p ) ) ), trim( substr( $line, $p + 1 ) ) );
		}
		return $out;
	}

	/** Všechny hodnoty hlavičky v pořadí výskytu. */
	public static function all( array $headers, $name ) {
		$name = strtolower( $name );
		$out  = array();
		foreach ( $headers as $h ) {
			if ( $h[0] === $name ) {
				$out[] = $h[1];
			}
		}
		return $out;
	}

	public static function first( array $headers, $name ) {
		$all = self::all( $headers, $name );
		return $all ? $all[0] : '';
	}

	/** Dekódování hlavičky (=?UTF-8?Q?…?=). */
	public static function decode_header( $value ) {
		if ( false === strpos( (string) $value, '=?' ) ) {
			return (string) $value;
		}
		$d = function_exists( 'iconv_mime_decode' ) ? @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false !== $d ? $d : mb_decode_mimeheader( $value );
	}

	/** Parametr z hlavičky (boundary, charset). */
	public static function param( $value, $param ) {
		return preg_match( '/' . preg_quote( $param, '/' ) . '\s*=\s*"?([^";]+)"?/i', (string) $value, $m ) ? trim( $m[1] ) : '';
	}

	/** E-mailová adresa z hlavičky From/Return-Path. */
	public static function address( $value ) {
		if ( preg_match( '/<([^>]+)>/', (string) $value, $m ) ) {
			return strtolower( trim( $m[1] ) );
		}
		return strtolower( trim( (string) $value ) );
	}

	private static function walk( array $headers, $body, array &$out, $depth ) {
		if ( $depth > 5 ) {
			return;
		}
		$ctype = strtolower( self::first( $headers, 'content-type' ) ?: 'text/plain' );
		if ( 0 === strpos( $ctype, 'multipart/' ) ) {
			$b = self::param( self::first( $headers, 'content-type' ), 'boundary' );
			if ( '' === $b ) {
				return;
			}
			$parts = explode( '--' . $b, $body );
			array_shift( $parts ); // Preambule.
			foreach ( $parts as $part ) {
				if ( 0 === strpos( $part, '--' ) ) {
					break; // Konec multipartu.
				}
				$part = ltrim( $part, "\n" );
				list( $ph, $pb ) = self::split( $part );
				self::walk( self::headers( $ph ), $pb, $out, $depth + 1 );
			}
			return;
		}
		$is_html = 0 === strpos( $ctype, 'text/html' );
		$is_text = 0 === strpos( $ctype, 'text/plain' );
		if ( ! $is_html && ! $is_text ) {
			return;
		}
		$enc = strtolower( self::first( $headers, 'content-transfer-encoding' ) );
		if ( 'quoted-printable' === $enc ) {
			$body = quoted_printable_decode( $body );
		} elseif ( 'base64' === $enc ) {
			$body = (string) base64_decode( preg_replace( '/\s+/', '', $body ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$cs = strtoupper( self::param( self::first( $headers, 'content-type' ), 'charset' ) ?: 'UTF-8' );
		if ( 'UTF-8' !== $cs && 'UTF8' !== $cs ) {
			$conv = @mb_convert_encoding( $body, 'UTF-8', $cs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$body = false !== $conv ? $conv : $body;
		}
		if ( $is_html && '' === $out['html'] ) {
			$out['html'] = $body;
		} elseif ( $is_text && '' === $out['text'] ) {
			$out['text'] = $body;
		}
	}

	/** Řádky dvousloupcových tabulek HTML jako [popisek => hodnota] (hodnota s \n místo <br>). */
	public static function table_pairs( $html ) {
		$pairs = array();
		if ( ! preg_match_all( '#<tr\b[^>]*>(.*?)</tr>#is', (string) $html, $rows ) ) {
			return $pairs;
		}
		foreach ( $rows[1] as $row ) {
			if ( 2 !== preg_match_all( '#<td\b[^>]*>(.*?)</td>#is', $row, $cells ) ) {
				continue;
			}
			$label = self::cell_text( $cells[1][0] );
			$value = self::cell_text( $cells[1][1] );
			if ( '' !== $label && ! isset( $pairs[ $label ] ) ) {
				$pairs[ $label ] = $value;
			}
		}
		return $pairs;
	}

	private static function cell_text( $html ) {
		$t = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$t = html_entity_decode( wp_strip_all_tags( $t, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = str_replace( "\xC2\xA0", ' ', $t );
		$lines = array_filter( array_map( fn( $l ) => trim( preg_replace( '/[ \t]+/', ' ', $l ) ), explode( "\n", $t ) ), 'strlen' );
		return implode( "\n", $lines );
	}
}
