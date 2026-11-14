# 9. GDPR, bezpečnost a uchovávání dat

- Přístup ke schránce platby@example.cz má jen modul kontroly plateb a administrátorka – aplikační heslo mimo veřejně přístupné soubory, vyhrazená schránka jen pro tento účel.
- Avíza obsahují bankovní údaje (číslo protiúčtu) – nutno zahrnout do zásad zpracování osobních údajů a stanovit dobu uchovávání.
- Poznámka pro lektorku může obsahovat zdravotní údaje (zvláštní kategorie osobních údajů) – přístup jen administrátorka, výslovné upozornění ve formuláři, definovaná doba uchovávání.
- Spojení se schránkou výhradně šifrovaně (IMAP přes SSL/TLS); spouštěcí skript CRONu nesmí být veřejně volatelný (PHP CLI nebo URL s tajným tokenem).
- Odkazy ke stažení PDF musí být netriviálně uhodnutelné (dostatečně dlouhý náhodný token).
- MailerLite (kap. 15.1 R17): do MailerLite (zpracovatel osobních údajů – uvést v zásadách zpracování) se předává jen e-mail, jméno, příjmení a volitelně telefon, a jen u rezervací se souhlasem s novinkami; v testovacím režimu se nic nepředává. API klíč je uložen šifrovaně (nebo v konstantě ve wp-config.php). Zákazník se ze skupin neodebírá a odhlášený odběratel se znovu nepřihlašuje.
- Přehled zákazníků (ResAndPay → Zákazníci) je jen souhrn údajů z existujících rezervací – nevzniká nová evidence ani tabulka. Přístup a export CSV mají jen uživatelé s oprávněním k rezervacím (export s bezpečnostním tokenem). Pro rozesílání nabídek a novinek používat jen zákazníky se souhlasem s newsletterem (filtr v přehledu i v exportu).

**Pozn.:** Toto není daňové ani právní poradenství. Otázku, zda „platební pokyn bez faktury“ postačuje z hlediska účetnictví a daní, doporučujeme probrat s účetním/daňovým poradcem – otevřená otázka 5.

## 9.1 Ochrana rezervačního formuláře proti robotům

Rezervace z webu nevyžaduje účet, proto je formulář chráněný proti robotům ve třech vrstvách. Robot by jinak mohl blokovat místa (rezervace drží místo až do splatnosti) a rozesílat e-maily s potvrzením na cizí adresy. Ochrana platí jen pro rezervace z webu; ruční rezervace v administraci se neomezují. Všechny parametry jsou v ResAndPay → Nastavení → Ochrana formuláře proti robotům (0 = vypnuto / bez limitu).

- Vrstva 1 – neviditelná (vždy zapnuta): skryté pole, které vyplní jen robot, a minimální doba vyplnění formuláře ověřená podepsaným časem zobrazení (výchozí 3 s). Zákazník nic nepozná; při příliš rychlém odeslání stačí formulář odeslat znovu.
- Vrstva 2 – limity četnosti (parametrizovatelné): z jedné IP adresy nejvýš 3 rezervace za 10 minut a 10 za 24 hodin; jeden e-mail nejvýš 3 aktivní rezervace u jedné akce. Při překročení dostane zákazník zdvořilou hlášku s kontaktem a správce e-mailové upozornění (nejvýš 1× za hodinu). Pozor: firma nebo domácnost může sdílet jednu IP adresu – proto jsou limity nastavitelné a další místa lze vždy přidat ručně v administraci.
- Vrstva 3 – volitelně Cloudflare Turnstile (ověření „nejsem robot“, většinou bez klikání): ve výchozím stavu vypnuto, zapíná se jen při problému s roboty (Site Key a Secret Key z dash.cloudflare.com). Jde o službu třetí strany – při zapnutí je nutné ji uvést v zásadách zpracování osobních údajů. Při nedostupnosti služby Cloudflare se rezervace neblokují (platí vrstvy 1 a 2).
- Ochrana osobních údajů: IP adresa se neukládá; pro limity se u rezervace ukládá jen její nevratný otisk (HMAC s tajným klíčem webu), který se po nastavené době (výchozí 30 dní) automaticky maže.
- Přehled: ResAndPay → Nástroje → Ochrana formuláře proti robotům ukazuje aktivní vrstvy a počty zablokovaných pokusů podle důvodu; upozornění správci je i v seznamu odeslaných e-mailů.
- Další ochrana v pluginu: bezpečnostní token formuláře (nonce), kontrola kapacity pod zámkem (souběh nepřeplní akci), neuhodnutelné odkazy na platební pokyn, ověření pravosti bankovních avíz (kap. 6.5), šifrované heslo ke schránce a tajný klíč v adrese CRONu.
