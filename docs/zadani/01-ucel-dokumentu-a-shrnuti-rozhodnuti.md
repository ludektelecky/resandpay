# 1. Účel dokumentu a shrnutí rozhodnutí

Tento dokument rozpracovává zadané use-case do podrobného analytického zadání pro vývojáře: databázi akcí, termínů a produktů, rezervační (objednávkový) formulář, vystavení platebního příkazu s QR kódem a kontrolu i správu úhrad na základě e-mailových notifikací z Raiffeisenbank. Dokument navazuje na projektové zadání webu slehkouhlavou.cz (WordPress + GeneratePress + GenerateBlocks + Ultimate Member) a rozšiřuje jeho Fázi 4 („rezervace a migrace“).

Se zadavatelem byla upřesněna klíčová architektonická rozhodnutí, ze kterých celý návrh vychází:

| **Otázka** | **Rozhodnutí** |
|---|---|
| Technický základ pro rezervace, objednávky a platby | Vlastní rezervační modul (WordPress plugin) – nahrazuje dříve plánovaný plugin Amelia. Jeden datový model akce → rezervace → platba. Nebude se stavět e-shop (WooCommerce) ani použit rezervační plugin třetí strany. |
| Typy akcí | Jednodenní akce, vícedenní akce (např. 5denní pobyt ve Španělsku – jedna rezervace platí pro celou akci) a krátké akce s časem od–do (např. 20. 1. 2027, 16:00–18:00). |
| Služby s termíny (masáže, skupinové lekce) | Hanka vypisuje termíny služby hromadně generátorem. Délka termínu a počet míst jsou pevné (zákazník je nevolí), ale jde o nastavitelné parametry služby: masáž 60 min a 1 místo, Sound Healing např. 15 míst. Termín lze rezervovat kdykoli až do jeho začátku; nestihne-li platba dorazit, zákazník platí na místě. |
| Rezervace a místa | Jedna rezervace = jedno místo = vlastní variabilní symbol. Chce-li zákazník více míst, vytvoří více samostatných rezervací (každou lze samostatně zaplatit i stornovat). |
| Splatnost | Nastavitelné pravidlo: počet dní od rezervace, vůči termínu (−1 = den před, 0 = v den termínu, +1 = den po termínu) nebo kombinace (dřívější z obou). Výchozí pravidlo v nastavení, přepsání u akce i služby. |
| Způsob platby | Primárně bankovní převod s QR kódem. Hotovost na místě je volitelná doplňková možnost (zapíná se v nastavení, u akce nebo služby, bez dalších omezení). I při volbě hotovosti dostane zákazník QR kód pro případnou platbu předem. |
| Identifikace akcí | Každá akce, služba i termín služby má jednoznačný čitelný kód (AK-2026-0012, SL-003, TE-2026-0145). Kód je neměnný a nikdy se nepoužije znovu; identifikuje akci, VS dál identifikuje konkrétní rezervaci a platbu (kap. 3.8). |
| Odbavení na místě | Hanka na lekci vyhledá rezervaci podle VS z potvrzovacího e-mailu (v E4 i načtením QR kódu) a označí platbu hotově nebo převodem. Příjmový doklad se nevystavuje. |
| Vícedenní akce | Rezervuje se vždy celá akce; jednotlivé dny samostatně rezervovat nelze. |
| Překrývání akcí | Akce a termíny se mohou časově překrývat. Systém na překryv pouze upozorní, nejde o chybu a vytvoření nebrání. |
| Stornopodmínky | Nejsou předmětem tohoto dokumentu – řeší je samostatný dokument. Systém pouze umožní rezervaci zrušit a uvolnit místo. |
| Platba ve dvou částech | U akce nebo služby lze zapnout 1. část platby (pevná částka nebo procento) a doplatek; vždy lze zaplatit i vše najednou. Menší platba se zapíše automaticky a zákazník je upozorněn (kap. 3.9). |
| Název | Plný název ReserveAndPay, v menu administrace ResAndPay, zkratka RAP jen tam, kde je nutné nejkratší označení (kap. 14.5). |
| Platební pokyn a e-maily | PDF platební pokyn s hlavičkou „INSYSTEMS CZ s.r.o. · S lehkou hlavou…“ (logo webu, slehkouhlavou.cz, info@example.cz) a QR platbou; posílá se jako příloha e-mailu i trvalý odkaz ke stažení. E-maily jsou v HTML v barvách webu s QR kódem přímo v těle (kap. 5). |
| Fakturace | Nebude se vystavovat daňový doklad (faktura). Postačí neformální platební pokyn (proforma), ale musí nést jednoznačný, jednorázový identifikátor platby. |
| Párování plateb | Bude se parsovat obsah e-mailových avíz služby „Informuj mě“ Raiffeisenbank (RB) o příchozích platbách, doručených do schránky platby@example.cz – nikoli napojení na účetní software nebo PSD2 API. |
| Účet pro příjem plateb | Raiffeisenbank, účet 1234567890/5500 (IBAN CZ71 5500 0000 0012 3456 7890), majitel INSYSTEMS CZ s.r.o. – dle vzorového avíza, k potvrzení zadavatelem. Údaje se zadávají v nastavení pluginu. |
| Kde běží kontrola plateb | Výhradně ve WordPressu na hostingu Active24, spouštění serverovým CRONem Active24. Žádný externí middleware. |
| Režim kontroly plateb | Parametrizovatelný v administraci: ručně (tlačítko „Zkontrolovat platby nyní“) nebo automaticky v nastaveném intervalu. Interval je konfigurovatelná hodnota, standardně 60 min. |
| Univerzálnost pluginu | Fáze 1: plugin pro slehkouhlavou.cz, ale bez čehokoli natvrdo zapsaného (identifikace, schránka, banka, vzhled, texty, měna) – viz kap. 14.1. Fáze 2: zobecnění pro libovolný WordPress web (kap. 14.3). |
| Vazba na členství | Po úspěšné platbě se zákazníkovi automaticky odemkne obsah / role v Ultimate Member – nákup může založit nebo rozšířit členský účet. |

**Pozn.:** Vlastní rezervační modul odstraňuje závislost na cizím pluginu, obcházení jeho platebních metod a riziko rozbití integrace při jeho aktualizacích. Cenou je vyšší objem vývoje a vlastní údržba (viz odhad v kap. 12 a rizika v kap. 11). Kombinace „parsing e-mailů místo bankovního API“ + „vše na sdíleném hostingu“ přináší rizika, která jsou shrnuta v kap. 10 a 11.
