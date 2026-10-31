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
			// Logo a motto pro tiskoviny pluginu (poukaz) – grafický manuál v1, kap. 01–02.
			'logo_source'         => 'site',
			'logo_line1'          => 's lehkou',
			'logo_line2'          => 'hlavou',
			'brand_motto'         => 'nádech — výdech — prostor',
			'brand_symbol_svg'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 80" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="4.2"><g stroke="#8C6423"><path d="M50 62 C40 46 42 26 50 10 C58 26 60 46 50 62 Z"/><path d="M50 62 C36 56 28 42 30 24 C42 32 48 46 50 62 Z"/><path d="M50 62 C64 56 72 42 70 24 C58 32 52 46 50 62 Z"/></g><path stroke="#6B3F4B" d="M8 64 C20 56 34 56 50 64 C66 56 80 56 92 64"/></svg>',
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
			'event_reminder_hours'  => 48, // Připomínka akce zákazníkům (SWD kap. 15.1, R2); 0 = vypnuto.
			'event_summary_enabled' => 1,
			'event_summary_time'    => '18:00',
			'event_summary_email'   => '',
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
			'code_prefix_material' => 'MA',
			'code_digits_material' => 3,
			// Materiály ke stažení (SWD kap. 15.1, R11).
			'mat_kinds'            => "ebook|eBook / PDF\nvideo|Video\naudio|Audio\nworksheet|Pracovní list\nbundle|Balíček\nother|Jiné",
			'mat_due_days'         => 7,
			'mat_access_days'      => 0,
			'mat_download_limit'   => 10,
			'mat_link_minutes'     => 240,
			'mat_log_days'         => 365,
			'mat_my_page'          => 0,
			'mat_register_page'    => 0,
			'mat_login_text'       => 'Materiály ke stažení jsou pro přihlášené. Přihlaste se, nebo si zdarma založte účet.',
			'mat_roles_text'       => 'Tento materiál je jen pro členy.',
			'mat_digital_consent'  => 'Souhlasím s poskytnutím digitálního obsahu před uplynutím lhůty pro odstoupení od smlouvy a beru na vědomí, že tím právo na odstoupení zaniká.',
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
			'time_display_default'   => 'show',
			'booking_access'         => 'all', // Kdo může rezervovat (SWD kap. 15.1, R5): all | logged | roles.
			'booking_access_roles'   => '',
			'priority_roles'         => '',
			'access_text'            => 'Rezervace na tuto akci je jen pro členy. Přihlaste se, prosím.',
			'access_tag'             => 'jen pro členy',
			'priority_text'          => 'pro členy od %s',
			'stats_roles'            => 'administrator,editor,contributor', // Kdo vidí statistiku (SWD kap. 15.1, R1).
			'coupon_prefix'          => 'SLH',
			'tpl_discount_lost_subject' => 'Změna platebního předpisu – sleva zanikla: {event}',
			'tpl_discount_lost_body'    => "Dobrý den, {name},\n\nk rezervaci akce {event} ({date}) jsme obdrželi platbu po splatnosti, případně platba do splatnosti nedorazila celá. {discount_label} ({discount}) platila jen při úhradě ve splatnosti, a proto zanikla.\n\nNová cena: {total}\nJiž zaplaceno: {paid}\nZbývá zaplatit: {remaining}\n\nPlatební údaje a QR kód na zbývající částku najdete níže. Pokud jde o omyl, napište nám prosím.\n\nS pozdravem\n{company}",
			'tpl_free_subject'          => 'Rezervace potvrzena: {event}',
			'tpl_free_body'             => "Dobrý den, {name},\n\nděkujeme za rezervaci akce {event} ({date}, {location}). Díky slevovému kupónu {coupon} je rezervace zdarma a je potvrzena – nic neplatíte.\n\nVariabilní symbol (číslo rezervace): {vs}\n\nTěšíme se na Vás.\n\nS pozdravem\n{company}",
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
			'tpl_event_reminder_subject' => 'Připomínka: {event} – {date}',
			'tpl_event_reminder_body'    => "Dobrý den, {name},\n\nrádi bychom Vám připomněli, že se blíží {event}.\n\nKdy: {date}\n{where}\n{map_url}\n\n{reminder_note}\n\n{payment_note}\n\nPodrobnosti: {event_url}\n\nPokud se nemůžete zúčastnit, dejte nám prosím vědět.\n\nTěšíme se na Vás\n{company}",
			'tpl_event_summary_subject'  => 'Přehled účastníků na {date}',
			'tpl_event_summary_body'     => "Dobrý den,\n\nposíláme přehled akcí a termínů na {date} s rezervacemi ({count}).",
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
			'tpl_mat_received_subject' => 'Objednávka přijata: {event}',
			'tpl_mat_received_body'    => "Dobrý den, {name},\n\nděkujeme za objednávku materiálu {event}.\n\nMateriál se Vám odemkne po přijetí platby. Platební údaje a QR kód najdete níže, platební pokyn je také v příloze. Nejrychlejší je naskenovat QR kód v bankovní aplikaci.\n\nPo zaplacení najdete materiál ve svém účtu: {my_materials_url}\n\nS pozdravem\n{company}",
			'tpl_mat_free_subject'     => 'Materiál je připraven: {event}',
			'tpl_mat_free_body'        => "Dobrý den, {name},\n\ndíky slevovému kupónu {coupon} je materiál {event} zdarma a už je Vám k dispozici.\n\nStáhnout: {material_url}\nVšechny Vaše materiály: {my_materials_url}\n{access_until}\n\nS pozdravem\n{company}",
			'tpl_mat_paid_subject'     => 'Platba přijata – materiál je připraven: {event}',
			'tpl_mat_paid_body'        => "Dobrý den, {name},\n\nděkujeme, Vaše platba byla přijata a materiál {event} je Vám k dispozici.\n\nStáhnout (po přihlášení): {material_url}\nVšechny Vaše materiály: {my_materials_url}\n{access_until}\nVariabilní symbol: {vs}\n\nS pozdravem\n{company}",
			'tpl_mat_reminder_subject' => 'Připomínka platby: {event}',
			'tpl_mat_reminder_body'    => "Dobrý den, {name},\n\npřipomínáme platbu za materiál {event}. Částka {amount_now} je splatná do {due_now}. Platební údaje a QR kód najdete níže.\n\nPokud jste již zaplatili, považujte prosím tuto zprávu za bezpředmětnou.\n\nS pozdravem\n{company}",
			'tpl_mat_overdue_subject'  => 'Doplatek za materiál: {event}',
			'tpl_mat_overdue_body'     => "Dobrý den, {name},\n\nk objednávce materiálu {event} evidujeme nezaplacenou částku {amount_now}. Platební údaje a QR kód najdete níže. Materiál se odemkne po zaplacení celé částky.\n\nS pozdravem\n{company}",
			'tpl_mat_partial_subject'  => 'Přijali jsme část platby: {event}',
			'tpl_mat_partial_body'     => "Dobrý den, {name},\n\nk objednávce materiálu {event} jsme přijali platbu {last_payment}. Zaplaceno {paid} z {total}, zbývá doplatit {remaining}. Materiál se odemkne po zaplacení celé částky – platební údaje a QR kód na zbývající částku najdete níže.\n\nS pozdravem\n{company}",
			'tpl_mat_expired_subject'  => 'Objednávka vypršela: {event}',
			'tpl_mat_expired_body'     => "Dobrý den, {name},\n\nVaše objednávka materiálu {event} s variabilním symbolem {vs} vypršela, protože do {due_now} jsme neobdrželi platbu.\n\nMateriál si můžete kdykoli objednat znovu: {material_url}\nPokud jste platbu mezitím odeslali, nic se neděje – po jejím přijetí se materiál odemkne.\n\nS pozdravem\n{company}",
			'tpl_mat_cancelled_subject' => 'Objednávka zrušena: {event}',
			'tpl_mat_cancelled_body'    => "Dobrý den, {name},\n\nVaše objednávka materiálu {event} s variabilním symbolem {vs} byla zrušena.\n{reason}\nV případě dotazů nás neváhejte kontaktovat.\n\nS pozdravem\n{company}",
			'tpl_mat_admin_subject'    => 'Nová objednávka materiálu: {event} – {name}',
			'tpl_mat_admin_body'       => "Nová objednávka materiálu\n\nMateriál: {event} ({event_code})\nJméno: {name}\nE-mail: {email}\nČástka: {amount}\nVS: {vs}",
			'tpl_mat_grant_subject'    => 'Materiál je pro Vás připraven: {event}',
			'tpl_mat_grant_body'       => "Dobrý den, {name},\n\nzpřístupnili jsme Vám materiál {event}.\n\nStáhnout (po přihlášení): {material_url}\nVšechny Vaše materiály: {my_materials_url}\n{access_until}\n\nS pozdravem\n{company}",
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
