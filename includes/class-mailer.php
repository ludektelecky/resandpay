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

	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'redirect_in_test_mode' ), 99 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
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
			'{last_minute_note}' => ! empty( $r['last_minute'] ) ? $s['tpl_lastminute_note'] : '',
		);
	}

	public static function render( $template, array $r ) {
		$ph = apply_filters( 'resandpay_mail_placeholders', self::placeholders( $r ), $r );
		return strtr( (string) $template, $ph );
	}

	public static function send_template( $to, $key, array $r ) {
		$s       = Resandpay_Settings::all();
		$subject = self::render( $s[ 'tpl_' . $key . '_subject' ] ?? '', $r );
		$body    = self::render( $s[ 'tpl_' . $key . '_body' ] ?? '', $r );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $s['mail_from_email'] && is_email( $s['mail_from_email'] ) ) {
			$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( $s['mail_from_name'] ), $s['mail_from_email'] );
		}
		self::$sending    = true;
		self::$last_error = '';
		$ok               = wp_mail( $to, $subject, $body, $headers );
		self::$sending = false;
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

	public static function send_received( array $r ) {
		$ok = self::send_template( $r['email'], 'received', $r );
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
		$args['subject'] = '[TEST → ' . $orig . '] ' . $args['subject'];
		$args['message'] = '*** ' . __( 'TESTOVACÍ REŽIM – původní příjemce:', 'reserveandpay' ) . ' ' . $orig . " ***\n\n" . $args['message'];
		return $args;
	}
}
