# ReserveAndPay WP plugin

**Rezervace a platby pro WordPress.** První nasazení: web [slehkouhlavou.cz](https://slehkouhlavou.cz) (projekt „S lehkou hlavou…“). Plugin je navržen tak, aby ho bylo možné ve fázi 2 zobecnit pro libovolný WordPress web.

Plugin pokrývá celý tok **akce → rezervace → platba**: vypsání akcí a termínů, rezervační formulář, platební pokyn s QR kódem (SPD) a automatickou kontrolu plateb z e-mailových avíz Raiffeisenbank – vše uvnitř WordPressu, bez externích služeb a bez rezervačních pluginů třetích stran.

> **Aktuální verze pluginu: 0.9.0** · zdrojové zadání: **ReserveAndPay WP plugin – Analytické zadání, verze 4.3** (25. 10. 2026, vydáno k pluginu 0.9.0) – viz `docs/`.

### Název a označení

| Označení | Kdy | Příklad |
|---|---|---|
| **ReserveAndPay** | plný název produktu – kde je místo | seznam pluginů, titulky stránek administrace, dokumentace, výstup CRONu, metadata PDF |
| **ResAndPay** | krátký název – omezené místo | položka v menu administrace (ResAndPay → Platby…), repozitář |
| **RAP** | zkratka – jen kde je nutné nejkratší označení | předmět testovacích e-mailů `[RAP TEST → …]`, log serveru, technické předpony (`--rap-*`, `rap_*`, `_rap_*`) |

Technické identifikátory se nemění (slug a textdomain `reserveandpay`, předpona `resandpay_`, shortcody `[reserveandpay_…]`).

---

## Funkce

### Akce a termíny
- Jednodenní akce, vícedenní akce (rezervuje se vždy celá akce) a krátké akce od–do
- Služby s termíny z generátoru: masáže (1 místo na termín) i pravidelné skupinové lekce (např. Sound Healing, 15 míst)
  - pevná délka termínu a pauza jako nastavitelné parametry služby (výchozí 60 + 15 min)
  - generátor termínů (dny v týdnu, časové okno, období) s náhledem a hromadným rušením
- Stránka akce s galerií: záhlaví (kategorie, nadpis, perex z úryvku, termín, místo, cena, „Rezervovat místo →“) vedle fotek z Knihovny médií, pod ním popis akce a vedle něj rezervační karta s počtem volných míst a uzávěrkou; volba „Jednoduchý“ vrací dřívější vzhled (Nastavení → Detail akce)
- Překrývání akcí je povoleno – systém pouze upozorní
- Hlídání kapacity: jedna rezervace = jedno místo = vlastní variabilní symbol; atomická kontrola proti přeplnění

### Rezervace
- Rezervační formulář v detailu akce / po výběru volného termínu
- Kalendář a výpis akcí na webu, pohled „Volné termíny“ u služeb
- Zobrazení času u akce i služby: zobrazovat čas, nebo jen datum (výchozí v nastavení)
- Stavy: Čeká na platbu · Částečně zaplaceno · Zaplaceno · Platba na místě · Po splatnosti · Expirováno · Zrušeno
- Rezervace kdykoli až do začátku; při rezervaci na poslední chvíli lze platit na místě
- Pozdní rezervace (až po splatnosti podle pravidla, např. −30 dní): celá částka do 7 dní, nejpozději den před akcí

### Platby
- Platební pokyn (proforma) s QR kódem dle standardu SPD, PDF ke stažení přes neuhodnutelný odkaz
- Unikátní, nikdy znovu nepoužitý variabilní symbol pro každou rezervaci (prefix s proměnnými %YYYY%, %YY%, %MM% – mění se s rokem sám)
- Kontrola plateb čtením schránky `bankovni.transakce@slehkouhlavou.cz` přes IMAP
  - režim **ručně** (tlačítko „Zkontrolovat platby nyní“) nebo **automaticky** v intervalu (standardně 60 min, konfigurovatelné)
  - spouštění serverovým CRONem hostingu Active24 (ne WP-Cron)
  - nespárované platby k ručnímu přiřazení, log běhů, upozornění při výpadku
- Slevy: slevové kupóny (automatický kód SLH-XXXX-XXXX-XX i vlastní, rozsah na akce / služby / kategorie, limity, hromadné generování, zlatý QR kód, PDF poukaz, kupón 100 % = zdarma) a sleva za včasnou rezervaci (zaniká při platbě po splatnosti, Ponechat slevu)
- Evidence jednotlivých plateb u rezervace, odpárování platby (s důvodem, avízo zpět k ruční kontrole), trvalé smazání zrušené rezervace bez platby
- Expirace neuhrazených rezervací (e-mail zákazníkovi, uvolnění místa); pozdní platba rezervaci obnoví, je-li volno
- Připomínka N dní před splatností (nastavitelné, 0 = vypnuto)
- Platba ve dvou částech – **1. část** (pevná částka nebo procento, u akce i služby) a **doplatek**; QR na 1. část i „zaplatit vše najednou“; menší platba se zapíše a zákazník dostane upozornění se zbývající částkou

### Napojení
- **Ultimate Member** – po zaplacení založení účtu / přidání role
- **MailerLite** – zápis rezervujících se souhlasem
- Transakční e-maily s upravitelnými texty

### Bezpečnost a provoz
- Ochrana rezervačního formuláře proti robotům: skryté pole, minimální doba vyplnění, parametrizovatelné limity četnosti (IP adresa jen jako otisk, e-mail u akce), volitelně Cloudflare Turnstile – SWD kap. 9.1
- **Testovací režim** – všechny e-maily se přesměrují na adresu správce, ostrá bankovní schránka se nikdy neupravuje
- Úklidový nástroj pro smazání testovacích dat před spuštěním
- Šifrované spojení se schránkou, spouštěcí skript CRONu chráněný tokenem / spouštěný přes PHP CLI

---

## Fáze vývoje a univerzálnost

**Fáze 1 – plugin pro slehkouhlavou.cz**, psaný podle pravidel připravenosti na zobecnění:

- identifikace provozovatele (jméno, IČ, adresa, IBAN, logo) v nastavení, ne v kódu
- bankovní schránka a přístupy v nastavení
- čtení avíz přes rozhraní **bankovní adaptér** (fáze 1: Raiffeisenbank)
- měna ukládána u akcí, rezervací i avíz od začátku (výchozí CZK)
- vzhled přes CSS proměnné, výchozí hodnoty dle grafického manuálu SLH
- všechny texty přeložitelné (textdomain `reserveandpay`, výchozí čeština)
- obecné služby a typy akcí – konkrétní obsah jsou data v administraci
- Ultimate Member a MailerLite jako volitelné moduly
- jednotný prefix `resandpay_` a vlastní hooky pro rozšíření

**Fáze 2 – zobecnění:** formulář identifikace společnosti, další bankovní adaptéry, nastavitelný vzhled a přepsatelné šablony, test připojení ke schránce, správa služeb, volba měny (EUR → QR EPC/SEPA), průvodce nastavením, automatické aktualizace z GitHub Releases.

---

## Technologie

| Oblast | Řešení |
|---|---|
| Platforma | WordPress (testováno na 7.1.x), PHP 8.x |
| Šablona webu | GeneratePress (child theme) + GenerateBlocks |
| Hosting | Active24 (sdílený webhosting, serverový CRON) |
| Data | CPT pro akce, služby a lokace; vlastní DB tabulky pro rezervace, platby, bankovní avíza a log |
| QR platba | Standard SPD (SPAYD) |
| Bankovní avíza | IMAP (SSL/TLS), Raiffeisenbank (služba Informuj mě) |

---

## Struktura repozitáře (plánovaná)

```
resandpay/
├── reserveandpay.php      # hlavní soubor pluginu
├── includes/              # jádro: akce, rezervace, kapacita, stavy, platby
│   └── banks/             # bankovní adaptéry (Raiffeisenbank, …)
├── admin/                 # administrace, nastavení, generátor termínů
├── public/                # kalendář, výpis akcí, rezervační formulář
├── templates/             # šablony e-mailů a PDF
├── assets/                # CSS (dle grafického manuálu), JS (čtečka QR, jsQR)
├── lib/                   # přibalené knihovny: TCPDF (PDF, QR), písma Fraunces a Work Sans – viz lib/README.md
├── cron/                  # spouštěcí skript pro CRON Active24
├── languages/             # překlady (cs_CZ)
├── tests/                 # automatické testy
├── docs/                  # zadání, deník vývoje, návody k nasazení
└── README.md
```

---

## Plán vývoje

| Etapa | Obsah | Stav |
|---|---|---|
| E0 | Doprecizování otázek, vzorek bankovního avíza, ověření hostingu Active24 | ⏳ |
| E1 | Jádro rezervací: akce, kapacita, formulář, stavy, administrace, e-maily, testovací režim | ✅ 0.1.0 (testuje se) |
| E2 | Kalendář a výpis akcí na webu, styling dle grafického manuálu | ✅ 0.2.0 (testuje se) |
| E3 | Služby a jejich termíny (1 i více míst), generátor termínů | ✅ 0.3.2 |
| E3+ | Pravidla splatnosti, stav Po splatnosti, hotovost, odbavení na místě, kódy akcí/služeb/termínů | ✅ 0.3.3–0.3.4 (testuje se) |
| E4 | QR platba (SPD), PDF platební pokyn, HTML e-maily, odbavení kamerou | ✅ 0.4.0 |
| E5 | Automatická kontrola plateb z avíz Raiffeisenbank (IMAP, CRON), ruční přiřazení | ✅ 0.5.0–0.5.4 |
| E6a | Expirace, připomínky, platba ve dvou částech (1. část a doplatek), pozdní rezervace | ✅ 0.6.0–0.6.1 (testuje se) |
| – | Krátký přehled nadcházejících na úvodní stránce (`[reserveandpay_upcoming]`) | ✅ 0.6.2 |
| – | Stránka akce s galerií a rezervační kartou | ✅ 0.7.0 |
| E6b | Ultimate Member, MailerLite | ⏳ po spuštění |
| E7 | Testování dle akceptačních kritérií a nasazení | ⏳ |
| F2 | Fáze 2 – zobecnění pluginu | ⏳ |

Po etapách E1 a E2 lze rezervace spustit s ručním potvrzováním plateb.

---

## Prostředí

| Prostředí | Adresa | Účel |
|---|---|---|
| Vývoj / staging | slehkouhlavou.cz | Aktuální vývojový web (indexování vypnuto) |
| Staging po spuštění | test.slehkouhlavou.cz *(plánováno)* | Testování nových verzí po spuštění ostrého webu |
| Produkce (dosavadní) | hankavildomcova.cz | Bude po spuštění nového webu ukončena |

---

## Instalace

1. Stáhnout aktuální verzi pluginu jako `.zip` (Releases).
2. WordPress → **Pluginy → Přidat nový → Nahrát plugin** → vybrat `.zip` → Instalovat → Aktivovat.
3. Na vývojovém webu zapnout **testovací režim** v nastavení pluginu.
4. Před každou instalací nové verze vytvořit zálohu webu (Duplicator).

---

## Použití (shortcody)

| Shortcode | Popis |
|---|---|
| `[reserveandpay_program]` | Přehled akcí s přepínačem Seznam / Kalendář a filtrem kategorií (doporučeno pro stránku „Termíny“) |
| `[reserveandpay_events limit="50" category="slug" filter="yes"]` | Seznam nadcházejících akcí (bez individuálních termínů) |
| `[reserveandpay_calendar category="slug"]` | Samotný měsíční kalendář |
| `[reserveandpay_slots service="bukalni-masaz" days="21"]` | Volné termíny služby (např. masáže) po dnech |
| `[reserveandpay_upcoming limit="3" first_service="yes" category="" more="yes"]` | Krátký přehled pro úvodní stránku: na 1. místě nejbližší služba s volným termínem, pak nadcházející akce podle data, celkem max. `limit`; zrušené a probíhající akce vynechá; `more` = odkaz „Zobrazit všechny termíny →“ |
| `[reserveandpay_booking id="123"]` | Detail a rezervační formulář akce (na stránce akce se vkládá automaticky) |

Šablona webu může výpis vložit i bez shortcodu v obsahu stránky (`do_shortcode()` nebo data z `Resandpay_Views::upcoming_feed()`); styly pluginu na takové stránce zapne filtr `resandpay_enqueue_assets`, např. `add_filter( 'resandpay_enqueue_assets', fn( $on ) => $on || is_front_page() );`.

## Testy

```bash
wp eval-file wp-content/plugins/reserveandpay/tests/run-tests.php   # jen na testovacím WordPressu!
bash wp-content/plugins/reserveandpay/tests/concurrency.sh 12
```

---

## Licence

Soukromý projekt – všechna práva vyhrazena. Kód není určen k veřejnému šíření bez souhlasu vlastníka.
