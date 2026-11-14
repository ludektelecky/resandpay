# 4. 1) Rezervační formulář, kalendář a administrace

Formulář je součástí rezervačního modulu. Zobrazí se v detailu akce, resp. po výběru volného termínu služby. Primární platební možností je „Bankovní převod (QR platba)“; je-li povolena, nabídne formulář jako doplňkovou možnost „Hotově na místě“ (kap. 3.7). Vzhled odpovídá grafickému manuálu značky v1 (krémový podklad #FAF6EF, primární tlačítko zlatohnědá #8C6423, data akcí a eyebrow ve švestkové #6B3F4B, vlasové linky #D6C3A0, písma Fraunces a Work Sans, rohy 2 px, tlačítka ve tvaru „sloveso + šipka“). Barvy a písma jsou v pluginu nastavitelné přes CSS proměnné.

## 4.1 Návrh polí formuláře

| **Pole** | **Povinné** | **Poznámka** |
|---|---|---|
| Jméno a příjmení | Ano | Základní pole |
| E-mail | Ano | Na tuto adresu jde platební pokyn i všechny notifikace |
| Telefon | Ano | Pro případ dotazu k rezervaci/platbě |
| Firma + IČ/DIČ | Volitelné, podmíněně | Zobrazit jen při zaškrtnutí „Nakupuji na firmu“ – viz otevřená otázka 2 |
| Poznámka pro lektorku | Ne | Volný text, např. zdravotní omezení u masáže/jógy (citlivý údaj – kap. 9) |
| Způsob platby | Jen je-li povolena hotovost | „Převodem – QR platba“ (předvybráno, doporučeno) / „Hotově na místě“ (kap. 3.7) |
| Souhlas se zpracováním osobních údajů (GDPR) | Ano | Checkbox s odkazem na zásady zpracování |
| Souhlas s obchodními a stornopodmínkami | Ano | Obsah stornopodmínek řeší samostatný dokument |
| Přihlásit se k newsletteru (MailerLite) | Ne | V souladu s existující MailerLite architekturou webu |

**Otevřená otázka 2:** Mají firemní zákazníci (B2B, např. Office jóga) jít stejným formulářem s podmíněnými poli IČ/DIČ, nebo zůstanou u odděleného poptávkového formuláře dle projektového zadání?

**Pozn.:** Jedna rezervace vždy odpovídá jednomu místu a má vlastní variabilní symbol. Pro více míst vyplní zákazník formulář vícekrát (po odeslání nabídne potvrzovací stránka „Rezervovat další místo“ s předvyplněnými údaji). Každé místo lze tak samostatně zaplatit i stornovat.

## 4.2 Tok rezervace – kroky

- Zákazník vybere akci v kalendáři/výpisu, nebo volný termín služby.
- Vyplní rezervační formulář (4.1) a odešle.
- Modul atomicky ověří volnou kapacitu, vytvoří rezervaci ve stavu „Čeká na platbu“ (při volbě hotovosti „Platba na místě“), vypočte splatnost (kap. 3.6), zablokuje jedno místo a přidělí jí unikátní VS (kap. 5.3).
- Systém odešle e-mail s platebním pokynem a QR kódem (kap. 5; u hotovosti potvrzení s QR kódem pro případnou platbu předem) a zobrazí potvrzovací stránku se shrnutím.
- Pokud zákazník nemá účet, nabídne se mu (nepovinně) jeho založení – princip „nižší bariéry“ z projektového zadání.

## 4.3 Kalendář a výpis akcí na webu

- Stránka „Kalendář akcí“ (v menu webu) s přepínačem Seznam / Kalendář a filtrem kategorií (štítky). Adresa /akce/ a archivy kategorií se na ni přesměrují.
- Seznam: řádky oddělené vlasovou linkou – datum (u vícedenních rozsah), kategorie, název, termín a místo, cena, volná místa a tlačítko „Rezervovat termín →“; obsazené a zrušené akce jsou označeny a mají „Zobrazit detail →“. Služby jako souhrnné karty (kap. 3.4).
- Kalendář: měsíční mřížka (pondělí první), vícedenní akce přes všechny dny, listování měsíci, zvýrazněný dnešek; pod 860 px se zobrazí jako seznam dnů.
- Přehled se roztáhne až na šířku 1120 px i v užším textovém sloupci šablony.
- Úvodní stránka webu – sekce „Nadcházející události“: krátký přehled z pluginu (shortcode [reserveandpay_upcoming], vkládá ho šablona). Na prvním místě je vždy nejbližší služba s volným termínem (souhrnná karta s nejbližším volným termínem a tlačítkem „Vybrat termín →“), za ní nadcházející akce podle data začátku; celkem nejvýše 3 položky. Zrušené akce a akce, které už začaly, se nevypisují; obsazené akce zůstávají s označením „Obsazeno“. Pod přehledem je odkaz „Zobrazit všechny termíny →“ na stránku Kalendář akcí. Bez vypsaných termínů se zobrazí hláška „Momentálně nejsou vypsané žádné termíny…“.
- Detail akce: kategorie, termín, místo s odkazem na mapu, cena, volná místa, tlačítko k formuláři, program (u vícedenních) a odkaz „← Všechny termíny“.
- Zobrazení času (od verze 0.7.6): u akce v rámečku „Parametry akce“ (řádek Termín) a u služby (platí pro všechny její termíny, i již vypsané) volba „Zobrazení času“ – Výchozí z nastavení / Zobrazovat čas / Jen datum. Výchozí hodnota je v Nastavení → Detail akce (výchozí „Zobrazovat čas“). Při „Jen datum“ se na webu (výpis, kalendář, detail, přehled na úvodní stránce, potvrzení), v e-mailech zákazníkovi a na platebním pokynu uvádí jen datum, např. „12.–14. 3. 2027“, „28. 2. – 2. 3. 2027“; ve výběru volných termínů služby je místo času tlačítko „Rezervovat“ (vhodné pro jeden termín denně). Administrace a e-mail správci čas zobrazují vždy a čas se dál používá pro splatnost, uzávěrku, rezervace na poslední chvíli, řazení a překryvy.
- Volitelně později: odkaz „Přidat do kalendáře“ (.ics) v potvrzovacím e-mailu.

| **Shortcode** | **Použití** |
|---|---|
| [reserveandpay_program] | Přehled akcí s přepínačem Seznam/Kalendář a filtrem (stránka Kalendář akcí) |
| [reserveandpay_events] | Jen seznam akcí (parametry limit, category, filter, services) |
| [reserveandpay_calendar] | Jen měsíční kalendář |
| [reserveandpay_slots service="bukalni-masaz"] | Volné termíny služby (stránka služby). Parametry: service = slug, kód (např. SL-003) nebo ID služby; days = počet dní na stránku (7–90, výchozí 21); description = zobrazení popisu služby z editoru služby v RaP: "toggle" = rozbalovací (název služby je odkaz, popis se rozbalí pod ním), "yes" = vždy zobrazený nad termíny, "no" = nezobrazovat; bez parametru platí volba „Popis na webu“ u služby (výchozí Nezobrazovat; od verze 0.12.1). Služba bez popisu nemá klikací název. Hodí se zvlášť u externí rezervace, kde zákazník detail termínu s popisem neuvidí. |
| [reserveandpay_upcoming] | Krátký přehled pro úvodní stránku: 1. nejbližší služba, pak akce podle data, max. 3 položky (parametry limit, category, first_service, more) |
| [reserveandpay_booking id="…"] | Detail a formulář konkrétní akce (na stránce akce se vkládá automaticky) |

## 4.4 Administrace pro Hanku

- Kódy (kap. 3.8): sloupec Kód v seznamu akcí a služeb, vyhledání podle kódu (najde i termín), kód v editaci, v seznamu a detailu rezervací, při odbavení a v CSV exportu; hledání rezervací podle kódu akce nebo služby.
- Menu „ResAndPay“: Rezervace, Nová rezervace, Odbavení, Kategorie akcí, Akce, Lokace, Služby, Generátor termínů, Nastavení, Nástroje.
- Přehled akcí a termínů s obsazeností (rezervováno / zaplaceno / volno); seznam akcí s filtrem Akce / Individuální termíny / Vše (výchozí bez individuálních termínů).
- Editace akce: pole termínu, místa, kapacity a ceny v pravém panelu editoru, méně častá nastavení sbalená; konec akce se doplní automaticky, termín služby přebírá počet míst ze služby (lze upravit); upozornění na chybějící údaje (i v seznamu akcí).
- Duplikace akce („Duplikovat“) – vytvoří koncept se stejnými údaji bez rezervací, stačí změnit termín.
- Seznam účastnic u akce: jméno, kontakt, stav platby (vč. „Platba na místě“), poznámka; export do CSV/Excelu.
- Ruční vytvoření rezervace (např. telefonická domluva), ruční zrušení rezervace s e-mailem zákaznici a ruční označení „zaplaceno hotově“. U rezervace je vidět zvolený způsob platby (štítek „zvolila hotovost“), v detailu akce počet a částka rezervací s hotovostí a odkaz na odbavení.
- Upozornění na časové překryvy akcí a termínů (informativní, nebrání uložení).
- Generátor a hromadné rušení termínů služeb (kap. 3.4).
- Přehled plateb a nespárovaných avíz s ručním přiřazením (kap. 6.3).
- Nastavení: kontrola plateb (6.4), výchozí pravidlo splatnosti (3.6), povolení hotovosti (3.7), hranice pro platbu na místě (3.6), texty e-mailů.

## 4.5 E-mailové notifikace

| **E-mail** | **Kdy se odesílá** |
|---|---|
| Platební pokyn s QR kódem | Ihned po vytvoření rezervace |
| Potvrzení rezervace – hotovost | Ihned po rezervaci s volbou hotovosti; obsahuje i platební údaje a QR kód pro platbu předem (kap. 3.7) |
| Připomínka po splatnosti | Jednou, když rezervace přejde do stavu „Po splatnosti“ (splatnost v den termínu nebo po něm, nezaplacený doplatek; kap. 3.6, 3.9) |
| Připomínka před splatností | N dní před splatností (nastavitelné, 0 = vypnuto), jednou pro každou splatnost (1. část, doplatek) – kap. 3.6 |
| 1. část platby přijata | Po zaplacení 1. části; potvrzuje místo a obsahuje QR kód na doplatek (kap. 3.9) |
| Neúplná platba | Po přijetí menší částky, než je potřeba; obsahuje zaplacenou a zbývající částku a QR kód na zbytek (kap. 3.9) |
| Potvrzení platby a rezervace | Po spárování platby (kap. 7.2a) |
| Vypršení rezervace | Po uplynutí splatnosti před termínem bez jakékoli platby; s odkazem na akci pro novou rezervaci (kap. 7.2b) |
| Připomínka akce | Nastavený čas před začátkem (výchozí 24 h) |
| Zrušení rezervace / akce | Při ručním zrušení Hankou nebo zrušení celé akce |

Texty všech e-mailů jsou upravitelné v administraci (prostý text se zástupnými proměnnými: jméno, akce, kód akce, termín, místo, částka, VS). Plugin je odesílá jako HTML e-mail v barvách webu s textovou verzí; u e-mailů s platbou připojí rámeček s platebními údaji, QR kódem, odkazem na PDF a PDF v příloze (kap. 5.4). V Nástrojích lze obnovit výchozí texty. Pro ostrý provoz se doporučuje odesílání přes SMTP – viz otevřená otázka 9. Každý odeslaný e-mail se zapisuje do seznamu odeslaných e-mailů (čas, typ, příjemce – v testovacím režimu i skutečná testovací adresa –, předmět a výsledek). Seznam je v ResAndPay → Nástroje (hledání podle e-mailu, VS a předmětu, filtr „jen chyby“) a u každé rezervace v jejím detailu; odpovídá na otázku „odešel zákazníkovi e-mail?“. „Odesláno“ znamená předání poštovnímu serveru – doručení do schránky (spam, firemní karanténa) web ověřit nemůže. Záznamy starší než nastavený počet dní (výchozí 365) se mažou automaticky.

## 4.6 Testovací režim a diagnostika e-mailů

- Testovací režim (výchozí zapnutý na vývojovém webu): všechny e-maily pluginu jdou jen na testovací adresu, původní příjemce je uveden v předmětu i textu. V administraci je režim viditelně označen.
- Nástroje: zkušební e-mail na libovolnou adresu, log posledních chyb odesílání, upozornění na nevhodnou doménu odesílatele; úklid testovacích dat (jen v testovacím režimu, potvrzení slovem SMAZAT).
- Zjištění z testu na Active24: e-maily z WordPressu se doručí, jen pokud je odesílatel na doméně webu (slehkouhlavou.cz). Pro ostrý provoz se doporučuje SMTP plugin se skutečnou schránkou (spolehlivější doručení a nižší riziko spamu).

## 4.7 Odbavení na místě

Stránka ResAndPay → Odbavení je určená i pro telefon – Hanka ji otevře na lekci. Slouží ke spárování rezervace a plátce na místě:

- Vyhledání rezervace podle variabilního symbolu z potvrzovacího e-mailu. Stačí zadat VS i bez úvodních nul nebo s mezerami; vložený text QR platby (SPD) se rozpozná podle pole X-VS.
- Načtení QR kódu kamerou telefonu přímo na stránce Odbavení (tlačítko „Načíst QR kód kamerou“): QR kód z e-mailu, potvrzovací stránky nebo PDF se přečte, z pole X-VS se vezme VS a zobrazí se karta rezervace. Používá vestavěný čtečku prohlížeče (Chrome/Android), jinak přibalenou knihovnu jsQR (Safari/iPhone); vyžaduje HTTPS.
- Karta rezervace: jméno, akce a termín, částka, VS, stav, zvolený způsob platby, kontakt a poznámka. Tlačítka „Zaplaceno hotově“ a „Zaplaceno převodem“ (převod ověřený na místě), volitelně e-mail s potvrzením platby.
- Přehled dne (s listováním po dnech): akce a termíny s aktivními rezervacemi, počet nezaplacených a částka k vybrání na místě; u každé nezaplacené rezervace rychlá tlačítka Hotově / Převodem. Štítek „hotově“ označí rezervace, které zvolily hotovost.
- Zaplacenou ani zrušenou rezervaci nelze odbavit znovu; expirovanou lze (klientka je na místě), i nad kapacitu.
