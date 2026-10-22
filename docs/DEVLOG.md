# Vývojový deník ReserveAndPay

## 0.7.2 (22. 10. 2026)

- **Seznam odeslaných e-mailů**: každý e-mail pluginu se zapíše – čas, typ (Rezervace přijata, 1. část platby přijata, Připomínka…), příjemce (v testovacím režimu i skutečná testovací adresa), předmět a výsledek (✓ odesláno / ✗ chyba s popisem)
  - **ResAndPay → Nástroje → Odeslané e-maily**: hledání podle e-mailu, VS a předmětu, filtr „jen chyby“, stránkování; odkaz na rezervaci
  - **Detail rezervace → Odeslané e-maily**: všechny e-maily k dané rezervaci
  - „Odesláno“ = předáno poštovnímu serveru; doručení do schránky (spam, firemní karanténa) web ověřit nemůže
  - doba uchování v Nastavení → E-maily (výchozí 365 dní), starší záznamy maže CRON; úklid testovacích dat smaže i záznamy k rezervacím
  - zapisuje se i zkušební e-mail z Nástrojů; seznam se vede od této verze
- Databáze verze 6 (nová tabulka seznamu e-mailů)
- Testy: 334 kontrol + souběh; analytické zadání v3.0 (kap. 4.5, 8, 13)

## 0.7.1 (22. 10. 2026)

- **Oprava: QR kód „Nebo zaplaťte vše najednou“** měl splatnost doplatku; nově má splatnost **1. části** (v QR kódu, e-mailu, PDF i na potvrzovací stránce). Kdo by platil vše najednou až ke splatnosti doplatku, tomu by rezervace mezitím expirovala
- **Starší texty e-mailů**: proměnné `{amount}` a `{due_date}` v e-mailech zákazníkovi znamenají u rozdělené nebo částečně zaplacené platby částku a splatnost, která se platí teď (1. část / zbytek) – dříve uložené texty (např. „Částka: {amount} · Splatnost: {due_date}“ z verze 0.1.0) tak ukazují správné údaje i bez obnovení. Celou cenu a konečnou splatnost dávají `{total}` a `{final_due}`; e-mail správci uvádí celou cenu
- **Oprava: pomlčka v názvu lokace a akce** se v PDF zobrazovala jako „&#8211;“
- Testy: 325 kontrol + souběh; analytické zadání v2.9 (kap. 3.9, 13)

## 0.7.0 (22. 10. 2026)

- **Nový vzhled stránky akce** podle schváleného vzoru („Vzor stránky akce RaS“):
  - **záhlaví**: odkaz „← Všechny termíny“, kategorie (eyebrow), nadpis, **perex** (pole Úryvek akce), Kdy / Kde (adresa a mapa), cena „/ osoba“ (u vícedenní „za celou akci“) a tlačítko „Rezervovat místo →“; u obsazené akce „Obsazeno“
  - **galerie** vpravo: velká fotka 4:3 a čtvercové náhledy, kliknutím se přepne velká fotka (bez JavaScriptu se fotka otevře); popisek fotky z knihovny se zobrazí pod ní. Bez fotek tlumená plocha s vlnami (náhradní pozadí)
  - **dechová linka** mezi záhlavím a popisem (dýchá 6,5 s, vypnutá při omezení animací)
  - pod ní **popis akce** z blokového editoru (u vícedenní akce i program) a vedle něj **rezervační karta** (při posunu zůstává na místě): „Rezervovat místo“, **„5 volných míst z 16“**, tečky po místech (do 40 míst), **uzávěrka** (je-li nastavená), formulář; po odeslání v kartě potvrzení s QR kódem
  - pod 860 px jeden sloupec, fotky nahoře
- **Fotky akce** – nový panel při úpravě akce: výběr z Knihovny médií (standardní editor galerie – pořadí přetažením, popisky), nejvýše 12 fotek; první je vždy **Náhledový obrázek** akce. Termín služby bez vlastních fotek použije náhledový obrázek služby. Duplikace akce fotky převezme
- **Nastavení → Detail akce**: „Vzhled stránky akce“ (S galerií a rezervační kartou – výchozí / Jednoduchý = vzhled do 0.6) a „Nadpis akce v záhlaví pluginu“ (výchozí zapnuto; titulek šablony GeneratePress se skryje)
- Na stránce akce s novým vzhledem: GeneratePress bez postranního panelu, skrytý náhledový obrázek šablony (fotky jsou v galerii), třídy `rap-detail-full` / `rap-detail-own-title` na `<body>`
- Nové CSS proměnné `--rap-gold-light`, `--rap-card-top`, `--rap-card-bottom`, `--rap-on-dark`
- Pro šablony: `Resandpay_Frontend::render_event_page()`, `Resandpay_Events::photos()`
- Testy: +14 kontrol (galerie, fotky, záhlaví, karta, obsazenost, nastavení, duplikace, potvrzení v kartě); analytické zadání beze změny (v2.8, doplní se při další verzi)

