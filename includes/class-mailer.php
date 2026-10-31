<?php
defined( 'ABSPATH' ) || exit;

/**
 * E-mailové notifikace. Texty jsou šablony z nastavení se zástupnými proměnnými.
 * V testovacím režimu se všechny e-maily pluginu přesměrují na testovací adresu.
 */
class Resandpay_Mailer {

	/** @var bool Příznak, že právě odesílá plugin (pro filtr přesměrování). */
	private static $sending = false;

	/** @var string Poslední chyba odesílání (pro zobrazení v administraci). */
	public static $last_error = '';

	/** @var string Skutečná adresa po přesměrování v testovacím režimu (pro seznam odeslaných). */
	public static $sent_to = '';

	/** @var array|null Části právě odesílaného e-mailu (textová verze, QR obrázek, PDF) pro phpmailer_init. */
	private static $parts = null;

	/** @var array Části posledního sestaveného e-mailu (diagnostika a testy). */
	public static $last_parts = array();

	/** @var array Řádky platebních údajů týkající se slevy (zobrazují se zlatě – e-mail i PDF). */
	public static $discount_keys = array();

	/** Šablony, ke kterým patří platební údaje, QR kód a PDF platebního pokynu. */
	const PAYMENT_KEYS = array( 'received', 'received_cash', 'overdue', 'reminder', 'partial', 'part1', 'discount_lost' );

	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'redirect_in_test_mode' ), 99 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_parts' ) );
	}

	/** Vloží do e-mailu textovou verzi, QR kód (inline, cid:rap-qr) a PDF přílohu. */
	public static function phpmailer_parts( $phpmailer ) {
		if ( ! self::$sending ) {
			return;
		}
		// Obrázky a PDF se vkládají ze souborů (ne z řetězce): SMTP pluginy jako FluentSMTP
		// přílohy znovu přidávají podle cesty k souboru a přílohu z řetězce neumí odeslat.
		try {
			if ( false !== strpos( (string) $phpmailer->Body, 'cid:rap-logo' ) && ( $logo = Resandpay_Brand::email_image() ) ) {
				$phpmailer->addEmbeddedImage( $logo['path'], 'rap-logo', $logo['name'], 'base64', $logo['mime'] );
			}
			if ( ! self::$parts ) {
				return;
			}
			$p                  = self::$parts;
			$phpmailer->AltBody = $p['text'];
			if ( ! empty( $p['qr_png'] ) && ( $f = self::tmp_file( $p['qr_png'], 'qr-platba.png' ) ) ) {
				$phpmailer->addEmbeddedImage( $f, 'rap-qr', 'qr-platba.png', 'base64', 'image/png' );
			}
			if ( ! empty( $p['qr2_png'] ) && ( $f = self::tmp_file( $p['qr2_png'], 'qr-platba-vse.png' ) ) ) {
				$phpmailer->addEmbeddedImage( $f, 'rap-qr2', 'qr-platba-vse.png', 'base64', 'image/png' );
			}
			if ( ! empty( $p['pdf'] ) && ( $f = self::tmp_file( $p['pdf'], $p['pdf_name'] ) ) ) {
				$phpmailer->addAttachment( $f, $p['pdf_name'], 'base64', 'application/pdf' );
			}
		} catch ( Exception $e ) {
			error_log( 'RAP mail parts: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/** @var string[] Dočasné soubory příloh aktuálního e-mailu (po odeslání se smažou). */
	private static $tmp_files = array();

	/** Uloží obsah přílohy do dočasného souboru s daným názvem; vrací cestu, nebo ''. */
	private static function tmp_file( $data, $name ) {
		$dir = trailingslashit( get_temp_dir() ) . 'rap-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$file = $dir . '/' . sanitize_file_name( $name );
		if ( false === file_put_contents( $file, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
		self::$tmp_files[] = $file;
		return $file;
	}

	/** Smaže dočasné soubory příloh. */
	private static function cleanup_tmp() {
		foreach ( self::$tmp_files as $f ) {
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@rmdir( dirname( $f ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		self::$tmp_files = array();
	}

	/** Zaznamená chybu odesílání (posledních 20) – zobrazuje se v Nástrojích. */
	public static function log_failure( $error ) {
		if ( ! self::$sending ) {
			return;
		}
		self::$last_error = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
		$log              = get_option( 'resandpay_mail_log', array() );
		$data             = $error instanceof WP_Error ? $error->get_error_data() : array();
		array_unshift(
			$log,
			array(
				'time'  => current_time( 'mysql' ),
				'to'    => is_array( $data['to'] ?? null ) ? implode( ', ', $data['to'] ) : (string) ( $data['to'] ?? '' ),
				'error' => self::$last_error,
			)
		);
		update_option( 'resandpay_mail_log', array_slice( $log, 0, 20 ), false );
	}

	/** Zástupné proměnné pro rezervaci. */
	public static function placeholders( array $r ) {
		$ev = Resandpay_Events::get( $r['event_id'] ) ?: array(
			'title'    => '',
			'location' => '',
			'start'    => '',
			'end'      => '',
		);
		$s  = Resandpay_Settings::all();
		return array(
			'{name}'             => $r['customer_name'],
			'{email}'            => $r['email'],
			'{phone}'            => $r['phone'],
			'{note}'             => $r['note'],
			'{event}'            => $ev['title'],
			'{event_code}'       => $ev['code'] ?? '',
			'{date}'             => Resandpay_Events::format_date( $ev ),
			'{location}'         => trim( $ev['location'] . ( ! empty( $ev['location_addr'] ) ? ', ' . $ev['location_addr'] : '' ) ),
			'{amount}'           => Resandpay_Events::format_price( $r['amount'], $r['currency'] ),
			'{vs}'               => $r['vs'],
			'{proforma}'         => $r['proforma_no'],
			'{due_date}'         => $r['due_at'] ? wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $r['due_at'] ) ) : '',
			'{account}'          => $s['bank_account'],
			'{iban}'             => $s['iban'],
			'{company}'          => $s['company_name'] ?: get_bloginfo( 'name' ),
			'{free}'             => (string) Resandpay_Events::free_places( $r['event_id'] ),
			'{reason}'           => $r['cancel_reason'] ? "\n" . __( 'Důvod:', 'reserveandpay' ) . ' ' . $r['cancel_reason'] . "\n" : '',
			'{last_minute_note}' => ! empty( $r['last_minute'] ) && 'cash' !== ( $r['pay_choice'] ?? '' ) ? $s['tpl_lastminute_note'] : '',
			'{pay_choice}'       => Resandpay_Reservations::pay_choice_labels()[ $r['pay_choice'] ?? 'transfer' ] ?? '',
			'{total}'            => Resandpay_Events::format_price( $r['amount'], $r['currency'] ),
			'{price_full}'       => Resandpay_Events::format_price( (float) ( $r['price_full'] ?? 0 ) ?: (float) $r['amount'], $r['currency'] ),
			'{discount}'         => (float) ( $r['discount_amount'] ?? 0 ) > 0 ? Resandpay_Events::format_price( $r['discount_amount'], $r['currency'] ) : '',
			'{discount_label}'   => (string) ( $r['discount_label'] ?? '' ),
			'{coupon}'           => ( ! empty( $r['coupon_id'] ) && ( $cp = Resandpay_Discounts::get( $r['coupon_id'] ) ) ) ? $cp['code'] : '',
			'{paid}'             => Resandpay_Events::format_price( $r['amount_paid'] ?? 0, $r['currency'] ),
			'{remaining}'        => Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ),
			'{amount_now}'       => Resandpay_Events::format_price( Resandpay_Reservations::amount_now( $r ), $r['currency'] ),
			'{due_now}'          => ( $dn = Resandpay_Reservations::due_now( $r ) ) ? wp_date( 'j. n. Y', Resandpay_Events::ts( $dn ) ) : '',
			'{part1}'            => Resandpay_Events::format_price( $r['part1_amount'] ?? 0, $r['currency'] ),
			'{part1_due}'        => ! empty( $r['part1_due_at'] ) ? wp_date( 'j. n. Y', Resandpay_Events::ts( $r['part1_due_at'] ) ) : '',
			'{final_due}'        => $r['due_at'] ? wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) ) : '',
			'{last_payment}'     => isset( $r['last_payment'] ) ? Resandpay_Events::format_price( $r['last_payment'], $r['currency'] ) : '',
			'{where}'            => '' !== trim( (string) $ev['location'] ) ? __( 'Kde:', 'reserveandpay' ) . ' ' . trim( $ev['location'] . ( ! empty( $ev['location_addr'] ) ? ', ' . $ev['location_addr'] : '' ) ) : '',
			'{map_url}'          => ! empty( $ev['location_map'] ) ? __( 'Mapa:', 'reserveandpay' ) . ' ' . $ev['location_map'] : '',
			'{reminder_note}'    => (string) ( $ev['reminder_note'] ?? '' ),
			'{payment_note}'     => self::payment_note( $r ),
			'{material_url}'     => 'material' === ( $r['item_type'] ?? '' ) && get_permalink( $r['event_id'] ) ? get_permalink( $r['event_id'] ) : '',
			'{my_materials_url}' => Resandpay_Materials::my_url(),
			'{access_until}'     => 'material' === ( $r['item_type'] ?? '' ) ? Resandpay_Materials::order_until_text( $r ) : '',
			'{event_url}'        => ! empty( $r['event_id'] ) && get_permalink( $r['event_id'] ) ? get_permalink( $r['event_id'] ) : home_url( '/' ),
			'{split_note}'       => Resandpay_Reservations::is_split( $r ) ? sprintf( /* translators: 1: 1. část, 2: datum, 3: doplatek, 4: datum */ __( "\nPlatba je rozdělená: 1. část %1\$s do %2\$s, doplatek %3\$s do %4\$s. Zaplacením 1. části je Vaše místo potvrzené. Můžete také zaplatit vše najednou do splatnosti 1. části – oba QR kódy najdete níže.\n", 'reserveandpay' ), Resandpay_Events::format_price( $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['part1_due_at'] ) ), Resandpay_Events::format_price( (float) $r['amount'] - (float) $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) ) ) : '',
		);
	}

	/** Stav platby do připomínky akce: uhrazeno / zaplatit na místě / platba zatím nedorazila. */
	public static function payment_note( array $r ) {
		$left = Resandpay_Reservations::remaining( $r );
		if ( Resandpay_Reservations::PAID === ( $r['status'] ?? '' ) || $left <= 0 ) {
			return __( 'Platba je uhrazena, děkujeme.', 'reserveandpay' );
		}
		$amt = Resandpay_Events::format_price( $left, $r['currency'] );
		if ( 'cash' === ( $r['pay_choice'] ?? '' ) || Resandpay_Reservations::ONSITE === ( $r['status'] ?? '' ) ) {
			/* translators: %s: částka */
			return sprintf( __( 'Na místě prosím zaplaťte %s.', 'reserveandpay' ), $amt );
		}
		/* translators: %s: částka */
		return sprintf( __( 'Platbu %s jsme zatím neobdrželi – platební údaje a QR kód najdete níže. Pokud jste již zaplatili, považujte prosím tuto informaci za bezpředmětnou.', 'reserveandpay' ), $amt );
	}

	public static function render( $template, array $r, $key = '' ) {
		$ph = self::placeholders( $r );
		// E-maily zákazníkovi: u rozdělené nebo částečně zaplacené platby znamená {amount} a {due_date}
		// to, co se platí teď (1. část / zbytek) – i ve starších uložených textech. Správce vidí celou cenu.
		if ( 'admin' !== $key && ( Resandpay_Reservations::is_split( $r ) || (float) ( $r['amount_paid'] ?? 0 ) > 0 ) && Resandpay_Reservations::PAID !== ( $r['status'] ?? '' ) ) {
			$dn                = Resandpay_Reservations::due_now( $r );
			$ph['{amount}']    = Resandpay_Events::format_price( Resandpay_Reservations::amount_now( $r ), $r['currency'] );
			$ph['{due_date}']  = $dn ? wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $dn ) ) : '';
		}
		if ( 'admin' === $key && ( $ev_full = Resandpay_Events::get( $r['event_id'] ?? 0 ) ) ) {
			$ph['{date}'] = Resandpay_Events::format_date_full( $ev_full ); // Správce vidí čas vždy.
		}
		$ph = apply_filters( 'resandpay_mail_placeholders', $ph, $r );
		// Prázdné proměnné na samostatném řádku nenechají v textu díru.
		return preg_replace( "/\n{3,}/", "\n\n", strtr( (string) $template, $ph ) );
	}

	/**
	 * Sestaví e-mail: předmět, HTML (v barvách webu), textovou verzi, QR kód a PDF.
	 * U šablon s platbou (PAYMENT_KEYS) přidá platební údaje, QR kód a platební pokyn.
	 */
	public static function build( $key, array $r ) {
		$s       = Resandpay_Settings::all();
		$tkey    = self::template_key( $key, $r );
		$subject = self::render( $s[ 'tpl_' . $tkey . '_subject' ] ?? '', $r, $key );
		$body    = self::render( $s[ 'tpl_' . $tkey . '_body' ] ?? '', $r, $key );
		$parts   = array(
			'subject'  => $subject,
			'text'     => $body,
			'qr_png'   => '',
			'qr2_png'  => '',
			'pdf'      => '',
			'pdf_name' => '',
		);
		$payment = in_array( $key, self::PAYMENT_KEYS, true ) && Resandpay_Reservations::PAID !== $r['status'];
		if ( 'event_reminder' === $key ) {
			// Připomínka akce: platební údaje jen u nezaplaceného převodu.
			$payment = Resandpay_Reservations::PAID !== $r['status'] && Resandpay_Reservations::ONSITE !== $r['status'] && 'cash' !== ( $r['pay_choice'] ?? '' ) && Resandpay_Reservations::remaining( $r ) > 0;
		}
		$box     = '';
		if ( $payment ) {
			$rows  = self::payment_rows( $r );
			$spd   = Resandpay_QR::applicable( $r ) ? Resandpay_QR::spd( $r ) : '';
			$png   = $spd ? Resandpay_QR::png( $spd, 6 ) : '';
			$spd2  = $spd ? Resandpay_QR::spd_full( $r ) : '';
			$png2  = $spd2 ? Resandpay_QR::png( $spd2, 5 ) : '';
			$pdf   = Resandpay_PDF::render( $r );
			$url   = Resandpay_PDF::url( $r );
			$parts = array_merge(
				$parts,
				array(
					'qr_png'   => $png,
					'qr2_png'  => $png2,
					'pdf'      => $pdf,
					'pdf_name' => Resandpay_PDF::filename( $r ),
					'spd'      => $spd,
				)
			);
			$text = "\n\n" . __( 'Platební údaje', 'reserveandpay' ) . "\n";
			foreach ( $rows as $k => $v ) {
				$text .= $k . ': ' . $v . "\n";
			}
			if ( $png2 ) {
				/* translators: 1: celá částka, 2: splatnost */
				$text .= sprintf( __( 'Nebo vše najednou: %1$s do %2$s (stejný variabilní symbol)', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ) . "\n";
			}
			$parts['text'] .= $text . __( 'Platební pokyn (PDF):', 'reserveandpay' ) . ' ' . $url . "\n";
			$box            = self::html_payment_box( $rows, $png ? 'cid:rap-qr' : '', $url, 'cash' === ( $r['pay_choice'] ?? '' ), $png2 ? 'cid:rap-qr2' : '', $r );
		}
		$parts['html']    = self::html_layout( $subject, self::text_to_html( $body ), $box );
		self::$last_parts = $parts;
		return $parts;
	}

	/**
	 * Šablona e-mailu: objednávky materiálů (SWD kap. 15.1, R11) mají vlastní texty mat_* –
	 * pokud pro daný e-mail existují, jinak se použije obecná šablona.
	 */
	public static function template_key( $key, array $r ) {
		if ( 'material' === ( $r['item_type'] ?? '' ) && array_key_exists( 'tpl_mat_' . $key . '_subject', Resandpay_Settings::defaults() ) ) {
			return 'mat_' . $key;
		}
		return $key;
	}

	/** Řádky platebních údajů (stejné v HTML i textové verzi). */
	public static function payment_rows( array $r ) {
		self::$discount_keys = array();
		$s     = Resandpay_Settings::all();
		$p1    = Resandpay_Reservations::in_part1( $r );
		$label = $p1 ? __( '1. část platby', 'reserveandpay' ) : ( (float) ( $r['amount_paid'] ?? 0 ) > 0 ? __( 'Zbývá zaplatit', 'reserveandpay' ) : __( 'Částka', 'reserveandpay' ) );
		$rows  = array(
			$label                                      => Resandpay_Events::format_price( Resandpay_Reservations::amount_now( $r ), $r['currency'] ),
			__( 'Variabilní symbol', 'reserveandpay' ) => $r['vs'],
			__( 'Číslo účtu', 'reserveandpay' )        => $s['bank_account'],
			__( 'IBAN', 'reserveandpay' )              => $s['iban'],
		);
		$due = Resandpay_Reservations::due_now( $r );
		if ( 'cash' !== ( $r['pay_choice'] ?? '' ) && $due ) {
			$rows[ __( 'Splatnost', 'reserveandpay' ) ] = wp_date( 'j. n. Y', Resandpay_Events::ts( $due ) );
		}
		if ( (float) ( $r['discount_amount'] ?? 0 ) > 0 ) {
			// OP01: sleva v textu, QR kódy už s částkou po slevě.
			$rows[ __( 'Cena', 'reserveandpay' ) ] = Resandpay_Events::format_price( $r['price_full'], $r['currency'] );
			if ( Resandpay_Reservations::discount_now( $r ) > 0 ) {
				$dk                   = $r['discount_label'] ?: __( 'Sleva', 'reserveandpay' );
				$after                = __( 'Cena po slevě', 'reserveandpay' );
				self::$discount_keys = array( $dk, $after );
				$rows[ $dk ]          = '−' . Resandpay_Events::format_price( $r['discount_amount'], $r['currency'] ) . ( Resandpay_Reservations::early_active( $r ) ? ' ' . __( '(platí při úhradě ve splatnosti)', 'reserveandpay' ) : '' );
				$rows[ $after ]       = Resandpay_Events::format_price( $r['amount'], $r['currency'] );
			} else {
				/* translators: %s: popis slevy */
				self::$discount_keys = array( __( 'Sleva', 'reserveandpay' ) );
				$rows[ __( 'Sleva', 'reserveandpay' ) ] = sprintf( __( '%s zanikla (platba po splatnosti)', 'reserveandpay' ), $r['discount_label'] );
			}
		}
		if ( Resandpay_Reservations::is_split( $r ) ) {
			/* translators: 1: 1. část, 2: datum, 3: doplatek, 4: datum */
			$rows[ __( 'Rozpis', 'reserveandpay' ) ] = sprintf( __( '1. část %1$s do %2$s · doplatek %3$s do %4$s', 'reserveandpay' ), Resandpay_Events::format_price( $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['part1_due_at'] ) ), Resandpay_Events::format_price( (float) $r['amount'] - (float) $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) ) );
		}
		if ( (float) ( $r['amount_paid'] ?? 0 ) > 0 ) {
			/* translators: 1: zaplaceno, 2: celkem */
			$rows[ __( 'Již zaplaceno', 'reserveandpay' ) ] = sprintf( __( '%1$s z %2$s', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount_paid'], $r['currency'] ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ) );
		}
		return array_filter( $rows, fn( $v ) => '' !== (string) $v );
	}

	public static function text_to_html( $text ) {
		$paras = preg_split( '/\n\s*\n/', trim( (string) $text ) );
		$out   = '';
		foreach ( $paras as $p ) {
			$out .= '<p style="margin:0 0 14px;">' . nl2br( esc_html( trim( $p ) ) ) . '</p>';
		}
		return make_clickable( $out );
	}

	private static function html_payment_box( array $rows, $qr_src, $pdf_url, $cash, $qr2_src = '', array $r = array() ) {
		$c   = self::colors();
		$out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['box'] . ';margin:8px 0 18px;"><tr><td style="padding:20px 22px;">';
		$out .= '<p style="margin:0 0 10px;font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:bold;color:' . $c['plum'] . ';">' . esc_html( $cash ? __( 'Platba předem převodem (nepovinné)', 'reserveandpay' ) : __( 'Platební údaje', 'reserveandpay' ) ) . '</p>';
		$out .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td valign="top" style="padding-right:12px;"><table role="presentation" cellpadding="0" cellspacing="0">';
		$i = 0;
		foreach ( $rows as $k => $v ) {
			$big  = $i < 2;
			$disc = in_array( $k, self::$discount_keys, true );
			$out .= '<tr><td style="padding:3px 14px 3px 0;font-size:13px;color:' . ( $disc ? $c['accent'] : $c['soft'] ) . ';white-space:nowrap;">' . esc_html( $k ) . '</td><td style="padding:3px 0;' . ( $big ? 'font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:bold;color:' . $c['accent'] . ';' : 'font-size:14px;color:' . ( $disc ? $c['accent'] . ';font-weight:bold' : $c['ink'] ) . ';' ) . '">' . esc_html( $v ) . '</td></tr>';
			++$i;
		}
		$out .= '</table></td>';
		if ( $qr_src ) {
			$out .= '<td valign="top" align="center" width="170" style="width:170px;"><img src="' . esc_attr( $qr_src ) . '" width="160" height="160" alt="' . esc_attr__( 'QR platba', 'reserveandpay' ) . '" style="display:block;width:160px;height:160px;border:0;background:#fff;"><p style="margin:6px 0 0;font-size:11px;color:' . $c['soft'] . ';">' . esc_html__( 'Naskenujte v bankovní aplikaci', 'reserveandpay' ) . '</p></td>';
		}
		$out .= '</tr></table>';
		if ( $qr2_src ) {
			/* translators: 1: celá částka, 2: splatnost */
			$out .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:14px;border-top:1px solid ' . $c['line'] . ';"><tr><td valign="middle" style="padding:12px 14px 0 0;"><img src="' . esc_attr( $qr2_src ) . '" width="110" height="110" alt="' . esc_attr__( 'QR platba – vše najednou', 'reserveandpay' ) . '" style="display:block;width:110px;height:110px;border:0;background:#fff;"></td><td valign="middle" style="padding-top:12px;font-size:13px;color:' . $c['ink'] . ';"><strong>' . esc_html__( 'Nebo zaplaťte vše najednou', 'reserveandpay' ) . '</strong><br>' . esc_html( sprintf( __( '%1$s do %2$s – stejný variabilní symbol', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ) ) . '</td></tr></table>';
		}
		$out .= '<p style="margin:16px 0 0;"><a href="' . esc_url( $pdf_url ) . '" style="display:inline-block;background:' . $c['accent'] . ';color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:2px;font-weight:bold;font-size:14px;">' . esc_html__( 'Stáhnout platební pokyn (PDF) →', 'reserveandpay' ) . '</a></p>';
		return $out . '</td></tr></table>';
	}

	private static function colors() {
		return apply_filters(
			'resandpay_mail_colors',
			array(
				'bg'     => '#F0E8DA',
				'card'   => '#FAF6EF',
				'box'    => '#F0E8DA',
				'ink'    => '#2E2A22',
				'soft'   => '#5C5E52',
				'accent' => '#8C6423',
				'plum'   => '#6B3F4B',
				'line'   => '#D6C3A0',
			)
		);
	}

	/** Rámec e-mailu v barvách webu (tabulky a inline styly kvůli e-mailovým klientům). */
	public static function html_layout( $title, $body_html, $box_html = '' ) {
		$s       = Resandpay_Settings::all();
		$c       = self::colors();
		$contact = implode( ' · ', array_filter( array( $s['company_name'], $s['contact_web'], $s['contact_email'] ) ) );
		return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $c['bg'] . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['bg'] . ';"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:' . $c['card'] . ';border-top:3px solid ' . $c['accent'] . ';">'
			. '<tr><td style="padding:26px 30px 8px;">' . Resandpay_Brand::email_header( $c ) . '</td></tr>'
			. '<tr><td style="padding:10px 30px 8px;font-family:\'Work Sans\',Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:' . $c['ink'] . ';">' . $body_html . $box_html . '</td></tr>'
			. '<tr><td style="padding:14px 30px 24px;border-top:1px solid ' . $c['line'] . ';font-family:Arial,Helvetica,sans-serif;font-size:12px;color:' . $c['soft'] . ';">' . esc_html( $contact ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Odeslání hotového HTML e-mailu (upozornění pro správkyni). */
	public static function send_raw( $to, $subject, $html, $text = '' ) {
		$s       = Resandpay_Settings::all();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $s['mail_from_email'] && is_email( $s['mail_from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( $s['mail_from_name'] ), $s['mail_from_email'] );
		}
		self::$sending    = true;
		self::$parts      = array(
			'text'   => $text,
			'qr_png' => '',
			'pdf'    => '',
		);
		self::$last_error = '';
		$ok               = wp_mail( $to, $subject, $html, $headers );
		self::$sending    = false;
		self::$parts      = null;
		self::cleanup_tmp();
		return $ok;
	}

	public static function send_template( $to, $key, array $r ) {
		$s       = Resandpay_Settings::all();
		$parts   = self::build( $key, $r );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $s['mail_from_email'] && is_email( $s['mail_from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( $s['mail_from_name'] ), $s['mail_from_email'] );
		}
		self::$sending    = true;
		self::$parts      = $parts;
		self::$last_error = '';
		self::$sent_to    = '';
		$ok               = wp_mail( $to, $parts['subject'], $parts['html'], $headers );
		self::$sending    = false;
		self::$parts      = null;
		self::cleanup_tmp();
		self::record( $key, $to, $parts['subject'], $ok, (int) ( $r['id'] ?? 0 ) );
		do_action( 'resandpay_mail_sent', $key, $to, $r, $ok );
		return $ok;
	}

	/** Odeslání zkušebního e-mailu z Nástrojů (stejné hlavičky jako ostré e-maily). */
	public static function send_test( $to ) {
		$s       = Resandpay_Settings::all();
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $s['mail_from_email'] && is_email( $s['mail_from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( $s['mail_from_name'] ), $s['mail_from_email'] );
		}
		self::$sending    = true;
		self::$last_error = '';
		self::$sent_to    = '';
		$subject          = 'ReserveAndPay – zkušební e-mail';
		$ok               = wp_mail( $to, $subject, "Toto je zkušební e-mail pluginu ReserveAndPay.\nOdesláno: " . current_time( 'mysql' ) . "\nOdesílatel: " . $s['mail_from_email'], $headers );
		self::$sending    = false;
		self::record( 'test', $to, $subject, $ok, 0 );
		return $ok ? true : new WP_Error( 'mail', self::$last_error ?: __( 'Funkce wp_mail() vrátila chybu bez popisu.', 'reserveandpay' ) );
	}

	/** Šablona potvrzení rezervace podle zvoleného způsobu platby. */
	public static function received_key( array $r ) {
		if ( self::is_free( $r ) ) {
			return 'free';
		}
		return 'cash' === ( $r['pay_choice'] ?? '' ) ? 'received_cash' : 'received';
	}

	/** Rezervace zdarma (kupón 100 %). */
	public static function is_free( array $r ) {
		return 'free' === ( $r['paid_method'] ?? '' ) && (float) $r['amount'] <= 0;
	}

	public static function send_received( array $r ) {
		$ok = self::send_template( $r['email'], self::received_key( $r ), $r );
		if ( Resandpay_Settings::get( 'notify_admin' ) && is_email( Resandpay_Settings::get( 'admin_email' ) ) ) {
			self::send_template( Resandpay_Settings::get( 'admin_email' ), 'admin', $r );
		}
		return $ok;
	}

	public static function send_paid( array $r ) {
		return self::send_template( $r['email'], 'paid', $r );
	}

	public static function send_cancelled( array $r ) {
		return self::send_template( $r['email'], 'cancelled', $r );
	}

	/** Tabulka seznamu odeslaných e-mailů. */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_mails';
	}

	/** Názvy typů e-mailů pro seznam odeslaných. */
	public static function key_labels() {
		return array(
			'received'      => __( 'Rezervace přijata', 'reserveandpay' ),
			'received_cash' => __( 'Rezervace přijata (hotovost)', 'reserveandpay' ),
			'admin'         => __( 'Nová rezervace (správce)', 'reserveandpay' ),
			'reminder'      => __( 'Připomínka před splatností', 'reserveandpay' ),
			'overdue'       => __( 'Připomínka po splatnosti', 'reserveandpay' ),
			'part1'         => __( '1. část platby přijata', 'reserveandpay' ),
			'partial'       => __( 'Neúplná platba', 'reserveandpay' ),
			'paid'          => __( 'Platba přijata', 'reserveandpay' ),
			'expired'       => __( 'Rezervace vypršela', 'reserveandpay' ),
			'cancelled'     => __( 'Zrušení rezervace', 'reserveandpay' ),
			'test'          => __( 'Zkušební e-mail', 'reserveandpay' ),
			'security'      => __( 'Upozornění – zablokované rezervace', 'reserveandpay' ),
			'discount_lost' => __( 'Změna předpisu – sleva zanikla', 'reserveandpay' ),
			'free'          => __( 'Rezervace zdarma potvrzena', 'reserveandpay' ),
			'event_reminder' => __( 'Připomínka akce', 'reserveandpay' ),
			'event_summary'  => __( 'Přehled účastníků (správce)', 'reserveandpay' ),
			'mat_grant'      => __( 'Materiál zpřístupněn', 'reserveandpay' ),
		);
	}

	/**
	 * Zápis do seznamu odeslaných e-mailů. „Odesláno“ = WordPress e-mail předal poštovnímu
	 * serveru; doručení do schránky příjemce plugin ověřit nemůže.
	 */
	public static function record( $key, $to, $subject, $ok, $reservation_id = 0 ) {
		global $wpdb;
		$to = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
		$wpdb->insert(
			self::log_table(),
			array(
				'created_at'     => current_time( 'mysql' ),
				'reservation_id' => (int) $reservation_id,
				'mail_key'       => mb_substr( sanitize_key( $key ), 0, 30 ),
				'recipient'      => mb_substr( $to, 0, 255 ),
				'sent_to'        => mb_substr( self::$sent_to ?: $to, 0, 255 ),
				'subject'        => mb_substr( wp_strip_all_tags( (string) $subject ), 0, 255 ),
				'ok'             => $ok ? 1 : 0,
				'error'          => $ok ? '' : mb_substr( self::$last_error ?: __( 'wp_mail() vrátil chybu bez popisu', 'reserveandpay' ), 0, 255 ),
			)
		);
	}

	/**
	 * Seznam odeslaných e-mailů.
	 *
	 * @param array $args reservation_id, search (e-mail / VS), only_failed, limit, offset.
	 */
	public static function log_rows( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'reservation_id' => 0, 'search' => '', 'only_failed' => false, 'limit' => 50, 'offset' => 0 ) );
		$where = array( '1=1' );
		if ( $args['reservation_id'] ) {
			$where[] = $wpdb->prepare( 'm.reservation_id = %d', $args['reservation_id'] );
		}
		if ( $args['only_failed'] ) {
			$where[] = 'm.ok = 0';
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(m.recipient LIKE %s OR m.sent_to LIKE %s OR r.vs LIKE %s OR m.subject LIKE %s)', $like, $like, $like, $like );
		}
		$sql = 'SELECT m.*, r.vs FROM ' . self::log_table() . ' m LEFT JOIN ' . Resandpay_Reservations::table() . ' r ON r.id = m.reservation_id WHERE ' . implode( ' AND ', $where );
		return array(
			'rows'  => $wpdb->get_results( $sql . ' ORDER BY m.id DESC LIMIT ' . max( 1, (int) $args['limit'] ) . ' OFFSET ' . max( 0, (int) $args['offset'] ), ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL
			'total' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::log_table() . ' m LEFT JOIN ' . Resandpay_Reservations::table() . ' r ON r.id = m.reservation_id WHERE ' . implode( ' AND ', $where ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/** Smaže záznamy starší než nastavený počet dní (volá se z CRONu). */
	public static function prune_log() {
		global $wpdb;
		$days = max( 30, (int) Resandpay_Settings::get( 'mail_log_days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . ' WHERE created_at < %s', wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/** Tabulka seznamu odeslaných e-mailů pro administraci (Nástroje i detail rezervace). */
	public static function render_log_table( array $rows, $show_reservation = true ) {
		$labels = self::key_labels();
		echo '<table class="widefat striped rap-maillog"><thead><tr><th>' . esc_html__( 'Čas', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Typ', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Příjemce', 'reserveandpay' ) . '</th>';
		if ( $show_reservation ) {
			echo '<th>' . esc_html__( 'Rezervace', 'reserveandpay' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Předmět', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Výsledek', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $m ) {
			$to = esc_html( $m['recipient'] );
			if ( $m['sent_to'] && strcasecmp( $m['sent_to'], $m['recipient'] ) ) {
				/* translators: %s: testovací adresa */
				$to .= '<br><small>' . esc_html( sprintf( __( 'testovací režim → %s', 'reserveandpay' ), $m['sent_to'] ) ) . '</small>';
			}
			echo '<tr><td>' . esc_html( wp_date( 'j. n. Y H:i', Resandpay_Events::ts( $m['created_at'] ) ) ) . '</td><td>' . esc_html( $labels[ $m['mail_key'] ] ?? $m['mail_key'] ) . '</td><td>' . $to . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $show_reservation ) {
				echo '<td>' . ( $m['reservation_id'] && $m['vs'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-detail&id=' . (int) $m['reservation_id'] ) ) . '">' . esc_html( $m['vs'] ) . '</a>' : '—' ) . '</td>';
			}
			echo '<td>' . esc_html( $m['subject'] ) . '</td><td>' . ( $m['ok'] ? '<span class="rap-ok">✓ ' . esc_html__( 'odesláno', 'reserveandpay' ) . '</span>' : '<span class="rap-err">✗ ' . esc_html( $m['error'] ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( '„Odesláno“ znamená, že web e-mail předal poštovnímu serveru. Zda dorazil do schránky (nebo skončil ve spamu či firemní karanténě), web zjistit nemůže.', 'reserveandpay' ) . '</p>';
	}

	/**
	 * Testovací režim: e-maily pluginu jdou jen na testovací adresu,
	 * původní příjemce je uveden v předmětu i v textu.
	 */
	public static function redirect_in_test_mode( $args ) {
		if ( ! self::$sending || ! Resandpay_Settings::is_test_mode() || apply_filters( 'resandpay_bypass_test_redirect', false ) ) {
			return $args;
		}
		$test = Resandpay_Settings::get( 'test_email' );
		if ( ! is_email( $test ) ) {
			$test = get_option( 'admin_email' );
		}
		$orig            = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];
		$args['to']      = $test;
		self::$sent_to   = $test;
		$args['subject'] = '[RAP TEST → ' . $orig . '] ' . $args['subject'];
		$note = __( 'TESTOVACÍ REŽIM – původní příjemce:', 'reserveandpay' ) . ' ' . $orig;
		if ( false !== stripos( (string) $args['message'], '<body' ) ) {
			$args['message'] = preg_replace( '/(<body[^>]*>)/i', '$1<div style="background:#fcf0f1;color:#8a2424;padding:10px 14px;font:13px Arial,sans-serif;">' . esc_html( $note ) . '</div>', $args['message'], 1 );
			if ( self::$parts ) {
				self::$parts['text'] = '*** ' . $note . " ***\n\n" . self::$parts['text'];
			}
		} else {
			$args['message'] = '*** ' . $note . " ***\n\n" . $args['message'];
		}
		return $args;
	}
}
