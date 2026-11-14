<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vodoznak v PDF materiálů (Issue #27, R11-e, kap. 15.1 R21).
 *
 * Při stažení (i zobrazení v prohlížeči) PDF souboru materiálu s vodoznakem se vytvoří kopie,
 * do každé stránky se vloží text s údaji kupujícího (zápatí, šikmo přes stránku, nebo obojí)
 * a údaje se zapíší i do metadat PDF. Soubor na disku zůstává beze změny; kopie se ukládá do
 * chráněné mezipaměti (24 h) a odesílá stejně jako originál (včetně HTTP Range).
 *
 * Stránky se importují knihovnou FPDI (MIT) do TCPDF. Bezplatný parser FPDI nepodporuje PDF
 * s komprimovanými křížovými odkazy (PDF 1.5+); takový soubor se stáhne bez vodoznaku, zapíše
 * se do logu stažení a editor materiálu na něj upozorní.
 */
class Resandpay_Watermark {

	const CACHE_DIR = 'wm-cache';

	public static function positions() {
		return array(
			'footer'   => __( 'Zápatí stránky', 'reserveandpay' ),
			'diagonal' => __( 'Šikmo přes stránku', 'reserveandpay' ),
			'both'     => __( 'Zápatí i šikmo přes stránku', 'reserveandpay' ),
		);
	}

	/** Je u materiálu zapnutý vodoznak (u materiálu, jinak výchozí z nastavení)? */
	public static function enabled_for( array $mat ) {
		$v = (string) get_post_meta( $mat['id'], '_rap_mat_wm', true );
		if ( '' === $v ) {
			return (bool) Resandpay_Settings::get( 'mat_wm_default' );
		}
		return '1' === $v;
	}

	public static function is_pdf( array $file ) {
		return 'application/pdf' === $file['mime'] || 'pdf' === strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
	}

	/** Text vodoznaku pro uživatele. */
	public static function text( array $mat, $user_id, $now = null, $email = '' ) {
		$u    = $user_id ? get_userdata( $user_id ) : null;
		$name = $u ? ( trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ) : ( '' !== $email ? Resandpay_Materials::name_for_email( $email ) : '' );
		return trim(
			strtr(
				(string) Resandpay_Settings::get( 'mat_wm_text' ),
				array(
					'{name}'  => $name,
					'{email}' => $u ? $u->user_email : $email,
					'{date}'  => wp_date( 'j. n. Y', $now ?? time() ),
					'{code}'  => $mat['code'],
					'{site}'  => wp_parse_url( home_url(), PHP_URL_HOST ),
				)
			)
		);
	}

	private static function load() {
		require_once RESANDPAY_DIR . 'lib/tcpdf/tcpdf.php';
		require_once RESANDPAY_DIR . 'lib/fpdi/src/autoload.php';
	}

	/**
	 * Lze do souboru vložit vodoznak? (výsledek v mezipaměti podle cesty a času změny)
	 *
	 * @return true|WP_Error
	 */
	public static function supported( $abs ) {
		$key = 'rap_wm_ok_' . md5( $abs . '|' . @filemtime( $abs ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$c   = get_transient( $key );
		if ( false !== $c ) {
			return 'ok' === $c ? true : new WP_Error( 'wm_unsupported', $c );
		}
		self::load();
		try {
			$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
			$pdf->setSourceFile( $abs );
			$res = true;
		} catch ( \Throwable $e ) {
			$res = new WP_Error( 'wm_unsupported', self::explain( $e->getMessage() ) );
		}
		set_transient( $key, true === $res ? 'ok' : $res->get_error_message(), WEEK_IN_SECONDS );
		return $res;
	}

	private static function explain( $msg ) {
		if ( false !== stripos( $msg, 'compression technique' ) ) {
			return __( 'PDF používá komprimované křížové odkazy (PDF 1.5 a novější), které vodoznak neumí zpracovat. Uložte soubor jako PDF kompatibilní s verzí 1.4 (např. Acrobat: Uložit jako jiný → Optimalizované PDF → kompatibilita Acrobat 5; nebo tisk do PDF) a nahrajte ho znovu.', 'reserveandpay' );
		}
		if ( false !== stripos( $msg, 'encrypt' ) ) {
			return __( 'PDF je zašifrované (chráněné heslem nebo omezeními) – vodoznak do něj nelze vložit.', 'reserveandpay' );
		}
		/* translators: %s: chyba knihovny */
		return sprintf( __( 'PDF se nepodařilo zpracovat: %s', 'reserveandpay' ), $msg );
	}

	private static function cache_dir() {
		$dir = Resandpay_Materials::base_dir() . '/' . self::CACHE_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Vytvoří (nebo vrátí z mezipaměti) kopii PDF s vodoznakem.
	 *
	 * @return string|WP_Error Absolutní cesta ke kopii.
	 */
	public static function make( $abs, array $mat, array $file, $user_id, $now = null, $email = '' ) {
		$sup = self::supported( $abs );
		if ( is_wp_error( $sup ) ) {
			return $sup;
		}
		$text = self::text( $mat, $user_id, $now, $email );
		$pos  = (string) Resandpay_Settings::get( 'mat_wm_position' );
		$pos  = isset( self::positions()[ $pos ] ) ? $pos : 'footer';
		$out  = self::cache_dir() . '/' . md5( $abs . '|' . filemtime( $abs ) . '|' . $user_id . '|' . $email . '|' . $text . '|' . $pos . '|' . wp_salt() ) . '.pdf';
		if ( is_readable( $out ) && filemtime( $out ) > time() - DAY_IN_SECONDS ) {
			return $out;
		}
		self::load();
		try {
			$pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetAutoPageBreak( false, 0 );
			$pdf->SetMargins( 0, 0, 0 );
			$pdf->SetCreator( 'ReserveAndPay' );
			/* translators: %s: text vodoznaku */
			$meta = sprintf( __( 'Licencováno pro: %s', 'reserveandpay' ), $text );
			$pdf->SetSubject( $meta );
			$pdf->SetKeywords( $meta );
			$pdf->SetTitle( $mat['title'] );
			$pdf->setFontSubsetting( true );
			$pages = $pdf->setSourceFile( $abs );
			for ( $i = 1; $i <= $pages; $i++ ) {
				$tpl  = $pdf->importPage( $i );
				$size = $pdf->getTemplateSize( $tpl );
				$w    = (float) $size['width'];
				$h    = (float) $size['height'];
				$pdf->AddPage( $w > $h ? 'L' : 'P', array( $w, $h ) );
				$pdf->useTemplate( $tpl, 0, 0, $w, $h );
				if ( '' === $text ) {
					continue;
				}
				if ( in_array( $pos, array( 'diagonal', 'both' ), true ) ) {
					$pdf->StartTransform();
					$pdf->SetAlpha( 0.14 );
					$pdf->SetFont( 'dejavusans', 'B', max( 14, min( 40, $w / 12 ) ) );
					$pdf->SetTextColor( 90, 90, 90 );
					$pdf->Rotate( rad2deg( atan2( $h, $w ) ), $w / 2, $h / 2 );
					$tw = $pdf->GetStringWidth( $text );
					$pdf->Text( ( $w - $tw ) / 2, $h / 2 - 5, $text );
					$pdf->StopTransform();
					$pdf->SetAlpha( 1 );
				}
				if ( in_array( $pos, array( 'footer', 'both' ), true ) ) {
					$pdf->SetFont( 'dejavusans', '', 7 );
					$pdf->SetTextColor( 110, 110, 110 );
					$pdf->SetXY( 8, $h - 7 );
					$pdf->Cell( $w - 16, 4, $text, 0, 0, 'C' );
				}
			}
			$pdf->Output( $out, 'F' );
		} catch ( \Throwable $e ) {
			@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'wm_failed', self::explain( $e->getMessage() ) );
		}
		return $out;
	}

	/** Úklid mezipaměti (starší než 24 h). */
	public static function prune() {
		$dir = Resandpay_Materials::base_dir() . '/' . self::CACHE_DIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*.pdf' ) as $f ) {
			if ( $f && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** PDF soubory materiálu, do kterých vodoznak vložit nelze: [název => důvod]. */
	public static function problems( array $mat ) {
		$out = array();
		foreach ( $mat['files'] as $f ) {
			if ( ! self::is_pdf( $f ) || $f['sample'] ) {
				continue;
			}
			$abs = Resandpay_Materials::abs_path( $f['path'] );
			if ( ! $abs || ! is_readable( $abs ) ) {
				continue;
			}
			$s = self::supported( $abs );
			if ( is_wp_error( $s ) ) {
				$out[ $f['label'] ?: $f['name'] ] = $s->get_error_message();
			}
		}
		return $out;
	}
}
