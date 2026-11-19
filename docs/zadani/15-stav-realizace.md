# 15. Stav realizace a kniha rozvoje

Vývoj probíhá na webu slehkouhlavou.cz (WordPress 7.1, PHP 8.5, MySQL 8.4, Active24) v testovacím režimu. Zdrojový kód je v repozitáři ludektelecky/resandpay, instalační balíčky a dokumentace i na Google Disku (INSYS/Yoga/SLehkouhlavou/ReserveAndPay). Každá verze prochází automatickými testy (logika rezervací, kapacita při souběhu, výpisy, generátor).

| **Etapa** | **Verze** | **Obsah** | **Stav** |
|---|---|---|---|
| E1 | 0.1.0–0.1.1 | Akce a lokace, kapacita, rezervační formulář, stavy, administrace, e-maily, nastavení, testovací režim, diagnostika e-mailů | Hotovo, nasazeno |
| E2 | 0.2.0–0.2.1 | Výpis a kalendář dle manuálu, stránka Kalendář akcí, editace a duplikace akcí | Hotovo, nasazeno |
| E3 | 0.3.0–0.3.2 | Služby, generátor a hromadné rušení termínů, volné termíny na webu, souhrnná karta služby, počet míst na termín (skupinové lekce) | Hotovo |
| E3+ | 0.3.3–0.3.4 | Pravidla splatnosti, stav Po splatnosti s připomínkou, hotovost jako doplňková platba, odbavení na místě podle VS; jednoznačné kódy akcí, služeb a termínů | Hotovo, nasazeno |
| E4 | 0.4.0 | QR platba (SPD), PDF platebního pokynu, HTML e-maily s QR a PDF, načtení QR kódu kamerou při odbavení | Hotovo, nasazeno; zbývá ruční test v aplikaci RB a další banky |
| E5 | 0.5.0–0.5.4 | Kontrola plateb z avíz Raiffeisenbank (IMAP, CRON Active24), ověření pravosti, ruční přiřazení, log a upozornění; název v menu ResAndPay; opravy uložení hesla a klíče CRONu | Hotovo, nasazeno (0.5.4); připojení ke schránce ověřeno, zbývá potvrdit pravidelné volání CRONu |
| E6a | 0.6.0–0.6.1 | Expirace s e-mailem, obnovení pozdní platbou, připomínka před splatností, platba ve dvou částech (1. část a doplatek), stav Částečně zaplaceno | Hotovo, 0.6.1 k nasazení (0.6.1: pozdní rezervace) |
| – | 0.6.2 | Krátký přehled nadcházejících na úvodní stránce ([reserveandpay_upcoming]) | Hotovo, testuje se |
| E6b | 0.19.0 | MailerLite (R17); Ultimate Member | MailerLite hotovo; Ultimate Member po spuštění |
| E7 | — | Závěrečné testy a spuštění | Plánováno |

**Pozn.:** Nastaveno na webu: provozovatel INSYSTEMS CZ s.r.o. (IČ 12345678), časové pásmo Europe/Prague, odesílatel e-mailů na doméně slehkouhlavou.cz, služba Bukální masáž (60 + 15 min, 1 300 Kč, Brno – Hotel Passage, Lidická 23) s testovacími termíny; po nasazení 0.3.2 se založí služba Sound Healing s 15 místy na termín. Účet pro příjem plateb (potvrzen 22. 10. 2026): Raiffeisenbank 1234567890/5500, IBAN CZ7155000000001234567890 – zadává se do nastavení po nasazení 0.4.0 spolu se značkou „S lehkou hlavou…“, webem slehkouhlavou.cz a e-mailem info@example.cz pro platební pokyn. Chybí: stránky GDPR a obchodních podmínek, adresa pro upozornění na nové rezervace.

## 15.1 Kniha rozvoje

