# 3. 0) Databáze akcí, termínů a produktů

## 3.1 Typy položek

| **Typ** | **Příklad** | **Charakteristika** |
|---|---|---|
| Jednodenní akce | Workshop, sobota 9:00–16:00 | Jedno datum, čas od–do, kapacita N míst |
| Krátká akce od–do | Sound healing, 20. 1. 2027, 16:00–18:00 | Jako jednodenní akce, trvá několik hodin |
| Vícedenní akce | Jógový pobyt ve Španělsku, 5 dní | Datum a čas začátku a konce přes více dnů. Rezervuje se vždy celá akce – jednotlivé dny nelze. Cena za celou akci. Volitelně program po dnech (informativní text/časy) |
| Termín služby | Bukální masáž, 60 min (1 místo); Sound Healing (15 míst) | Akce s počtem míst podle služby, vzniká generátorem termínů ze služby (kap. 3.4) |
| Pravidelná skupinová lekce | Lekce každé úterý 18:00–19:30 | Řeší se jako služba s počtem míst > 1 a generátorem termínů (kap. 3.4); každý termín má vlastní kapacitu a rezervace |
| Digitální produkt | eBook, nahrávka | Bez termínu a kapacity – kap. 3.5 |

## 3.2 Datový model akce

Akce (včetně individuálních termínů) se vede jako vlastní typ obsahu (CPT „Akce“) – díky tomu má vlastní URL, SEO, popis editovatelný v blokovém editoru a obrázky z Media Library, dle zásady „konfigurovatelné, ne zadrátované“ z projektového zadání.

| **Pole** | **Popis** |
|---|---|
| Kód | Jednoznačný čitelný kód akce (AK-2026-0012) nebo termínu služby (TE-2026-0145) – přidělí ho systém, je neměnný (kap. 3.8) |
| Název | Např. „Jógový pobyt Estepona“, „Sound healing večer“, „Bukální masáž“ |
| Typ | Jednodenní / vícedenní / krátká od–do / individuální termín (kap. 3.1) |
| Kategorie | Vazba na taxonomii webu – Sound healing / Kancelář / Cesty, příp. další |
| Popis a obrázek | Text akce v blokovém editoru, obrázek přes Media Library |
| Místo konání | Lokace (název, adresa, odkaz na mapu) – včetně zahraničí; lokace se ukládají jako číselník pro opakované použití |
| Začátek a konec | Datum a čas začátku a konce; u vícedenní akce rozdílná data |
| Kapacita | Počet míst; termín služby přebírá počet míst ze služby (masáž 1) |
| Cena | Částka za jedno místo (u vícedenní akce za celou akci); měna viz otevřená otázka 6 |
| Pravidlo splatnosti | Režim (od rezervace / vůči termínu / kombinace) a počet dní; prázdné = výchozí z nastavení (kap. 3.6) |
| Platba v hotovosti | Výchozí z nastavení / povolit / nepovolit (kap. 3.7) |
| Uzávěrka rezervací | Do kdy lze rezervovat. Výchozí: až do začátku akce/termínu (kap. 3.6) |
| Stav akce | Koncept / Publikováno / Zrušeno (zrušení akce = e-mail všem rezervujícím) |
| Viditelnost | Veřejná / jen pro přihlášené (např. přednostní registrace členů) |
| Přiřazená role po zaplacení | Která role nebo oprávnění v Ultimate Member se má odemknout (kap. 7.2a) |
| Služba | Jen u individuálních termínů – odkaz na definici služby, ze které termín vznikl (kap. 3.4) |

## 3.3 Hlídání kapacity

- Každá rezervace blokuje právě jedno místo – ve stavech „Čeká na platbu“, „Platba na místě“ i „Zaplaceno“; uvolní ho až expirace nebo zrušení rezervace.
- Ověření volné kapacity a založení rezervace musí proběhnout atomicky (databázová transakce / zámek), aby při současném odeslání dvou rezervací na poslední místo nedošlo k přeplnění akce.
- Počet volných míst se na webu zobrazuje vždy z evidence rezervací – nikdy se nepíše ručně do textu.
- Při vyprodání se rezervační formulář skryje a zobrazí se „Obsazeno“ (čekací listina volitelně ve 2. etapě).

## 3.4 Služby a jejich termíny (masáže, skupinové lekce)

Masáže, pravidelné skupinové lekce a další služby se nerezervují výpočtem volné dostupnosti, ale jako předem vypsané termíny. Každý termín je akce s počtem míst podle služby – u masáže 1 místo, u skupinové lekce více (např. Sound Healing 15). Rezervace termínu probíhá stejným tokem jako u ostatních akcí: jedna rezervace = jedno místo = vlastní VS.

### Definice služby

