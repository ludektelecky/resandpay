# ResAndPay

**Rezervace a platby pro WordPress** – vlastní plugin pro web [slehkouhlavou.cz](https://slehkouhlavou.cz) (projekt „S lehkou hlavou…“, Hanka Vildomcová).

Plugin pokrývá celý tok **akce → rezervace → platba**: vypsání akcí a termínů, rezervační formulář, platební pokyn s QR kódem (SPD) a automatickou kontrolu plateb z e-mailových avíz UniCredit Bank – vše uvnitř WordPressu, bez externích služeb a bez rezervačních pluginů třetích stran.

> Zdrojové zadání: **SWD EasyShop – Analytické zadání, verze 1.4** (21. 10. 2026).

---

## Funkce

### Akce a termíny
- Jednodenní akce, vícedenní akce (rezervuje se vždy celá akce) a krátké akce od–do
- Individuální termíny (např. masáže) jako akce s kapacitou 1
  - pevná délka termínu a pauza jako nastavitelné parametry služby (výchozí 60 + 15 min)
  - generátor termínů (dny v týdnu, časové okno, období) s náhledem a hromadným rušením
- Překrývání akcí je povoleno – systém pouze upozorní
- Hlídání kapacity: jedna rezervace = jedno místo = vlastní variabilní symbol; atomická kontrola proti přeplnění

### Rezervace
- Rezervační formulář v detailu akce / po výběru volného termínu
- Kalendář a výpis akcí na webu, pohled „Volné termíny“ u služeb
- Stavy: Čeká na platbu · Zaplaceno · Platba na místě · Expirováno · Nejasná platba · Zrušeno
- Rezervace kdykoli až do začátku; při rezervaci na poslední chvíli lze platit na místě

### Platby
- Platební pokyn (proforma) s QR kódem dle standardu SPD, PDF ke stažení přes neuhodnutelný odkaz
- Unikátní, nikdy znovu nepoužitý variabilní symbol pro každou rezervaci
- Kontrola plateb čtením schránky `bankovnitransakce@slehkouhlavou.cz` přes IMAP
  - režim **ručně** (tlačítko „Zkontrolovat platby nyní“) nebo **automaticky** v intervalu (standardně 60 min, konfigurovatelné)
  - spouštění serverovým CRONem hostingu Active24 (ne WP-Cron)
  - nespárované platby k ručnímu přiřazení, log běhů, upozornění při výpadku
- Expirace neuhrazených rezervací a uvolnění místa

### Napojení
- **Ultimate Member** – po zaplacení založení účtu / přidání role
- **MailerLite** – zápis rezervujících se souhlasem
- Transakční e-maily s upravitelnými texty

### Bezpečnost a provoz
- **Testovací režim** – všechny e-maily se přesměrují na adresu správce, ostrá bankovní schránka se nikdy neupravuje
- Úklidový nástroj pro smazání testovacích dat před spuštěním
- Šifrované spojení se schránkou, spouštěcí skript CRONu chráněný tokenem / spouštěný přes PHP CLI

---

## Technologie

| Oblast | Řešení |
|---|---|
| Platforma | WordPress (testováno na 7.1.x), PHP 8.x |
| Šablona webu | GeneratePress (child theme) + GenerateBlocks |
| Hosting | Active24 (sdílený webhosting, serverový CRON) |
| Data | CPT pro akce, služby a lokace; vlastní DB tabulky pro rezervace, platby, bankovní avíza a log |
| QR platba | Standard SPD (SPAYD) |
| Bankovní avíza | IMAP (SSL/TLS), UniCredit Bank |

---

## Struktura repozitáře (plánovaná)

```
resandpay/
├── resandpay.php          # hlavní soubor pluginu
├── includes/              # jádro: akce, rezervace, kapacita, stavy, platby
├── admin/                 # administrace, nastavení, generátor termínů
├── public/                # kalendář, výpis akcí, rezervační formulář
├── templates/             # šablony e-mailů a PDF
├── assets/                # CSS (dle grafického manuálu), JS
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
| E1 | Jádro rezervací: akce, kapacita, formulář, stavy, administrace, e-maily, testovací režim | ⏳ |
| E2 | Kalendář a výpis akcí na webu, styling dle grafického manuálu | ⏳ |
| E3 | Služby a individuální termíny, generátor termínů | ⏳ |
| E4 | QR platební pokyn (SPD), variabilní symbol, PDF | ⏳ |
| E5 | Kontrola plateb (IMAP, párování, ruční/automatický režim, CRON) | ⏳ |
| E6 | Expirace, platba na místě, Ultimate Member, MailerLite | ⏳ |
| E7 | Testování dle akceptačních kritérií a nasazení | ⏳ |

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

## Licence

Soukromý projekt – všechna práva vyhrazena. Kód není určen k veřejnému šíření.
