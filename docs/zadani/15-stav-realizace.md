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

> Od 14. 11. 2026 se nové rozvojové požadavky vedou v [GitHub Issues](https://github.com/ludektelecky/resandpay/issues) (štítek `rozvoj`). Požadavky R1–R17 níže mají v Issues svůj záznam; podrobná specifikace zůstává zde.

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

**Pozn.: Zapsáno do knihy rozvoje 31. 10. 2026 na žádost vlastníka – zatím neimplementováno.**

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
| R11-a Videa | Soubory přímo na webu (chráněná složka). Na stránce materiálu se video i audio přehrává v prohlížeči a lze ho stáhnout; externí přehrávače (Vimeo, YouTube) se nezavádějí. |
| R11-b Kdo může objednat | Jen přihlášený uživatel – objednávka i nárok jsou vázané na účet (jméno a e-mail z účtu). |
| R11-c Doba přístupu | Nastavitelná u každého materiálu: počet dní, 0 = natrvalo; výchozí hodnota v nastavení (0). |
| R11-d Materiály pro účastnice | Ano – u materiálu lze vybrat akce a služby; zaplacená rezervace dává materiál zdarma. |
| R11-h Právní a daňová stránka | Znění souhlasu se ztrátou práva na odstoupení ověří vlastník s právníkem, daně s účetní. Text souhlasu je v nastavení. |
| R11-e Vodoznak v PDF | Zatím neřešeno – kandidát do knihy rozvoje. |
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
| Přístup a role | Zdarma pro přihlášené / Placený / Jen vybrané role (role z Ultimate Member) |
| Cena a měna | U placeného materiálu |
| Splatnost objednávky | Dní od objednání; prázdné = nastavení (výchozí 7) |
| Doba přístupu | Dní od zaplacení (u účastnic od zaplacení rezervace, u ručního zpřístupnění od jeho data); 0 = natrvalo; prázdné = nastavení |
| Zdarma pro účastnice | Výběr akcí a služeb (všechny termíny služby); rezervace se páruje podle účtu nebo stejného e-mailu |
| Staženo z nabídky | Zmizí z katalogu a nelze ho objednat; kdo ho má, stahuje dál |
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

- Soubory se nahrávají v editoru materiálu (pole „Soubory ke stažení“) po částech 2 MB přes AJAX do složky wp-content/uploads/resandpay-protected/m{ID}-{náhodný řetězec}/ s náhodnou předponou názvu. Složka má .htaccess (Require all denied) a index.php. Povolené typy: typy WordPressu + EPUB, MOBI, M4A, M4V, WEBM; PHP a jiné spustitelné typy nejsou povoleny.
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
- Mimo rozsah 0.13.0: zápis do MailerLite (spolu s E6b), vodoznak v PDF (R11-e), rozšíření stránky Statistika (R1) o materiály – počty jsou zatím v seznamu materiálů a v Přístupech.

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

- Volitelný modul (třída Resandpay_MailerLite), ve výchozím stavu vypnutý. Nastavení → záložka **Napojení** → MailerLite: zapnutí, API klíč (šifrovaně, prázdné pole = ponechat; alternativně konstanta RESANDPAY_MAILERLITE_API_KEY), **výchozí skupiny** (zaškrtávací seznam skupin načtených z účtu – tlačítko „Ověřit připojení a načíst skupiny“, seznam v mezipaměti), **Kdy zapsat** (po zaplacení – výchozí / hned po vytvoření rezervace), **Předávat telefon**.
- Skupiny se vždy načítají z účtu MailerLite daného webu, administrátor je jen vybírá; lze vybrat více skupin. U akce, služby a materiálu boční panel **MailerLite**: výchozí / vybrané skupiny / neposílat do MailerLite. Termín služby přebírá nastavení služby. Žádná skupina = zákazník se zapíše jen do odběratelů účtu.
- Zapisují se jen rezervace se souhlasem s novinkami (pole ve formuláři rezervace a objednávky materiálu). Odesílá se e-mail (malými písmeny), jméno a příjmení (z pole Jméno – první slovo / zbytek) a volitelně telefon (pole phone). API connect.mailerlite.com, POST /subscribers – zákazník se jen přidává do skupin, nikdy se neodebírá; parametr status se neposílá, takže odhlášený odběratel se znovu nepřihlásí. Dvojí potvrzení a automatizace se řídí nastavením účtu MailerLite.
- Odesílání na pozadí: rezervace se zařadí do fronty (stav „čeká na odeslání“), WP-Cron ji odešle do minuty, zbytek po dávkách 50 i při pravidelné kontrole pluginu. Při chybě 3 pokusy, pak stav „chyba“ se zprávou; neplatný API klíč hned chyba. V testovacím režimu pluginu se do MailerLite neposílá (stav „neodesláno – testovací režim“).
- Stav u rezervace (sloupce ml_status, ml_groups, ml_at, ml_error, ml_tries; DB 13): detail rezervace – řádek MailerLite se stavem, skupinami, časem a chybou, odkaz **Odeslat znovu / Odeslat teď**. Přehled Zákazníci – sloupec a filtr MailerLite (odesláno / čeká / chyba / se souhlasem neodesláno / bez souhlasu), stav i v exportu CSV. V Nastavení souhrn stavů a tlačítko „Odeslat čekající teď“.
- **Import stávajících zákazníků**: tlačítko v Nastavení → Napojení zařadí do fronty zákazníky, jejichž poslední rezervace má souhlas s novinkami a kteří ještě nebyli odesláni – podle jejich poslední rezervace se souhlasem (při „po zaplacení“ poslední zaplacené); skupiny podle této rezervace.
- Pro vývojáře filtry resandpay_mailerlite_groups (skupiny pro rezervaci) a resandpay_mailerlite_subscriber (data odběratele).