| **Parametr služby** | **Popis a výchozí hodnota** |
|---|---|
| Kód | Jednoznačný kód služby (SL-003) – přidělí ho systém (kap. 3.8) |
| Název | Např. „Bukální masáž“ |
| Délka termínu | Pevná délka – zákazník ji nevolí. Nastavitelný parametr služby v administraci. Výchozí: 60 min. |
| Pauza mezi termíny | Čas na přípravu mezi dvěma termíny. Nastavitelný parametr. Výchozí: 15 min. |
| Počet míst na termín | Kolik osob se může na jeden termín rezervovat (1–500). Výchozí: 1 = individuální služba (masáž). Více = skupinová lekce (např. Sound Healing 15) – termín zůstává volný, dokud se nezaplní. |
| Cena | Částka v CZK za jedno místo |
| Místo konání | Výchozí lokace pro generované termíny |
| Splatnost a platba | Pravidlo splatnosti a povolení hotovosti pro generované termíny (kap. 3.6, 3.7) |
| Kategorie | Kategorie akcí (např. Bukální masáž) – přenese se na termíny a slouží pro filtr na webu |
| Popis | Text služby – zobrazí se u každého termínu na webu (např. co si vzít, kontraindikace) |
| Stránka s volnými termíny | Stránka webu, na které je výběr volných termínů služby (např. Bukální masáž); karta služby i detail termínu na ni odkazují |

**Pozn.:** Služba je v administraci samostatná položka (ResAndPay → Služby). Při generování se údaje služby (délka, počet míst, cena, místo, kategorie, splatnost) zkopírují do každého termínu. Pozdější změna služby se proto týká jen nově vygenerovaných termínů; již vypsané termíny zůstávají beze změny. Výjimka: změnu počtu míst lze volbou „Změnu promítnout i do nadcházejících termínů“ přenést i do vypsaných termínů – termín, který už má víc rezervací než nový počet míst, se nezmění a administrace ho vypíše. Počet míst lze upravit i u jednotlivého termínu.

### Generátor termínů

- Hanka zvolí službu, dny v týdnu, časové okno (např. 9:00–17:00) a období (nejvýš půl roku dopředu). Generátor vytvoří termíny podle délky služby a pauzy (např. 9:00, 10:15, 11:30 …); poslední termín musí skončit nejpozději v konci okna. Termíny v minulosti se nevytvářejí.
- Před uložením se zobrazí náhled vytvářených termínů; jednotlivé termíny lze z náhledu vyřadit (odškrtnutím). Termíny, které už pro službu existují, náhled označí a při vytvoření se přeskočí – opakované generování tak nevytvoří duplicity.
- Hromadné zrušení termínů za zvolené období (nemoc, dovolená) s náhledem: volné termíny se odstraní (do koše), obsazené termíny se označí jako zrušené, jejich rezervace se zruší a zákaznice dostanou e-mail s uvedeným důvodem.
- Změna délky služby nebo pauzy se projeví u nově generovaných termínů; již vypsané termíny zůstávají beze změny. Změnu počtu míst lze volitelně promítnout i do nadcházejících termínů (viz pozn. výše).
- Překryvy: termín, který se časově kryje s jinou akcí nebo jiným termínem, generátor v náhledu pouze označí upozorněním – nejde o chybu. Hanka rozhodne, zda ho ponechá (výchozí: ponechat), nebo z náhledu vyřadí. Stejné upozornění se zobrazí i při ručním zakládání či úpravě akce.

### Zobrazení na webu

- Na stránce služby je výběr „Volné termíny“: dny (např. „út 3. 11.“) a pod nimi volné časy jako tlačítka. Kliknutí na čas otevře detail termínu s popisem služby, místem, cenou a rezervačním formulářem; odkaz „← Jiný termín“ vede zpět. Stránkování po třech týdnech („Další termíny →“). Bez data v odkazu začíná výpis vždy dnem nejbližšího volného termínu (hledá se bez omezení dopředu); je-li to později než dnes, je nad výpisem věta „Nejbližší volný termín je po 4. 1. 2027 18:45 – dřívější termíny jsou obsazené.“ (druhá část jen tehdy, když dřívější termíny existují a jsou obsazené). Tlačítko „← Předchozí termíny“ vede nejvýše k dnešku. Odkaz s datem (rap_from, šipky) začíná tímto datem. Nemá-li služba žádný volný termín, výpis začíná dneškem s hláškou a výzvou ke kontaktu. Stejně bez omezení dopředu hledá nejbližší volný termín i karta služby v přehledu.
- Obsazené, zrušené a prošlé termíny se nezobrazují. U skupinové lekce zůstává termín v nabídce, dokud jsou volná místa.
- U služby s více místy ukazuje výběr u každého času počet volných míst („13 volných míst“) a v záhlaví kapacitu („max. 15 osob“); detail termínu ukazuje „Volná místa 13 z 15“. U masáže (1 místo) se počet míst neukazuje.
- Přehled akcí (stránka Kalendář akcí) ukazuje službu jako jednu souhrnnou kartu: nejbližší volný termín (den v týdnu, datum vždy s rokem, čas, délka, místo – např. „po 4. 1. 2027 18:45 · 60 min“, stejně jako u akcí), cena, počet volných termínů (u skupinové služby volná místa nejbližšího termínu, např. „volno 13 z 15“) a tlačítko „Vybrat termín →“ na stránku s volnými termíny. Karta se řadí podle data nejbližšího termínu spolu s akcemi; služba bez volných termínů se nezobrazí.
- Měsíční kalendář jednotlivé termíny služeb nezobrazuje (zahltily by ho). Při filtru kategorie služby se nad kalendářem ukáže karta služby.