## 0.6.2 (22. 10. 2026)

- Nový shortcode **`[reserveandpay_upcoming]`** – krátký přehled nadcházejících pro úvodní stránku webu: na **prvním místě vždy nejbližší služba** s volným termínem (souhrnná karta jako v přehledu akcí), za ní **nadcházející akce podle data**; celkem **nejvýše 3 položky** (parametr `limit`)
  - zrušené akce a akce, které už začaly (např. probíhající vícedenní), se vynechají; obsazené akce zůstanou s označením „Obsazeno“
  - parametry `category`, `first_service="no"` (jen akce), `more="no"` (bez odkazu „Zobrazit všechny termíny →“ na stránku s přehledem akcí)
- Data přehledu pro šablony: `Resandpay_Views::upcoming_feed()`, filtr `resandpay_upcoming_feed`
- Filtr **`resandpay_enqueue_assets`** – šablona zapne styly pluginu i na stránce bez shortcodu v obsahu (úvodní stránka vykreslovaná šablonou)
- Úvodní stránka slehkouhlavou.cz: sekce „Nadcházející události“ v child theme už není ručně psaná, vypisuje ji plugin
- Testy: 294 kontrol + souběh; analytické zadání v2.8 (kap. 4)

## 0.6.1 (22. 10. 2026)

- **Pozdní rezervace**: rezervuje-li zákazník až po splatnosti podle pravidla (např. splatnost 30 dní před akcí a rezervace 14 dní předem), platí **celou částku do N dní od rezervace** (N = „1. část splatná do“, výchozí 7), nejpozději den před akcí. Platba se nedělí, rezervace po splatnosti normálně expiruje a nepřechází do „Platba na místě“ (dříve se brala jako rezervace na poslední chvíli – splatnost až začátek akce, platba na místě i při vypnuté hotovosti)
- Rezervace na poslední chvíli zůstává jen pro rezervace méně než „Hranice pro platbu na místě“ (výchozí 24 h) před začátkem
- Nápověda u splatnosti a platby ve dvou částech v editaci akce a služby
- Testy: 295 kontrol + souběh; analytické zadání v2.7 (kap. 3.6, 3.9, 13)

## 0.6.0 – Etapa E6a (22. 10. 2026)

- **Expirace**: nezaplacená rezervace se splatností před termínem po splatnosti přejde do stavu Expirováno, místo se uvolní a zákazník dostane e-mail „Rezervace vypršela“ s odkazem na akci (nová šablona)
- **Pozdní platba** expirované rezervace: je-li místo volné, rezervace se obnoví jako zaplacená; je-li obsazeno, avízo jde k ruční kontrole
- **Připomínka před splatností**: N dní předem (Nastavení → Rezervace a platby, výchozí 1 den, 0 = vypnuto), jednou pro každou splatnost (1. část i doplatek); nová šablona
- **Platba ve dvou částech (1. část a doplatek)** u akce i služby (Další nastavení): 1. část jako pevná částka nebo procento, splatnost 1. části v dnech od rezervace; doplatek do splatnosti akce
  - e-mail, platební pokyn (PDF) a potvrzovací stránka: rozpis, QR na 1. část a druhý QR „Nebo zaplaťte vše najednou“
  - po zaplacení 1. části stav **Částečně zaplaceno** a e-mail „1. část platby přijata – místo potvrzeno“ s QR na doplatek
  - nezaplacená 1. část → expirace; nezaplacený doplatek → Po splatnosti s připomínkou, rozhodne Hanka
- **Menší platba** (i u platby najednou) se zapíše automaticky, rezervace je Částečně zaplaceno a zákazník dostane upozornění se zbývající částkou a QR kódem; přeplatek jde k ruční kontrole
- Platby se sčítají (nové pole Zaplaceno); detail rezervace ukazuje Uhrazeno / zbývá a umožňuje **Připsat platbu** (částka, hotově / převodem); odbavení ukazuje částku, která zbývá vybrat; CSV export má sloupce Uhrazeno a Zbývá
- Text připomínky po splatnosti uvádí zbývající částku; nové proměnné {total} {paid} {remaining} {amount_now} {due_now} {part1} {part1_due} {final_due} {last_payment} {event_url} {split_note}
- Databáze verze 5 (u dříve zaplacených rezervací se při aktualizaci doplní zaplacená částka)
- Testy: 288 kontrol + souběh; analytické zadání v2.6 (nová kap. 3.9, zodpovězena otázka 7)

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
