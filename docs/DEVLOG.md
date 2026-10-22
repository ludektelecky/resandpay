# Vývojový deník ReserveAndPay

## 0.5.4 (22. 10. 2026)

- **Oprava: klíč CRONu se ve verzích 0.5.0–0.5.2 neukládal** – stránka Platby při každém otevření ukázala nový klíč, který web neznal; CRON úloha s dříve zkopírovanou adresou proto nefungovala. Od 0.5.3 se klíč ukládá a nemění – **adresu v CRON úloze je potřeba jednou zkopírovat znovu**
- Oprava dvojitého šifrování hesla rozšířena: ve verzích 0.5.0–0.5.2 přidalo každé otevření stránky Platby jednu vrstvu šifrování; heslo se nyní přečte při libovolném počtu vrstev a uloží se znovu jako jedna vrstva
- Testy rozšířeny na 249 kontrol (nezávislé na stavu z předchozího běhu)

## 0.5.3 (22. 10. 2026)

- **Oprava: heslo ke schránce se mohlo zašifrovat dvakrát** – každý interní zápis nastavení v administraci (první otevření stránky Platby s vygenerováním klíče CRONu, obnovení výchozích textů e-mailů) prošel kontrolou formuláře znovu a uložené heslo znovu zašifroval; server pak dostal místo hesla zašifrovaný řetězec („[AUTHENTICATIONFAILED]“). Interní zápisy už kontrolou formuláře neprocházejí a heslo se šifruje jen z odeslaného formuláře
- Již dvakrát zašifrované heslo se po aktualizaci **samo opraví** (dešifruje se správně) – není potřeba ho zadávat znovu
- Testy rozšířeny na 246 kontrol (zápisy nastavení jako v administraci, celý postup v prohlížeči proti serveru Dovecot)

## 0.5.2 (22. 10. 2026)

- Přihlášení ke schránce přednostně přes **AUTHENTICATE PLAIN** (funguje i heslo s diakritikou, uvozovkami nebo zpětným lomítkem; i server bez SASL-IR), jinak / při neúspěchu LOGIN
- Chyba přihlášení uvádí uživatele a **přesnou odpověď serveru** (např. „[AUTHENTICATIONFAILED] Authentication failed.“)
- Stránka Platby ukazuje, **kdy bylo heslo naposledy uloženo**; v nastavení upozornění, že prohlížeč může do pole hesla sám vložit heslo k WordPressu
- Testy rozšířeny na 242 kontrol (přihlášení s diakritikou proti serveru Dovecot); analytické zadání beze změny (v2.5, doplní se při další verzi)

## 0.5.1 (22. 10. 2026)

- Položka v menu administrace se jmenuje **ResAndPay** (dříve „Rezervace“); titulky stránek nesou plný název **ReserveAndPay**
- Zkratka **RAP**: předmět e-mailů v testovacím režimu `[RAP TEST → příjemce]`, technické záznamy v logu
- Pravidla pojmenování zapsána do analytického zadání (v2.5, kap. 14.5), README a pokynů projektu; technické identifikátory beze změny
- Pozn.: starší záznamy deníku uvádějí cesty „Rezervace → …“ – dnes „ResAndPay → …“

## 0.5.0 – Etapa E5 (22. 10. 2026)