## 3.5 Digitální produkty bez termínu

eBooky, videa, nahrávky a další materiály ke stažení se vedou ve stejném pluginu jako samostatný typ obsahu „Materiál“ bez termínu a kapacity. Stažení je vždy podmíněno přihlášením; placené materiály sdílejí platební tok rezervací (VS, QR platba, kontrola plateb, kupóny) a po zaplacení se materiál odemkne v účtu zákazníka. Podrobně R11 v kap. 15.1 (implementováno v pluginu 0.13.0).

**Otázka 1 (zodpovězeno 31. 10. 2026): placené digitální materiály se zavádějí – návrh R11 v kap. 15.1, implementace po jeho schválení.**

## 3.6 Splatnost, rezervace na poslední chvíli a platba na místě

Splatnost se počítá podle pravidla akce (nebo služby, ze které termín vznikl); není-li u akce nastaveno, platí výchozí pravidlo z nastavení. Splatnost se spočítá při rezervaci, uloží se jako konkrétní datum a je vždy do konce dne (23:59).

| **Režim** | **Výpočet** | **Příklad (lekce 10. 6. 18:00, rezervace 1. 6.)** |
|---|---|---|
| Od rezervace | Den rezervace + N dní, nejpozději začátek termínu | N = 7 → 8. 6.; N = 30 → 10. 6. 18:00 |
| Vůči termínu | Den termínu + N dní: −1 = den před, 0 = v den termínu, +1 = den po termínu | −1 → 9. 6.; 0 → 10. 6.; +1 → 11. 6. |
| Kombinace | Dřívější z obou pravidel | 7 dní, nejpozději −3 → 7. 6. |
| Pozdní rezervace | Splatnost podle pravidla už uplynula, do začátku zbývá víc než hranice pro platbu na místě: rezervace + N dní (N = „1. část splatná do“, výchozí 7), nejpozději den před termínem | −30, rezervace 27. 5. → 3. 6.; rezervace 6. 6. → 9. 6. |

Akci i termín lze rezervovat kdykoli až do jejich začátku. Platba převodem však u rezervace krátce před začátkem nemusí dorazit včas – v takovém případě zákazník platí na místě. Pravidla:

- Rezervace na poslední chvíli: splatnost by vyšla do začátku termínu a rezervace vzniká méně než X hodin před začátkem (parametr „Hranice pro platbu na místě“, výchozí 24 h). Splatnost = začátek termínu, e-mail obsahuje QR kód i informaci, že lze zaplatit na místě.
- Pozdní rezervace (od verze 0.6.1): splatnost podle pravidla už při rezervaci uplynula, ale do začátku zbývá víc než hranice pro platbu na místě – typicky pobyt se splatností 30 dní předem rezervovaný 14 dní předem. Platí se celá částka (bez rozdělení) do N dní od rezervace, nejpozději den před termínem; rezervace se chová jako běžná – po splatnosti expiruje, na místě se neplatí.
- Taková rezervace po splatnosti neexpiruje – pokud platba do začátku nedorazí, přejde do stavu „Platba na místě“ a místo zůstává blokované.
- Splatnost v den termínu nebo po něm (vůči termínu 0, +1 …): rezervace se nikdy neexpiruje (termín už proběhl). Nedorazí-li platba do splatnosti, přejde do stavu „Po splatnosti“, zákazník dostane jednu připomínku s platebními údaji a Hanka ji vidí v seznamu rezervací. Dorazí-li převod později, spáruje se standardně.
- Rezervace se splatností před začátkem termínu, za kterou do splatnosti nepřišla žádná platba, po splatnosti expiruje: místo se uvolní a zákazník dostane e-mail s odkazem na akci (kap. 7.2b). Dorazí-li platba později a místo je stále volné, rezervace se obnoví jako zaplacená; je-li mezitím obsazeno, platba jde k ruční kontrole.
- Připomínka před splatností: N dní před splatností (nastavení „Připomínka před splatností“, výchozí 1 den, 0 = vypnuto) dostane zákazník nezaplacené rezervace jednu připomínku s platebními údaji a QR kódem – u platby ve dvou částech zvlášť pro 1. část i pro doplatek. Nepřipomíná se u rezervací vzniklých až v okně připomínky, u hotovosti a rezervací na poslední chvíli.
- Hanka po přijetí platby na místě označí rezervaci jako zaplacenou (hotově, nebo převodem) – v seznamu rezervací nebo na stránce Odbavení (kap. 4.7).
- Změna pravidla u akce, služby nebo v nastavení se týká jen nových rezervací.

