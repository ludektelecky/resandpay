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
			$spd   = $paid ? '' : Resandpay_QR::spd( $r );
			$box_y = $pdf->GetY();
			$box_h = 62;
			$pdf->SetFillColor( ...self::C_BOX );
			$pdf->Rect( 18, $box_y, $w, $box_h, 'F' );
			self::label( $pdf, 24, $box_y + 6, $paid ? __( 'Uhrazeno', 'reserveandpay' ) : __( 'Platební údaje', 'reserveandpay' ) );
			$items = array(
				__( 'Částka', 'reserveandpay' )            => Resandpay_Events::format_price( $r['amount'], $r['currency'] ),
				__( 'Variabilní symbol', 'reserveandpay' ) => $r['vs'],
				__( 'Číslo účtu', 'reserveandpay' )        => $s['bank_account'],
				__( 'IBAN', 'reserveandpay' )              => $s['iban'],
			);
			if ( $paid ) {
				$items[ __( 'Zaplaceno', 'reserveandpay' ) ] = $r['paid_at'] ? wp_date( 'j. n. Y', Resandpay_Events::ts( $r['paid_at'] ) ) : '';
			} elseif ( 'cash' !== ( $r['pay_choice'] ?? '' ) && $r['due_at'] ) {
				$items[ __( 'Splatnost', 'reserveandpay' ) ] = wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) );
			}
			$y = $box_y + 13;
			foreach ( $items as $k => $v ) {
				if ( '' === (string) $v ) {
					continue;
				}
				$big = in_array( $k, array( __( 'Částka', 'reserveandpay' ), __( 'Variabilní symbol', 'reserveandpay' ) ), true );
				self::font( $pdf, 'worksans', 8.5, self::C_SOFT );
				$pdf->SetXY( 24, $y + ( $big ? 1.2 : 0 ) );
				$pdf->Cell( 34, 5, $k, 0, 0, 'L' );
				self::font( $pdf, $big ? 'fraunces_sb' : 'worksans', $big ? 14 : 9.5, $big ? self::C_ACCENT : self::C_INK );
				$pdf->SetXY( 58, $y );
				$pdf->Cell( 66, $big ? 7 : 5, (string) $v, 0, 0, 'L' );
				$y += $big ? 9 : 6.5;
			}
			if ( $spd ) {
				$pdf->write2DBarcode(
					$spd,
					'QRCODE,M',
					140,
					$box_y + 6,
					46,
					46,
					array(
						'border'  => false,
						'padding' => 0,
						'fgcolor' => array( 0, 0, 0 ),
						'bgcolor' => array( 255, 255, 255 ),
					),
					'N'
				);
				self::font( $pdf, 'worksansb', 8, self::C_PLUM );
				$pdf->SetXY( 140, $box_y + 53 );
				$pdf->Cell( 46, 4, self::upper( __( 'QR platba', 'reserveandpay' ) ), 0, 0, 'C' );
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
