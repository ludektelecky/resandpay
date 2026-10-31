<?php
defined( 'ABSPATH' ) || exit;

/**
 * PDF platebního pokynu (SWD kap. 5.2, 5.4). Vzhled dle grafického manuálu
 * (Fraunces / Work Sans, krémové a zlatohnědé tóny). Nejde o daňový doklad.
 */
class Resandpay_PDF {

	const C_INK    = array( 46, 42, 34 );
	const C_SOFT   = array( 92, 94, 82 );
	const C_ACCENT = array( 140, 100, 35 );
	const C_PLUM   = array( 107, 63, 75 );
	const C_LINE   = array( 214, 195, 160 );
	const C_BOX    = array( 240, 232, 218 );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ), 1 );
	}

	/** Trvalý odkaz ke stažení (token rezervace – netriviálně uhodnutelný). */
	public static function url( array $r ) {
		return add_query_arg( 'rap_pdf', rawurlencode( $r['token'] ), home_url( '/' ) );
	}

	public static function filename( array $r ) {
		return sanitize_file_name( 'platebni-pokyn-' . $r['proforma_no'] . '.pdf' );
	}

	public static function maybe_download() {
		if ( empty( $_GET['rap_pdf'] ) ) {
			return;
		}
		$r = Resandpay_Reservations::get_by_token( sanitize_text_field( wp_unslash( $_GET['rap_pdf'] ) ) );
		if ( ! $r ) {
			status_header( 404 );
			wp_die( esc_html__( 'Platební pokyn nebyl nalezen.', 'reserveandpay' ), '', array( 'response' => 404 ) );
		}
		$pdf = self::render( $r );
		if ( '' === $pdf ) {
			wp_die( esc_html__( 'Platební pokyn se nepodařilo vytvořit.', 'reserveandpay' ) );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . self::filename( $r ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binární PDF.
		exit;
	}

	private static function load() {
		if ( class_exists( 'TCPDF' ) ) {
			return;
		}
		if ( ! defined( 'K_TCPDF_EXTERNAL_CONFIG' ) ) {
			define( 'K_TCPDF_EXTERNAL_CONFIG', true );
		}
		if ( ! defined( 'K_PATH_FONTS' ) ) {
			define( 'K_PATH_FONTS', RESANDPAY_DIR . 'lib/tcpdf/fonts/' );
		}
		if ( ! defined( 'K_PATH_CACHE' ) ) {
			define( 'K_PATH_CACHE', trailingslashit( get_temp_dir() ) );
		}
		if ( ! defined( 'K_TCPDF_THROW_EXCEPTION_ERROR' ) ) {
			define( 'K_TCPDF_THROW_EXCEPTION_ERROR', true );
		}
		if ( ! defined( 'K_TCPDF_CALLS_IN_HTML' ) ) {
			define( 'K_TCPDF_CALLS_IN_HTML', false );
		}
		require_once RESANDPAY_DIR . 'lib/tcpdf/tcpdf.php';
	}

	/**
	 * Vytvoří PDF platebního pokynu.
	 *
	 * @return string Obsah PDF ('' při chybě).
	 */
	public static function render( array $r ) {
		self::load();
		$s  = Resandpay_Settings::all();
		$ev = Resandpay_Events::get( $r['event_id'] ) ?: array(
			'title'         => '',
			'code'          => '',
			'start'         => '',
			'end'           => '',
			'location'      => '',
			'location_addr' => '',
		);
		try {
			$pdf = new TCPDF( 'P', 'mm', 'A4', true, 'UTF-8', false );
			$pdf->SetCreator( 'ReserveAndPay' );
			$pdf->SetAuthor( $s['company_name'] );
			/* translators: %s: číslo pokynu */
			$pdf->SetTitle( sprintf( __( 'Platební pokyn %s', 'reserveandpay' ), $r['proforma_no'] ) );
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetMargins( 18, 16, 18 );
			$pdf->SetAutoPageBreak( true, 18 );
			$pdf->setCellPaddings( 0, 0, 0, 0 );
			$pdf->AddPage();
			$w = 174; // Šířka obsahu.

			// Hlavička: logo vlevo (jednotné pro všechny výstupy – Resandpay_Brand), nadpis vpravo.
			Resandpay_Brand::pdf( $pdf, 18, 15, 10, 88 );
			self::font( $pdf, 'worksansb', 8.5, self::C_PLUM );
			$pdf->SetXY( 110, 15 );
			$pdf->Cell( 82, 5, self::upper( __( 'Platební pokyn', 'reserveandpay' ) ), 0, 2, 'R' );
			self::font( $pdf, 'fraunces_sb', 17, self::C_ACCENT );
			$pdf->Cell( 82, 8, $r['proforma_no'], 0, 2, 'R' );
			self::font( $pdf, 'worksans', 8.5, self::C_SOFT );
			/* translators: %s: datum */
			$pdf->Cell( 82, 5, sprintf( __( 'Vystaveno %s', 'reserveandpay' ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['created_at'] ) ) ), 0, 2, 'R' );

			// Provozovatel.
			$pdf->SetXY( 18, 36 );
			$issuer = trim( $s['company_name'] . ( $s['brand_name'] ? ' · ' . $s['brand_name'] : '' ), ' ·' );
			self::font( $pdf, 'fraunces_sb', 12.5, self::C_INK );
			$pdf->MultiCell( $w, 6, $issuer, 0, 'L', false, 1 );
			self::font( $pdf, 'worksans', 9, self::C_SOFT );
			$line = array_filter(
				array(
					$s['company_address'],
					/* translators: %s: IČ */
					$s['company_ic'] ? sprintf( __( 'IČ %s', 'reserveandpay' ), $s['company_ic'] ) : '',
				)
			);
			if ( $line ) {
				$pdf->MultiCell( $w, 4.6, implode( ' · ', $line ), 0, 'L', false, 1 );
			}
			$contact = array_filter( array( $s['contact_web'], $s['contact_email'] ) );
			if ( $contact ) {
				self::font( $pdf, 'worksans', 9, self::C_ACCENT );
				$pdf->MultiCell( $w, 4.6, implode( ' · ', $contact ), 0, 'L', false, 1 );
			}
			self::rule( $pdf, $pdf->GetY() + 4 );

			// Odběratel a rezervace.
			$pdf->SetY( $pdf->GetY() + 8 );
			$top = $pdf->GetY();
			self::label( $pdf, 18, $top, __( 'Odběratel', 'reserveandpay' ) );
			self::font( $pdf, 'worksansb', 10, self::C_INK );
			$pdf->SetXY( 18, $top + 5 );
			$pdf->MultiCell( 80, 5, $r['customer_name'], 0, 'L', false, 1 );
			self::font( $pdf, 'worksans', 9, self::C_SOFT );
			$cust = array_filter(
				array(
					$r['company'] ? $r['company'] . ( $r['company_ic'] ? ', IČ ' . $r['company_ic'] : '' ) . ( $r['company_dic'] ? ', DIČ ' . $r['company_dic'] : '' ) : '',
					$r['email'],
					$r['phone'],
				)
			);
			foreach ( $cust as $c ) {
				$pdf->SetX( 18 );
				$pdf->MultiCell( 80, 4.6, $c, 0, 'L', false, 1 );
			}
			$y_left = $pdf->GetY();

			$is_mat = 'material' === ( $ev['type'] ?? '' );
			self::label( $pdf, 104, $top, $is_mat ? __( 'Objednávka', 'reserveandpay' ) : __( 'Rezervace', 'reserveandpay' ) );
			self::font( $pdf, 'worksansb', 10, self::C_INK );
			$pdf->SetXY( 104, $top + 5 );
			$pdf->MultiCell( 88, 5, $ev['title'], 0, 'L', false, 1 );
			self::font( $pdf, 'worksans', 9, self::C_SOFT );
			$rows = array_filter(
				array(
					Resandpay_Events::format_date( $ev ),
					trim( $ev['location'] . ( $ev['location_addr'] ? ', ' . $ev['location_addr'] : '' ), ', ' ),
					/* translators: %s: kód akce */
					$ev['code'] ? sprintf( $is_mat ? /* translators: %s: kód materiálu */ __( 'Kód materiálu %s', 'reserveandpay' ) : __( 'Kód akce %s', 'reserveandpay' ), $ev['code'] ) : '',
					$is_mat ? ( $ev['kind_label'] ?? '' ) : '',
				)
			);
			foreach ( $rows as $c ) {
				$pdf->SetX( 104 );
				$pdf->MultiCell( 88, 4.6, $c, 0, 'L', false, 1 );
			}
			$pdf->SetY( max( $y_left, $pdf->GetY() ) + 7 );

			// Platební údaje.
			$paid  = Resandpay_Reservations::PAID === $r['status'];
			$spd   = $paid || Resandpay_Reservations::amount_now( $r ) <= 0 ? '' : Resandpay_QR::spd( $r );
			$spd2  = $paid ? '' : Resandpay_QR::spd_full( $r );
			Resandpay_Mailer::$discount_keys = array();
			if ( $paid ) {
				$items = array(
					__( 'Částka', 'reserveandpay' )            => Resandpay_Events::format_price( $r['amount'], $r['currency'] ),
					__( 'Variabilní symbol', 'reserveandpay' ) => $r['vs'],
					__( 'Číslo účtu', 'reserveandpay' )        => $s['bank_account'],
					__( 'IBAN', 'reserveandpay' )              => $s['iban'],
					__( 'Zaplaceno', 'reserveandpay' )         => $r['paid_at'] ? wp_date( 'j. n. Y', Resandpay_Events::ts( $r['paid_at'] ) ) : '',
				);
			} else {
				$items = Resandpay_Mailer::payment_rows( $r );
			}
			$items = array_filter( $items, fn( $v ) => '' !== (string) $v );
			$rows_h = 13;
			$i      = 0;
			foreach ( $items as $k => $v ) {
				$rows_h += ( $i < 2 ? 9 : ( mb_strlen( (string) $v ) > 44 ? 10 : 6.5 ) ) + ( mb_strlen( (string) $k ) > 22 ? 2.6 : 0 );
				++$i;
			}
			$top_h = max( 62, $rows_h + 3 );
			$box_h = $top_h + ( $spd2 ? 34 : 0 );
			if ( $pdf->GetY() + $box_h > 270 ) {
				$pdf->AddPage();
			}
			$box_y = $pdf->GetY();
			$pdf->SetFillColor( ...self::C_BOX );
			$pdf->Rect( 18, $box_y, $w, $box_h, 'F' );
			self::label( $pdf, 24, $box_y + 6, $paid ? __( 'Uhrazeno', 'reserveandpay' ) : __( 'Platební údaje', 'reserveandpay' ) );
			$y = $box_y + 13;
			$i = 0;
			foreach ( $items as $k => $v ) {
				$big  = $i < 2;
				$disc = in_array( $k, Resandpay_Mailer::$discount_keys, true ); // Sleva zlatě (zlatohnědá).
				++$i;
				self::font( $pdf, 'worksans', 8.5, $disc ? self::C_ACCENT : self::C_SOFT );
				$pdf->SetXY( 24, $y + ( $big ? 1.2 : 0 ) );
				if ( $pdf->GetStringWidth( $k ) > 33 ) {
					// Dlouhý popis (např. název slevy) do dvou řádků, aby nepřekrýval hodnotu.
					$pdf->MultiCell( 33, 3.6, $k, 0, 'L' );
					$y += 2.6;
				} else {
					$pdf->Cell( 34, 5, $k, 0, 0, 'L' );
				}
				if ( ! $big && mb_strlen( (string) $v ) > 44 ) {
					self::font( $pdf, $disc ? 'worksansb' : 'worksans', 8.5, $disc ? self::C_ACCENT : self::C_INK );
					$pdf->SetXY( 58, $y + 0.4 );
					$pdf->MultiCell( 78, 4.2, (string) $v, 0, 'L' );
					$y += 10;
					continue;
				}
				self::font( $pdf, $big ? 'fraunces_sb' : ( $disc ? 'worksansb' : 'worksans' ), $big ? 14 : 9.5, $big || $disc ? self::C_ACCENT : self::C_INK );
				$pdf->SetXY( 58, $y );
				$pdf->Cell( 78, $big ? 7 : 5, (string) $v, 0, 0, 'L' );
				$y += $big ? 9 : 6.5;
			}
			$qr_style = array(
				'border'  => false,
				'padding' => 0,
				'fgcolor' => array( 0, 0, 0 ),
				'bgcolor' => array( 255, 255, 255 ),
			);
			if ( $spd ) {
				$pdf->write2DBarcode( $spd, 'QRCODE,M', 140, $box_y + 6, 46, 46, $qr_style, 'N' );
				self::font( $pdf, 'worksansb', 8, self::C_PLUM );
				$pdf->SetXY( 140, $box_y + 53 );
				$pdf->Cell( 46, 4, self::upper( Resandpay_Reservations::in_part1( $r ) ? __( 'QR platba – 1. část', 'reserveandpay' ) : __( 'QR platba', 'reserveandpay' ) ), 0, 0, 'C' );
			}
			if ( $spd2 ) {
				$y2 = $box_y + $top_h;
				$pdf->SetDrawColor( ...self::C_LINE );
				$pdf->Line( 24, $y2, 18 + $w - 6, $y2 );
				$pdf->write2DBarcode( $spd2, 'QRCODE,M', 24, $y2 + 4, 26, 26, $qr_style, 'N' );
				self::font( $pdf, 'fraunces_sb', 11, self::C_INK );
				$pdf->SetXY( 56, $y2 + 9 );
				$pdf->Cell( 120, 6, __( 'Nebo zaplaťte vše najednou', 'reserveandpay' ), 0, 0, 'L' );
				self::font( $pdf, 'worksans', 9, self::C_SOFT );
				$pdf->SetXY( 56, $y2 + 16 );
				/* translators: 1: celá částka, 2: splatnost */
				$pdf->Cell( 120, 5, sprintf( __( '%1$s do %2$s – stejný variabilní symbol', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ), 0, 0, 'L' );
			}
			$pdf->SetY( $box_y + $box_h + 7 );

			// Poznámky.
			self::font( $pdf, 'worksans', 9.5, self::C_INK );
			foreach ( self::notes( $r, $ev, $s ) as $note ) {
				$pdf->MultiCell( $w, 5, $note, 0, 'L', false, 1 );
				$pdf->Ln( 1.5 );
			}

			// Patička.
			self::rule( $pdf, 270 );
			self::font( $pdf, 'worksans', 8, self::C_SOFT );
			$pdf->SetXY( 18, 273 );
			$pdf->MultiCell( $w, 4, trim( $s['pdf_footer'] . ( $contact ? ' · ' . implode( ' · ', $contact ) : '' ), ' ·' ), 0, 'C', false, 1 );
			return $pdf->Output( '', 'S' );
		} catch ( Exception $e ) {
			error_log( 'RAP PDF: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return '';
		}
	}

	/**
	 * PDF poukaz se slevovým kupónem (A5 na šířku) dle grafického manuálu v1:
	 * krémový podklad, tenké linky, motiv soustředných kružnic, decentní řádkové logo
	 * (lotos z vlny), dechová linka s mottem a zřetelná adresa webu. QR kód kupónu je
	 * ve zlatohnědé, aby se nepletl s platebním QR kódem. Logo, motto a web jsou v nastavení.
	 *
	 * @param array $coupons Jeden nebo více kupónů (každý na vlastní stránce).
	 */
	public static function voucher( array $coupons ) {
		self::load();
		$s       = Resandpay_Settings::all();
		$cream   = array( 250, 246, 239 );
		$bg_alt  = array( 240, 232, 218 );
		$gold    = array( 184, 134, 46 );
		$web     = preg_replace( '#^(https?://)?(www\.)?#i', '', rtrim( (string) ( $s['contact_web'] ?: wp_parse_url( home_url(), PHP_URL_HOST ) ), '/' ) );
		$motto   = trim( (string) $s['brand_motto'] );
		try {
			$pdf = new TCPDF( 'L', 'mm', 'A5', true, 'UTF-8', false );
			$pdf->SetCreator( 'ReserveAndPay' );
			$pdf->SetAuthor( $s['company_name'] );
			$pdf->SetTitle( __( 'Slevový kupón', 'reserveandpay' ) );
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetMargins( 0, 0, 0 );
			$pdf->SetAutoPageBreak( false );
			$pdf->setCellPaddings( 0, 0, 0, 0 );
			foreach ( $coupons as $c ) {
				$pdf->AddPage();
				// Podklad.
				$pdf->SetFillColor( ...$cream );
				$pdf->Rect( 0, 0, 210, 148, 'F' );
				// Motiv: soustředné kružnice (jako podklad webu) – jeden motiv, přetéká přes okraj, tlumeně.
				$pdf->StartTransform();
				$pdf->Rect( 7, 7, 196, 134, 'CNZ' );
				$pdf->SetAlpha( 0.28 );
				$pdf->SetDrawColor( ...$gold );
				$pdf->SetLineWidth( 0.3 );
				foreach ( array( 16, 32, 48, 64, 80, 96 ) as $rad ) {
					$pdf->Circle( 167, 64, $rad, 0, 360, 'D' );
				}
				$pdf->SetAlpha( 1 );
				$pdf->StopTransform();
				// Tenký rámeček.
				$pdf->SetDrawColor( 214, 195, 160 );
				$pdf->SetLineWidth( 0.35 );
				$pdf->Rect( 7, 7, 196, 134, 'D' );
				// Logo – decentně, stejné jako v ostatních výstupech (Resandpay_Brand).
				Resandpay_Brand::pdf( $pdf, 17, 14.2, 7.6, 70 );
				// Eyebrow a výše slevy.
				self::font( $pdf, 'worksansb', 8, self::C_PLUM );
				$pdf->setFontSpacing( 0.32 );
				$pdf->SetXY( 17, 37 );
				$pdf->Cell( 110, 5, self::upper( __( 'Slevový kupón', 'reserveandpay' ) ), 0, 0, 'L' );
				$pdf->setFontSpacing( 0 );
				self::font( $pdf, 'fraunces_md', 42, self::C_ACCENT );
				$pdf->SetXY( 16, 43 );
				$pdf->Cell( 112, 18, '−' . Resandpay_Discounts::value_label( $c['mode'], $c['value'] ), 0, 0, 'L' );
				self::font( $pdf, 'fraunces_i', 13, self::C_INK );
				/* translators: %s: rozsah */
				$pdf->SetXY( 17, 63 );
				$pdf->MultiCell( 108, 5.6, sprintf( __( 'platí pro: %s', 'reserveandpay' ), Resandpay_Discounts::scope_label( $c ) ), 0, 'L', false, 1, 17 );
				$valid = array();
				if ( $c['valid_from'] ) {
					/* translators: %s: datum */
					$valid[] = sprintf( __( 'od %s', 'reserveandpay' ), wp_date( 'j. n. Y', strtotime( $c['valid_from'] . ' 12:00' ) ) );
				}
				if ( $c['valid_to'] ) {
					/* translators: %s: datum */
					$valid[] = sprintf( __( 'do %s', 'reserveandpay' ), wp_date( 'j. n. Y', strtotime( $c['valid_to'] . ' 12:00' ) ) );
				}
				if ( $valid ) {
					self::font( $pdf, 'worksans', 9, self::C_SOFT );
					/* translators: %s: platnost */
					$pdf->MultiCell( 108, 5, sprintf( __( 'Platnost %s', 'reserveandpay' ), implode( ' ', $valid ) ), 0, 'L', false, 1, 17 );
				}
				// Kód kupónu.
				$pdf->SetFillColor( ...$bg_alt );
				$pdf->SetDrawColor( 214, 195, 160 );
				$pdf->SetLineWidth( 0.3 );
				$pdf->Rect( 17, 84, 100, 18, 'DF' );
				self::font( $pdf, 'worksansb', 6.8, self::C_PLUM );
				$pdf->setFontSpacing( 0.25 );
				$pdf->SetXY( 21, 86.6 );
				$pdf->Cell( 92, 3.5, self::upper( __( 'Kód kupónu', 'reserveandpay' ) ), 0, 0, 'L' );
				$pdf->setFontSpacing( 0.4 );
				self::font( $pdf, 'fraunces_md', 16, self::C_INK );
				$pdf->SetXY( 21, 91 );
				$pdf->Cell( 92, 8, $c['code'], 0, 0, 'L' );
				$pdf->setFontSpacing( 0 );
				self::font( $pdf, 'worksans', 8.3, self::C_SOFT );
				$pdf->SetXY( 17, 105 );
				/* translators: %s: web */
				$pdf->MultiCell( 104, 4.3, sprintf( __( 'Naskenujte QR kód, nebo zadejte kód při rezervaci na %s v poli „Slevový kupón“. Kupón nelze kombinovat s jinou slevou.', 'reserveandpay' ), $web ), 0, 'L', false, 1, 17 );
				// Zlatohnědý QR kód (moduly gold-deep na krémové) s popiskem – odlišený od platebních QR.
				$pdf->SetFillColor( ...$cream );
				$pdf->Rect( 140, 37, 54, 54, 'F' );
				$pdf->SetDrawColor( ...$gold );
				$pdf->SetLineWidth( 0.5 );
				$pdf->Rect( 140, 37, 54, 54, 'D' );
				$pdf->write2DBarcode(
					Resandpay_Discounts::coupon_url( $c ),
					'QRCODE,Q',
					144,
					41,
					46,
					46,
					array(
						'border'  => false,
						'padding' => 0,
						'fgcolor' => self::C_ACCENT,
						'bgcolor' => $cream,
					),
					'N'
				);
				self::font( $pdf, 'worksansb', 7, self::C_ACCENT );
				$pdf->setFontSpacing( 0.25 );
				$pdf->SetXY( 140, 93 );
				$pdf->Cell( 54, 4, self::upper( __( 'Slevový kupón', 'reserveandpay' ) ), 0, 2, 'C' );
				$pdf->setFontSpacing( 0 );
				self::font( $pdf, 'worksans', 6.8, self::C_SOFT );
				$pdf->Cell( 54, 3.6, __( 'není platební QR kód', 'reserveandpay' ), 0, 0, 'C' );
				// Adresa webu – zřetelně.
				self::font( $pdf, 'fraunces_md', 15, self::C_ACCENT );
				$pdf->SetXY( 140, 104 );
				$pdf->Cell( 54, 7, $web, 0, 0, 'C' );
				// Dechová linka a motto (podpis značky).
				$pdf->ImageSVG( '@<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 28" fill="none"><path fill="none" d="M2 14 C30 2 50 26 80 14 S130 2 160 14 S200 26 218 14" stroke="#8C6423" stroke-width="2" stroke-linecap="round"/></svg>', 83, 119, 44, 5.6, '', '', '', 0, false );
				if ( $motto ) {
					self::font( $pdf, 'worksansb', 7, self::C_SOFT );
					$pdf->setFontSpacing( 0.42 );
					$pdf->SetXY( 7, 127.5 );
					$pdf->Cell( 196, 4, self::upper( $motto ), 0, 0, 'C' );
					$pdf->setFontSpacing( 0 );
				}
			}
			return $pdf->Output( '', 'S' );
		} catch ( Exception $e ) {
			error_log( 'RAP PDF poukaz: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return '';
		}
	}

	/** Texty pod platebními údaji podle situace rezervace. */
	public static function notes( array $r, array $ev, array $s ) {
		$out = array();
		if ( Resandpay_Reservations::PAID === $r['status'] ) {
			$out[] = __( 'Platba byla přijata, rezervace je potvrzena. Děkujeme.', 'reserveandpay' );
		} elseif ( 'cash' === ( $r['pay_choice'] ?? '' ) ) {
			/* translators: %s: částka */
			$out[] = sprintf( __( 'Zvolili jste platbu hotově na místě (%s). Pokud Vám to vyhovuje, můžete pohodlně zaplatit předem převodem – stačí naskenovat QR kód v bankovní aplikaci.', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ) );
		} else {
			$out[] = __( 'Platbu prosím proveďte převodem s uvedeným variabilním symbolem – podle něj ji přiřadíme k Vaší rezervaci. Nejrychlejší je naskenovat QR kód v bankovní aplikaci. Každé místo má vlastní variabilní symbol.', 'reserveandpay' );
			if ( Resandpay_Reservations::is_split( $r ) && (float) $r['amount_paid'] <= 0 ) {
				$out[] = __( 'Platba je rozdělená na 1. část a doplatek – obojí posílejte se stejným variabilním symbolem. Můžete také zaplatit vše najednou do splatnosti 1. části (druhý QR kód).', 'reserveandpay' );
			} elseif ( (float) $r['amount_paid'] > 0 ) {
				/* translators: 1: zaplaceno, 2: zbývá */
				$out[] = sprintf( __( 'Evidujeme platbu %1$s. Zbývá zaplatit %2$s – se stejným variabilním symbolem.', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount_paid'], $r['currency'] ), Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ) );
			}
			if ( ! empty( $r['last_minute'] ) ) {
				$out[] = __( 'Rezervujete krátce před začátkem. Pokud platba převodem nestihne dorazit, můžete zaplatit na místě.', 'reserveandpay' );
			}
		}
		$terms = (int) $s['terms_page'];
		if ( $terms && 'publish' === get_post_status( $terms ) ) {
			/* translators: %s: URL */
			$out[] = sprintf( __( 'Obchodní a stornopodmínky: %s', 'reserveandpay' ), get_permalink( $terms ) );
		}
		return apply_filters( 'resandpay_pdf_notes', $out, $r, $ev );
	}

	private static function font( TCPDF $pdf, $family, $size, array $rgb ) {
		$pdf->SetFont( $family, '', $size );
		$pdf->SetTextColor( ...$rgb );
	}

	private static function label( TCPDF $pdf, $x, $y, $text ) {
		self::font( $pdf, 'worksansb', 7.5, self::C_PLUM );
		$pdf->SetXY( $x, $y );
		$pdf->Cell( 80, 4, self::upper( $text ), 0, 0, 'L', false, '', 0, false, 'T', 'T' );
	}

	private static function rule( TCPDF $pdf, $y ) {
		$pdf->SetDrawColor( ...self::C_LINE );
		$pdf->SetLineWidth( 0.25 );
		$pdf->Line( 18, $y, 192, $y );
	}

	private static function upper( $t ) {
		return mb_strtoupper( (string) $t );
	}
}