## 3.7 Platba v hotovosti (doplňková možnost)

Primární a vždy předvybranou platbou je bankovní převod s QR kódem. Hotovost na místě je doplňková možnost bez dalších omezení – jen se zapíná nebo vypíná.

- Zapnutí: výchozí hodnota v nastavení („Povolit platbu v hotovosti na místě“), u akce i služby lze přepsat (výchozí / povolit / nepovolit). Termíny služby převezmou nastavení ze služby.
- Formulář: je-li hotovost povolena, zobrazí se volba „Způsob platby“ – „Převodem – QR platba“ (první, předvybraná, s vysvětlením výhody) a „Hotově na místě“.
- Rezervace s volbou hotovosti vznikne ve stavu „Platba na místě“ (blokuje místo, neexpiruje) a má vlastní VS jako každá rezervace.
- Potvrzovací e-mail u hotovosti má vlastní upravitelnou šablonu: potvrzení rezervace, částka k úhradě na místě a platební údaje s QR kódem pro případ, že se zákazník rozhodne zaplatit předem převodem. Splatnost se v textu neuvádí jako povinnost.
- Zaplatí-li zákazník nakonec převodem se správným VS, kontrola plateb rezervaci spáruje a označí „Zaplaceno“ (převodem).
- Příjmový doklad se při hotovostní platbě nevystavuje (rozhodnutí zadavatele).

## 3.8 Jednoznačné kódy akcí, služeb a termínů

Každá akce, služba i termín služby má vedle interního čísla WordPressu jednoznačný čitelný kód. Slouží pro komunikaci, vyhledávání, exporty, platební pokyn a zprávu pro příjemce v QR platbě.

| **Položka** | **Formát** | **Příklad a poznámka** |
|---|---|---|
| Akce | AK-RRRR-NNNN | AK-2026-0012 – rok založení, řada akcí začíná každý rok od 1 |
| Služba | SL-NNN | SL-003 – služby jsou dlouhodobé, rok se neuvádí |
| Termín služby | TE-RRRR-NNNN | TE-2026-0145 – samostatná řada; vazba na službu je vidět v administraci |

- Kód vznikne automaticky při prvním uložení (termíny už v generátoru) a je neměnný – nemění se se změnou data, názvu, ceny, místa ani typu. Proto neobsahuje datum konání.
- Řady se jen zvyšují; kód smazané akce se nikdy znovu nepřidělí. Přidělování probíhá pod zámkem stejně jako VS, takže ani při souběhu nevzniknou duplicity.
- Duplikát akce dostane nový kód.
- Předpony (AK/SL/TE) a počet číslic jsou v nastavení (pravidla fáze 1); změna platí jen pro nově přidělované kódy.
- Po aktualizaci na verzi 0.3.4 se kódy automaticky doplní existujícím akcím, službám a termínům v pořadí jejich založení.
- Kód nenahrazuje variabilní symbol: VS (číselný) identifikuje konkrétní rezervaci a platbu, kód identifikuje akci, službu nebo termín. Rezervace tak nese obojí, např. VS 26000031 · AK-2026-0012.
- Kde se kód zobrazuje: seznam akcí a služeb (sloupec Kód, vyhledání podle kódu), editace akce a služby, seznam a detail rezervací (hledání rezervací podle kódu akce i služby), odbavení na místě, CSV export, proměnná {event_code} v e-mailech, platební pokyn (PDF) a zpráva pro příjemce v QR platbě (E4). Shortcody přijímají i kód (např. [reserveandpay_booking id="AK-2026-0012"], [reserveandpay_slots service="SL-003"]).
- Na webu se zákaznicím kód nezobrazuje (rozhodnutí zadavatele); v e-mailu a na platebním pokynu ano.

## 3.9 Platba ve dvou částech (1. část a doplatek)

