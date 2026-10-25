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

	/** Cesta k logu webu (Vzhled → Přizpůsobit → Identita webu), jen PNG/JPG. */
	private static function logo_path() {
		$id   = (int) get_theme_mod( 'custom_logo' );
		$file = $id ? get_attached_file( $id ) : '';
		return $file && is_readable( $file ) && preg_match( '/\.(png|jpe?g)$/i', $file ) ? $file : '';
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

			// Hlavička: logo vlevo, nadpis vpravo.
			$logo = self::logo_path();
			if ( $logo ) {
				try {
					$pdf->Image( $logo, 18, 14, 0, 16, '', '', '', true, 300, '', false, false, 0, 'LT' );
				} catch ( Exception $e ) {
					$logo = '';
				}
			}
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

			self::label( $pdf, 104, $top, __( 'Rezervace', 'reserveandpay' ) );
			self::font( $pdf, 'worksansb', 10, self::C_INK );
			$pdf->SetXY( 104, $top + 5 );
			$pdf->MultiCell( 88, 5, $ev['title'], 0, 'L', false, 1 );
			self::font( $pdf, 'worksans', 9, self::C_SOFT );
			$rows = array_filter(
				array(
					Resandpay_Events::format_date( $ev ),
					trim( $ev['location'] . ( $ev['location_addr'] ? ', ' . $ev['location_addr'] : '' ), ', ' ),
					/* translators: %s: kód akce */
					$ev['code'] ? sprintf( __( 'Kód akce %s', 'reserveandpay' ), $ev['code'] ) : '',
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
				$rows_h += $i < 2 ? 9 : ( mb_strlen( (string) $v ) > 44 ? 10 : 6.5 );
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
				$big = $i < 2;
				++$i;
				self::font( $pdf, 'worksans', 8.5, self::C_SOFT );
				$pdf->SetXY( 24, $y + ( $big ? 1.2 : 0 ) );
				$pdf->Cell( 34, 5, $k, 0, 0, 'L' );
				if ( ! $big && mb_strlen( (string) $v ) > 44 ) {
					self::font( $pdf, 'worksans', 8.5, self::C_INK );
					$pdf->SetXY( 58, $y + 0.4 );
					$pdf->MultiCell( 78, 4.2, (string) $v, 0, 'L' );
					$y += 10;
					continue;
				}
				self::font( $pdf, $big ? 'fraunces_sb' : 'worksans', $big ? 14 : 9.5, $big ? self::C_ACCENT : self::C_INK );
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
	 * PDF poukaz se slevovým kupónem (A5 na šířku) v grafice webu – SWD kap. 3.10.
	 * QR kód kupónu je ve zlaté barvě, aby se nepletl s platebním QR kódem.
	 *
	 * @param array $coupons Jeden nebo více kupónů (každý na vlastní stránce).
	 */
	public static function voucher( array $coupons ) {
		self::load();
		$s = Resandpay_Settings::all();
		try {
			$pdf = new TCPDF( 'L', 'mm', 'A5', true, 'UTF-8', false );
			$pdf->SetCreator( 'ReserveAndPay' );
			$pdf->SetAuthor( $s['company_name'] );
			$pdf->SetTitle( __( 'Slevový kupón', 'reserveandpay' ) );
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetMargins( 14, 12, 14 );
			$pdf->SetAutoPageBreak( false );
			$pdf->setCellPaddings( 0, 0, 0, 0 );
			$gold = array( 184, 134, 46 );
			$logo = self::logo_path();
			foreach ( $coupons as $c ) {
				$pdf->AddPage();
				$pdf->SetFillColor( 251, 247, 239 );
				$pdf->Rect( 0, 0, 210, 148, 'F' );
				$pdf->SetDrawColor( ...$gold );
				$pdf->SetLineWidth( 1.2 );
				$pdf->Rect( 7, 7, 196, 134, 'D' );
				$pdf->SetLineWidth( 0.3 );
				$pdf->Rect( 9.5, 9.5, 191, 129, 'D' );
				if ( $logo ) {
					try {
						$pdf->Image( $logo, 16, 15, 0, 13, '', '', '', true, 300, '', false, false, 0, 'LT' );
					} catch ( Exception $e ) {
						$logo = '';
					}
				}
				if ( ! $logo ) {
					self::font( $pdf, 'fraunces_sb', 13, self::C_ACCENT );
					$pdf->SetXY( 16, 16 );
					$pdf->Cell( 110, 6, $s['brand_name'] ?: $s['company_name'], 0, 0, 'L' );
				}
				self::font( $pdf, 'worksansb', 8.5, self::C_PLUM );
				$pdf->SetXY( 16, 34 );
				$pdf->Cell( 110, 5, self::upper( __( 'Slevový kupón', 'reserveandpay' ) ), 0, 2, 'L' );
				self::font( $pdf, 'fraunces_sb', 40, self::C_ACCENT );
				$pdf->Cell( 110, 18, '−' . Resandpay_Discounts::value_label( $c['mode'], $c['value'] ), 0, 2, 'L' );
				self::font( $pdf, 'worksans', 10, self::C_INK );
				/* translators: %s: rozsah */
				$pdf->MultiCell( 112, 5, sprintf( __( 'Sleva na: %s', 'reserveandpay' ), Resandpay_Discounts::scope_label( $c ) ), 0, 'L', false, 1, 16 );
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
					self::font( $pdf, 'worksans', 9.5, self::C_SOFT );
					/* translators: %s: platnost */
					$pdf->MultiCell( 112, 5, sprintf( __( 'Platnost %s', 'reserveandpay' ), implode( ' ', $valid ) ), 0, 'L', false, 1, 16 );
				}
				// Kód.
				$pdf->SetFillColor( 255, 255, 255 );
				$pdf->SetDrawColor( ...self::C_LINE );
				$pdf->Rect( 16, 92, 104, 17, 'DF' );
				self::font( $pdf, 'worksansb', 7, self::C_PLUM );
				$pdf->SetXY( 20, 94.5 );
				$pdf->Cell( 96, 3.5, self::upper( __( 'Kód kupónu', 'reserveandpay' ) ), 0, 2, 'L' );
				self::font( $pdf, 'fraunces_sb', 17, self::C_INK );
				$pdf->Cell( 96, 8, $c['code'], 0, 0, 'L' );
				self::font( $pdf, 'worksans', 8.5, self::C_SOFT );
				$pdf->SetXY( 16, 113 );
				$pdf->MultiCell( 112, 4.4, __( 'Naskenujte QR kód – kupón se v rezervaci vyplní sám. Nebo zadejte kód do pole „Slevový kupón“ při rezervaci na webu. Kupón nelze kombinovat s jinou slevou.', 'reserveandpay' ), 0, 'L', false, 1 );
				// Zlatý QR kód s rámečkem a popiskem.
				$pdf->SetDrawColor( ...$gold );
				$pdf->SetLineWidth( 0.8 );
				$pdf->SetFillColor( 251, 247, 239 );
				$pdf->Rect( 136, 34, 58, 70, 'DF' );
				$pdf->write2DBarcode(
					Resandpay_Discounts::coupon_url( $c ),
					'QRCODE,Q',
					141,
					39,
					48,
					48,
					array(
						'border'  => false,
						'padding' => 0,
						'fgcolor' => self::C_ACCENT,
						'bgcolor' => array( 251, 247, 239 ),
					),
					'N'
				);
				self::font( $pdf, 'worksansb', 8, $gold );
				$pdf->SetXY( 136, 92 );
				$pdf->Cell( 58, 5, self::upper( __( 'Slevový kupón', 'reserveandpay' ) ), 0, 2, 'C' );
				self::font( $pdf, 'worksans', 7, self::C_SOFT );
				$pdf->Cell( 58, 4, __( 'není platební QR kód', 'reserveandpay' ), 0, 0, 'C' );
				// Patička.
				$contact = array_filter( array( $s['contact_web'], $s['contact_email'] ) );
				self::font( $pdf, 'worksans', 8, self::C_SOFT );
				$pdf->SetXY( 16, 129 );
				$pdf->Cell( 178, 4, trim( ( $s['brand_name'] ?: $s['company_name'] ) . ( $contact ? ' · ' . implode( ' · ', $contact ) : '' ), ' ·' ), 0, 0, 'L' );
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