> Od 14. 11. 2026 se nové rozvojové požadavky vedou v [GitHub Issues](https://github.com/ludektelecky/resandpay/issues) (štítek `rozvoj`). Požadavky R1–R32 níže mají v Issues svůj záznam; podrobná specifikace zůstává zde.

Funkce navržené k dalšímu rozvoji pluginu. Položka se po schválení podrobného zadání přesune do příslušné kapitoly a do tabulky stavu realizace.

### R1 – ResAndPay → Statistika (přehled termínů a obsazenosti)

**Pozn.: Zapsáno do knihy rozvoje 25. 10. 2026, upřesněno a schváleno 27. 10. 2026, implementováno ve verzi pluginu 0.10.0. Nastavení rolí: Nastavení → Statistika → „Statistiku vidí role“.**

Nová stránka administrace ResAndPay → Statistika: tabulka všech vypsaných akcí i termínů služeb za zvolené období s obsazeností, s možností rozpadu až na jednotlivé rezervace.

- Filtr – období OD–DO (podle začátku akce / termínu; výchozí: od dneška na 3 měsíce dopředu).
- Filtr – výběr: vše / všechny akce / všechny služby / konkrétní akce / konkrétní služba (všechny její termíny).
- Řádek tabulky = jedna akce nebo jeden termín služby: kód termínu (ID, kap. 3.8), název, druh (akce / služba), datum a čas, rezervováno x z celkového počtu n míst, volná místa, obsazenost v %.
- Pod tabulkou řádek Celkem: počet termínů, míst celkem, rezervováno, volno a průměrná obsazenost za filtr.
- Rozpad (rozbalení řádku) – pro akce i služby: seznam aktivních rezervací se jménem (a VS a stavem platby) a pod nimi volná místa jako řádky bez jména („volné místo“) v počtu podle kapacity, takže je vidět celé obsazení termínu.
- Za rezervovaná místa se počítají aktivní rezervace (Čeká na platbu, Částečně zaplaceno, Zaplaceno, Platba na místě, Po splatnosti); zrušené a expirované se nepočítají ani nezobrazují.
- Zrušené akce a termíny se zobrazí se štítkem „zrušeno“ a do součtů se nezapočítají. Externí termíny (kap. 3.11) se zobrazí se štítkem „externí“ bez obsazenosti (ReserveAndPay ji nezná).
- Přístup podle oprávnění „Prohlížet statistiku ReserveAndPay“ – viz Zobrazení níže; jména zákazníků vidí jen přihlášení uživatelé s tímto oprávněním (kap. 9).
- Řazení tabulky podle libovolného sloupce (kliknutím na záhlaví; výchozí podle data).
- Součty plateb: u každého řádku i v řádku Celkem předepsáno (součet k úhradě po slevách) a zaplaceno (přijaté platby z evidence plateb, kap. 7); u rozpadu částka a stav platby jednotlivé rezervace.
- Export do CSV podle aktuálního filtru – tabulka termínů i rozpad na rezervace (UTF-8 se středníkem pro Excel).

### Zobrazení – administrace i webová stránka

- Administrace: stránka ResAndPay → Statistika.
- Web: shortcode [reserveandpay_stats] na libovolné stránce (např. „Přehled rezervací“) – dashboard ve vzhledu webu dle grafického manuálu, použitelný i na mobilu, bez nutnosti vstupu do administrace WordPressu. Obsah a funkce stejné jako v administraci (sdílený kód).
- Přístup: jen přihlášený uživatel s oprávněním „Prohlížet statistiku ReserveAndPay“ (resandpay_view_stats); ostatním se místo dashboardu zobrazí výzva k přihlášení (frontendový formulář, např. Ultimate Member). Role s oprávněním se volí v Nastavení – výchozí Administrátor, Šéfredaktor a Spolupracovník.
- Tajný odkaz bez přihlášení se nezavádí.
- Ochrana osobních údajů na webu: stránka s dashboardem se neukládá do cache (DONOTCACHEPAGE, hlavičky no-cache) a je vyloučena z indexování (noindex); export CSV jen s přihlášením, oprávněním a bezpečnostním tokenem (nonce). Uživatelé s oprávněním vidí jména zákazníků – oprávnění přidělovat jen spolupracovníkům, kteří s rezervacemi pracují (kap. 9).

### R2 – Připomínka akce zákazníkům a přehled účastníků

**Pozn.: Navrženo a schváleno 27. 10. 2026, implementováno ve verzi pluginu 0.11.0.**

Zákazník dostane před začátkem akce nebo termínu služby e-mail s připomínkou; správkyně / lektorka dostane den předem přehled účastníků. Odesílá pravidelná kontrola splatností (CRON, kap. 6).

- Kdy: N hodin před začátkem – výchozí v Nastavení → Připomínky akcí (48 h), u akce i služby lze změnit (pole „Připomínka akce zákazníkům“); termín služby přebírá nastavení ze služby; 0 = nepřipomínat; nejvýš 720 h.
- Komu: každé aktivní rezervaci nejvýš jednou (Čeká na platbu, Částečně zaplaceno, Zaplaceno, Platba na místě, Po splatnosti). Rezervace vzniklá až po okamžiku připomínky ji nedostane. Zrušené a externí termíny (kap. 3.11) se nepřipomínají.
- Obsah (šablona v Nastavení → E-maily, „Připomínka akce“): termín, místo a odkaz na mapu, odkaz na detail akce a volitelný text u akce / služby (např. „Co si vzít s sebou“). Proměnné {reminder_note}, {payment_note}, {where}, {map_url}.
- Stav platby v připomínce: zaplaceno → „Platba je uhrazena“; platba na místě → „Na místě prosím zaplaťte X Kč“; nezaplacený převod → upozornění a platební údaje s QR kódem a platebním pokynem.
- Přehled účastníků den předem (Nastavení → Připomínky akcí, ve výchozím stavu zapnuto): jednou denně po nastaveném čase (výchozí 18:00) e-mail se seznamem zítřejších akcí a termínů s rezervacemi – jméno, telefon, stav platby a zbývající částka, poznámka zákazníka, volná místa. Příjemce: zvláštní e-mail, jinak e-mail pro upozornění na nové rezervace. Bez rezervací se nic neposílá.
- Oba e-maily se zapisují do seznamu odeslaných e-mailů (Nástroje, detail rezervace) jako „Připomínka akce“ a „Přehled účastníků (správce)“; v testovacím režimu se přesměrují na testovací adresu.

### R3 – Rámeček „Parametry akce“ v editoru akce

**Pozn.: Schváleno 31. 10. 2026, implementováno ve verzi pluginu 0.12.0.**

- Nastavení akce je v hlavní části editoru (rámeček „Parametry akce“) jako tabulka po vzoru služby: Termín (typ, začátek, konec, zobrazení času), Místo a kapacita, Cena a platba (cena, splatnost, hotovost, platba ve dvou částech), Sleva, Rezervace (kdo a od kdy může rezervovat, způsob rezervace, uzávěrka, akce zrušena), Připomínka. Nic se neschovává pod „Další nastavení“.
- Pravý panel „Akce“: tlačítko „Parametry akce ↓“ (otevře a posune na rámeček), kód akce, chybějící údaje, zahájení rezervací a omezení přístupu; dále rámeček Obsazenost. Blokový editor rámeček při první návštěvě sám otevře.
- U termínu služby jsou řádky přebírané ze služby (sleva, přístup a zahájení rezervací, připomínka) označené „ze služby …“ s odkazem na úpravu služby.

### R4 – Ohlášení dopředu: zahájení rezervací a přednostní rezervace

**Pozn.: Schváleno 31. 10. 2026, implementováno ve verzi pluginu 0.12.0.**

- Pole „Zahájení rezervací (od kdy)“ u akce a u služby (termíny služby přebírají ze služby, i již vypsané; jednotlivý termín vlastní nemá). Prázdné = rezervovat lze hned.
- Do zahájení je akce na webu vidět (výpis, kalendář, úvodní stránka, detail s cenou, popisem a slevou), místo tlačítka text „Rezervace od 1. 12. 2026 v 10:00“; formulář se nezobrazí a server rezervaci z webu odmítne. V nastavený okamžik se rezervace otevře sama. Platí i pro externí rezervaci (tlačítko ven až od zahájení). Ruční rezervace v administraci se neomezuje.
- Výběr termínů služby před zahájením: časy jsou vidět, ale neklikatelné, nahoře „Rezervace termínů od …“.
- Přednostní rezervace: volitelné pole „Přednostní rezervace od“ (dřívější než zahájení). Uživatelé s rolemi z Nastavení → Rezervace a platby → „Přednostní rezervace pro role“ mohou rezervovat už od tohoto data; ostatní vidí „Rezervace od … · pro členy od …“ (text v nastavení).

### R5 – Přístup k rezervaci podle role

**Pozn.: Schváleno 31. 10. 2026, implementováno ve verzi pluginu 0.12.0.**

- Nastavení → Rezervace a platby → „Kdo může rezervovat“ (výchozí pro celý web): Všichni / Jen přihlášení / Jen vybrané role (zaškrtnutím; role spravuje Ultimate Member). U akce a služby lze změnit („Výchozí z nastavení“ / vlastní volba), termíny služby přebírají ze služby.
- Omezuje jen rezervaci – akce zůstává na webu vidět všem. Ve výpisu je u omezených akcí štítek (výchozí „jen pro členy“); v detailu místo formuláře text z nastavení (výchozí „Rezervace na tuto akci je jen pro členy. Přihlaste se, prosím.“) s odkazem na přihlášení. Server rezervaci bez oprávnění odmítne; ruční rezervace v administraci se neomezuje.
- Dřívější volba „Jen pro přihlášené uživatele“ je nahrazena; akce s touto volbou se automaticky chovají jako „Jen přihlášení“.
- Pozn.: zobrazení závisí na přihlášeném uživateli a čase – stránky s výpisem a detailem akcí se nemají ukládat do cache stránek (na webu žádný cache plugin není).

### R6 – Vlastní stránka služby

**Pozn.: Zapsáno do knihy rozvoje 31. 10. 2026, implementováno ve verzi pluginu 0.14.1 (13. 11. 2026): v editoru služby řádek „Shortcody“ (výběr termínů a časté dotazy), v pravém panelu akce „Shortcody akce“ (rezervace a časté dotazy) – vždy celý shortcode s kódem, tlačítko Kopírovat a tabulka parametrů; nápověda v Nastavení u stránky přehledu akcí uvádí celý shortcode s parametry.**

- Každá služba by měla vlastní veřejnou adresu (např. /sluzby/sound-healing-lidicka/) s názvem, perexem, fotkami, popisem z editoru služby, cenou a výběrem termínů – obdobně jako detail akce.
- Přínos: sdílení odkazu na konkrétní službu, vyhledávače (SEO), odkaz z karty služby a z názvu služby ve výběru termínů na plný popis.
- K upřesnění před implementací: tvar adresy (/sluzby/…), vztah ke stávající „Stránce s volnými termíny“ (nahradit / ponechat), fotky a perex u služby, zobrazení v menu a ve výpisech.

### R7 – Upozornění v parametrech služby: kdy se služba zobrazí ve výpisu

**Pozn.: Zapsáno do knihy rozvoje 31. 10. 2026 na žádost vlastníka – zatím neimplementováno.**

- V editoru služby (Parametry služby) upozornění, že se služba ve výpisu termínů (stránka Termíny, úvodní stránka, kalendář) ukáže jen tehdy, když má aspoň jeden volný termín, který jde rezervovat.
- Služba se tedy nezobrazí, když: nemá vygenerovaný žádný budoucí termín; má všechny termíny obsazené; u externí rezervace chybí adresa; termíny jsou po uzávěrce rezervací.
- Upozornění má ukázat i aktuální stav služby (např. „Ve výpisu se teď nezobrazuje – žádný volný budoucí termín“). Stránka s výběrem termínů ([reserveandpay_slots]) se zobrazuje vždy (s hláškou, že nejsou volné termíny).

### R8 – Termíny jen pro ruční rezervaci

**Pozn.: Zapsáno do knihy rozvoje 31. 10. 2026 na žádost vlastníka – zatím neimplementováno.**

- Požadavek: v generátoru termínů vygenerovat termíny i pro období, kdy rezervace z webu není možná – termíny slouží jen pro ruční zadání rezervace v administraci.
- Stav dnes (0.12.1): generátor vytvoří termíny pro libovolné budoucí období; ruční rezervace v administraci ignoruje uzávěrku i „Zahájení rezervací“ a omezení podle role. Rezervaci z webu lze zavřít polem „Zahájení rezervací“ u služby – termíny jsou ale na webu vidět s textem „Rezervace od …“. Ruční rezervace na termín, který už začal, možná není.
- Návrh: volba v generátoru a u termínu „Jen pro ruční rezervaci“ – termín se na webu nezobrazuje (výpis, výběr termínů, kalendář) a rezervovat ho lze jen v administraci (ResAndPay → Nová rezervace); v seznamu termínů štítek „jen ručně“. K upřesnění: zda povolit ruční zápis i na termín, který už proběhl (doplnění evidence).

### R9 – Kódy služeb a akcí jako preferovaný odkaz ve shortcodech

**Pozn.: Zapsáno do knihy rozvoje 31. 10. 2026 na žádost vlastníka; implementováno ve verzi pluginu 0.14.1 (nápovědy s kódy, tlačítko Kopírovat), příklady v README opraveny na kódy 19. 11. 2026 ([Issue #9](https://github.com/ludektelecky/resandpay/issues/9)).**

- Ve všech nápovědách a příkladech shortcodů (editor služby – „Stránka s volnými termíny“, editor akce, dokumentace) uvádět jako preferovaný odkaz kód služby nebo akce (kap. 3.8), ne slug: např. [reserveandpay_slots service="SL-003" description="toggle"] místo [reserveandpay_slots service="parova-zvukova-lazen-s-restorativni-jogou-lidicka-brno" description="toggle"].
- Důvod: kód je krátký, čitelný a nemění se při přejmenování služby / akce (slug se může změnit a shortcode by přestal fungovat).
- Stav dnes: shortcody kód už přijímají – [reserveandpay_slots service="…"] (kód, slug nebo ID) a [reserveandpay_booking id="…"] (kód nebo ID); jde tedy jen o nápovědy, příklady a tlačítko „Kopírovat shortcode“ s kódem. Slug a ID zůstávají funkční (zpětná kompatibilita).
- Doplněk: v editoru služby a akce hotový shortcode s kódem ke zkopírování jedním kliknutím; u akce i [reserveandpay_booking id="AK-…"].

### R10 – Nastavitelný počet fotek v galerii akce

**Pozn.: Zapsáno do knihy rozvoje 31. 10. 2026 na žádost vlastníka – zatím neimplementováno.**

- Stav dnes (0.12.1): galerie akce má pevný limit 12 fotek (výběr v okně knihovny i uložení akce); před galerií se na webu zobrazuje Náhledový obrázek akce, je-li nastavený.
- Návrh: limit přesunout do Nastavení → Detail akce („Nejvyšší počet fotek v galerii akce“, výchozí 12) – v souladu s pravidlem fáze 1 „nic natvrdo“ (kap. 14.1).
- Při vyšším počtu (např. 20 a více) náhledy pod hlavní fotkou jako posuvný pás (vodorovné posouvání, na mobilu tažením), aby záhlaví detailu akce nebylo příliš vysoké; poměr stran fotek zůstává 4:3.
- K upřesnění: zda limit platit i pro fotky služby (R6 – vlastní stránka služby) a zda zobrazit v galerii celé okno s listováním (lightbox).

### R11 – Knihovna materiálů ke stažení (eBooky, videa, instruktážní materiály)

**Pozn.: Zapsáno 31. 10. 2026, schváleno 31. 10. 2026 (odpovědi na otázky R11-a až R11-d a R11-h), implementováno ve verzi pluginu 0.13.0.**

Sekce webu pro stahování eBooků, videí a dalších elektronických instruktážních materiálů. Každé stažení je podmíněno přihlášením; některé materiály jsou zdarma pro přihlášené, jiné až po zaplacení.

### R11.1 Rozhodnutí

| **Otázka** | **Rozhodnutí (31. 10. 2026)** |
|---|---|
| R11-a Videa | Soubory přímo na webu (chráněná složka RaP-Pf). Na stránce materiálu se video i audio přehrává v prohlížeči a lze ho stáhnout; od 0.32.0 volba „Jen přehrát“ a vodoznak ve videu (R30) a externí video z YouTube s tlačítkem „Přehrát na YouTube“ (R31). |
| R11-b Kdo může objednat | Přihlášený uživatel (jméno a e-mail z účtu); od 0.24.0 (R22) i zákazník bez účtu podle e-mailu. |
| R11-c Doba přístupu | Nastavitelná u každého materiálu: počet dní, 0 = natrvalo; výchozí hodnota v nastavení (0). |
| R11-d Materiály pro účastnice | Ano – u materiálu lze vybrat akce a služby; zaplacená rezervace dává materiál zdarma. |
| R11-h Právní a daňová stránka | Znění souhlasu se ztrátou práva na odstoupení ověří vlastník s právníkem, daně s účetní. Text souhlasu je v nastavení. |
| R11-e Vodoznak v PDF | Hotovo v 0.23.0 (R21). |
| R11-f Limity hostingu | Obejito nahráváním po částech (2 MB), takže limit velikosti souboru je dán jen místem na disku. Ochranu složky (.htaccess) ověřuje plugin sám a v editoru materiálu upozorní, pokud nefunguje. |
| R11-g První materiály | Doplní vlastník při naplnění knihovny. |

### R11.2 Princip a vztah k Ultimate Member

- Materiály spravuje ReserveAndPay, ne Content Restriction v Ultimate Member: soubor v Knihovně médií má veřejnou adresu a placený nárok podle VS Ultimate Member nezná. Ultimate Member dál zajišťuje registraci, přihlášení a role.
- Přístup u materiálu: Zdarma pro přihlášené · Placený · Jen vybrané role. Navíc má přístup každý se zaplacenou objednávkou, s ručním zpřístupněním nebo se zaplacenou rezervací navázané akce či služby. Správci (oprávnění pro rezervace) mají přístup vždy.
- Nepřihlášený vidí katalog i detail (popis, cenu, názvy souborů se zámkem) a tlačítka „Přihlásit se“ a „Založit účet“ (stránka registrace z nastavení, např. Ultimate Member). Bez přihlášení lze stáhnout jen soubor označený jako ukázka.

### R11.3 Materiál – datový model

Typ obsahu „Materiál“ (resandpay_material) s adresou /materialy/…/, blokovým editorem (popis), úryvkem (perex v katalogu), náhledovým obrázkem (3:4, bez obrázku zástupný motiv soustředných kružnic) a kategoriemi sdílenými s akcemi. Menu ResAndPay → Materiály.

| **Pole** | **Popis** |
|---|---|
| Kód | MA-NNN – přidělí se při prvním uložení, neměnný, nikdy znovu nepoužitý (kap. 3.8); předpona a počet číslic v nastavení |
| Druh | Z číselníku v nastavení (výchozí eBook / PDF, Video, Audio, Pracovní list, Balíček, Jiné) |
| Přístup a role | Od 0.27.0 (R25): Jen pro přihlášené / Pro přihlášené i bez přihlášení – odeslat na e-mail / Jen pro vybrané role (role z Ultimate Member); placený / zdarma určuje cena. Do 0.26.0: Zdarma pro přihlášené / Placený / Jen vybrané role / Odeslat na e-mail. |
| Cena a měna | Prázdná / 0 = zdarma, vyšší = placený (od 0.27.0); akční cena do data (R25) |
| Splatnost objednávky | Dní od objednání; prázdné = nastavení (výchozí 7) |
| Doba přístupu | Dní od zaplacení (u účastnic od zaplacení rezervace, u ručního zpřístupnění od jeho data); 0 = natrvalo; prázdné = nastavení |
| Zdarma pro účastnice | Výběr akcí a služeb (všechny termíny služby); rezervace se páruje podle účtu nebo stejného e-mailu |
| Dostupnost | V nabídce / **Staženo z nabídky** – zmizí z katalogu a nelze ho objednat, kdo ho má, stahuje dál / **Znepřístupněno** (od 0.21.0, R19) – zmizí z katalogu a nikdo ho nestáhne |
| Soubory | 1 až N: popisek na webu, původní název, velikost, typ, „ukázka (bez přihlášení)“, pořadí |

Nové tabulky: resandpay_grants (ruční zpřístupnění: uživatel, od, do, poznámka, odebrání s důvodem) a resandpay_downloads (log stažení). Tabulka rezervací má nové sloupce item_type (event / material) a consent_digital_at.

### R11.4 Objednávka a platba

- Objednávka = záznam v tabulce rezervací (item_type = material) bez termínu a kapacity: VS ze společné řady, číslo proformy, QR platba SPD, PDF platební pokyn („Objednávka“, „Kód materiálu“), kontrola plateb z avíz, ruční přiřazení, evidence plateb a odpárování beze změny.
- Formulář jen pro přihlášené: jméno (předvyplněné z účtu), e-mail z účtu, volitelně firma/IČ/DIČ, souhlas se zpracováním osobních údajů, s obchodními podmínkami a povinný souhlas s okamžitým poskytnutím digitálního obsahu (text v nastavení, ukládá se čas), newsletter, slevový kupón.
- Kupóny: rozsah „všechny“, kategorie nebo konkrétní materiál (výběr „Akce a materiály“ u kupónu). Kupón 100 % = materiál hned zdarma. Sleva za včasnou rezervaci, platba ve dvou částech a hotovost se u materiálů nepoužívají.
- Stavy: Čeká na platbu → Zaplaceno (vznik nároku) / Expirováno (po splatnosti; pozdní platba objednávku obnoví vždy) / Zrušeno. Částečná platba → Částečně zaplaceno; materiál se odemkne až po zaplacení celé částky.
- Druhá objednávka téhož materiálu nevznikne, dokud se čeká na platbu (zobrazí se platební údaje). Zakoupený materiál nelze koupit znovu; po skončení časově omezeného přístupu ano.
- E-maily (vlastní šablony v Nastavení → E-maily, předpona „Materiál –“): objednávka přijata (s QR a PDF), zdarma s kupónem, platba přijata, připomínka splatnosti, neúplná platba, doplatek po splatnosti, objednávka vypršela, zrušení, nová objednávka (správce), ručně zpřístupněn. Proměnné {material_url}, {my_materials_url}, {access_until}. E-mail nikdy neobsahuje přímý odkaz na soubor.

### R11.5 Chráněné uložení a stahování

- Soubory se nahrávají v editoru materiálu (pole „Soubory ke stažení“) po částech 2 MB přes AJAX do chráněné složky **RaP-Pf** (kap. 14.5) wp-content/uploads/resandpay-protected/m{ID}-{náhodný řetězec}/ s náhodnou předponou názvu. Složka má .htaccess (Require all denied) a index.php. Povolené typy: typy WordPressu + EPUB, MOBI, M4A, M4V, WEBM; PHP a jiné spustitelné typy nejsou povoleny.
- Odkaz ke stažení (?rap_dl=…) je podepsaný (HMAC), vázaný na uživatele, materiál a soubor a platí omezenou dobu (nastavení, výchozí 240 min). Server před odesláním vždy znovu ověří přihlášení a nárok.
- Odeslání po částech bez načtení do paměti, s podporou HTTP Range (posun ve videu, navázání přerušeného stahování); hlavičky no-store, nosniff, noindex.
- Limit stažení: na uživatele a soubor za 24 h (výchozí 10, 0 = bez limitu); přehrávání na webu a pokračování přerušeného stahování se nepočítá. Při překročení hláška (HTTP 429) a e-mail správci nejvýš 1× denně.
- Log stažení (čas, uživatel, materiál, soubor, druh stažení / přehrání / ukázka, výsledek, otisk IP) s dobou uchování v nastavení (výchozí 365 dní). Nedokončená nahrávání se po 24 h mažou.
- Trvalé smazání materiálu smaže i jeho soubory. Odstranění souboru v editoru ho smaže z disku po uložení.
- Kontrola ochrany: plugin zkusí stáhnout zkušební soubor z chráněné složky přes web (1× za 12 h); pokud se to podaří, editor materiálu zobrazí varování.

### R11.6 Web

| **Shortcode** | **Popis** |
|---|---|
| [reserveandpay_materials category="" type="" access="" filter="yes" limit=""] | Katalog: karty (náhled 3:4, druh, název, perex, cena / zdarma pro přihlášené / jen pro členy / Máte k dispozici / Čeká na platbu) s filtrem druhu a tématu |
| [reserveandpay_material id="MA-001"] | Karta jednoho materiálu (kód, slug nebo ID) – do článku nebo na stránku služby |
| [reserveandpay_my_materials] | Moje materiály: čekající platby, dostupné materiály se soubory a přehrávači, historie objednávek; nepřihlášenému formulář přihlášení |

- Detail materiálu: popis a vedle něj karta „Ke stažení“ podle stavu uživatele – Přihlásit se / objednávkový formulář / platební údaje s QR kódem / seznam souborů s tlačítky Stáhnout a přehrávačem videa a audia. Na úzkém sloupci a mobilu se karta přesune pod popis.
- Stránky s materiály se neukládají do cache (DONOTCACHEPAGE, no-cache), stránka „Moje materiály“ má noindex.

### R11.7 Administrace a nastavení

- Editor materiálu: Parametry materiálu, Soubory ke stažení (nahrávání s průběhem), Přístupy – počty objednávek a stažení, ruční zpřístupnění podle e-mailu účtu (dní: 0 = doba materiálu, −1 = natrvalo, volitelně e-mail), odebrání s důvodem, posledních 20 stažení.
- Seznam materiálů: kód, druh, přístup / cena, počet souborů, objednávky (zaplaceno / celkem), stažení za 30 dní. Objednávky jsou v ResAndPay → Rezervace (filtr „Materiály (objednávky)“).
- Nastavení → Materiály ke stažení: stránka „Moje materiály“, stránka s registrací, druhy materiálů, splatnost, výchozí doba přístupu, limit stažení, platnost odkazu, uchování logu, texty pro nepřihlášené a pro role, text souhlasu s digitálním obsahem. Kódy: předpona a číslice materiálu.
- Mimo rozsah 0.13.0: zápis do MailerLite (spolu s E6b – hotovo v 0.19.0), vodoznak v PDF (R11-e – hotovo v 0.23.0), rozšíření stránky Statistika (R1) o materiály – hotovo v 0.22.0 (R20).

### R11.8 Nasazení

- Po aktualizaci na 0.13.0 jednou uložit Nastavení → Trvalé odkazy (nová adresa /materialy/).
- Založit stránku „Moje materiály“ se shortcodem [reserveandpay_my_materials] a stránku „Materiály“ s [reserveandpay_materials]; v Nastavení → Materiály ke stažení vybrat stránku „Moje materiály“ a stránku registrace (Ultimate Member).
- Zkontrolovat v editoru prvního materiálu, že se nezobrazuje varování o nechráněné složce.

### R12 – Časté dotazy (FAQ) u akcí a služeb

**Pozn.: Navrženo a schváleno 13. 11. 2026, implementováno ve verzi pluginu 0.14.0.**

### R12.1 Rozhodnutí

- Časté dotazy se zadávají u konkrétní akce a u konkrétní služby; sdílené dotazy podle kategorie se nezavádějí.
- Dotazy má i stránka služby (pod volnými termíny), nejen akce.
- Výchozí stav na webu: všechny odpovědi sbalené; otevřít lze více odpovědí najednou.
- Strukturovaná data schema.org FAQPage pro vyhledávače se generují.

### R12.2 Administrace

- Rámeček „Časté dotazy“ v editaci akce i služby: opakovač dvojic otázka + odpověď – přidat, odebrat, posunout nahoru / dolů; nejvýše 30 dotazů.
- Otázka je prostý text, odpověď smí obsahovat základní HTML (odkaz, tučné písmo, odrážky). Řádek bez otázky nebo bez odpovědi se neuloží.
- Individuální termín bez vlastních dotazů přebírá dotazy své služby. Duplikát akce dotazy zkopíruje. Generátor termínů dotazy nezakládá ani nemění.
- Data: post meta _rap_faq (pole otázka / odpověď) u akce i služby; bez změny databáze.

### R12.3 Web

- Sekce „ČASTÉ DOTAZY“ (eyebrow) pod popisem akce, u vícedenní akce pod programem; v jednoduchém vzhledu detailu pod obsahem před rezervačním formulářem. U služby pod volnými termíny ([reserveandpay_slots]; parametr faq="no" sekci skryje).
- Otázka je vidět vždy, odpověď se rozbalí pod ní kliknutím. Nativní prvek <details>/<summary> – funguje bez JavaScriptu, ovládá se klávesnicí a hledání v prohlížeči najde i text sbalených odpovědí; stejný princip jako rozbalovací popis služby (0.12.1).
- Vzhled dle grafického manuálu: otázka Fraunces 500 (19 px, ink), oddělení vlasovými linkami line-sand, vpravo tenká šipka ve zlatohnědé #8C6423 (po rozbalení otočená), odpověď Work Sans 16 px v ink-soft s levou linkou 2 px. Barvy přes CSS proměnné pluginu.
- Bez dotazů se sekce nevykreslí. Na jedné stránce se tytéž dotazy nevypíší dvakrát.
- Shortcode [reserveandpay_faq id="…"] – dotazy akce nebo služby podle kódu nebo ID na libovolné stránce; bez id dotazy aktuální akce.
- Strukturovaná data FAQPage (JSON-LD) jednou za stránku v patičce ze všech vypsaných dotazů. Vypnutí filtrem resandpay_faq_schema (např. když je generuje SEO plugin); nadpis sekce mění filtr resandpay_faq_title.

### R13 – Odpočet do zahájení prodeje, konce prodeje a konce slevy u akce

**Pozn.: Navrženo a schváleno 13. 11. 2026, implementováno ve verzi pluginu 0.15.0. Podrobně v Knize rozvoje (Google dokument „Rozvoj slehkouhlavou.cz – rozvojové požadavky“) jako R-007.**

- Zapínatelně u akce (u služby ne), v rámečku Parametry akce řádek „Odpočet“: do zahájení prodeje / do ukončení prodeje (uzávěrka, jinak začátek akce) / do konce slevy (23:59 posledního dne slevy). Vždy jen nejbližší odpočet; dny · hodiny · minuty, živě bez přenačtení, pod odpočtem přesné datum.
- Dál než N dní před cílem jen datum místo odpočtu („Prodej od 1. 2. 2027 9:00“, „Sleva do 31. 1. 2027“ – u slevy bez času); N je parametr pro celé RaP v Nastavení → Odpočet (výchozí 30). Bez JavaScriptu statický údaj vykreslený serverem (minuty zaokrouhlené nahoru).
- Zobrazení: detail akce a rezervační karta; shortcode [reserveandpay_countdown id="AK-2027-0001"] (id = kód akce). Ve výpisu termínů ne. Vzhled dle manuálu (Fraunces, zlatá #B8862E, podklad #F0E8DA), texty v Nastavení.
- Implementace (0.15.0): post meta _rap_countdown (seznam open, discount, close; výchozí prázdné = vypnuto), třída Resandpay_Countdown. Cíle: zahájení prodeje = pole Zahájení rezervací (open_at); konec slevy = konec dne „Sleva za včasnou rezervaci – do“ (23:59:59, i sleva převzatá ze služby); ukončení prodeje = začátek − uzávěrka rezervací. Cíl bez data, zrušená nebo obsazená akce a termín služby odpočet nemají.
- Přepínání v prohlížeči: krátký skript (bez knihovny) přepočítává každých 15 s; po dosažení nuly přejde na další cíl nebo odpočet skryje; po zahájení prodeje stránku jednou obnoví (pojistka v sessionStorage), aby se objevil rezervační formulář. Čtečkám obrazovky se předává souvislá věta („Sleva končí za 3 dny 4 hodiny 12 minut“).
- Nastavení → Odpočet: „Odpočet nejvýš (dní předem)“ (30), texty „Prodej začíná za“, „Sleva končí za“, „Prodej končí za“ a texty pro režim jen datum „Prodej od %s“, „Sleva do %s“, „Prodej do %s“ (%s = datum). V editoru akce nápověda s celým shortcodem s kódem akce a příklad v bočním panelu „Shortcody akce“.

### R14 – Aktualizace pluginu z prostředí WordPressu

**Pozn.: Navrženo a schváleno 13. 11. 2026 (plugin používá i další vývojář ve svých projektech), implementováno ve verzi pluginu 0.16.0. V Knize rozvoje jako R-008.**

- Zdroj aktualizací: GitHub Releases veřejného repozitáře ludektelecky/resandpay. Každá vydaná verze má release se značkou vX.Y.Z, popisem změn z DEVLOGu a přiloženým balíčkem reserveandpay-X.Y.Z.zip (kořenová složka reserveandpay/, bez testů a dokumentace). Stahuje se jen tento balíček, nikdy zdrojový kód větve; pre-release se nenabízí. Repozitář zůstává veřejný, licence v hlavičce „Proprietary“ beze změny.
- Implementace: knihovna Plugin Update Checker 5.7 (MIT) v lib/plugin-update-checker, třída Resandpay_Updater. Kontrola zhruba každých 12 h přes veřejné API GitHubu bez tokenu (limit 60 dotazů za hodinu z jedné IP adresy je dostatečný i na sdíleném hostingu). Hlavička Update URI zabrání záměně s pluginem stejného názvu na wordpress.org.
- Administrace: v Pluginech upozornění na novou verzi, „Zobrazit podrobnosti“ s popisem změn, „Aktualizovat nyní“ a u pluginu odkaz „Zkontrolovat aktualizace“. Úpravy databáze proběhnou po aktualizaci samy (Resandpay_Install::maybe_upgrade).
- Rozhodnutí: automatické aktualizace zůstávají vypnuté (výchozí stav WordPressu, plugin je nezapíná), dokud web nemá staging; aktualizuje se ručně tlačítkem po záloze. Kanál verzí (stabilní / testovací) se nezavádí – každá vydaná verze je určena k aktualizaci.
- Číslování verzí 0.x.y: y = opravy bez zásahu; x = nové funkce a každá změna, která vyžaduje zásah nebo mění chování. V DEVLOGu je u takové verze řádek „⚠ Po aktualizaci:“ s postupem (např. „uložte Trvalé odkazy“), stejně v popisu release.
- Verze 0.16.0 se na weby nahrává ručně naposledy. Vypnutí kontroly na konkrétním webu: konstanta RESANDPAY_DISABLE_UPDATES ve wp-config.php nebo filtr resandpay_update_checker. Web potřebuje odchozí HTTPS na api.github.com a github.com.
- Verze v záhlaví (0.16.1): na každé obrazovce ReserveAndPay štítek „ReserveAndPay X.Y.Z“ u nadpisu stránky, v blokovém editoru v pravém panelu (stav a publikování) a v patičce administrace vedle verze WordPressu – vždy je patrné, která verze je nainstalovaná.

### R15 – Vzhled pluginu podle webu

**Pozn.: Navrženo a schváleno 13. 11. 2026 (plugin používají i jiné weby), implementováno ve verzi pluginu 0.17.0. V Knize rozvoje jako R-009.**

- Nastavení → Vzhled → „Barvy a písma“: „Ze šablony webu (automaticky)“ – výchozí pro nové weby; paleta a písma z theme.json blokových šablon (globální styly: pozadí, text, tlačítko, odkazy, písma), z GeneratePress (globální barvy contrast, contrast-2, base-2, base-3, accent; typografie body a nadpisů) nebo z palety editoru klasických šablon; chybějící role se dopočítají. „Vlastní barvy a písma“ – 10 rolí (podklad, druhý podklad, text, doplňkový text, akcent, akcent po najetí, druhý akcent, zvýraznění, linky, chyba) a písmo nadpisů a textu (CSS font-family, prázdné = písmo webu). V nastavení náhled použité palety.
- Jedna paleta (třída Resandpay_Style) pro všechny výstupy: web – CSS proměnné --rap-* vkládané za frontend.css; e-maily – barvy, písma s bezpečnou náhradou, barva textu tlačítka podle kontrastu (WCAG); PDF platební pokyn a slevový poukaz – barvy a sada písem; přehled účastníků; štítek verze v administraci. Filtry resandpay_style a resandpay_mail_colors.
- Písmo v PDF: DejaVu Serif / DejaVu Sans (vloženo v pluginu, plná čeština, výchozí pro nové weby) nebo Fraunces / Work Sans. Do PDF lze vložit jen písma obsažená v pluginu.
- Pro vývojáře: pole „Vlastní CSS“ (načte se za styly pluginu jen tam, kde plugin něco zobrazuje), filtr resandpay_enqueue_styles (vypnutí frontend.css), dokumentace proměnných --rap-* a hlavních tříd v README (Vzhled a CSS).
- Neutrální výchozí hodnoty nové instalace: logo z nastavení pluginu bez textů a symbolu (bez loga webu název značky / webu), prázdné motto; popisek a nápověda poznámky ve formuláři v Nastavení. Stávající web (aktualizace na DB 12) si ponechá dosavadní vzhled – chybějící klíče se doplní paletou a texty dle grafického manuálu „s lehkou hlavou“, uložené hodnoty se nemění. Na jiném webu je po aktualizaci potřeba přepnout „Ze šablony webu“ (DEVLOG „⚠ Po aktualizaci“).

### R16 – Přehled zákazníků

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #19](https://github.com/ludektelecky/resandpay/issues/19)), implementováno ve verzi pluginu 0.18.0.**

- Nová stránka **ResAndPay → Zákazníci** (oprávnění k rezervacím): všichni, kdo kdy provedli jakoukoli rezervaci – akce, termíny služeb i objednávky materiálů. Zákazník = e-mail bez ohledu na velikost písmen a mezery na okrajích; rezervace bez e-mailu se nezapočítávají.
- Sloupce: jméno a telefon z poslední rezervace, e-mail, počet rezervací (z toho aktivní a zrušené / expirované – započítávají se všechny stavy), první a poslední rezervace, zaplaceno celkem (součet uhrazených částek po měnách), souhlas s newsletterem z poslední rezervace.
- Hledání podle jména, e-mailu a telefonu kterékoli rezervace zákazníka; řazení podle jména, e-mailu, počtu rezervací, první a poslední rezervace a zaplacené částky (výchozí: poslední rezervace sestupně); stránkování po 50; filtr „jen se souhlasem s newsletterem“.
- Proklik na zákazníka → seznam rezervací (ResAndPay → Rezervace s filtrem `email`) se souhrnem zákazníka v záhlaví, stavy, hledáním, akcemi nad rezervacemi a exportem CSV; filtr lze kombinovat s výběrem akce. V detailu rezervace odkaz „všechny rezervace zákazníka (N)“.
- Export CSV (UTF-8 se středníkem pro Excel) podle aktuálního hledání a filtru: jméno, e-mail, telefon, rezervací, aktivních, zrušených / expirovaných, první a poslední rezervace, zaplaceno, newsletter.
- Počítá se přímo z tabulky rezervací (třída Resandpay_Customers) – bez nové tabulky a bez změny databáze. Hromadné oznámení zákazníkům není součástí, řeší se samostatně.

### R17 – Napojení na MailerLite (E6b)

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #22](https://github.com/ludektelecky/resandpay/issues/22)), implementováno ve verzi pluginu 0.19.0.**

- Volitelný modul (třída Resandpay_MailerLite), ve výchozím stavu vypnutý. Nastavení → záložka **MailerLite** (každý napojený systém má vlastní záložku; do 0.19.0 „Napojení“): zapnutí, API klíč (šifrovaně, prázdné pole = ponechat; alternativně konstanta RESANDPAY_MAILERLITE_API_KEY), **výchozí skupiny** (zaškrtávací seznam skupin načtených z účtu – tlačítko „Ověřit připojení a načíst skupiny“, seznam v mezipaměti), **Kdy zapsat** (po zaplacení – výchozí / hned po vytvoření rezervace), **Předávat telefon**.
- Skupiny se vždy načítají z účtu MailerLite daného webu, administrátor je jen vybírá; lze vybrat více skupin. U akce, služby a materiálu boční panel **MailerLite**: výchozí / vybrané skupiny / neposílat do MailerLite. Termín služby přebírá nastavení služby. Žádná skupina = zákazník se zapíše jen do odběratelů účtu.
- Zapisují se jen rezervace se souhlasem s novinkami (pole ve formuláři rezervace a objednávky materiálu). Odesílá se e-mail (malými písmeny), jméno a příjmení (z pole Jméno – první slovo / zbytek) a volitelně telefon (pole phone). API connect.mailerlite.com, POST /subscribers – zákazník se jen přidává do skupin, nikdy se neodebírá; parametr status se neposílá, takže odhlášený odběratel se znovu nepřihlásí. Dvojí potvrzení a automatizace se řídí nastavením účtu MailerLite.
- Odesílání na pozadí: rezervace se zařadí do fronty (stav „čeká na odeslání“), WP-Cron ji odešle do minuty, zbytek po dávkách 50 i při pravidelné kontrole pluginu. Při chybě 3 pokusy, pak stav „chyba“ se zprávou; neplatný API klíč hned chyba. V testovacím režimu pluginu se do MailerLite neposílá (stav „neodesláno – testovací režim“).
- Stav u rezervace (sloupce ml_status, ml_groups, ml_at, ml_error, ml_tries; DB 13): detail rezervace – řádek MailerLite se stavem, skupinami, časem a chybou, odkaz **Odeslat znovu / Odeslat teď**. Přehled Zákazníci – sloupec a filtr MailerLite (odesláno / čeká / chyba / se souhlasem neodesláno / bez souhlasu), stav i v exportu CSV. V Nastavení souhrn stavů a tlačítko „Odeslat čekající teď“.
- **Import stávajících zákazníků**: tlačítko v Nastavení → MailerLite zařadí do fronty zákazníky, jejichž poslední rezervace má souhlas s novinkami a kteří ještě nebyli odesláni – podle jejich poslední rezervace se souhlasem (při „po zaplacení“ poslední zaplacené); skupiny podle této rezervace.
- Pro vývojáře filtry resandpay_mailerlite_groups (skupiny pro rezervaci) a resandpay_mailerlite_subscriber (data odběratele).

### R18 – Profil zákazníka „Moje rezervace“

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #24](https://github.com/ludektelecky/resandpay/issues/24)), implementováno ve verzi pluginu 0.20.0.**

- Shortcode **[reserveandpay_my_reservations]** (třída Resandpay_Profile); stránka se vybírá v Nastavení → Rezervace → **Profil zákazníka** (nevybráno = profil vypnutý). Tamtéž **Platnost osobního odkazu** (dní, výchozí 30, 1–365).
- Přístup: **přihlášený uživatel** vidí rezervace podle e-mailu účtu; **kdokoli s osobním odkazem** (`?rap_p=`) vidí rezervace e-mailu, na který odkaz patří. Odkaz = token rezervace, čas vypršení a podpis HMAC (nic se neukládá, e-mail v odkazu není). Po vypršení nebo bez odkazu stránka nabídne **„Pošlete mi odkaz“** – po zadání e-mailu přijde e-mail „Odkaz na profil zákazníka“ (šablona v Nastavení → E-maily), jen pokud k e-mailu existuje rezervace; odpověď je vždy stejná, limit 1× za 5 min na e-mail a 10× za hodinu z IP.
- **Odkaz v každém e-mailu zákazníkovi:** na konec se připojí text „Odkaz na profil v e-mailech“ (Nastavení → E-maily, výchozí „Své rezervace a platby najdete v profilu: {profile_url}“; prázdné = nepřipojovat). Proměnné {profile_url} a {profile_days} lze použít v textech e-mailů. E-maily správci odkaz nemají.
- **Obsah profilu:** všechny rezervace zákazníka – **Nadcházející** (platné rezervace akcí a termínů, které ještě neskončily, podle data), **Proběhlé nebo zrušené** (proběhlé, zrušené a expirované), **Objednávky materiálů**. U každé: název s odkazem, termín, místo, datum rezervace, stav, částka, uhrazeno a zbývá, splatnost, VS; u nezaplacených QR platba a **Platební pokyn (PDF)**; u zaplacených materiálů odkaz do Moje materiály. Poznámka zákazníka se nezobrazuje.
- **Novinky e-mailem:** zaškrtávací pole „Chci dostávat novinky e-mailem“ – změna se zapíše ke všem rezervacím zákazníka s datem a způsobem (sloupce newsletter_at, newsletter_src; DB 14; v detailu rezervace „změněno zákazníkem v profilu“). Udělení souhlasu → zařazení do MailerLite; odvolání → odhlášení v MailerLite (stav „odhlášeno (souhlas odvolán)“, filtr v přehledu Zákazníci; import ho nenabízí) – je-li napojení zapnuté. Hook resandpay_newsletter_changed.
- Stránka profilu se neukládá do cache, neindexuje (noindex) a posílá Referrer-Policy: no-referrer.

### R19 – Znepřístupnění materiálu

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #26](https://github.com/ludektelecky/resandpay/issues/26)), implementováno ve verzi pluginu 0.21.0.**

- Parametry materiálu → **Dostupnost**: V nabídce / Staženo z nabídky (beze změny: zmizí z katalogu, nelze objednat, kdo ho má, stahuje dál) / **Znepřístupněno**.
- Znepřístupněný materiál zmizí z katalogu i z karty `[reserveandpay_material]` a **nikdo ho nestáhne** – ani se zaplacenou objednávkou, ručním zpřístupněním nebo jako účastník; nestáhne se ani ukázka a už vydané odkazy ke stažení přestanou platit (nárok se ověřuje při každém stažení). Správci (oprávnění k rezervacím) mají přístup dál.
- Na stránce materiálu, v Moje materiály (u toho, kdo měl nárok) a v profilu zákazníka se místo souborů zobrazí text z Nastavení → Materiály ke stažení („Text u znepřístupněného materiálu“, výchozí „Materiál už není k dispozici.“).
- Nároky se nemažou – po vrácení do nabídky se obnoví. Zákazníkům se nic neposílá, správce se na vrácení peněz neupozorňuje (rozhodnutí 14. 11. 2026).
- Záznam, kdo a kdy materiál znepřístupnil (meta _rap_mat_disabled_at / _by, zobrazeno v Parametrech materiálu); v seznamu materiálů štítek „znepřístupněno“. Meta _rap_mat_withdrawn zůstává (znepřístupněný materiál je zároveň stažený z nabídky).

### R20 – Statistika materiálů

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #28](https://github.com/ludektelecky/resandpay/issues/28)), implementováno ve verzi pluginu 0.22.0.**

- Stránka Statistika (administrace i web se shortcodem [reserveandpay_stats], stejná oprávnění) má přepínač **Termíny akcí a služeb / Materiály** (parametr rap_st_view=materials). Třída Resandpay_Stats_Materials.
- Filtr období Od–Do (výchozí poslední 3 měsíce). Souhrn: zaplacené objednávky, tržby, zdarma, stažení a přehrání, uživatelů stahovalo (různí uživatelé přes všechny materiály).
- **Graf** vývoje v čase (inline SVG, bez knihoven): sloupce stažení a přehrání a zaplacených objednávek po dnech (do 62 dní), týdnech (do ~1 roku) nebo měsících; hodnota po najetí myší, popis pro čtečky.
- **Tabulka po materiálech** (zveřejněné a materiály s aktivitou v období; štítek staženo z nabídky / znepřístupněno): kód, název, druh a přístup / cena, zaplacené a čekající objednávky, tržby (s informací o slevách), zdarma (kupón 100 % + ruční zpřístupnění + účastníci navázaných akcí a služeb – rozpad v nápovědě), stažení, přehrání, různí uživatelé; součty; řazení podle sloupců (výchozí stažení sestupně).
- Období: objednávky podle data objednání, tržby = přijaté platby těchto objednávek, ruční zpřístupnění podle data zpřístupnění, účastníci podle data zaplacení rezervace, stažení podle logu (jen úspěšná, ukázky zvlášť). Materiály „zdarma pro přihlášené“ jsou vidět jen ve staženích.
- **Export CSV** (UTF-8 se středníkem) se všemi ukazateli včetně rozpadu zdarma, expirovaných a zrušených objednávek, slev, kupónů a ukázek.

### R21 – Vodoznak v PDF materiálů (R11-e)

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #27](https://github.com/ludektelecky/resandpay/issues/27)), implementováno ve verzi pluginu 0.23.0.**

- Nastavení → Materiály ke stažení: **Vodoznak v PDF – výchozí** (vypnuto), **Text vodoznaku** (proměnné {name}, {email}, {date}, {code}, {site}; výchozí „{name} · {email} · {date}“), **Umístění** – zápatí stránky / šikmo přes stránku / obojí (výchozí zápatí). U materiálu Parametry → **Vodoznak v PDF**: výchozí / ano / ne.
- Při stažení i zobrazení PDF v prohlížeči (ne u ukázky) se vytvoří kopie, do každé stránky se vloží text (zápatí: malý šedý text dole; šikmo: velký světlý text přes střed, průhlednost 14 %) a údaje se zapíší do **metadat PDF** (předmět a klíčová slova „Licencováno pro: …“). Soubor na disku se nemění; kopie je v chráněné mezipaměti (resandpay-protected/wm-cache, 24 h, úklid automaticky) a odesílá se stejně jako originál včetně HTTP Range.
- Třída Resandpay_Watermark, knihovna FPDI 2.6.4 (MIT, lib/fpdi) nad TCPDF. Omezení: bezplatný parser FPDI nezpracuje PDF s komprimovanými křížovými odkazy (PDF 1.5+) ani zašifrované PDF – takový soubor se stáhne **bez vodoznaku**, do logu stažení se zapíše záznam (druh wm, výsledek unsupported) a editor materiálu u souboru zobrazí upozornění s postupem (uložit jako PDF kompatibilní s 1.4). Odkazy, záložky a formuláře původního PDF se do kopie nepřenášejí.

### R22 – Materiál podle e-mailu bez účtu

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #29](https://github.com/ludektelecky/resandpay/issues/29)), implementováno ve verzi pluginu 0.24.0.**

- **Zpřístupnění podle e-mailu** (editor materiálu → Přístupy): jeden nebo více e-mailů najednou (řádek „e-mail“, „Jméno <e-mail>“ nebo „e-mail;Jméno“; duplicity a neplatné řádky se přeskočí), doba přístupu, poznámka, „poslat e-mail“. Účet na webu není potřeba. Zákazník bez účtu dostane e-mail „Materiál – zpřístupněn bez účtu“ (šablona v Nastavení → E-maily) s **odkazem na materiál** s osobním tokenem a s **odkazem na profil zákazníka**; zákazník s účtem stávající e-mail „Materiál – ručně zpřístupněn“. Odebrání přístupu s důvodem jako dosud.
- **Objednávka placeného materiálu bez účtu**: nepřihlášený zákazník na stránce materiálu vyplní jméno a e-mail (+ souhlasy, firma, kupón, newsletter) – jen je-li vybraná stránka profilu zákazníka; možnost „Přihlaste se“ zůstává. Ochrana formuláře proti robotům jako u rezervací. Po odeslání se stránka otevře s osobním odkazem a platebními údaji; e-mail o zaplacení obsahuje odkaz na materiál s osobním tokenem. Druhá objednávka téhož e-mailu nevznikne, dokud se čeká na platbu.
- **Stahování bez přihlášení**: osobní odkaz (?rap_p=, profil R18 – token objednávky / rezervace nebo nově token zpřístupnění) otevře stránku materiálu se soubory a **profil zákazníka** s novou částí **Materiály ke stažení** (zpřístupněné podle e-mailu, zaplacené objednávky, účastníci navázaných akcí). Odkaz ke stažení je podepsaný, časově omezený a vázaný na e-mail; platí limity stažení (počítané podle e-mailu), log stažení (sloupec email) a vodoznak (jméno z objednávky / zpřístupnění). Materiály „zdarma pro přihlášené“ a „jen pro role“ dál vyžadují přihlášení.
- **Účet se stejným e-mailem**: objednávky a zpřístupnění podle e-mailu platí i po přihlášení – materiál se zobrazí v Moje materiály (rozhodnutí 14. 11. 2026).
- „Pošlete mi odkaz“ na stránce profilu funguje i pro zákazníka, který má jen zpřístupnění (bez rezervace).
- Databáze 15: tabulka resandpay_grants – sloupce email, name, token; resandpay_downloads – sloupec email. Funkce Resandpay_Materials::access_email(), grant_email(), grant_bulk(), for_email().

### R23 – Odbavení na místě na webu a přístup podle rolí

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #30](https://github.com/ludektelecky/resandpay/issues/30)), implementováno ve verzi pluginu 0.25.0.**

- Shortcode **[reserveandpay_checkin]** pro samostatnou webovou stránku (např. /odbaveni) – stejné odbavení jako ResAndPay → Odbavení, ve vzhledu webu a pro telefon (velká tlačítka). Logika i výpis jsou sdílené (třída Resandpay_Checkin, administrace Resandpay_Admin_Checkin jen obaluje).
- **Nastavení → Rezervace → Odbavení na místě → Odbavení vidí role** (role webu včetně Ultimate Member), výchozí **Administrátor, Šéfredaktor**; platí pro webovou stránku i administraci (oprávnění resandpay_checkin, správce webu vždy). Nepřihlášený dostane výzvu k přihlášení, bez oprávnění hlášku „K odbavení nemáte oprávnění“.
- Uživatel odbavení smí jen platbu na místě (nic nerušit ani nevracet): najít rezervaci podle **VS** nebo **QR kódu** (kamera), **Zaplaceno hotově** (volitelně potvrzení e-mailem), **Zobrazit QR kód k zaplacení** (QR platba na zbývající částku – platba převodem se spáruje automaticky z bankovního avíza) **Zkontrolovat platbu v bance** (spustí kontrolu plateb – načtení avíz a spárování – a ukáže výsledek pro rezervaci; znovu nejvýš 1× za nastavený počet sekund, Nastavení → Odbavení na místě, výchozí 30 s, 5–600) a **Znovu poslat platební údaje** (e-mail zákazníkovi s aktuálním nedoplatkem, QR a PDF – stejně jako v detailu rezervace; jen u nezaplacené rezervace). Ruční „Zaplaceno převodem“ a odkaz na detail rezervace jen v administraci pro role s oprávněním k rezervacím.
- Karta rezervace i přehled dne zobrazují **telefon a poznámku** zákazníka (rozhodnutí 14. 11. 2026).
- Stránka se neukládá do cache a neindexuje (osobní údaje); akce s nonce a kontrolou oprávnění, zpráva po akci (PRG).

### R24 – Materiál „Odeslat na e-mail (bez účtu)“

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #33](https://github.com/ludektelecky/resandpay/issues/33), sjednocení [Issue #34](https://github.com/ludektelecky/resandpay/issues/34)), implementováno ve verzi pluginu 0.26.0.**

- Nový přístup u materiálu **„Odeslat na e-mail (bez účtu)“** (Resandpay_Materials::ACCESS_EMAIL = email), řídí se **cenou**: **s cenou** se chová jako placený materiál – zákazník bez účtu vyplní objednávkový formulář (jméno, e-mail, souhlasy včetně obchodních podmínek a okamžitého poskytnutí digitálního obsahu), přijde mu **platební pokyn** a po zaplacení odkaz na materiál a profil (R22); **bez ceny (prázdná / 0)** platí následující body (zdarma po zadání e-mailu). Nepřihlášený zákazník na stránce materiálu vyplní **jméno**, **e-mail**, **souhlas se zpracováním osobních údajů** (povinný) a nepovinný, nezaškrtnutý **souhlas s novinkami**; ochrana formuláře proti robotům a limity četnosti jako u rezervací. Formulář se zobrazí jen s vybranou stránkou profilu zákazníka (jinak editor materiálu upozorní). Přihlášený uživatel má materiál zdarma hned (materiál s cenou si kupuje).
- Po odeslání vznikne **objednávka zdarma** (stav Zaplaceno, částka 0, způsob úhrady „Zdarma“, user_id účtu se stejným e-mailem, jinak 0) a přijde e-mail **„Materiál zdarma po zadání e-mailu“** (šablona v Nastavení → E-maily) s **odkazem na materiál** (osobní token) a automaticky připojeným **odkazem na profil**. Stránka jen oznámí „Odkaz jsme poslali na váš e-mail“ – soubory se zpřístupní až z odkazu (ověření, že e-mail patří zákazníkovi; zvláštní potvrzovací krok se nepoužívá – rozhodnutí 14. 11. 2026).
- Stejný e-mail podruhé: nová objednávka nevznikne, odkaz se pošle znovu nejvýš 1× za 5 min.
- Zákazník je v přehledu **Zákazníci**, ve **statistice materiálů** (zdarma) a se souhlasem s novinkami v **MailerLite** (skupina materiálu, podle nastavení „Kdy zapsat“ – objednávka je hned zaplacená). Doba přístupu podle materiálu.

### R25 – Přístup k materiálu: kdo + cena, akční cena do data

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #36](https://github.com/ludektelecky/resandpay/issues/36)), implementováno ve verzi pluginu 0.27.0.**

- **Přístup** u materiálu určuje jen, *kdo* smí materiál získat (meta _rap_mat_audience): **Jen pro přihlášené** · **Pro přihlášené i bez přihlášení – odeslat na e-mail** (ověření e-mailu odkazem jako R22/R24) · **Jen pro vybrané role** (role z Ultimate Member). **Placený / zdarma určuje jen cena**: prázdná / 0 = zdarma, vyšší = placený (objednávka, QR platba, kontrola plateb).

| Přístup | Cena | Přihlášený | Nepřihlášený |
|---|---|---|---|
| Jen přihlášení | 0 | stáhne hned | přihlášení / registrace |
| Jen přihlášení | > 0 | objedná, zaplatí, stáhne | přihlášení |
| I e-mail | 0 | stáhne hned | jméno + e-mail → odkaz na materiál a profil |
| I e-mail | > 0 | objedná, zaplatí, stáhne | jméno + e-mail → platební pokyn → po zaplacení odkaz |
| Role | 0 | s rolí stáhne, bez role „jen pro členy“ | přihlášení |
| Role | > 0 | s rolí kupuje, bez role nelze objednat | přihlášení |

- Napříč kombinacemi dál platí zdarma pro účastníky vybraných akcí / služeb (účastník se zaplacenou rezervací má i placený materiál za 0 Kč), ruční zpřístupnění, slevové kupóny, ukázky, dostupnost, vodoznak.
- „Odeslat na e-mail“ **nelze uložit bez stránky profilu zákazníka** (volba je v editoru zakázaná; při pokusu se ponechá dosavadní přístup a zobrazí hláška).
- **Akční cena do data**: v Parametrech materiálu sleva (pevná částka nebo procento) a datum, do kdy platí, a popis – stejný mechanismus jako sleva za včasnou rezervaci u akcí (meta _rap_early_*): na webu „300 Kč → 200 Kč (při objednávce do 31. 12. a platbě ve splatnosti)“, sleva zaniká při platbě po splatnosti, slevový kupón s ní nelze kombinovat.
- **Převod (DB 16)** beze změny chování: Zdarma pro přihlášené → Jen přihlášení (dosud nepoužitá cena se vynuluje), Placený → Odeslat na e-mail (bez stránky profilu Jen přihlášení), Odeslat na e-mail → Odeslat na e-mail, Jen vybrané role → Role (cena se vynuluje). Meta _rap_mat_access zůstává kvůli kompatibilitě.

### R26 – Přehlednější editor: nastavení v záložkách

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #38](https://github.com/ludektelecky/resandpay/issues/38)), implementováno ve verzi pluginu 0.28.0.**

- **Materiál** se edituje v **klasickém editoru** (popis nahoře, nastavení hned pod ním na celé šířce; filtr resandpay_material_block_editor vrátí blokový editor). Dosavadní rámečky Parametry materiálu, Soubory ke stažení a Přístupy jsou sloučené do jednoho rámečku **„Nastavení materiálu“** se záložkami: **Základní** (kód, druh, dostupnost) · **Přístup a cena** (přístup, role, cena, akční cena, splatnost, doba přístupu) · **Zdarma pro účastníky** · **Soubory** (soubory, vodoznak v PDF) · **Přístupy a stažení** (objednávky, ruční zpřístupnění, poslední stažení) · **MailerLite** (jen při zapnutém napojení – přesunuto z pravého panelu).
- **Akce** (Parametry akce) – záložky **Termín a místo** · **Cena a platba** · **Rezervace** · **Připomínka a odpočet** · **MailerLite**; **služba** (Parametry služby) – **Termíny a místo** · **Cena a platba** · **Rezervace a připomínka** · **Web a shortcody** · **MailerLite**. MailerLite už není v bočním panelu akce, služby ani materiálu. Ostatní rámečky akce (fotky, FAQ, program, obsazenost) beze změny.
- Záložky přepínají zobrazení bez přenačtení, ukládá se vše najednou (Aktualizovat); poslední záložka se pamatuje, odkaz s kotvou (např. #rap_material_access po zpřístupnění) a neplatné pole otevřou příslušnou záložku; bez JavaScriptu je vidět vše jako dřív. Obecné pomocné funkce Resandpay_Admin::tabs_open() / tab() / tabs_script() (atributy data-rap-tabs, data-rap-tab).

### R27 – Historie stažení materiálů u zákazníka

**Pozn.: Navrženo a schváleno 14. 11. 2026 ([Issue #41](https://github.com/ludektelecky/resandpay/issues/41)), implementováno ve verzi pluginu 0.29.0.**

- V profilu zákazníka (část Materiály ke stažení) a na stránce **Moje materiály** je u každého materiálu **„Staženo N× · naposledy …“** a rozbalovací **historie stažení** (datum a čas, soubor, stažení / přehrání; „… a N starších“). Počet záznamů v Nastavení → Materiály ke stažení → „Historie stažení u zákazníka – počet záznamů“ (výchozí 5, 0 = jen celkový počet).
- Zákazník = e-mail profilu: záznamy logu s tímto e-mailem (stažení přes osobní odkaz) i záznamy účtů se stejným e-mailem (po přihlášení). Nepočítají se ukázky, neúspěšné pokusy (limit) ani technické záznamy vodoznaku.
- Profil nově ukazuje i materiály, ke kterým zákazník nemá záznam o nároku, ale **stáhl si je** (typicky „Jen pro přihlášené“ zdarma): po přihlášení se stažením, přes osobní odkaz s historií a „Ke stažení po přihlášení“, bez přístupu „Materiál už nemáte k dispozici“, znepřístupněné s textem z nastavení.
- Historie se řídí uchováváním logu (Uchovávat záznamy o stažení, výchozí 365 dní). Funkce Resandpay_Materials::download_history(), downloaded_ids().

### R28 – Fotky z proběhlé akce a hláška „akce již proběhla“

**Pozn.: Navrženo a schváleno 19. 11. 2026 ([Issue #45](https://github.com/ludektelecky/resandpay/issues/45)), implementováno ve verzi pluginu 0.30.0.**

- **Hláška podle stavu akce:** během akce (od začátku do konce) „Rezervace již není možná – akce už začala.“, po skončení „Rezervace není možná – akce již proběhla.“ (chyba `over` v Resandpay_Events::check_bookable). Akce bez vyplněného konce (při uložení se konec vyplní začátkem) končí o půlnoci po dni konání. Texty v Nastavení → Web a vzhled → Detail akce (event_started_text, event_over_text).
- **Fotky z akce** – samostatná komponenta vedle galerie v záhlaví (ta se nemění), jen u akcí (ne u termínů služeb). Editor akce → Parametry akce → záložka **Fotky z akce**:
  - **Kdo fotky uvidí** (meta `_rap_photos_vis`): *Jen účastníci akce* (výchozí) · *Přihlášení uživatelé* · *Veřejné*. Účastník = zákazník se **zaplacenou** rezervací (i zaplacenou na místě; zrušené, propadlé a nezaplacené ne), podle účtu nebo e-mailu (i e-mailu účtu). Správce akce vidí fotky vždy.
  - **Nahrání** více fotek najednou: v prohlížeči se zmenší (delší strana 2048 px) a znovu uloží jako JPEG – odstraní se EXIF včetně polohy (GPS). Server vytvoří čtvercový náhled 480 × 480 (bez knihovny pro úpravu obrázků se použije fotka). Nejvýše 500 fotek, pořadí přetažením, odebrání (smaže soubory) – uloží se s akcí (meta `_rap_photos`, pole `rap_photos_order`).
  - **Ochrana** stejně jako materiály: soubory v chráněné složce RaP-Pf (`resandpay-protected/e{ID}-…`, přímý přístup zakázán), náhledy i fotky posílá plugin přes podepsaný odkaz `?rap_ph=` vázaný na návštěvníka (účet / e-mail) s platností 6–7 h; přístup se ověří u každého obrázku znovu (po zrušení rezervace odkaz přestane fungovat). U veřejných fotek odkaz bez omezení doby, po změně na omezený přístup přestane platit.
  - **E-mail účastníkům:** po skončení akce tlačítko „Poslat účastníkům e-mail, že jsou fotky k dispozici (N)“ – jeden e-mail na adresu, šablona Nastavení → E-maily → Fotky z akce (`tpl_event_photos_*`, proměnná `{photos_url}` = stránka akce s osobním odkazem, bez přihlášení). Zaznamená se datum a počet (meta `_rap_photos_notified`).
- **Stránka akce po skončení** (vzhled s galerií): v záhlaví místo „Rezervovat místo →“ tlačítko **„Fotky z akce ↓“** (jen jsou-li fotky a návštěvník je smí vidět), bez odpočtu; v pravém sloupci karta s hláškou a odkazem na fotky; pod popisem akce **přes celou šířku** sekce „Fotky z akce (N)“ (`#rap-photos`): mřížka čtvercových náhledů (na mobilu 3 v řadě), prvních 24 hned, další tlačítkem „Zobrazit další“, líné načítání. Jednoduchý vzhled a shortcode formuláře: hláška a sekce fotek na místě formuláře.
- **Bez přístupu:** místo mřížky text z nastavení (photos_locked_text / photos_login_text), tlačítko Přihlásit se, odkaz Moje rezervace a po odeslání e-mailu „Účastníkům jsme poslali odkaz e-mailem“. Stránka otevřená osobním odkazem se neukládá do mezipaměti.
- **Prohlížeč fotek** (jen prohlížení, bez stažení): přes celou obrazovku na tmavém pozadí, šipky a klávesy ←/→, tah prstem, počítadlo „3 / 24“, Esc / křížek, bez externích knihoven (assets/js/event-photos.js, třídy `rap-photos*`, `rap-lightbox*`).
- **Profil zákazníka:** u zaplacené rezervace proběhlé akce s fotkami odkaz „Fotky z akce →“ (přes osobní odkaz s tokenem).
- Funkce Resandpay_Event_Photos (add, set_order, can_view, is_attendee, url, authorize, section, notify), administrace Resandpay_Admin_Event_Photos. Bez změny databáze.

### R29 – Na webu jen celková kapacita, bez volných míst

**Pozn.: Navrženo a schváleno 19. 11. 2026 ([Issue #47](https://github.com/ludektelecky/resandpay/issues/47)), implementováno ve verzi pluginu 0.31.0.**

- U **akce** (Parametry akce → Termín a místo → Místo a kapacita) a u **služby** (Parametry služby → Termíny a místo → Počet míst na termín) volba **„Na webu nezobrazovat volná místa“** (meta `_rap_hide_free`, výchozí vypnuto). Termín služby přebírá volbu ze služby (platí i pro již vypsané termíny), v editoru termínu se volba nenabízí. Rozsah jen u jednotlivé akce a služby – globální výchozí stav v Nastavení se nezavádí.
- Se zapnutou volbou se ve všech veřejných částech místo počtu volných / přihlášených míst ukáže celková kapacita **„Kapacita: N míst“**: výpis akcí (místo „Volná místa: X z N“), záhlaví detailu akce (údaj Kapacita), rezervační karta (bez teček po místech a bez „poslední místa“; uzávěrka zůstává), shortcode detailu (Kapacita místo „Volná místa X z N“), karta služby v přehledu (místo „Nejbližší: volno X z N“; počet termínů s volnem zůstává), výběr termínů služby (u časů bez „13 volných míst“, v záhlaví „max. N osob“).
- **Plně obsazená akce / termín dál ukazuje „Obsazeno“** a další rezervaci nepustí (kontrola kapacity beze změny); obsazený termín služby se ve výběru nenabízí.
- Externí akce a služby (kap. 3.11) se řídí svou volbou „Zobrazit celkovou kapacitu“. Administrace, Statistika, odbavení a e-maily (proměnná `{free}` v upozornění správci) beze změny.
- Funkce Resandpay_Events::hides_free(), public_capacity(), save_hide_free_meta() (ukládá jen se značkou `rap_hide_free_field`, takže generátor ani jiné uložení volbu nepřepíše), hide_free_field(). Bez změny databáze.

### R30 – Video a audio jen k přehrání, vodoznak ve videu

**Pozn.: Navrženo a schváleno 19. 11. 2026 ([Issue #50](https://github.com/ludektelecky/resandpay/issues/50)), implementováno ve verzi pluginu 0.32.0.**

- Editor materiálu → Soubory: u každého video / audio souboru v RaP-Pf sloupec **„Jen přehrát“** (příznak `play_only` v `_rap_mat_files`; u jiných typů se nenabízí ani neuloží). Výchozí vypnuto.
- Web (stránka materiálu, Moje materiály, profil zákazníka): u souboru „jen přehrát“ jen přehrávač bez tlačítka „Stáhnout“ (`controlsList="nodownload"`, bez kontextové nabídky, u videa bez obrazu v obraze), místo velikosti „ke sledování online“ / „k poslechu online“. Server odkaz ke stažení (bez příznaku přehrávání) odmítne – chyba `play_only`, HTTP 403; uživatel s oprávněním ke správě rezervací si soubor stáhne. Platí i pro ukázky.
- **Vodoznak přes video:** zapíná se stejně jako vodoznak v PDF (Nastavení → Materiály ke stažení → „Vodoznak v PDF a videu – výchozí“, u materiálu výchozí / ano / ne), text „Text vodoznaku“ ({date} = datum přehrání), umístění „Umístění vodoznaku“: zápatí = malý poloprůhledný text dole přes obraz, šikmo = velký světlý text přes střed, obojí. Překryv v HTML nad přehrávačem (soubor se nemění) u všech videí materiálu; celou obrazovku přepíná tlačítko ⛶ a dvojklik na obalu videa (nativní celá obrazovka videa je vypnutá, `nofullscreen`), aby vodoznak zůstal vidět (assets/js/material-player.js, třídy `rap-mat-video`, `rap-mat-wm*`). Na iPhonu (bez celé obrazovky prvku) zůstává video v okně stránky. **Audio:** pod přehrávačem „Licencováno pro: …“. Ukázky bez přihlášení jsou bez vodoznaku.
- Omezení (nápověda v editoru): data se při přehrávání přenášejí do prohlížeče – stažení se výrazně ztíží, úplně se mu nezabrání (záznam obrazovky, nástroje prohlížeče); vodoznak záznam odradí.
- Funkce Resandpay_Materials::is_media(), Resandpay_Materials_Views::player(). Bez změny databáze.

### R31 – Externí video z YouTube v materiálu

**Pozn.: Navrženo a schváleno 19. 11. 2026 ([Issue #51](https://github.com/ludektelecky/resandpay/issues/51)), implementováno ve verzi pluginu 0.32.0.** Rozhodnutí: v první verzi jen YouTube; přehrání do historie ano; tlačítko „Přehrát na YouTube“ (bez vloženého přehrávače).

- Editor materiálu → Soubory → **Externí videa (YouTube)**: název na webu, adresa, ukázka (bez přihlášení), odstranění, tlačítko „+ další video“. Meta `_rap_mat_videos` (id `yt…`, label, url, sample). Přijímá https adresy youtube.com / www / m / youtube-nocookie.com (watch?v=, /shorts/, /live/, /embed/) a youtu.be s 11znakovým ID; ukládá se jednotně `https://www.youtube.com/watch?v=ID`. Neplatná adresa se neuloží (upozornění „video_bad“), u existujícího videa zůstane původní adresa.
- Web: položka v seznamu souborů „YouTube · video online“; oprávněný zákazník (a u ukázky kdokoli) vidí tlačítko **„Přehrát na YouTube ↗“** (nové okno, `rel="noopener nofollow"`), bez přístupu zámek 🔒. Adresa videa se do stránky **nevypisuje** – tlačítko vede na podepsaný odkaz `?rap_dl=` (stejný jako u souborů, vázaný na návštěvníka a časově omezený); server ověří přístup, zapíše přehrání (`kind = play`, u ukázky `sample`) a přesměruje (302) na YouTube.
- Historie zákazníka „přehrání na YouTube“, statistika materiálu (přehrání), poslední stažení v editoru „(YouTube)“; počet souborů v seznamu materiálů zahrnuje videa.
- Omezení (nápověda): po otevření na YouTube zákazník adresu vidí a může ji přeposlat (neveřejné video přehraje kdokoli s odkazem) – přísněji chráněný obsah nahrát do RaP-Pf (R30). Bez vloženého přehrávače nejsou na stránce cookies YouTube. Vimeo / Bunny Stream případně později.
- Funkce Resandpay_Materials::videos(), video(), youtube_url(), save_videos(). Bez změny databáze.

### R32 – Upravitelné texty, když služba nemá volné termíny

**Pozn.: Navrženo a schváleno 19. 11. 2026 ([Issue #53](https://github.com/ludektelecky/resandpay/issues/53)), implementováno ve verzi pluginu 0.33.0.**

- **Rozlišení důvodu** ve výběru termínů služby (`Resandpay_Views::render_slots()`), když v zobrazeném období není volný termín: existuje volný termín jinde v čase (`Resandpay_Services::first_free()`) → **v tomto období nic** (`slots_period_text`); jinak existuje budoucí nezrušený termín (`Resandpay_Services::has_upcoming()`) → **obsazené** (`slots_full_text`); jinak **nevypsané** (`slots_none_text`). Odstavec má třídu `rap-empty rap-empty--none | --full | --period`.
- **Nastavení → Web a vzhled → Prázdné výpisy:** čtyři texty (typ pole `html`) – nevypsané, obsazené, v tomto období nic a **prázdný výpis akcí** (`list_empty_text`; `[reserveandpay_program]` / `[reserveandpay_events]` / `[reserveandpay_upcoming]`). Výchozí (nové instalace): „Termíny zatím nejsou vypsané.“, „Všechny termíny jsou obsazené.“, „V tomto období nejsou volné termíny. Zkuste další týdny.“, „Momentálně nejsou vypsané žádné termíny.“
- **Služba** → Parametry služby → Web a shortcody → „Text bez termínů“: vlastní text pro **nevypsané** a **obsazené** (meta `_rap_slots_none_text`, `_rap_slots_full_text`); prázdné = text z Nastavení (v poli šedě).
- Proměnné `{service}` (název služby) a `{contact}` (Nastavení → E-mail v kontaktech, vloží se jako odkaz mailto). Povolené HTML přes `Resandpay_Settings::kses_text()`: `a` (href, target, rel; protokoly https, mailto, tel), `strong`, `b`, `em`, `i`, `br`; ostatní značky a atributy se odstraní.
- **Migrace DB 17** (`Resandpay_Install::keep_legacy_empty_texts()`, vzor `keep_legacy_brand()`): stávajícím webům se doplní dosavadní texty napevno z kódu (u služby ve všech třech případech „V tomto období nejsou volné termíny. Zkuste další týdny, nebo mi napište – rádi se domluvíme.“, u výpisu „Momentálně nejsou vypsané žádné termíny. Sledujte novinky – brzy přibudou.“); uložené hodnoty se nepřepíší.