U vícedenních akcí a pobytů se platba často dělí na 1. část a doplatek. Plugin to řeší jednou rezervací s jedním VS – obě platby se posílají se stejným variabilním symbolem a sčítají se. Pojmy: „1. část platby“ a „doplatek“ (nejde o zálohu).

| **Nastavení** | **Popis** |
|---|---|
| Platba ve dvou částech | U akce i služby (termíny ji převezmou): Ne – platba najednou (výchozí) / 1. část jako pevná částka / 1. část jako procento z ceny. U masáží a lekcí se nepoužívá. |
| Výše 1. části | Pevná částka (např. 2 000 Kč) nebo procento (např. 30 %, max. 99 %); zaokrouhluje se na celé koruny. Je-li 1. část rovna ceně nebo vyšší, platí se najednou. |
| Splatnost 1. části | Počet dní od rezervace (výchozí 7), do konce dne; nejpozději ke splatnosti celé částky. |
| Splatnost doplatku | Splatnost rezervace podle pravidla akce (kap. 3.6), např. 14 dní před termínem. |

- Rezervace, e-mail, platební pokyn (PDF) a potvrzovací stránka uvádějí 1. část, její splatnost a rozpis „1. část … do … · doplatek … do …“. Hlavní QR kód je na 1. část; druhý QR kód „Nebo zaplaťte vše najednou“ je na celou částku se splatností 1. části – kdo platí najednou, musí zaplatit do splatnosti 1. části, jinak by rezervace expirovala. V textech e-mailů zákazníkovi znamenají {amount} a {due_date} u rozdělené nebo částečně zaplacené platby částku a splatnost, která se platí teď; celou cenu a konečnou splatnost uvádějí {total} a {final_due}.
- Zaplacením 1. části je místo potvrzené: stav „Částečně zaplaceno“, zákazník dostane e-mail „1. část platby přijata – místo potvrzeno“ s QR kódem na doplatek.
- Zaplacení celé částky (najednou nebo doplatkem) → „Zaplaceno“ a potvrzení platby.
- Menší částka, než je potřeba (i u platby najednou): zapíše se automaticky, rezervace přejde do stavu „Částečně zaplaceno“ a zákazník dostane upozornění, že neuhradil celou platbu – kolik zaplatil, kolik zbývá a QR kód na zbývající částku. Hanka avízo vidí jako spárované s poznámkou „Částečná platba“.
- Vyšší částka (přeplatek) se automaticky nezapíše – avízo jde k ruční kontrole; při ručním přiřazení lze přeplatek vědomě přijmout.
- Nezaplacená 1. část do její splatnosti: rezervace expiruje stejně jako nezaplacená rezervace (kap. 7.2b). Byla-li zaplacena aspoň část, rezervace neexpiruje.
- Nezaplacený doplatek po splatnosti: rezervace neexpiruje, přejde do stavu „Po splatnosti“, zákazník dostane jednu připomínku na zbývající částku a o dalším postupu rozhodne Hanka (zrušení, domluva, doplatek na místě).
- Ruční zápis platby: v detailu rezervace „Připsat platbu“ (částka, hotově / převodem) – např. 1. část hotově; tlačítka „Doplaceno (převod / hotově)“ označí uhrazený celý zbytek. Při odbavení se zobrazuje částka, která zbývá vybrat.
- Seznam rezervací ukazuje u částky „zaplaceno X“, detail rezervace Uhrazeno / zbývá a rozpis, CSV export sloupce Uhrazeno a Zbývá.
- Platba ve dvou částech se nepoužije u volby hotovosti, u rezervace na poslední chvíli a u pozdní rezervace po splatnosti doplatku – ta se platí celá najednou do N dní od rezervace (kap. 3.6).

## 3.10 Slevy – slevový kupón a sleva za včasnou rezervaci

**Pozn.:** Schváleno 25. 10. 2026, implementováno ve verzi pluginu 0.8.0.

U akcí i služeb (u služby pro všechny její termíny) lze poskytnout slevu dvěma způsoby: slevovým kupónem, který zákazník uplatní při rezervaci, a slevou za včasnou rezervaci do konkrétního data. Rezervace si ukládá plnou cenu, uplatněnou slevu (typ, popis, částku, příp. kód kupónu) a cenu po slevě, která je částkou k úhradě.

### Obecná pravidla

