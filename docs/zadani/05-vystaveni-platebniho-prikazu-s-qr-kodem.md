# 5. 2) Vystavení platebního příkazu s QR kódem

## 5.1 Standard: QR Platba / SPD (SPAYD)

Pro generování QR kódu se použije český bankovní standard SPD (Short Payment Descriptor, také SPAYD), který podporují všechny běžné bankovní aplikace v ČR včetně Raiffeisenbank. Formát je textový řetězec začínající „SPD*1.0*“, do kterého se kóduje účet (IBAN, povinné), částka, měna, variabilní symbol, datum splatnosti a zpráva pro příjemce.

| **Klíč SPD** | **Význam** | **Hodnota v našem případě** |
|---|---|---|
| ACC | IBAN účtu (povinné) | IBAN účtu pro příjem plateb u Raiffeisenbank: CZ7155000000001234567890 (z nastavení pluginu; bez IBAN se QR negeneruje) |
| AM | Částka | Cena za jedno místo (kap. 3.2) |
| CC | Měna | CZK |
| X-VS | Variabilní symbol (max. 10 číslic) | Unikátní identifikátor rezervace – viz 5.3 |
| DT | Datum splatnosti (RRRRMMDD) | Datum vystavení + splatnost, nejpozději začátek akce (kap. 3.6) |
| MSG | Zpráva pro příjemce (max. 60 znaků) | Kód akce/termínu a jméno zákazníka jen v ASCII (bez diakritiky a typografických znaků, bez „*“), např. „AK-2026-0012 Jana Novakova“ (kap. 3.8) |
| RN | Jméno příjemce (max. 35 znaků) | Nastavitelné; výchozí jméno provozovatele „INSYSTEMS CZ s.r.o.“ – bankovní aplikace ukáže, komu se platí |

**Implementace:** SPD řetězec se sestaví z rezervace a nastavení; matici QR kódu (úroveň opravy M) počítá přibalená knihovna TCPDF a obrázek PNG skládá plugin sám, takže hosting nepotřebuje rozšíření GD ani Imagick. QR kód i platební údaje se vloží do PDF platebního pokynu, do těla e-mailu a na potvrzovací stránku. U zaplacené rezervace a u jiné měny než CZK se QR negeneruje (EUR – EPC QR ve fázi 2).

## 5.2 Obsah platebního pokynu (proformy)

- Hlavička: „INSYSTEMS CZ s.r.o. · S lehkou hlavou…“ (provozovatel = majitel účtu, značka z nastavení), adresa a IČ, logo (jednotné pro všechny výstupy – viz níže), web slehkouhlavou.cz a e-mail info@example.cz; nadpis „Platební pokyn“ s číslem a datem vystavení
- Odběratel: jméno, firma a IČ/DIČ (je-li zadána), e-mail, telefon
- Jednoznačný identifikátor platby (číslo proformy + variabilní symbol) – viz 5.3
- Rezervovaná akce/služba: název, kód (kap. 3.8), termín, místo, cena
- Platební údaje: číslo účtu, IBAN, částka, variabilní symbol, datum splatnosti
- QR kód pro platbu – posílá se vždy, i u rezervace s volbou hotovosti (kap. 3.7)
- Patička „Nejedná se o daňový doklad“ s webem a e-mailem (text patičky je v nastavení); vzhled dle grafického manuálu (Fraunces, Work Sans, krémový rámeček platebních údajů)
- U zaplacené rezervace rámeček „Uhrazeno“ s datem platby, bez QR kódu; u volby hotovosti text o platbě na místě a možnosti zaplatit předem
- Logo ve všech výstupech ReserveAndPay (od verze 0.8.2) – platební pokyn (PDF), slevový poukaz (PDF) a HTML e-maily – se bere z jednoho místa: Nastavení → Identifikace → „Logo ve výstupech“. Výchozí je logo webu (Vzhled → Přizpůsobit → Identita webu; PNG, JPG nebo SVG – změnou loga webu se změní všude); dokud logo webu není nahrané, nebo je zvoleno „Vždy logo z nastavení pluginu“, použije se řádková sestava loga dle grafického manuálu (vektorový symbol lotosu z vlny + „s lehkou“ Fraunces Medium a „hlavou“ Fraunces Italic ve zlatohnědé), jejíž symbol (SVG) a texty jsou v nastavení. V e-mailu se PNG/JPG vkládá jako obrázek; SVG a symbol z nastavení se v e-mailu nahrazují textovou sestavou loga, protože e-mailové programy SVG nezobrazí. Průhlednost PNG se zachová.
- Odkaz na stornopodmínky (samostatný dokument), informace o tom, co se stane po vypršení splatnosti (kap. 7.2b), a u rezervací na poslední chvíli o možnosti platby na místě (kap. 3.6)