- **Kontrola plateb z avíz Raiffeisenbank** (Rezervace → Platby): čtení schránky přes IMAP, rozpoznání avíza „Pohyb na účtě“, ověření pravosti, párování podle VS a částky, rezervace → Zaplaceno + potvrzení zákaznici
- Vlastní **IMAP klient** bez rozšíření php-imap (od PHP 8.4 není součástí PHP): SSL/TLS, BODY.PEEK (nic se neoznačí jako přečtené), přesun zpracovaných avíz do složky „Zpracováno“ (vytvoří se sama), jiné e-maily nechá být; otestováno proti serveru Dovecot
- Vlastní **MIME parser** a **adaptér Raiffeisenbank** (datum a čas, na účet, částka, typ pohybu, protiúčet a plátce, VS/KS/SS, zpráva pro příjemce)
- **Ověření pravosti**: From/Return-Path info@rb.cz, první Received-SPF = Pass z rb.cz, právě jedna hlavička X-Spamd-Result s DKIM a DMARC „allow“ pro rb.cz (vložená falešná hlavička → neověřeno)
- K ruční kontrole: bez VS, neznámý VS, jiná částka/měna, zaplacená či zrušená rezervace, neověřená pravost, změněný formát avíza, možná duplicita; ignoruje se jen odchozí platba a platba na jiný účet; stejné avízo (Message-ID) se nikdy nezpracuje dvakrát
- **Ruční přiřazení** podle VS (návrh rezervace podle VS ve zprávě pro příjemce; rozdíl částky jen s potvrzením) a **vyřešení bez přiřazení** s poznámkou; **zpracování avíza ze souboru .eml**
- **CRON**: volání `https://web/?rap_cron=KLÍČ` (tajný klíč generuje plugin), doporučeno */15; plugin sám hlídá režim (automaticky / jen ručně) a interval (15 min – 1× denně, výchozí 60); při úspěchu bez výstupu, při chybě vypíše důvod. Každé volání zpracuje i splatnosti
- Tlačítka „Zkontrolovat platby nyní“ a „Otestovat připojení ke schránce“ (nic nemění), log běhů (90 dní), stav posledního běhu a volání CRONu
- **Upozornění** na e-mail pro upozornění: avíza k ruční kontrole po běhu; výpadek kontroly > 3× interval (nejvýš 1× denně); počet avíz k ruční kontrole v menu
- Nastavení → Kontrola plateb (server imap.websupport.cz:993 SSL, uživatel, heslo šifrovaně AES-256-GCM nebo konstanta `RESANDPAY_IMAP_PASSWORD`, složky, odesílatel, ověření pravosti, dávka, upozornění, log)
- Databáze verze 4 (tabulky avíz a běhů) – migrace proběhne automaticky
- Analytické zadání v2.4 (vydáno k pluginu 0.5.0); testy rozšířeny na 238 kontrol

## 0.4.0 – Etapa E4 (22. 10. 2026)

- **QR platba (SPD 1.0)**: ACC (IBAN), AM, CC, DT (splatnost), MSG (kód akce + jméno, jen ASCII, max. 60 znaků), RN (jméno příjemce, výchozí provozovatel), X-VS. Bez IBAN nebo u jiné měny než CZK se QR negeneruje. Obrázek PNG skládá plugin sám – hosting nepotřebuje GD ani Imagick
- **PDF platební pokyn** (TCPDF, písma Fraunces a Work Sans dle manuálu): hlavička „INSYSTEMS CZ s.r.o. · S lehkou hlavou…“ s logem webu (Identita webu), adresou a IČ, web a e-mail; odběratel, rezervace s kódem akce, rámeček platebních údajů s QR kódem, texty podle situace (převod / hotovost / poslední chvíle / uhrazeno), patička „Nejedná se o daňový doklad“
- Trvalý odkaz ke stažení PDF (`?rap_pdf=token`), na potvrzovací stránce i v detailu rezervace
- **HTML e-maily v barvách webu** s textovou verzí; u přijetí rezervace (převod i hotovost), připomínky po splatnosti a opětovného zaslání rámeček s platebními údaji, QR kód přímo v těle (inline obrázek) a PDF v příloze. Testovací režim přidá do HTML pruh s původním příjemcem
- Nové výchozí texty e-mailů (platební údaje už neopakují – jsou v rámečku); Nástroje → **Obnovit výchozí texty e-mailů**
- Potvrzovací stránka: QR kód, platební údaje a „Stáhnout platební pokyn (PDF) →“ (upraveno i pro telefon)
- **Odbavení kamerou**: tlačítko „Načíst QR kód kamerou“ – BarcodeDetector, jinak přibalené jsQR (Safari/iPhone); z QR platby vezme VS a ukáže kartu rezervace
- Nastavení → Platební pokyn a QR platba: značka v hlavičce, web a e-mail v kontaktech, jméno příjemce v QR, patička
- Přibalené knihovny v `lib/` (TCPDF 6.10.0 ořezané, LGPL; písma OFL) a `assets/js/jsQR.js` (1.4.0, Apache-2.0)
- Analytické zadání v2.3 (vydáno k pluginu 0.4.0); testy rozšířeny na 189 kontrol