- OP01 – zobrazení: sleva je uvedena v textu e-mailu, na platebním pokynu (PDF) a na potvrzovací stránce v rozpisu „Cena 4 900 Kč · Sleva … −500 Kč · Cena po slevě 4 400 Kč“. QR kódy pro platbu obsahují už částky po slevě (i QR „zaplatit vše najednou“).
- OP01 – platba ve dvou částech (kap. 3.9): 1. část se počítá z plné ceny, sleva se vždy odečte až z doplatku. Příklad: cena 4 900 Kč, 1. část 2 000 Kč, sleva 500 Kč → 1. část 2 000 Kč, doplatek 2 400 Kč, vše najednou 4 400 Kč. Sleva smí být nejvýš do výše doplatku; při vyšší hodnotě administrace u nastavení akce / služby / kupónu upozorní a sleva se omezí na výši doplatku.
- Jen jedna sleva na rezervaci: slevový kupón nelze uplatnit tam, kde platí jiná sleva. Platí-li v okamžiku rezervace u akce sleva za včasnou rezervaci, kupón se odmítne s hláškou „Kupón nelze kombinovat se slevou za včasnou rezervaci“; po datu včasné rezervace lze kupón uplatnit. Ruční sleva v administraci se nezavádí.
- Při změně předpisu platby (zánik nebo ponechání slevy) zůstává stejný variabilní symbol i číslo platebního pokynu; PDF zobrazí aktuální stav s poznámkou „Aktualizovaný předpis ze dne …“.
- Seznam rezervací, detail rezervace a CSV export obsahují Cenu, Slevu a Kupón; detail rezervace vede historii slevy (uplatnění, zánik s důvodem, ponechání slevy s důvodem).

### Slevový kupón

