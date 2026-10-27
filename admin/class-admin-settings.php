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
				'brand_name'        => array( __( 'Značka v hlavičce', 'reserveandpay' ), $text, __( 'Za jménem provozovatele, např. „INSYSTEMS CZ s.r.o. · S lehkou hlavou…“. Logo nastavíte níže (Logo ve výstupech).', 'reserveandpay' ) ),
				'contact_web'       => array( __( 'Web v kontaktech', 'reserveandpay' ), $text, __( 'Např. slehkouhlavou.cz – v hlavičce a patičce platebního pokynu.', 'reserveandpay' ) ),
				'contact_email'     => array( __( 'E-mail v kontaktech', 'reserveandpay' ), 'email' ),
				'qr_recipient_name' => array( __( 'Jméno příjemce v QR platbě', 'reserveandpay' ), $text, __( 'Pole RN (max. 35 znaků, bez diakritiky). Prázdné = jméno provozovatele.', 'reserveandpay' ) ),
				'pdf_footer'        => array( __( 'Patička platebního pokynu', 'reserveandpay' ), $text ),
				'logo_source'       => array( __( 'Logo ve výstupech', 'reserveandpay' ), 'select', __( 'Platí pro všechny výstupy ReserveAndPay: platební pokyn (PDF), slevový poukaz (PDF) a e-maily. Logo webu = Vzhled → Přizpůsobit → Identita webu (PNG, JPG nebo SVG); dokud není nahrané, použije se logo z nastavení pluginu níže. V e-mailech se SVG nahrazuje textovou sestavou loga (e-mailové programy SVG nezobrazí).', 'reserveandpay' ), array( 'site' => __( 'Logo webu (jinak logo z nastavení pluginu)', 'reserveandpay' ), 'plugin' => __( 'Vždy logo z nastavení pluginu', 'reserveandpay' ) ) ),
				'logo_line1'        => array( __( 'Logo – první část názvu', 'reserveandpay' ), $text, __( 'Logo z nastavení pluginu (řádková sestava dle grafického manuálu): symbol níže, první část písmem Fraunces, druhá kurzívou ve zlatohnědé.', 'reserveandpay' ) ),
				'logo_line2'        => array( __( 'Logo – druhá část názvu (kurzívou)', 'reserveandpay' ), $text ),
				'brand_symbol_svg'  => array( __( 'Symbol loga (SVG)', 'reserveandpay' ), 'svg', __( 'Vektorový symbol značky (výchozí: lotos z vlny dle grafického manuálu). Povolené prvky svg, g, path, circle, line, polyline, ellipse.', 'reserveandpay' ) ),
				'brand_motto'       => array( __( 'Motto', 'reserveandpay' ), $text, __( 'Na slevovém poukazu pod dechovou linkou, verzálkami.', 'reserveandpay' ) ),
			),
			__( 'Rezervace a platby', 'reserveandpay' ) => array(
				'currency'          => array( __( 'Výchozí měna', 'reserveandpay' ), $text, __( 'Třípísmenný kód, např. CZK.', 'reserveandpay' ) ),
				'due_mode'          => array( __( 'Výchozí pravidlo splatnosti', 'reserveandpay' ), 'select', __( 'U akce i služby lze pravidlo přepsat. Splatnost je vždy do konce dne.', 'reserveandpay' ), Resandpay_Events::due_mode_labels() ),
				'due_days'          => array( __( 'Splatnost – dní od rezervace', 'reserveandpay' ), 'number' ),
				'due_offset'        => array( __( 'Splatnost – dní vůči termínu', 'reserveandpay' ), 'int', __( '−1 = den před termínem, 0 = v den termínu, +1 = den po termínu. Nezaplacená rezervace se splatností před termínem po splatnosti expiruje (místo se uvolní, zákazník dostane e-mail); se splatností v den termínu nebo po něm přejde do stavu „Po splatnosti“ a zákazník dostane připomínku.', 'reserveandpay' ) ),
				'reminder_days'     => array( __( 'Připomínka před splatností (dní)', 'reserveandpay' ), 'number', __( 'Kolik dní před splatností poslat zákazníkovi připomínku nezaplacené rezervace (i 1. části a doplatku). 0 = nepřipomínat.', 'reserveandpay' ) ),
				'cash_enabled'      => array( __( 'Povolit platbu v hotovosti na místě', 'reserveandpay' ), 'checkbox', __( 'Doplňková možnost k platbě převodem (QR), která zůstává předvybraná. U akce i služby lze přepsat.', 'reserveandpay' ) ),
				'last_minute_hours' => array( __( 'Hranice pro platbu na místě (hodin před začátkem)', 'reserveandpay' ), 'number', __( 'Rezervace vytvořená později než tato hranice dostane i možnost zaplatit na místě a po splatnosti neexpiruje. Výchozí 24 h.', 'reserveandpay' ) ),
				'coupon_prefix'     => array( __( 'Předpona kódu slevového kupónu', 'reserveandpay' ), $text, __( 'Automatické kódy mají tvar PŘEDPONA-XXXX-XXXX-XX (např. SLH-7KQ4-M9XD-2P).', 'reserveandpay' ) ),
				'vs_prefix'         => array( __( 'Prefix variabilního symbolu', 'reserveandpay' ), $text, sprintf( /* translators: 1: příklad VS, 2: příklad VS příští rok */ __( 'Číslice a proměnné na začátku VS: %%YYYY%% = rok (2026), %%YY%% = rok dvoumístně (26), %%MM%% = měsíc (01–12); vyhodnotí se k okamžiku rezervace, takže se prefix s novým rokem změní sám. Po dosazení nejvýš 4 číslice. Pořadové číslo za prefixem běží dál a nikdy se nevrací – VS je vždy jedinečný. Příští rezervace dostane VS %1$s, stejná rezervace za rok by dostala %2$s.', 'reserveandpay' ), Resandpay_Reservations::format_vs( (int) get_option( 'resandpay_sequence' ) + 1 ), Resandpay_Reservations::format_vs( (int) get_option( 'resandpay_sequence' ) + 1, strtotime( '+1 year' ) ) ) ),
				'gdpr_page'         => array( __( 'Stránka se zásadami zpracování osobních údajů', 'reserveandpay' ), 'page' ),
				'terms_page'        => array( __( 'Stránka s obchodními a stornopodmínkami', 'reserveandpay' ), 'page' ),
				'events_page'       => array( __( 'Stránka s přehledem akcí', 'reserveandpay' ), 'page', __( 'Stránka se shortcodem [reserveandpay_program]. Archiv /akce/ a kategorie se na ni přesměrují a detail akce na ni odkazuje.', 'reserveandpay' ) ),
			),
			__( 'Detail akce', 'reserveandpay' )       => array(
				'detail_layout'    => array( __( 'Vzhled stránky akce', 'reserveandpay' ), 'select', __( 'S galerií: nahoře název, termín, cena a fotky, pod nimi popis akce a vedle něj rezervační formulář. Jednoduchý: údaje, text a formulář pod sebou (vzhled do verze 0.6).', 'reserveandpay' ), array( 'full' => __( 'S galerií a rezervační kartou', 'reserveandpay' ), 'basic' => __( 'Jednoduchý', 'reserveandpay' ) ) ),
				'time_display_default' => array( __( 'Zobrazení času akcí a termínů', 'reserveandpay' ), 'select', __( 'Výchozí volba; u každé akce a služby ji lze změnit v „Další nastavení“. Jen datum: na webu, v e-mailech a na platebním pokynu bez času. Administrace čas zobrazuje vždy.', 'reserveandpay' ), array( 'show' => __( 'Zobrazovat čas', 'reserveandpay' ), 'date' => __( 'Jen datum (bez času)', 'reserveandpay' ) ) ),
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
			__( 'Ochrana formuláře proti robotům', 'reserveandpay' ) => array(
				'antibot_min_seconds'    => array( __( 'Minimální doba vyplnění formuláře (s)', 'reserveandpay' ), 'number', __( 'Robot formulář odešle okamžitě. Výchozí 3 s, 0 = nekontrolovat.', 'reserveandpay' ) ),
				'rate_ip_window_minutes' => array( __( 'Limit z jedné IP adresy – okno (minut)', 'reserveandpay' ), 'number', __( 'Délka okna pro následující limit. Výchozí 10 min.', 'reserveandpay' ) ),
				'rate_ip_max_window'     => array( __( 'Limit z jedné IP adresy – rezervací za okno', 'reserveandpay' ), 'number', __( 'Výchozí 3. 0 = bez limitu. Pozor: firma nebo domácnost může sdílet jednu IP adresu.', 'reserveandpay' ) ),
				'rate_ip_max_day'        => array( __( 'Limit z jedné IP adresy – rezervací za 24 h', 'reserveandpay' ), 'number', __( 'Výchozí 10. 0 = bez limitu.', 'reserveandpay' ) ),
				'rate_email_max_event'   => array( __( 'Limit aktivních rezervací jednoho e-mailu u jedné akce', 'reserveandpay' ), 'number', __( 'Výchozí 3 (každé místo = jedna rezervace). 0 = bez limitu. Další místa lze přidat ručně v administraci.', 'reserveandpay' ) ),
				'rate_notify'            => array( __( 'Upozornit správce na zablokované rezervace', 'reserveandpay' ), 'checkbox', __( 'Při překročení limitu četnosti přijde e-mail na adresu pro upozornění, nejvýš 1× za hodinu.', 'reserveandpay' ) ),
				'ip_hash_days'           => array( __( 'Uchovávat otisk IP adresy (dní)', 'reserveandpay' ), 'number', __( 'IP adresa se neukládá, jen její nevratný otisk pro limity; po této době se smaže. Výchozí 30 dní.', 'reserveandpay' ) ),
				'turnstile_enabled'      => array( __( 'Ověření „nejsem robot“ (Cloudflare Turnstile)', 'reserveandpay' ), 'checkbox', __( 'Volitelné, ve výchozím stavu vypnuto. Zapnout jen při problému s roboty – jde o službu třetí strany (Cloudflare), kterou je nutné uvést v zásadách zpracování osobních údajů. Klíče: dash.cloudflare.com → Turnstile.', 'reserveandpay' ) ),
				'turnstile_site_key'     => array( __( 'Turnstile – Site Key', 'reserveandpay' ), $text ),
				'turnstile_secret'       => array( __( 'Turnstile – Secret Key', 'reserveandpay' ), $text ),
			),
			__( 'Připomínky akcí', 'reserveandpay' )   => array(
				'event_reminder_hours'  => array( __( 'Připomínka akce zákazníkům (hodin před začátkem)', 'reserveandpay' ), 'number', __( 'Výchozí hodnota; u akce i služby ji lze změnit. Připomínka přijde jednou každé aktivní rezervaci (ne rezervaci vzniklé až po tomto okamžiku a ne u externích termínů). 0 = nepřipomínat.', 'reserveandpay' ) ),
				'event_summary_enabled' => array( __( 'Přehled účastníků den předem', 'reserveandpay' ), 'checkbox', __( 'Jednou denně e-mail se seznamem zítřejších akcí a termínů s rezervacemi (jméno, telefon, stav platby, poznámka).', 'reserveandpay' ) ),
				'event_summary_time'    => array( __( 'Čas odeslání přehledu', 'reserveandpay' ), 'text', __( 'Ve formátu HH:MM (např. 18:00). Přehled odejde při první kontrole po tomto čase.', 'reserveandpay' ) ),
				'event_summary_email'   => array( __( 'E-mail pro přehled účastníků', 'reserveandpay' ), 'email', __( 'Prázdné = e-mail pro upozornění na nové rezervace.', 'reserveandpay' ) ),
			),
			__( 'Statistika', 'reserveandpay' )        => array(
				'stats_roles' => array( __( 'Statistiku vidí role', 'reserveandpay' ), 'roles', __( 'Přehled termínů a obsazenosti (ResAndPay → Statistika a stránka se shortcodem [reserveandpay_stats]). Uživatelé s oprávněním vidí jména zákazníků a stav plateb – přidělujte jen těm, kdo s rezervacemi pracují.', 'reserveandpay' ) ),
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
				'tpl_event_reminder_subject' => array( __( 'Připomínka akce – předmět', 'reserveandpay' ), $text ),
				'tpl_event_reminder_body'    => array( __( 'Připomínka akce – text', 'reserveandpay' ), 'textarea', __( 'Posílá se N hodin před začátkem (Připomínky akcí). {reminder_note} = text u akce / služby, {payment_note} = stav platby, {where} = „Kde: místo“ (jen je-li místo zadané), {map_url} = odkaz na mapu místa.', 'reserveandpay' ) ),
				'tpl_event_summary_subject'  => array( __( 'Přehled účastníků – předmět', 'reserveandpay' ), $text, __( '{date} = datum, {count} = počet rezervací.', 'reserveandpay' ) ),
				'tpl_event_summary_body'     => array( __( 'Přehled účastníků – úvodní text', 'reserveandpay' ), 'textarea', __( 'Pod text se automaticky připojí seznam akcí a účastníků.', 'reserveandpay' ) ),
				'tpl_reminder_subject'  => array( __( 'Připomínka před splatností – předmět', 'reserveandpay' ), $text ),
				'tpl_reminder_body'     => array( __( 'Připomínka před splatností – text', 'reserveandpay' ), 'textarea' ),
				'tpl_expired_subject'   => array( __( 'Vypršení rezervace – předmět', 'reserveandpay' ), $text ),
				'tpl_expired_body'      => array( __( 'Vypršení rezervace – text', 'reserveandpay' ), 'textarea', __( 'Posílá se, když nezaplacená rezervace se splatností před termínem po splatnosti expiruje.', 'reserveandpay' ) ),
				'tpl_part1_subject'     => array( __( '1. část platby přijata – předmět', 'reserveandpay' ), $text ),
				'tpl_part1_body'        => array( __( '1. část platby přijata – text', 'reserveandpay' ), 'textarea', __( 'Posílá se po zaplacení 1. části u platby ve dvou částech; obsahuje QR kód na doplatek.', 'reserveandpay' ) ),
				'tpl_partial_subject'   => array( __( 'Neúplná platba – předmět', 'reserveandpay' ), $text ),
				'tpl_partial_body'      => array( __( 'Neúplná platba – text', 'reserveandpay' ), 'textarea', __( 'Posílá se, když přijde menší částka, než je potřeba; obsahuje QR kód na zbývající částku.', 'reserveandpay' ) ),
				'tpl_discount_lost_subject' => array( __( 'Změna předpisu – sleva zanikla – předmět', 'reserveandpay' ), $text ),
				'tpl_discount_lost_body'    => array( __( 'Změna předpisu – sleva zanikla – text', 'reserveandpay' ), 'textarea', __( 'Posílá se, když sleva za včasnou rezervaci zanikne kvůli platbě po splatnosti; obsahuje QR kód na zbývající částku.', 'reserveandpay' ) ),
				'tpl_free_subject'          => array( __( 'Rezervace zdarma – předmět', 'reserveandpay' ), $text ),
				'tpl_free_body'             => array( __( 'Rezervace zdarma – text', 'reserveandpay' ), 'textarea', __( 'Posílá se u rezervace zdarma (slevový kupón 100 %).', 'reserveandpay' ) ),
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

	/** SVG symbolu loga – jen bezpečné vektorové prvky (žádné skripty, odkazy ani obrázky). */
	public static function sanitize_svg( $svg ) {
		$attrs = array_fill_keys( array( 'xmlns', 'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'points', 'transform', 'opacity' ), true );
		$out = trim( wp_kses( $svg, array_fill_keys( array( 'svg', 'g', 'path', 'circle', 'line', 'polyline', 'ellipse' ), $attrs ) ) );
		return str_replace( ' viewbox=', ' viewBox=', $out );
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
				if ( ! array_key_exists( $key, $in ) && ! ( $from_form && in_array( $type, array( 'checkbox', 'roles' ), true ) ) ) {
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
					case 'svg':
						$out[ $key ] = self::sanitize_svg( (string) $val );
						break;
					case 'roles':
						$known       = array_keys( wp_roles()->get_names() );
						$out[ $key ] = implode( ',', array_values( array_intersect( array_map( 'sanitize_key', (array) $val ), $known ) ) );
						break;
					default:
						$out[ $key ] = sanitize_text_field( (string) $val );
				}
			}
		}
		$out['currency']  = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['currency'] ), 0, 3 ) ) ?: 'CZK';
		$vs_tpl = strtoupper( preg_replace( '/\s+/', '', (string) $out['vs_prefix'] ) );
		if ( preg_replace( '/%(YYYY|YY|MM)%|\d/', '', $vs_tpl ) !== '' || strlen( Resandpay_Reservations::vs_prefix( $vs_tpl ) ) > 4 ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( Resandpay_Settings::OPTION, 'vs_prefix', __( 'Prefix VS může obsahovat jen číslice a proměnné %YYYY%, %YY%, %MM% a po dosazení mít nejvýš 4 číslice – ponechán původní.', 'reserveandpay' ) );
			}
			$vs_tpl = (string) Resandpay_Settings::get( 'vs_prefix' );
		}
		$out['vs_prefix'] = $vs_tpl;
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
				echo '<p class="description">' . esc_html__( 'Proměnné:', 'reserveandpay' ) . ' <code>{name} {email} {phone} {note} {event} {date} {location} {amount} {vs} {proforma} {due_date} {account} {iban} {company} {free} {reason} {last_minute_note} {pay_choice} {event_code} {event_url} {total} {paid} {remaining} {last_payment} {amount_now} {due_now} {part1} {part1_due} {final_due} {split_note} {price_full} {discount} {discount_label} {coupon} {reminder_note} {payment_note} {where} {map_url}</code></p>';
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
					case 'roles':
						$sel = array_filter( explode( ',', (string) $val ) );
						echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
						foreach ( wp_roles()->get_names() as $rk => $rl ) {
							echo '<label class="rap-inline" style="margin-right:1.2em"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $rk ) . '" ' . checked( in_array( $rk, $sel, true ), true, false ) . '> ' . esc_html( translate_user_role( $rl ) ) . '</label> ';
						}
						break;
					case 'svg':
						echo '<div class="rap-svg-preview" style="width:60px;height:48px">' . self::sanitize_svg( (string) $val ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- prošlo wp_kses.
						echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
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