## 0.3.4 (22. 10. 2026)

- **Jednoznačné kódy**: akce `AK-RRRR-NNNN`, služba `SL-NNN`, termín služby `TE-RRRR-NNNN` (rok založení, řada akcí a termínů od 1 každý rok). Kód vznikne při prvním uložení (termíny v generátoru), je neměnný a nikdy se nepoužije znovu; přidělování pod zámkem
- Duplikát akce dostane nový kód; po aktualizaci se kódy automaticky doplní existujícím akcím, službám a termínům (v pořadí založení)
- Nastavení → Kódy akcí, služeb a termínů: předpony a počet číslic (platí pro nové kódy)
- Administrace: sloupec Kód v seznamu akcí a služeb, vyhledání podle kódu (najde i termín), kód v editaci akce/služby, v seznamu a detailu rezervací, při odbavení a v CSV exportu; hledání rezervací podle kódu akce i služby (SL-… najde rezervace všech jejích termínů)
- E-maily: nová proměnná `{event_code}`; shortcody přijímají i kód (`[reserveandpay_booking id="AK-2026-0012"]`, `[reserveandpay_slots service="SL-003"]`)
- Na webu se kód zákaznicím nezobrazuje
- Analytické zadání v2.2 (vydáno k pluginu 0.3.4); testy rozšířeny na 162 kontrol

## 0.3.3 (22. 10. 2026)

- **Pravidla splatnosti** (Nastavení, přepsání u akce i služby): počet dní od rezervace (dosavadní chování, nejpozději začátek termínu), **vůči termínu** (−1 = den před, 0 = v den termínu, +1 = den po termínu, vždy do konce dne) nebo kombinace (dřívější z obou); termíny služby pravidlo převezmou ze služby
- Nový stav **Po splatnosti**: nezaplacená rezervace se splatností v den termínu nebo po něm po splatnosti nepropadne, dál blokuje místo a zákaznice dostane jednu připomínku (nová šablona e-mailu). Rezervace na poslední chvíli bez platby přejde po začátku na „Platba na místě“. Spouští hodinový plánovač WordPressu
- **Platba v hotovosti** jako doplňková možnost (Nastavení, u akce i služby výchozí / povolit / nepovolit): ve formuláři je převod (QR) první a předvybraný, hotovost druhá; rezervace s hotovostí je ve stavu „Platba na místě“ a dostane vlastní e-mail s údaji pro platbu převodem předem
- **Odbavení na místě** (Rezervace → Odbavení, i pro telefon): vyhledání podle VS (i bez úvodních nul, s mezerami nebo z textu QR platby), karta rezervace s tlačítky „Zaplaceno hotově“ / „Zaplaceno převodem“, přehled dne s částkou k vybrání a rychlým odbavením; odkaz z detailu akce
- Seznam, detail a CSV export rezervací ukazují zvolený způsob platby a způsob úhrady („Hotově na místě“); ruční rezervace umí zvolit hotovost
- Databáze verze 2 (sloupce pay_choice, reminded_at) – migrace proběhne automaticky po aktualizaci
- Analytické zadání v2.0, následně v2.1 (na titulní straně a v historii verzí uvedena verze pluginu); testy rozšířeny na 145 kontrol

## 0.3.2 (22. 10. 2026)

