<?php
defined( 'ABSPATH' ) || exit;

/**
 * Jednotné logo pro všechny výstupy ReserveAndPay (platební pokyn PDF, slevový poukaz PDF,
 * HTML e-maily) – SWD kap. 5.2 a 14.1.
 *
 * Zdroj (Nastavení → Identifikace → „Logo ve výstupech“):
 * - site   = logo webu (Vzhled → Přizpůsobit → Identita webu; PNG, JPG nebo SVG),
 *            není-li nahrané, použije se logo z nastavení pluginu,
 * - plugin = vždy logo z nastavení pluginu (vektorový symbol + dva texty dle grafického manuálu).
 */
class Resandpay_Brand {

	/** @return array type: raster (path, mime) | svg (svg) | mark (svg, line1, line2). */
	public static function logo() {
		$s = Resandpay_Settings::all();
		if ( 'plugin' !== ( $s['logo_source'] ?? 'site' ) ) {
			$site = self::site_logo();
			if ( $site ) {
				return $site;
			}
		}
		return array(
			'type'  => 'mark',
			'svg'   => self::stroke_only( (string) $s['brand_symbol_svg'] ),
			'line1' => trim( (string) $s['logo_line1'] ),
			'line2' => trim( (string) $s['logo_line2'] ),
		);
	}

