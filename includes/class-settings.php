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
			// Rezervace.
			'currency'            => 'CZK',
			'due_days'            => 7,
			'last_minute_hours'   => 24,
			'gdpr_page'           => 0,
			'terms_page'          => 0,
			'events_page'         => 0,
			'vs_prefix'           => gmdate( 'y' ),
			'notify_admin'        => 1,
			// E-maily.
			'mail_from_name'      => get_option( 'blogname' ),
			'mail_from_email'     => get_option( 'admin_email' ),
			// Testovací režim.
			'test_mode'           => 1,
			'test_email'          => get_option( 'admin_email' ),
			// Šablony e-mailů.
			'tpl_received_subject' => 'Rezervace přijata: {event}',
			'tpl_received_body'    => "Dobrý den, {name},\n\nděkujeme za rezervaci akce {event} ({date}, {location}).\n\nRezervace bude potvrzena po přijetí platby:\n\nČíslo účtu: {account}\nIBAN: {iban}\nČástka: {amount}\nVariabilní symbol: {vs}\nSplatnost: {due_date}\n{last_minute_note}\nProsíme, uveďte variabilní symbol – podle něj platbu přiřadíme k Vaší rezervaci. Každé místo má vlastní variabilní symbol.\n\nČíslo platebního pokynu: {proforma}\n\nS pozdravem\n{company}",
			'tpl_lastminute_note'  => "\nRezervujete krátce před začátkem akce. Pokud platba převodem nestihne dorazit, můžete zaplatit na místě.\n",
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

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, false );
	}

	public static function is_test_mode() {
		return (bool) self::get( 'test_mode' );
	}
}