- Služba má nové pole **Počet míst na termín** (1–500, výchozí 1). Masáž = 1 místo, skupinová lekce (např. Sound Healing) = více míst; generátor ho propíše do každého termínu
- Termín skupinové služby zůstává ve volných termínech, dokud se nezaplní; na stránce termínů je u času počet volných míst („13 volných míst“) a v záhlaví „max. 15 osob“; detail termínu ukazuje „Volná místa 13 z 15“
- Karta služby v přehledu akcí u skupinové služby ukazuje volná místa nejbližšího termínu („Nejbližší: volno 13 z 15“)
- Volba **Změnu promítnout i do nadcházejících termínů**: termíny s víc rezervacemi, než je nový počet míst, se nezmění a administrace je vypíše
- Počet míst lze upravit i u jednotlivého termínu (pole Kapacita už není u termínu uzamčené na 1)
- Seznam služeb ukazuje počet míst, generátor ho uvádí u názvu služby; hromadné rušení vypíše všechny rezervace obsazeného termínu
- Analytické zadání v1.9; testy rozšířeny na 108 kontrol

## 0.3.1 (21. 10. 2026)

- Přehled akcí: služby (např. Bukální masáž) se zobrazí jako jedna souhrnná karta – nejbližší volný termín (den, čas, délka, místo), cena, počet volných termínů a „Vybrat termín →“ na stránku s volnými termíny; karty se řadí podle data spolu s akcemi
- Filtr kategorie služby tak už nevede na prázdný seznam; v kalendáři se karta služby ukáže nad mřížkou jen při filtru kategorie (jednotlivé termíny v kalendáři nejsou)
- Shortcody `[reserveandpay_program]` / `[reserveandpay_events]` mají parametr `services="no"` pro vypnutí karet služeb
- Oprava: hláška po generování/hromadném rušení termínů se zobrazí i na seznamu akcí

## 0.3.0 – Etapa E3 (21. 10. 2026)

- Nový typ obsahu **Služby** (menu Rezervace → Služby): pevná délka termínu a pauza jako nastavitelné parametry (výchozí 60 + 15 min), cena, měna, místo, splatnost, kategorie, stránka s volnými termíny; popis služby se zobrazí u každého termínu
- **Generátor termínů** (Rezervace → Generátor termínů): dny v týdnu, časové okno, období (max. půl roku) → náhled s možností termíny odškrtnout; existující termíny se přeskočí; překryvy s jinými akcemi jen označí (nebrání)
- Termín = akce typu „individuální termín“ s kapacitou 1; údaje (délka, cena, místo, kategorie) se při generování zkopírují – pozdější změna služby se týká jen nových termínů
- **Hromadné zrušení** v období (nemoc, dovolená): náhled; volné termíny do koše, obsazené označeny jako zrušené a rezervace zrušeny s e-mailem a důvodem
- Shortcode `[reserveandpay_slots service="slug|ID" days="21"]` – volné termíny po dnech jako tlačítka s časem, stránkování „Další termíny →“
- Detail termínu: popis služby, „Termín: volný/obsazený“, odkaz „← Jiný termín“
- Seznam akcí v administraci: filtr Akce / Individuální termíny / Vše (výchozí bez individuálních termínů)

## 0.2.1 (21. 10. 2026)

- Přehled akcí a kalendář se roztáhnou až na 1120 px i v úzkém textovém sloupci šablony (bez vodorovného posuvníku)
- Názvy akcí v kalendáři na dva řádky místo zkrácení

## 0.2.0 – Etapa E2 (21. 10. 2026)

