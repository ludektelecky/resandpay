# 14. Konfigurovatelnost a rozšiřitelnost

ReserveAndPay vzniká ve dvou fázích. Fáze 1 dodá plugin pro slehkouhlavou.cz. Fáze 2 ho zobecní tak, aby byl použitelný na libovolném WordPress webu. Aby fáze 2 znamenala jen doplnění funkcí a ne přepis, musí kód fáze 1 od začátku dodržovat následující pravidla.

## 14.1 Pravidla fáze 1 – připravenost na zobecnění

- Identifikace provozovatele (jméno/firma, IČ, adresa, IBAN a číslo účtu, logo) je uložená v nastavení pluginu, nikde v kódu. Ve fázi 1 stačí jednoduchá stránka nastavení.
- Bankovní schránka a přístupové údaje jsou v nastavení (kap. 6.4) – žádná doména ani e-mailová adresa není zapsaná v kódu.
- Čtení avíz probíhá přes jednotné rozhraní „bankovní adaptér“ (kap. 14.2). Ve fázi 1 existuje jen adaptér Raiffeisenbank; jádro pluginu na konkrétní banku nezávisí.
- Měna se ukládá u každé akce, služby, rezervace i avíza už od fáze 1 (výchozí CZK), aby nebylo nutné zpětně měnit databázi.
- Vzhled je definován přes CSS proměnné (barvy, písma, zaoblení). Hodnoty dle grafického manuálu SLH (#ae9333 / #f3cb33) jsou výchozí, ne zapečené.
- Šablony formulářů, e-mailů a PDF jsou oddělené od logiky a připravené na přepsání ze šablony webu.
- Všechny texty jsou přeložitelné standardem WordPressu (textdomain „reserveandpay“), výchozí jazyk čeština. V kódu nejsou názvy „SLH“, „Hanka“ ani konkrétní služby.
- Služby a typy akcí jsou obecné – masáže, pobyty apod. si provozovatel zakládá v administraci jako data.
- Napojení na Ultimate Member a MailerLite jsou samostatné volitelné moduly; plugin funguje i bez nich.
- Jednotný prefix (funkce, tabulky, volby, hooky: „resandpay_“) a vlastní hooky pro rozšíření třetími stranami.

## 14.2 Bankovní adaptér

Bankovní adaptér je samostatná třída se společným rozhraním. Jádro pluginu volá adaptér a dostává jednotný výsledek, takže přidání další banky nevyžaduje zásah do párování ani stavového modelu.

| **Funkce adaptéru** | **Popis** |
|---|---|
| Rozpoznání avíza | Ověří, že e-mail pochází od dané banky (odesílatel, předmět, struktura) |
| Vyčtení platby | Vrátí jednotná data: částka, měna, variabilní symbol, protiúčet, datum, identifikátor transakce |
| Rozlišení typu pohybu | Příchozí platba vs. odchozí / ostatní pohyby (ty se ignorují) |
| Testovací vzorky | Každý adaptér má sadu vzorových avíz a automatický test – změna formátu banky se tak rychle odhalí |

**Pozn.:** Ve fázi 2 lze vedle e-mailových adaptérů doplnit i adaptéry čtoucí výpisy přes API banky (např. banky nabízející přímé API pro účty), pokud to provozovatel bude chtít – jádro pluginu se tím nemění.

## 14.3 Fáze 2 – zobecnění

| **Oblast** | **Co se ve fázi 2 doplní** |
|---|---|
| Identifikace společnosti | Plnohodnotný formulář nastavení s validací (IČ, IBAN), více údajů na platebním pokynu (DIČ, kontakt, patička) |
| Více bank | Další bankovní adaptéry (např. UniCredit, Fio, ČSOB, KB) a volba banky v nastavení |
| Univerzální vzhled | Nastavení barev a písem v administraci, možnost převzít styl ze šablony webu, přepsatelné šablony formulářů, e-mailů a PDF |
| Adresa schránky | Stávající nastavení doplněné o test připojení a diagnostiku |
| Seznam služeb | Pohodlnější správa služeb a typů akcí, šablony služeb pro rychlé založení |
| Měna | Volba měny u akce/služby. U EUR jiný platební QR kód (EPC/SEPA) místo českého SPD, který funguje jen pro tuzemské platby |
| Nasazení na nový web | Průvodce prvním nastavením po aktivaci pluginu |
| Aktualizace | Automatické aktualizace z GitHub Releases – oprava se jednou vydá a dostanou ji všechny weby |

## 14.4 Distribuce a verzování

- Plugin se distribuuje jako jeden .zip; každý web má vlastní nastavení a vlastní data.
- Verzování podle schématu major.minor.patch; změny databáze se při aktualizaci provádějí automaticky migracemi.
- Zdrojový kód je v soukromém repozitáři ludektelecky/resandpay; vydání (releases) slouží jako zdroj instalací a aktualizací.
- WordPress multisite (více webů v jedné instalaci) není v rozsahu fáze 1 ani 2.

## 14.5 Název a označení

Plugin se v textech, administraci a dokumentaci označuje jednotně podle těchto pravidel:

| **Označení** | **Kdy se použije** | **Příklady** |
|---|---|---|
| ReserveAndPay | Plný název – všude, kde se pojmenovává produkt a je na něj místo | Název pluginu v seznamu pluginů, titulky stránek administrace (Nastavení ReserveAndPay, Nástroje ReserveAndPay), upozornění na testovací režim, dokumentace a README, výstup CRONu, metadata PDF |
| ResAndPay | Krátký název – kde je omezené místo, ale má jít o čitelné jméno | Položka v levém menu administrace (ResAndPay → Rezervace, Platby, Odbavení, Nastavení…), repozitář ludektelecky/resandpay |
| RAP | Zkratka – jen kde je potřeba nejkratší označení | Předmět e-mailů v testovacím režimu „[RAP TEST → příjemce]“, technické záznamy v logu serveru, technické předpony v kódu (CSS proměnné --rap-*, pole formulářů rap_*, metadata _rap_*) |

**Pozn.:** Technické identifikátory se nemění, protože by změna rozbila uložená nastavení, data a obsah stránek: složka a slug pluginu i textová doména „reserveandpay“, předpona funkcí, tabulek a voleb „resandpay_“, shortcody [reserveandpay_…]. Zákaznicím se název pluginu nezobrazuje – web, e-maily a platební pokyn vystupují pod značkou provozovatele („S lehkou hlavou…“).
