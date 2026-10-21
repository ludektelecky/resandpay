# Vývojový deník ReserveAndPay

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
