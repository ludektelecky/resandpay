# Vývojový deník ReserveAndPay

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
