# Vývojový deník ReserveAndPay

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
