<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nastavení pluginu – jediné místo, kde se čtou konfigurovatelné hodnoty.
 * Nic z identifikace provozovatele, schránky ani textů není zapsáno v kódu
 * mimo výchozí hodnoty níže (pravidla fáze 1, SWD kap. 14.1).
 */
class Resandpay_Settings {

	const OPTION = 'resandpay_settings';

	public static function defaults() {
		return array(
			// Provozovatel.
			'company_name'        => '',
			'company_ic'          => '',
			'company_address'     => '',
			'bank_account'        => '',
			'iban'                => '',
			'admin_email'         => get_option( 'admin_email' ),
			// Platební pokyn (PDF) a QR platba.
			'brand_name'          => get_option( 'blogname' ),
			'contact_web'         => preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'contact_email'       => get_option( 'admin_email' ),
			'qr_recipient_name'   => '',
			'pdf_footer'          => 'Nejedná se o daňový doklad',
			// Rezervace.
			'currency'            => 'CZK',
			// Splatnost: booking = N dní od rezervace (nejpozději začátek termínu),
			// event = termín ± N dní (-1 den před, 0 v den termínu, +1 den po),
			// combined = dřívější z obou.
			'due_mode'            => 'booking',
			'due_days'            => 7,
			'due_offset'          => -1,
			// Hotovost jako doplňková volba k platbě převodem (QR).
			'cash_enabled'        => 0,
			// Připomínka N dní před splatností (0 = vypnuto).
			'reminder_days'       => 1,
			'last_minute_hours'   => 24,
			'gdpr_page'           => 0,
			'terms_page'          => 0,
			'events_page'         => 0,
			// Detail akce: full = záhlaví s galerií a rezervační karta vedle popisu, basic = údaje, text a formulář pod sebou.
			'detail_layout'       => 'full',
			// Nadpis akce vykreslí plugin v záhlaví (titulek šablony GeneratePress se skryje).
			'detail_own_title'    => 1,
			'vs_prefix'           => '%YY%',
			'notify_admin'        => 1,
			// Kódy akcí, služeb a termínů (AK-2026-0012, SL-003, TE-2026-0145).
			'code_prefix_event'   => 'AK',
			'code_digits_event'   => 4,
			'code_prefix_service' => 'SL',
			'code_digits_service' => 3,
			'code_prefix_slot'    => 'TE',
			'code_digits_slot'    => 4,
			// Kontrola plateb (SWD kap. 6.4).
			'payment_mode'         => 'auto',
			'payment_interval'     => 60,
			'imap_host'            => 'imap.websupport.cz',
			'imap_port'            => 993,
			'imap_security'        => 'ssl',
			'imap_user'            => '',
			'imap_password'        => '',
			'imap_folder'          => 'INBOX',
			'imap_folder_done'     => 'Zpracováno',
			'bank_sender'          => 'info@rb.cz',
			'bank_require_auth'    => 1,
			'payment_batch'        => 50,
			'payment_alert_factor' => 3,
			'payment_log_days'     => 90,
			'cron_key'             => '',
			// E-maily.
			'mail_from_name'      => get_option( 'blogname' ),
			'mail_from_email'     => get_option( 'admin_email' ),
			'mail_log_days'       => 365,
			// Ochrana formuláře proti robotům.
			'antibot_min_seconds'    => 3,
			'rate_ip_window_minutes' => 10,
			'rate_ip_max_window'     => 3,
			'rate_ip_max_day'        => 10,
			'rate_email_max_event'   => 3,
			'rate_notify'            => 1,
			'ip_hash_days'           => 30,
			'turnstile_enabled'      => 0,
			'turnstile_site_key'     => '',
			'turnstile_secret'       => '',
			// Testovací režim.
			'test_mode'           => 1,
			'test_email'          => get_option( 'admin_email' ),
			// Šablony e-mailů.
			'tpl_received_subject' => 'Rezervace přijata: {event}',
			'tpl_received_body'    => "Dobrý den, {name},\n\nděkujeme za rezervaci akce {event} ({date}, {location}).\n\nRezervace bude potvrzena po přijetí platby. Platební údaje a QR kód najdete níže, platební pokyn je také v příloze. Nejrychlejší je naskenovat QR kód v bankovní aplikaci – údaje se vyplní samy.\n\nProsíme, uveďte variabilní symbol {vs} – podle něj platbu přiřadíme k Vaší rezervaci. Každé místo má vlastní variabilní symbol.\n{split_note}{last_minute_note}\nS pozdravem\n{company}",
			'tpl_lastminute_note'  => "\nRezervujete krátce před začátkem akce. Pokud platba převodem nestihne dorazit, můžete zaplatit na místě.\n",
			'tpl_received_cash_subject' => 'Rezervace přijata: {event}',
			'tpl_received_cash_body'    => "Dobrý den, {name},\n\nděkujeme za rezervaci akce {event} ({date}, {location}).\n\nZvolili jste platbu v hotovosti na místě ({amount}). Pokud Vám to vyhovuje, můžete pohodlně zaplatit předem převodem – stačí naskenovat QR kód níže v bankovní aplikaci a na místě pak nemusíte řešit hotovost.\n\nVariabilní symbol {vs} prosím mějte u sebe i na místě – podle něj Vás rychle odbavíme.\n\nS pozdravem\n{company}",
			'tpl_overdue_subject'  => 'Připomínka platby: {event}',
			'tpl_overdue_body'     => "Dobrý den, {name},\n\nk rezervaci akce {event} ({date}) zatím evidujeme nezaplacenou částku {amount_now}. Splatnost byla {due_now}. Platební údaje a QR kód najdete níže.\n\nPokud jste již zaplatili, považujte prosím tento e-mail za bezpředmětný.\n\nS pozdravem\n{company}",
			'tpl_reminder_subject' => 'Připomínka splatnosti: {event}',
			'tpl_reminder_body'    => "Dobrý den, {name},\n\npřipomínáme platbu k rezervaci akce {event} ({date}). Částka k úhradě {amount_now} je splatná do {due_now}. Platební údaje a QR kód najdete níže.\n\nPokud jste již zaplatili, považujte prosím tento e-mail za bezpředmětný.\n\nS pozdravem\n{company}",
			'tpl_expired_subject'  => 'Rezervace vypršela: {event}',
			'tpl_expired_body'     => "Dobrý den, {name},\n\nVaše rezervace akce {event} ({date}) s variabilním symbolem {vs} vypršela, protože do {due_now} jsme neobdrželi platbu. Místo bylo uvolněno.\n\nPokud máte o akci stále zájem, můžete se znovu přihlásit: {event_url}\n\nPokud jste platbu mezitím odeslali, napište nám prosím – vyřešíme to.\n\nS pozdravem\n{company}",
			'tpl_partial_subject'  => 'Přijali jsme část platby: {event}',
			'tpl_partial_body'     => "Dobrý den, {name},\n\nk rezervaci akce {event} ({date}) jsme přijali platbu {last_payment}. Celá částka ale zatím uhrazena není: zaplaceno {paid} z {total}, zbývá doplatit {remaining}.\n\nPlatební údaje a QR kód na zbývající částku najdete níže.\n\nS pozdravem\n{company}",
			'tpl_part1_subject'    => '1. část platby přijata – místo potvrzeno: {event}',
			'tpl_part1_body'       => "Dobrý den, {name},\n\npřijali jsme 1. část platby ({paid}) – Vaše místo na akci {event} ({date}) je potvrzené.\n\nDoplatek {remaining} je splatný do {final_due}. Platební údaje a QR kód na doplatek najdete níže.\n\nS pozdravem\n{company}",
			'tpl_paid_subject'     => 'Platba přijata – rezervace potvrzena: {event}',
			'tpl_paid_body'        => "Dobrý den, {name},\n\nVaše platba za akci {event} byla přijata a rezervace je potvrzena.\n\nTermín: {date}\nMísto: {location}\nVariabilní symbol: {vs}\n\nTěšíme se na Vás.\n\nS pozdravem\n{company}",
			'tpl_cancelled_subject' => 'Rezervace zrušena: {event}',
			'tpl_cancelled_body'    => "Dobrý den, {name},\n\nVaše rezervace akce {event} ({date}) s variabilním symbolem {vs} byla zrušena.\n{reason}\nV případě dotazů nás neváhejte kontaktovat.\n\nS pozdravem\n{company}",
			'tpl_admin_subject'     => 'Nová rezervace: {event} – {name}',
			'tpl_admin_body'        => "Nová rezervace\n\nAkce: {event}\nTermín: {date}\nJméno: {name}\nE-mail: {email}\nTelefon: {phone}\nČástka: {amount}\nVS: {vs}\nPoznámka: {note}\n\nVolná místa po rezervaci: {free}",
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** @var bool Interní zápis – obejde kontrolu formuláře nastavení (sanitize_callback). */
	public static $internal = false;

	/**
	 * Interní zápis hodnot (bez kontroly formuláře – ta je jen pro odeslaný formulář
	 * a jinak by např. znovu zašifrovala už zašifrované heslo).
	 */
	public static function update( array $values ) {
		$all            = array_merge( self::all(), $values );
		self::$internal = true;
		try {
			update_option( self::OPTION, $all, false );
		} finally {
			self::$internal = false;
		}
	}

	/** Obnoví výchozí texty e-mailů (klíče tpl_*). */
	public static function reset_templates() {
		$def = array_filter( self::defaults(), fn( $k ) => 0 === strpos( $k, 'tpl_' ), ARRAY_FILTER_USE_KEY );
		self::update( $def );
	}

	public static function is_test_mode() {
		return (bool) self::get( 'test_mode' );
	}
}