- Správa v ResAndPay → Slevové kupóny: kód, sleva v % nebo pevnou částkou, platnost od–do (podle data rezervace), rozsah platnosti, limit použití celkem a na jeden e-mail, zapnuto / vypnuto, interní poznámka.
- Rozsah platnosti: všechny akce a služby, nebo vybrané akce, vybrané služby (všechny jejich termíny, i později vygenerované) a vybrané kategorie (i budoucí akce v kategorii); volby lze kombinovat. Mimo rozsah zákazník uvidí „Kupón pro tuto akci neplatí“.
- Kód: automaticky generovaný čitelný kód ve tvaru SLH-XXXX-XXXX-XX (10 náhodných znaků z 31 bez zaměnitelných 0/O, 1/I/L, tj. asi 50 bitů – neuhodnutelný; předpona je v Nastavení → Rezervace a platby), nebo vlastní čitelný kód zadaný ručně (např. JARO2027 pro kampaň – uhodnutelný, vhodné s limitem použití). Velikost písmen, mezery a pomlčky se při zadání nerozlišují. Interně má kupón navíc vlastní GUID.
- Hromadné generování: vytvoření N jednorázových kupónů najednou (např. 50 dárkových poukazů) se stejnými parametry a vlastním kódem pro každý; ke stažení jako CSV (kód, odkaz, sleva, platnost) a ZIP s QR obrázky a PDF poukazy.
- QR kód kupónu: samostatný QR kód – ne platební – s odkazem na web s kupónem, např. https://slehkouhlavou.cz/akce/joga-vikend-mirosov/?kupon=SLH-7KQ4-M9XD-2P. Platí-li kupón pro jednu akci, vede na ni, jinak na Kalendář akcí; po naskenování se kupón v rezervačním formuláři vyplní a uplatní sám a zákazník vidí cenu po slevě. Barevně odlišen od platebních QR kódů: moduly v tmavé zlaté z palety webu (--gold-deep #8C6423) na krémovém podkladu se zlatým rámečkem (--gold #B8862E) a popiskem „Slevový kupón“; kontrast ověřen čtením v aplikacích telefonu.
- Tisknutelný poukaz (PDF, A5 na šířku) dle grafického manuálu v1: krémový podklad a tenké linky; jeden motiv – soustředné kružnice jako v podkladu webu (zlatá #B8862E, tlumeně, přetékají přes okraj); decentní řádková sestava loga (lotos z vlny – lístky zlatohnědé, vlna švestková; „s lehkou“ Fraunces Medium, „hlavou“ Fraunces Italic ve zlatohnědé); eyebrow „Slevový kupón“, výše slevy, pro co platí, platnost, kód, zlatohnědý QR kód s popiskem „není platební QR kód“, zřetelná adresa webu (slehkouhlavou.cz, malými písmeny, bez www) a dole dechová linka s mottem „NÁDECH — VÝDECH — PROSTOR“. Logo je stejné jako v ostatních výstupech (kap. 5.2); texty loga, SVG symbolu, motto a web jsou v Nastavení → Identifikace (pravidla fáze 1 – nic natvrdo). V administraci u kupónu: Stáhnout QR (PNG), Stáhnout poukaz (PDF), Kopírovat odkaz.
- Formulář rezervace: nepovinné pole „Slevový kupón“ s tlačítkem „Použít“ – hned zobrazí cenu po slevě, nebo důvod odmítnutí (neplatný kód, mimo platnost, vyčerpaný limit, neplatí pro tuto akci, nelze kombinovat s jinou slevou). Při odeslání se kupón ověří znovu. Zkoušení kódů naslepo omezuje ochrana formuláře (kap. 9.1).
- Sleva z kupónu se pozdní platbou neztrácí. Po vypršení nebo zrušení rezervace se použití kupónu vrátí a lze ho využít znovu.
- Kupón na 100 % (nebo pevná sleva ≥ cena): cena 0 Kč → rezervace je rovnou potvrzená (Zaplaceno – zdarma), platba se nedělí na dvě části a zákazník dostane e-mail „Rezervace potvrzena“ bez platebních údajů (šablona „Rezervace zdarma“).
- Kupón lze zadat i při ruční rezervaci v administraci.
- Kupón z odkazu / QR (parametr ?kupon=…) si web zapamatuje na 7 dní (funkční cookie rap_kupon), takže se vyplní i tehdy, když zákazník z Kalendáře akcí přejde na konkrétní akci.
- Platí-li u akce sleva za včasnou rezervaci, formulář pole kupónu nenabízí a místo něj uvede „Sleva za včasnou rezervaci: cena po slevě … – uplatní se automaticky při platbě převodem ve splatnosti“.
- Použitý kupón nelze smazat, jen vypnout. Seznam kupónů ukazuje použito / limit, export všech kupónů do CSV; dávka z hromadného generování se stahuje jako CSV, ZIP (CSV + QR PNG + PDF poukazy) nebo všechny poukazy v jednom PDF.

### Sleva za včasnou rezervaci

- Nastavení u akce / služby: výše slevy (Kč nebo %), datum „při rezervaci do“ (včetně, do 23:59) a popis (např. „Sleva za včasnou rezervaci“). Platí jen pro platbu převodem – při volbě hotovosti na místě se nepoužije; rezervace na poslední chvíli ji nemá (datum je již pryč).
- Na webu všude (výpis, kalendář, detail akce, přehled na úvodní stránce, rezervační karta): „4 900 Kč · při rezervaci do 31. 12. a platbě ve splatnosti 4 400 Kč“. Po datu se informace skryje.
- Zvýraznění slevy (od verze 0.8.3): vše, co se týká slevy, je ve zlatohnědé barvě webu (--rap-accent). Cena po slevě má stejnou velikost a písmo jako základní cena (na webu pod ní na samostatném řádku), podmínka „při rezervaci do … a platbě ve splatnosti“ zůstává menším písmem. V rezervačním formuláři, na potvrzovací stránce, v HTML e-mailech zákazníkovi a na platebním pokynu (PDF) jsou řádky slevy a řádek „Cena po slevě“ zlatohnědě (hodnoty tučně).
- Rezervace do data: předpis platby se slevou a podmínkou v textu „Sleva platí při úhradě ve splatnosti“.
- a) Všechny platby ve splatnosti → sleva platí, systém nic nemění.
- b) Platba po splatnosti → sleva zanikne. Týká se platby najednou, 1. části i doplatku; za platbu po splatnosti se považuje i nedoplatek – zaplaceno ve splatnosti méně a zbytek po splatnosti (např. 4 000 z 4 400 Kč → sleva zanikne, doplácí se 900 Kč). Rozhoduje datum připsání na účet podle bankovního avíza, ne čas zpracování. Zanikne jen sleva za včasnou rezervaci.
- Po zániku slevy se splatnost ani pravidla pro pozdní úhradu nemění (stav Po splatnosti, jedna připomínka, expirace, obnovení pozdní platbou – kap. 3.6, 7.2b). Zákazník dostane e-mail „Změna platebního předpisu – sleva zanikla“ s důvodem, novou částkou, zaplacenou a zbývající částkou a QR kódem; zanikne-li sleva tím, že doplatek nepřišel do splatnosti, nahradí tento e-mail dnešní připomínku po splatnosti (nechodí dva e-maily).
- Expirovaná rezervace obnovená pozdní platbou (kap. 7.2b) je již bez slevy.
- Ponechat slevu: tlačítko v detailu rezervace s povinným důvodem (např. „banka platbu zdržela“) – vrátí slevu, přepočítá zbývající částku (při úplném zaplacení → Zaplaceno) a záznam zůstane v historii rezervace.
- Technicky: rezervace ukládá plnou cenu, výši a typ slevy, popis, vazbu na kupón a čas zániku slevy; při zániku se částka k úhradě zvýší na plnou cenu, při ponechání se vrátí. Platba z avíza se při kontrole přeplatku porovnává se zbývající částkou po případném zániku slevy (pozdní platba plné ceny tak není přeplatek). Nová e-mailová šablona „Změna předpisu – sleva zanikla“, proměnné {price_full}, {discount}, {discount_label}, {coupon}.