- Vzhled dle grafického manuálu v1 (krémový podklad, vlasové linky, gold-deep tlačítka „sloveso + šipka“, data akcí ve švestkové, Fraunces/Work Sans, rohy 2 px)
- Seznam akcí jako řádky (datum, eyebrow kategorie, název, termín · místo, cena, volná místa, „Rezervovat termín →“), filtr kategorií
- Měsíční kalendář (pondělí první, vícedenní akce přes dny, navigace měsíců, dnešek), pod 860 px jako seznam dnů
- Shortcody `[reserveandpay_program]` (přepínač Seznam/Kalendář + filtr), `[reserveandpay_calendar]`, `[reserveandpay_events]`
- Nastavení „Stránka s přehledem akcí“ – archiv /akce/ a kategorie se na ni přesměrují, detail má odkaz „← Všechny termíny“
- Detail akce: eyebrow, termín ve švestkové, tlačítko na formulář, program po dnech
- Editace akce: pole v pravém panelu editoru, „Další nastavení“ sbalené, automatické doplnění konce, kapacita 1 u individuálního termínu, upozornění na chybějící údaje (i v seznamu akcí)
- Duplikace akce (řádková akce „Duplikovat“ i v panelu Obsazenost) – koncept se stejnými údaji, bez rezervací

## 0.1.1 – Diagnostika e-mailů (21. 10. 2026)

- Nástroje: zkušební e-mail (bez přesměrování), kontrola domény odesílatele, log posledních 20 chyb odesílání
- „Znovu poslat platební údaje“ hlásí chybu, pokud e-mail selže

## 0.1.0 – Etapa E1 (21. 10. 2026)

Hotovo:
- CPT **Akce** (`resandpay_event`, URL `/akce/…`), **Lokace** (`resandpay_location`), taxonomie **Kategorie akcí**
- Typy akcí: jednodenní, vícedenní, krátká od–do, individuální termín (kapacita vždy 1)
- Údaje akce: začátek/konec, lokace, kapacita, cena + měna, splatnost (dní), uzávěrka rezervací, jen pro přihlášené, zrušeno, program po dnech
- Vlastní tabulka rezervací `{prefix}resandpay_reservations`, tabulka zámků `{prefix}resandpay_locks`
- Atomická kontrola kapacity (DB zámek přes primární klíč – funguje na MySQL i SQLite), test souběhu
- Jedna rezervace = jedno místo = vlastní VS (prefix + řada, nikdy znovu nepoužitý), číslo pokynu PF-RRRR-NNNNNN
- Splatnost = konec dne (vytvoření + N dní), nejpozději začátek akce; rezervace na poslední chvíli (hranice v nastavení, výchozí 24 h) → splatnost = začátek, nabídka platby na místě
- Stavy: Čeká na platbu, Zaplaceno, Platba na místě, Expirováno, Zrušeno – s povolenými přechody; reaktivace hlídá kapacitu
- Rezervační formulář (`the_content` u akce + shortcode `[reserveandpay_booking id=""]`), honeypot, nonce, PRG, „Rezervovat další místo“
- Výpis akcí: shortcode `[reserveandpay_events limit="20" category="slug"]`
- Administrace: seznam rezervací (filtry, hledání, stavy, hromadné rušení), detail, ruční rezervace, export CSV (středník, UTF-8 BOM), obsazenost v editaci akce, sloupce v seznamu akcí, upozornění na překryvy (jen informativní)
- E-maily: přijetí rezervace (s platebními údaji), upozornění správce, zaplaceno, zrušeno – šablony v nastavení s proměnnými
- Nastavení: provozovatel, účet/IBAN, splatnost, hranice platby na místě, prefix VS, stránky GDPR/podmínek, odesílatel, šablony, testovací režim
- Testovací režim: všechny e-maily pluginu jdou jen na testovací adresu
- Nástroje: úklid testovacích dat (jen v testovacím režimu, potvrzení SMAZAT)
- Vzhled přes CSS proměnné (`--rap-accent` …), výchozí barvy dle manuálu SLH

Testy: `wp eval-file tests/run-tests.php` (48 kontrol) a `bash tests/concurrency.sh 12` (souběh).

Další etapy: E2 kalendář a styling, E3 generátor termínů, E4 QR + PDF, E5 kontrola plateb, E6 expirace + UM + MailerLite.
