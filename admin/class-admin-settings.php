<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stránka nastavení pluginu.
 */
class Resandpay_Admin_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Nastavení ReserveAndPay', 'reserveandpay' ), __( 'Nastavení', 'reserveandpay' ), resandpay_cap_settings(), 'reserveandpay-settings', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'resandpay_settings_group',
			Resandpay_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function fields() {
		$text = 'text';
		return array(
			__( 'Provozovatel', 'reserveandpay' )      => array(
				'company_name'    => array( __( 'Jméno / název provozovatele', 'reserveandpay' ), $text ),
				'company_ic'      => array( __( 'IČ', 'reserveandpay' ), $text ),
				'company_address' => array( __( 'Adresa', 'reserveandpay' ), $text ),
				'bank_account'    => array( __( 'Číslo účtu', 'reserveandpay' ), $text, __( 'Např. 123456789/2700', 'reserveandpay' ) ),
				'iban'            => array( __( 'IBAN', 'reserveandpay' ), $text ),
				'admin_email'     => array( __( 'E-mail pro upozornění na nové rezervace', 'reserveandpay' ), 'email' ),
				'notify_admin'    => array( __( 'Posílat upozornění na nové rezervace', 'reserveandpay' ), 'checkbox' ),
			),
			__( 'Platební pokyn a QR platba', 'reserveandpay' ) => array(
				'brand_name'        => array( __( 'Značka v hlavičce', 'reserveandpay' ), $text, __( 'Za jménem provozovatele, např. „INSYSTEMS CZ s.r.o. · S lehkou hlavou…“. Logo se bere z Vzhled → Přizpůsobit → Identita webu.', 'reserveandpay' ) ),
				'contact_web'       => array( __( 'Web v kontaktech', 'reserveandpay' ), $text, __( 'Např. slehkouhlavou.cz – v hlavičce a patičce platebního pokynu.', 'reserveandpay' ) ),
				'contact_email'     => array( __( 'E-mail v kontaktech', 'reserveandpay' ), 'email' ),
				'qr_recipient_name' => array( __( 'Jméno příjemce v QR platbě', 'reserveandpay' ), $text, __( 'Pole RN (max. 35 znaků, bez diakritiky). Prázdné = jméno provozovatele.', 'reserveandpay' ) ),
				'pdf_footer'        => array( __( 'Patička platebního pokynu', 'reserveandpay' ), $text ),
			),
			__( 'Rezervace a platby', 'reserveandpay' ) => array(
				'currency'          => array( __( 'Výchozí měna', 'reserveandpay' ), $text, __( 'Třípísmenný kód, např. CZK.', 'reserveandpay' ) ),
				'due_mode'          => array( __( 'Výchozí pravidlo splatnosti', 'reserveandpay' ), 'select', __( 'U akce i služby lze pravidlo přepsat. Splatnost je vždy do konce dne.', 'reserveandpay' ), Resandpay_Events::due_mode_labels() ),
				'due_days'          => array( __( 'Splatnost – dní od rezervace', 'reserveandpay' ), 'number' ),
				'due_offset'        => array( __( 'Splatnost – dní vůči termínu', 'reserveandpay' ), 'int', __( '−1 = den před termínem, 0 = v den termínu, +1 = den po termínu. Nezaplacená rezervace se splatností před termínem po splatnosti expiruje (místo se uvolní, zákazník dostane e-mail); se splatností v den termínu nebo po něm přejde do stavu „Po splatnosti“ a zákazník dostane připomínku.', 'reserveandpay' ) ),
				'reminder_days'     => array( __( 'Připomínka před splatností (dní)', 'reserveandpay' ), 'number', __( 'Kolik dní před splatností poslat zákazníkovi připomínku nezaplacené rezervace (i 1. části a doplatku). 0 = nepřipomínat.', 'reserveandpay' ) ),
				'cash_enabled'      => array( __( 'Povolit platbu v hotovosti na místě', 'reserveandpay' ), 'checkbox', __( 'Doplňková možnost k platbě převodem (QR), která zůstává předvybraná. U akce i služby lze přepsat.', 'reserveandpay' ) ),
				'last_minute_hours' => array( __( 'Hranice pro platbu na místě (hodin před začátkem)', 'reserveandpay' ), 'number', __( 'Rezervace vytvořená později než tato hranice dostane i možnost zaplatit na místě a po splatnosti neexpiruje. Výchozí 24 h.', 'reserveandpay' ) ),
				'vs_prefix'         => array( __( 'Prefix variabilního symbolu', 'reserveandpay' ), $text, __( 'Číslice na začátku VS (např. rok 26). VS je vždy jedinečný a nikdy se nepoužije znovu.', 'reserveandpay' ) ),
				'gdpr_page'         => array( __( 'Stránka se zásadami zpracování osobních údajů', 'reserveandpay' ), 'page' ),
				'terms_page'        => array( __( 'Stránka s obchodními a stornopodmínkami', 'reserveandpay' ), 'page' ),
				'events_page'       => array( __( 'Stránka s přehledem akcí', 'reserveandpay' ), 'page', __( 'Stránka se shortcodem [reserveandpay_program]. Archiv /akce/ a kategorie se na ni přesměrují a detail akce na ni odkazuje.', 'reserveandpay' ) ),
			),
			__( 'Detail akce', 'reserveandpay' )       => array(
				'detail_layout'    => array( __( 'Vzhled stránky akce', 'reserveandpay' ), 'select', __( 'S galerií: nahoře název, termín, cena a fotky, pod nimi popis akce a vedle něj rezervační formulář. Jednoduchý: údaje, text a formulář pod sebou (vzhled do verze 0.6).', 'reserveandpay' ), array( 'full' => __( 'S galerií a rezervační kartou', 'reserveandpay' ), 'basic' => __( 'Jednoduchý', 'reserveandpay' ) ) ),
				'detail_own_title' => array( __( 'Nadpis akce v záhlaví pluginu', 'reserveandpay' ), 'checkbox', __( 'Platí pro vzhled s galerií. Nadpis se zobrazí v záhlaví vedle fotek a titulek šablony GeneratePress se skryje. U jiné šablony vypněte, pokud se nadpis zobrazí dvakrát.', 'reserveandpay' ) ),
			),
			__( 'Kontrola plateb', 'reserveandpay' ) => array(
				'payment_mode'         => array( __( 'Režim kontroly plateb', 'reserveandpay' ), 'select', __( 'Automaticky = při volání CRONu, když uplynul interval. Ručně = jen tlačítkem v ResAndPay → Platby.', 'reserveandpay' ), array( 'auto' => __( 'Automaticky v intervalu', 'reserveandpay' ), 'manual' => __( 'Jen ručně', 'reserveandpay' ) ) ),
				'payment_interval'     => array( __( 'Interval kontroly', 'reserveandpay' ), 'select', __( 'Nejkratší reálný interval je dán krokem CRONu na hostingu (doporučeno */15).', 'reserveandpay' ), array( '15' => '15 min', '30' => '30 min', '60' => '60 min', '120' => '2 h', '240' => '4 h', '1440' => __( '1× denně', 'reserveandpay' ) ) ),
				'imap_host'            => array( __( 'IMAP server', 'reserveandpay' ), $text, __( 'Active24/Websupport: imap.websupport.cz', 'reserveandpay' ) ),
				'imap_port'            => array( __( 'Port', 'reserveandpay' ), 'number', __( '993 (SSL/TLS)', 'reserveandpay' ) ),
				'imap_security'        => array( __( 'Šifrování', 'reserveandpay' ), 'select', '', array( 'ssl' => 'SSL/TLS', 'none' => __( 'bez šifrování (jen pro testy)', 'reserveandpay' ) ) ),
				'imap_user'            => array( __( 'Uživatel (e-mailová adresa)', 'reserveandpay' ), $text, __( 'Např. bankovni.transakce@slehkouhlavou.cz', 'reserveandpay' ) ),
				'imap_password'        => array( __( 'Heslo ke schránce', 'reserveandpay' ), 'password', __( 'Ukládá se šifrovaně a už se nezobrazí. Prázdné pole = ponechat uložené heslo. Pozor: prohlížeč sem může sám vložit heslo k WordPressu – před uložením nastavení pole zkontrolujte. Alternativně konstanta RESANDPAY_IMAP_PASSWORD ve wp-config.php.', 'reserveandpay' ) ),
				'imap_folder'          => array( __( 'Složka s avízy', 'reserveandpay' ), $text ),
				'imap_folder_done'     => array( __( 'Složka pro zpracovaná avíza', 'reserveandpay' ), $text, __( 'Zpracovaná avíza se do ní přesunou (vytvoří se automaticky). Prázdné = nepřesouvat.', 'reserveandpay' ) ),
				'bank_sender'          => array( __( 'Odesílatel avíz', 'reserveandpay' ), $text, __( 'Raiffeisenbank: info@rb.cz. Ostatní e-maily ve schránce se ignorují.', 'reserveandpay' ) ),
				'bank_require_auth'    => array( __( 'Vyžadovat ověřenou pravost avíza', 'reserveandpay' ), 'checkbox', __( 'SPF, DKIM a DMARC banky. Avízo bez ověření se nespáruje automaticky, ale půjde k ruční kontrole. Doporučeno ponechat zapnuté.', 'reserveandpay' ) ),
				'payment_batch'        => array( __( 'Max. avíz na jeden běh', 'reserveandpay' ), 'number' ),
				'payment_alert_factor' => array( __( 'Upozornit při výpadku po (násobek intervalu)', 'reserveandpay' ), 'number', __( 'Výchozí 3 – při intervalu 60 min upozornění po 3 h bez úspěšné kontroly (na e-mail pro upozornění, nejvýš 1× denně).', 'reserveandpay' ) ),
				'payment_log_days'     => array( __( 'Uchovávat log běhů (dní)', 'reserveandpay' ), 'number' ),
			),
			__( 'Kódy akcí, služeb a termínů', 'reserveandpay' ) => array(
				'code_prefix_event'   => array( __( 'Předpona kódu akce', 'reserveandpay' ), $text, __( 'Např. AK → AK-2026-0012 (rok založení, řada od 1 každý rok).', 'reserveandpay' ) ),
				'code_digits_event'   => array( __( 'Počet číslic – akce', 'reserveandpay' ), 'number' ),
				'code_prefix_service' => array( __( 'Předpona kódu služby', 'reserveandpay' ), $text, __( 'Např. SL → SL-003.', 'reserveandpay' ) ),
				'code_digits_service' => array( __( 'Počet číslic – služba', 'reserveandpay' ), 'number' ),
				'code_prefix_slot'    => array( __( 'Předpona kódu termínu služby', 'reserveandpay' ), $text, __( 'Např. TE → TE-2026-0145. Změna předpon a číslic platí jen pro nově přidělované kódy; přidělený kód se nikdy nemění.', 'reserveandpay' ) ),
				'code_digits_slot'    => array( __( 'Počet číslic – termín', 'reserveandpay' ), 'number' ),
			),
			__( 'E-maily', 'reserveandpay' )           => array(
				'mail_from_name'        => array( __( 'Jméno odesílatele', 'reserveandpay' ), $text ),
				'mail_from_email'       => array( __( 'E-mail odesílatele', 'reserveandpay' ), 'email' ),
				'mail_log_days'         => array( __( 'Uchovávat seznam odeslaných e-mailů (dní)', 'reserveandpay' ), 'number', __( 'Seznam je v Nástrojích a v detailu rezervace. Starší záznamy se mažou automaticky.', 'reserveandpay' ) ),
				'tpl_received_subject'  => array( __( 'Přijetí rezervace – předmět', 'reserveandpay' ), $text ),
				'tpl_received_body'     => array( __( 'Přijetí rezervace – text', 'reserveandpay' ), 'textarea' ),
				'tpl_received_cash_subject' => array( __( 'Přijetí rezervace (hotovost) – předmět', 'reserveandpay' ), $text ),
				'tpl_received_cash_body'    => array( __( 'Přijetí rezervace (hotovost) – text', 'reserveandpay' ), 'textarea', __( 'Posílá se, když zákazník zvolí platbu hotově; obsahuje i údaje pro platbu převodem předem.', 'reserveandpay' ) ),
				'tpl_overdue_subject'   => array( __( 'Připomínka platby po splatnosti – předmět', 'reserveandpay' ), $text ),
				'tpl_overdue_body'      => array( __( 'Připomínka platby po splatnosti – text', 'reserveandpay' ), 'textarea' ),
				'tpl_reminder_subject'  => array( __( 'Připomínka před splatností – předmět', 'reserveandpay' ), $text ),
				'tpl_reminder_body'     => array( __( 'Připomínka před splatností – text', 'reserveandpay' ), 'textarea' ),
				'tpl_expired_subject'   => array( __( 'Vypršení rezervace – předmět', 'reserveandpay' ), $text ),
				'tpl_expired_body'      => array( __( 'Vypršení rezervace – text', 'reserveandpay' ), 'textarea', __( 'Posílá se, když nezaplacená rezervace se splatností před termínem po splatnosti expiruje.', 'reserveandpay' ) ),
				'tpl_part1_subject'     => array( __( '1. část platby přijata – předmět', 'reserveandpay' ), $text ),
				'tpl_part1_body'        => array( __( '1. část platby přijata – text', 'reserveandpay' ), 'textarea', __( 'Posílá se po zaplacení 1. části u platby ve dvou částech; obsahuje QR kód na doplatek.', 'reserveandpay' ) ),
				'tpl_partial_subject'   => array( __( 'Neúplná platba – předmět', 'reserveandpay' ), $text ),
				'tpl_partial_body'      => array( __( 'Neúplná platba – text', 'reserveandpay' ), 'textarea', __( 'Posílá se, když přijde menší částka, než je potřeba; obsahuje QR kód na zbývající částku.', 'reserveandpay' ) ),
				'tpl_lastminute_note'   => array( __( 'Doplněk pro rezervaci na poslední chvíli', 'reserveandpay' ), 'textarea', __( 'Vkládá se místo {last_minute_note}.', 'reserveandpay' ) ),
				'tpl_paid_subject'      => array( __( 'Potvrzení platby – předmět', 'reserveandpay' ), $text ),
				'tpl_paid_body'         => array( __( 'Potvrzení platby – text', 'reserveandpay' ), 'textarea' ),
				'tpl_cancelled_subject' => array( __( 'Zrušení – předmět', 'reserveandpay' ), $text ),
				'tpl_cancelled_body'    => array( __( 'Zrušení – text', 'reserveandpay' ), 'textarea' ),
				'tpl_admin_subject'     => array( __( 'Upozornění pro správce – předmět', 'reserveandpay' ), $text ),
				'tpl_admin_body'        => array( __( 'Upozornění pro správce – text', 'reserveandpay' ), 'textarea' ),
			),
			__( 'Testovací režim', 'reserveandpay' )   => array(
				'test_mode'  => array( __( 'Zapnout testovací režim', 'reserveandpay' ), 'checkbox', __( 'Všechny e-maily pluginu se pošlou jen na testovací adresu. Na vývojovém webu nechte zapnuté.', 'reserveandpay' ) ),
				'test_email' => array( __( 'Testovací e-mail', 'reserveandpay' ), 'email' ),
			),
		);
	}

	public static function sanitize( $in ) {
		if ( Resandpay_Settings::$internal ) {
			return $in; // Interní zápis pluginu (Resandpay_Settings::update) – hodnoty jsou už připravené.
		}
		$in  = is_array( $in ) ? $in : array();
		$out       = Resandpay_Settings::all();
		$from_form = ! empty( $in['__form'] );
		foreach ( self::fields() as $section ) {
			foreach ( $section as $key => $def ) {
				$type = $def[1];
				if ( ! array_key_exists( $key, $in ) && ! ( $from_form && 'checkbox' === $type ) ) {
					continue; // Částečná aktualizace – ponechat stávající hodnotu.
				}
				$val = $in[ $key ] ?? null;
				switch ( $type ) {
					case 'checkbox':
						$out[ $key ] = empty( $val ) ? 0 : 1;
						break;
					case 'number':
						$out[ $key ] = max( 0, (float) str_replace( ',', '.', (string) $val ) );
						break;
					case 'int':
						$out[ $key ] = max( -365, min( 365, (int) $val ) );
						break;
					case 'password':
						// Nové heslo jen z odeslaného formuláře; už zašifrovanou hodnotu nikdy nešifrovat znovu.
						if ( $from_form && '' !== (string) $val && 0 !== strpos( (string) $val, 'v1:' ) ) {
							$out[ $key ] = Resandpay_Payments::encrypt( (string) $val );
							update_option( 'resandpay_imap_pwd_saved', time(), false );
						}
						break;
					case 'select':
						$out[ $key ] = isset( $def[3][ $val ] ) ? (string) $val : (string) array_key_first( $def[3] );
						break;
					case 'page':
						$out[ $key ] = absint( $val );
						break;
					case 'email':
						$out[ $key ] = sanitize_email( (string) $val );
						break;
					case 'textarea':
						$out[ $key ] = sanitize_textarea_field( (string) $val );
						break;
					default:
						$out[ $key ] = sanitize_text_field( (string) $val );
				}
			}
		}
		$out['currency']  = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['currency'] ), 0, 3 ) ) ?: 'CZK';
		$out['vs_prefix'] = substr( preg_replace( '/\D/', '', $out['vs_prefix'] ), 0, 4 );
		$out['iban']      = strtoupper( preg_replace( '/\s+/', '', $out['iban'] ) );
		$out['imap_port']        = max( 1, min( 65535, (int) $out['imap_port'] ) ) ?: 993;
		$out['payment_batch']    = max( 1, min( 500, (int) $out['payment_batch'] ) );
		$out['payment_interval'] = max( 15, (int) $out['payment_interval'] );
		$out['bank_sender']      = sanitize_email( $out['bank_sender'] ) ?: 'info@rb.cz';
		foreach ( array( 'event' => 'AK', 'service' => 'SL', 'slot' => 'TE' ) as $k => $def ) {
			$out[ 'code_prefix_' . $k ] = substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $out[ 'code_prefix_' . $k ] ) ), 0, 6 ) ?: $def;
			$out[ 'code_digits_' . $k ] = max( 2, min( 8, (int) $out[ 'code_digits_' . $k ] ) );
		}
		return $out;
	}

	public static function page() {
		$s = Resandpay_Settings::all();
		echo '<div class="wrap"><h1>' . esc_html__( 'Nastavení ReserveAndPay', 'reserveandpay' ) . '</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'resandpay_settings_group' );
		echo '<input type="hidden" name="' . esc_attr( Resandpay_Settings::OPTION ) . '[__form]" value="1">';
		foreach ( self::fields() as $title => $section ) {
			echo '<h2' . ( __( 'Kontrola plateb', 'reserveandpay' ) === $title ? ' id="rap-payments"' : '' ) . '>' . esc_html( $title ) . '</h2>';
			if ( __( 'E-maily', 'reserveandpay' ) === $title ) {
				echo '<p class="description">' . esc_html__( 'Proměnné:', 'reserveandpay' ) . ' <code>{name} {email} {phone} {note} {event} {date} {location} {amount} {vs} {proforma} {due_date} {account} {iban} {company} {free} {reason} {last_minute_note} {pay_choice} {event_code} {event_url} {total} {paid} {remaining} {last_payment} {amount_now} {due_now} {part1} {part1_due} {final_due} {split_note}</code></p>';
			}
			echo '<table class="form-table">';
			foreach ( $section as $key => $def ) {
				$name = Resandpay_Settings::OPTION . '[' . $key . ']';
				$id   = 'rap_' . $key;
				$val  = $s[ $key ] ?? '';
				echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $def[0] ) . '</label></th><td>';
				switch ( $def[1] ) {
					case 'checkbox':
						echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $val ), true, false ) . '>';
						break;
					case 'textarea':
						echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="8" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
						break;
					case 'page':
						wp_dropdown_pages(
							array(
								'name'              => esc_attr( $name ),
								'id'                => esc_attr( $id ),
								'selected'          => (int) $val,
								'show_option_none'  => '— ' . __( 'nevybráno', 'reserveandpay' ) . ' —',
								'option_none_value' => 0,
							)
						);
						break;
					case 'select':
						echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
						foreach ( $def[3] as $ok => $ol ) {
							echo '<option value="' . esc_attr( $ok ) . '" ' . selected( (string) $val, (string) $ok, false ) . '>' . esc_html( $ol ) . '</option>';
						}
						echo '</select>';
						break;
					case 'password':
						$saved = '' !== Resandpay_Payments::imap_password();
						echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $saved ? __( '•••••• (uloženo)', 'reserveandpay' ) : '' ) . '">';
						if ( defined( 'RESANDPAY_IMAP_PASSWORD' ) ) {
							echo '<p class="description"><strong>' . esc_html__( 'Heslo je nastavené ve wp-config.php – má přednost.', 'reserveandpay' ) . '</strong></p>';
						}
						break;
					case 'int':
						echo '<input type="number" step="1" min="-365" max="365" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="small-text">';
						break;
					case 'number':
						echo '<input type="number" step="0.5" min="0" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="small-text">';
						break;
					default:
						echo '<input type="' . esc_attr( 'email' === $def[1] ? 'email' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text">';
				}
				if ( ! empty( $def[2] ) ) {
					echo '<p class="description">' . esc_html( $def[2] ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</table>';
		}
		submit_button();
		echo '</form></div>';
	}
}