## 5.3 Jednoznačný identifikátor platby

- Variabilní symbol (VS) – čistě numerický, max. 10 číslic, generovaný sekvenčně (např. RRMMDDxxxx nebo interní řada od pevného počátku). VS je klíč, podle kterého modul kontroly plateb rozpozná platbu; díky SPD QR kódu si ho platící aplikace přenese automaticky.
- Číslo proformy – čitelnější interní formát (např. PF-2026-000123) pro zákazníka a administraci; pro párování se nepoužívá.
- Tvar VS (od verze 0.7.5): prefix + pořadové číslo. Prefix se nastavuje v Nastavení → Rezervace a platby a může obsahovat číslice a proměnné %YYYY% (rok, 2026), %YY% (rok dvoumístně, 26) a %MM% (měsíc, 01–12), které se vyhodnotí k okamžiku rezervace – s novým rokem se prefix změní sám (výchozí %YY%: 26000123 letos, 27000124 v roce 2027). Po dosazení nejvýš 4 číslice; jinou hodnotu nastavení neuloží. Pořadové číslo za prefixem se nikdy nevrací ani se s rokem nenuluje, takže VS zůstává jedinečný i při změně prefixu.

**Pozn.:** VS musí být pro každou rezervaci jedinečný a nikdy se nesmí znovu použít (ani po zrušení/expiraci), aby pozdní platba expirované rezervace nebyla zaměněna s jinou, novější rezervací.

## 5.4 Odeslání e-mailu a stažení na odkaze

- HTML e-mail v barvách webu (krémový podklad, zlatohnědá linka a tlačítko, značka v hlavičce, kontakty v patičce) s textovou verzí pro klienty bez HTML. Text e-mailu je ze šablony v nastavení.
- Obrázky v e-mailu (logo, QR kódy) a PDF platebního pokynu se vkládají jako soubory (dočasné soubory se po odeslání smažou), ne z paměti – jen tak je spolehlivě odešlou i SMTP pluginy (např. FluentSMTP), které přílohy přidávají znovu podle cesty k souboru (od verze 0.9.1). S FluentSMTP se vložené obrázky v některých programech mohou ukázat navíc i jako přílohy.
- Pod textem rámeček „Platební údaje“ (u hotovosti „Platba předem převodem (nepovinné)“): částka, VS, číslo účtu, IBAN, splatnost (u hotovosti bez splatnosti) a QR kód vložený přímo do e-mailu (inline obrázek) – zobrazí se i bez povolení stahování obrázků.
- PDF platebního pokynu jako příloha e-mailu a tlačítko „Stáhnout platební pokyn (PDF)“ – trvalá, netriviálně uhodnutelná URL (token rezervace), platná i později pro archivaci zákazníkem.
- Totéž (QR kód, platební údaje, tlačítko PDF) na potvrzovací stránce po rezervaci; odkaz na PDF i v detailu rezervace v administraci.
- Platební údaje, QR kód a PDF obsahují e-maily: přijetí rezervace (převod i hotovost), připomínka po splatnosti a opětovné zaslání z administrace. Potvrzení platby a zrušení jsou bez nich.