	/** Logo webu z Identity webu, nebo null. */
	public static function site_logo() {
		$id   = (int) get_theme_mod( 'custom_logo' );
		$file = $id ? get_attached_file( $id ) : '';
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		if ( preg_match( '/\.svg$/i', $file ) ) {
			$svg = class_exists( 'Resandpay_Admin_Settings' ) ? Resandpay_Admin_Settings::sanitize_svg( (string) file_get_contents( $file ) ) : self::basic_svg( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '' !== $svg ? array( 'type' => 'svg', 'svg' => $svg ) : null;
		}
		if ( preg_match( '/\.(png|jpe?g)$/i', $file, $m ) ) {
			return array(
				'type' => 'raster',
				'path' => $file,
				'mime' => 'png' === strtolower( $m[1] ) ? 'image/png' : 'image/jpeg',
			);
		}
		return null;
	}

	/** Bezpečné SVG i mimo administraci (stejná pravidla jako v nastavení). */
	private static function basic_svg( $svg ) {
		$attrs = array_fill_keys( array( 'xmlns', 'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'width', 'height', 'x1', 'y1', 'x2', 'y2', 'points', 'transform', 'opacity' ), true );
		$out   = trim( wp_kses( $svg, array_fill_keys( array( 'svg', 'g', 'path', 'circle', 'line', 'polyline', 'polygon', 'ellipse', 'rect' ), $attrs ) ) );
		return str_replace( ' viewbox=', ' viewBox=', $out );
	}

	/** TCPDF nedědí fill="none" z kořene SVG – tahové motivy doplnit explicitně (manuál: žádná výplň). */
	public static function stroke_only( $svg ) {
		return preg_replace( '/<(path|g|circle|ellipse|polyline|line)(?![^>]*\bfill=)/i', '<$1 fill="none"', $svg );
	}

	/** Poměr stran SVG (šířka / výška) z viewBox. */
	private static function svg_ratio( $svg, $fallback = 1.25 ) {
		if ( preg_match( '/viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $m ) && (float) $m[2] > 0 ) {
			return (float) $m[1] / (float) $m[2];
		}
		return $fallback;
	}

	/**
	 * Nakreslí logo do PDF (TCPDF).
	 *
	 * @param float $h Výška loga v mm (u řádkové sestavy výška symbolu; písmo = h / 1,5 dle manuálu).
	 * @param float $max_w Nejvyšší šířka v mm.
	 * @return float Šířka nakresleného loga v mm (0 = nic).
	 */
	public static function pdf( $pdf, $x, $y, $h, $max_w = 90 ) {
		$logo = self::logo();
		try {
			if ( 'raster' === $logo['type'] ) {
				$size = @getimagesize( $logo['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$w    = $size && $size[1] ? min( $max_w, $h * $size[0] / $size[1] ) : $h * 3;
				$pdf->Image( $logo['path'], $x, $y, $w, 0, '', '', '', false, 300, '', false, false, 0, 'LT' ); // resize=false – zachová průhlednost PNG.
				return $w;
			}
			if ( 'svg' === $logo['type'] ) {
				$w = min( $max_w, $h * self::svg_ratio( $logo['svg'], 3 ) );
				$pdf->ImageSVG( '@' . self::stroke_only( $logo['svg'] ), $x, $y, $w, $h, '', '', '', 0, false );
				return $w;
			}
			// Řádková sestava: symbol 1,5× výšky písma, mezera 0,4×; „hlavou“ 1,09× (grafický manuál, kap. 02).
			$pt  = $h / 1.5 / 0.3528;
			$cx  = $x;
			if ( '' !== $logo['svg'] ) {
				$sw = $h * self::svg_ratio( $logo['svg'] );
				$pdf->ImageSVG( '@' . $logo['svg'], $cx, $y, $sw, $h, '', '', '', 0, false );
				$cx += $sw + 0.4 * $h / 1.5;
			}
			$base = $y + $h * 0.22;
			$pdf->SetFont( 'fraunces_md', '', $pt );
			$pdf->SetTextColor( 46, 42, 34 );
			$pdf->SetXY( $cx, $base );
			$t1 = $logo['line1'] . ' ';
			$w1 = $pdf->GetStringWidth( $t1 );
			$pdf->Cell( $w1, $h * 0.6, $t1, 0, 0, 'L' );
			$pdf->SetFont( 'fraunces_i', '', $pt * 1.09 );
			$pdf->SetTextColor( 140, 100, 35 );
			$w2 = $pdf->GetStringWidth( $logo['line2'] );
			$pdf->Cell( $w2 + 1, $h * 0.6, $logo['line2'], 0, 0, 'L' );
			return $cx - $x + $w1 + $w2;
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/** PNG/JPG loga webu pro vložení do e-mailu (cid:rap-logo), jinak null. */
	public static function email_image() {
		$logo = self::logo();
		if ( 'raster' !== $logo['type'] ) {
			return null;
		}
		$size = @getimagesize( $logo['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return array(
			'data'   => (string) file_get_contents( $logo['path'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			'mime'   => $logo['mime'],
			'name'   => basename( $logo['path'] ),
			'height' => 44,
			'width'  => $size && $size[1] ? (int) round( 44 * $size[0] / $size[1] ) : 160,
		);
	}

	/**
	 * Hlavička HTML e-mailu: obrázek loga (PNG/JPG), jinak textová sestava loga
	 * („s lehkou“ + kurzívou „hlavou“) – e-mailové programy SVG spolehlivě nezobrazí.
	 */
	public static function email_header( array $colors ) {
		$img = self::email_image();
		$alt = trim( Resandpay_Settings::get( 'brand_name' ) ?: get_bloginfo( 'name' ) );
		if ( $img ) {
			return '<img src="cid:rap-logo" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '" alt="' . esc_attr( $alt ) . '" style="display:block;border:0;height:' . (int) $img['height'] . 'px;width:auto;max-width:100%;">';
		}
		$logo = self::logo();
		if ( 'mark' !== $logo['type'] ) {
			return '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:22px;color:' . esc_attr( $colors['accent'] ) . ';">' . esc_html( $alt ) . '</span>';
		}
		return '<span style="font-family:Fraunces,Georgia,\'Times New Roman\',serif;font-size:21px;color:' . esc_attr( $colors['ink'] ) . ';">' . esc_html( $logo['line1'] ) . '</span> <span style="font-family:Fraunces,Georgia,\'Times New Roman\',serif;font-style:italic;font-size:23px;color:' . esc_attr( $colors['accent'] ) . ';">' . esc_html( $logo['line2'] ) . '</span>';
	}
}
