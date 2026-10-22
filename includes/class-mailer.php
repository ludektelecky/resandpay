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

	/** @var array|null Části právě odesílaného e-mailu (textová verze, QR obrázek, PDF) pro phpmailer_init. */
	private static $parts = null;

	/** @var array Části posledního sestaveného e-mailu (diagnostika a testy). */
	public static $last_parts = array();

	/** Šablony, ke kterým patří platební údaje, QR kód a PDF platebního pokynu. */
	const PAYMENT_KEYS = array( 'received', 'received_cash', 'overdue', 'reminder', 'partial', 'part1' );

	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'redirect_in_test_mode' ), 99 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_parts' ) );
	}

	/** Vloží do e-mailu textovou verzi, QR kód (inline, cid:rap-qr) a PDF přílohu. */
	public static function phpmailer_parts( $phpmailer ) {
		if ( ! self::$sending || ! self::$parts ) {
			return;
		}
		$p                  = self::$parts;
		$phpmailer->AltBody = $p['text'];
		if ( ! empty( $p['qr_png'] ) ) {
			$phpmailer->addStringEmbeddedImage( $p['qr_png'], 'rap-qr', 'qr-platba.png', 'base64', 'image/png' );
		}
		if ( ! empty( $p['qr2_png'] ) ) {
			$phpmailer->addStringEmbeddedImage( $p['qr2_png'], 'rap-qr2', 'qr-platba-vse.png', 'base64', 'image/png' );
		}
		if ( ! empty( $p['pdf'] ) ) {
			$phpmailer->addStringAttachment( $p['pdf'], $p['pdf_name'], 'base64', 'application/pdf' );
		}
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
			'{paid}'             => Resandpay_Events::format_price( $r['amount_paid'] ?? 0, $r['currency'] ),
			'{remaining}'        => Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ),
			'{amount_now}'       => Resandpay_Events::format_price( Resandpay_Reservations::amount_now( $r ), $r['currency'] ),
			'{due_now}'          => ( $dn = Resandpay_Reservations::due_now( $r ) ) ? wp_date( 'j. n. Y', Resandpay_Events::ts( $dn ) ) : '',
			'{part1}'            => Resandpay_Events::format_price( $r['part1_amount'] ?? 0, $r['currency'] ),
			'{part1_due}'        => ! empty( $r['part1_due_at'] ) ? wp_date( 'j. n. Y', Resandpay_Events::ts( $r['part1_due_at'] ) ) : '',
			'{final_due}'        => $r['due_at'] ? wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) ) : '',
			'{last_payment}'     => isset( $r['last_payment'] ) ? Resandpay_Events::format_price( $r['last_payment'], $r['currency'] ) : '',
			'{event_url}'        => ! empty( $r['event_id'] ) && get_permalink( $r['event_id'] ) ? get_permalink( $r['event_id'] ) : home_url( '/' ),
			'{split_note}'       => Resandpay_Reservations::is_split( $r ) ? sprintf( /* translators: 1: 1. část, 2: datum, 3: doplatek, 4: datum */ __( "\nPlatba je rozdělená: 1. část %1\$s do %2\$s, doplatek %3\$s do %4\$s. Zaplacením 1. části je Vaše místo potvrzené. Můžete také zaplatit vše najednou do splatnosti 1. části – oba QR kódy najdete níže.\n", 'reserveandpay' ), Resandpay_Events::format_price( $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['part1_due_at'] ) ), Resandpay_Events::format_price( (float) $r['amount'] - (float) $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) ) ) : '',
		);
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
		$ph = apply_filters( 'resandpay_mail_placeholders', $ph, $r );
		return strtr( (string) $template, $ph );
	}

	/**
	 * Sestaví e-mail: předmět, HTML (v barvách webu), textovou verzi, QR kód a PDF.
	 * U šablon s platbou (PAYMENT_KEYS) přidá platební údaje, QR kód a platební pokyn.
	 */
	public static function build( $key, array $r ) {
		$s       = Resandpay_Settings::all();
		$subject = self::render( $s[ 'tpl_' . $key . '_subject' ] ?? '', $r, $key );
		$body    = self::render( $s[ 'tpl_' . $key . '_body' ] ?? '', $r, $key );
		$parts   = array(
			'subject'  => $subject,
			'text'     => $body,
			'qr_png'   => '',
			'qr2_png'  => '',
			'pdf'      => '',
			'pdf_name' => '',
		);
		$payment = in_array( $key, self::PAYMENT_KEYS, true ) && Resandpay_Reservations::PAID !== $r['status'];
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

	/** Řádky platebních údajů (stejné v HTML i textové verzi). */
	public static function payment_rows( array $r ) {
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
			$out .= '<tr><td style="padding:3px 14px 3px 0;font-size:13px;color:' . $c['soft'] . ';white-space:nowrap;">' . esc_html( $k ) . '</td><td style="padding:3px 0;' . ( $big ? 'font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:bold;color:' . $c['accent'] . ';' : 'font-size:14px;color:' . $c['ink'] . ';' ) . '">' . esc_html( $v ) . '</td></tr>';
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
		$brand   = $s['brand_name'] ?: get_bloginfo( 'name' );
		$contact = implode( ' · ', array_filter( array( $s['company_name'], $s['contact_web'], $s['contact_email'] ) ) );
		return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $c['bg'] . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $c['bg'] . ';"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:' . $c['card'] . ';border-top:3px solid ' . $c['accent'] . ';">'
			. '<tr><td style="padding:26px 30px 8px;font-family:Georgia,\'Times New Roman\',serif;font-size:22px;color:' . $c['accent'] . ';">' . esc_html( $brand ) . '</td></tr>'
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
		$ok               = wp_mail( $to, $parts['subject'], $parts['html'], $headers );
		self::$sending    = false;
		self::$parts      = null;
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
		$ok               = wp_mail( $to, 'ReserveAndPay – zkušební e-mail', "Toto je zkušební e-mail pluginu ReserveAndPay.\nOdesláno: " . current_time( 'mysql' ) . "\nOdesílatel: " . $s['mail_from_email'], $headers );
		self::$sending    = false;
		return $ok ? true : new WP_Error( 'mail', self::$last_error ?: __( 'Funkce wp_mail() vrátila chybu bez popisu.', 'reserveandpay' ) );
	}

	/** Šablona potvrzení rezervace podle zvoleného způsobu platby. */
	public static function received_key( array $r ) {
		return 'cash' === ( $r['pay_choice'] ?? '' ) ? 'received_cash' : 'received';
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