## 3.11 Externí rezervační systém (rezervace mimo ReserveAndPay)

**Pozn.: Schváleno 25. 10. 2026, implementováno ve verzi pluginu 0.9.0.**

Některé akce nebo služby se rezervují v jiném rezervačním systému. Termíny se přesto vypisují v ReserveAndPay, aby je zákazník našel na webu slehkouhlavou.cz spolu s ostatními; kliknutím na „Rezervovat“ je přesměrován do externího systému. Předávání údajů do externího systému ani zpětná vazba do ReserveAndPay (obsazenost, rezervace, platby) se v této fázi neřeší.

### Nastavení

- U akce i služby volba „Způsob rezervace“: Rezervace v ReserveAndPay (výchozí) / Externí rezervační systém. Termíny služby přebírají nastavení ze služby (stejně jako „Zobrazení času“).
- Pro externí systém: Adresa (URL) – povinná, jen https://; Text tlačítka (výchozí „Rezervovat“); Otevřít v novém okně (ano/ne, výchozí ano); Poznámka pro zákazníka (nepovinná, např. „Rezervace a platba probíhá v systému XY.“).
- Adresa může obsahovat proměnné termínu %DATE% (RRRR-MM-DD), %TIME% (HH:MM) a %CODE% (kód termínu, kap. 3.8) – zákazník tak u služby přistane rovnou na zvoleném dni. Jde jen o sestavení odkazu, žádné osobní údaje se nepředávají.
- U jednotlivého termínu služby lze adresu přepsat vlastní (některé systémy mají odkaz pro každý termín zvlášť).

### Zobrazení na webu

- Akce a termíny se vypisují všude jako dnes (výpis termínů, kalendář, přehled na úvodní stránce, karta služby, výběr volných termínů, detail akce); mění se jen cíl tlačítka „Rezervovat“ / „Vybrat termín“ / časů – vede na externí adresu (rel="noopener nofollow").
- Volná místa se nezobrazují (ReserveAndPay obsazenost nezná); ruční přepínač „Obsazeno“ se nezavádí. Volitelně „Zobrazit celkovou kapacitu“ (u akce / služby, od verze 0.11.1): na webu se ukáže „Kapacita: N míst“ z pole Kapacita (ve výpisu, detailu akce, rezervační kartě a výběru termínů služby), volná místa dál ne; pole Kapacita je pak povinné. Ve Statistice se kapacita externího termínu ukáže, do součtů a obsazenosti se nezapočítá. Termín, který už není dostupný, správce zruší nebo skryje běžným způsobem.
- Výběr termínů služby: všechny vypsané termíny vedou ven, každý čas na svůj odkaz. Výpis začíná nejbližším vypsaným termínem (kap. 3.4), věta „dřívější termíny jsou obsazené“ se nezobrazuje.
- Detail akce / termínu: místo rezervačního formuláře karta s termínem, cenou, poznámkou a tlačítkem do externího systému. Tlačítko v záhlaví detailu vede rovnou ven. Karta služby v přehledu termínů: „Nejbližší termín“ bez počtu volných; bez stránky s termíny vede tlačítko rovnou do externího systému.
- Uzávěrka rezervací a minulé termíny: platí současná pravidla – po uzávěrce nebo začátku akce tlačítko zmizí.
- Cena a sleva za včasnou rezervaci se zobrazují beze změny (kap. 3.10); soulad s cenou a slevou v externím systému si hlídá správce. Slevový kupón nelze v ReserveAndPay uplatnit (formulář se nezobrazuje).

### Co ReserveAndPay v tomto režimu nedělá

- Nevytváří rezervace, nepřiděluje variabilní symbol, negeneruje QR platbu ani platební pokyn, neposílá e-maily, nepočítá obsazenost, neměří kliknutí; ochrana proti robotům (kap. 9.1) se neuplatní.

### Administrace

- Seznam akcí a služeb: štítek „Externí“ (místo obsazenosti „externí“). Pole „Způsob rezervace“ je v editoru akce pod cenou (u termínu služby jen vlastní adresa), u služby v řádku „Rezervace“; adresa bez https:// se neuloží (upozornění). Externí akce nevyžaduje kapacitu, vyžaduje adresu. Ruční rezervace v administraci u externí akce / termínu není dostupná.
- Přepnutí z ReserveAndPay na externí systém u akce, která už má aktivní rezervace, systém nedovolí bez upozornění (stávající rezervace zůstávají a vyřizují se v ReserveAndPay).

### Pozdější rozšíření

- Řešení je připravené na doplnění bez přepisování: předání údajů do externího systému (parametry odkazu), import obsazenosti nebo rezervací zpět do ReserveAndPay.
