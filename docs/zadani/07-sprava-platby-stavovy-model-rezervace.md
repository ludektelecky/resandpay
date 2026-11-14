# 7. 4) Správa platby (stavový model rezervace)

## 7.1 Stavy rezervace

| **Stav** | **Kdy nastává** | **Co se děje** |
|---|---|---|
| Čeká na platbu | Ihned po odeslání rezervačního formuláře | Místo je zablokováno, vystaven platební pokyn s QR kódem, odeslán e-mail, běží splatnost |
| Zaplaceno | Modul spáruje avízo s VS a částkou, příp. ruční přiřazení administrátorkou | Viz 7.2a – potvrzení zákazníkovi, odemčení obsahu, rezervace potvrzena |
| Částečně zaplaceno | Zaplacena 1. část (kap. 3.9) nebo menší částka, než je celá cena | Místo je potvrzené a blokované; zákazník má QR kód na zbytek; po doplacení „Zaplaceno“, po splatnosti doplatku „Po splatnosti“ |
| Platba na místě | Zákazník zvolil hotovost (kap. 3.7), nebo rezervace na poslední chvíli, jejíž platba do začátku akce nedorazila (kap. 3.6) | Místo zůstává blokované, neexpiruje; Hanka po zaplacení označí rezervaci jako zaplacenou (hotově / převodem), např. na stránce Odbavení (kap. 4.7) |
| Po splatnosti | Uplyne splatnost, která byla v den termínu nebo po něm, bez platby (kap. 3.6), nebo splatnost doplatku či zbytku po částečné platbě (kap. 3.9) | Místo zůstává blokované (termín proběhl), zákazník dostane jednu připomínku; po zaplacení „Zaplaceno“, jinak ruční řešení nebo zrušení |
| Expirováno | Uplyne splatnost (u platby ve dvou částech splatnost 1. části) před začátkem termínu a nepřišla žádná platba | Viz 7.2b – e-mail zákazníkovi, uvolnění místa (termín masáže se znovu zobrazí jako volný); pozdní platba rezervaci obnoví, je-li volno |
| Nejasná platba (k ruční kontrole) | Avízo dorazilo, ale nešlo automaticky spárovat | Zobrazeno administrátorce k ručnímu vyřešení (6.3) |
| Zrušeno | Ručně administrátorkou nebo zrušením celé akce | Uvolnění místa, e-mail zákaznici. Stornopodmínky a vracení plateb řeší samostatný dokument |

## 7.2a Potvrzení platby a odemčení členství

Při přechodu rezervace do stavu „Zaplaceno“ modul (interní hook) provede:

- Odeslání e-mailu s potvrzením platby a rezervace a dalšími instrukcemi (termín, místo, odkaz na členskou sekci, přístup k digitálnímu produktu).
- Pokud zákazník nemá účet, automatické založení účtu v Ultimate Member na e-mail z rezervace a přiřazení role dle pole „Přiřazená role po zaplacení“ (kap. 3.2).
- Pokud účet má, role/oprávnění se jen přidá – nesmí dojít k odebrání jiných rolí.
- Zápis rezervujícího do MailerLite (source group dle typu akce), pokud udělil souhlas.

**Otevřená otázka 4:** Jak předat nový účet zákazníkovi bez hesla – vygenerovaným heslem v e-mailu, nebo odkazem „nastavte si heslo“ (bezpečnější, doporučeno)?

## 7.2b Uplynutí splatnosti

Kontrola splatnosti je součástí modulu a běží stejným CRONem Active24 (kap. 6.4). Před expirací se vždy nejprve provede běh kontroly plateb, aby nebyla expirována rezervace, jejíž platba už ve schránce leží. Pro každou rezervaci „Čeká na platbu“ po splatnosti, která není rezervací na poslední chvíli (kap. 3.6):

- Nepřišla-li žádná platba a splatnost byla před začátkem termínu: nastaví rezervaci na „Expirováno“, uvolní místo a odešle zákazníkovi e-mail o vypršení s odkazem na akci (může se přihlásit znovu, je-li volno) a s prosbou ozvat se, pokud platbu mezitím odeslal.
- Byla-li zaplacena aspoň část (kap. 3.9), rezervace neexpiruje: přejde do stavu „Po splatnosti“ s jednou připomínkou na zbývající částku; o dalším postupu rozhodne Hanka.
- Pozdní platba expirované rezervace: je-li místo stále volné, rezervace se obnoví jako zaplacená (příp. částečně zaplacená); je-li akce mezitím obsazená, platba jde k ruční kontrole (přiřazení i nad kapacitu, nebo vrácení).
- Připomínka před splatností: N dní předem (nastavitelné, výchozí 1, 0 = vypnuto), jednou pro každou splatnost (kap. 3.6).

**Pozn.:** Rezervace se splatností v den termínu nebo po něm neexpirují – přecházejí do stavu „Po splatnosti“ s jednou připomínkou (kap. 3.6); rezervace na poslední chvíli přecházejí do „Platba na místě“. Přechody spouští CRON Active24 (každých 15 minut) a jako záloha hodinový plánovač WordPressu.

## 7.3 Evidence plateb, odpárování a smazání rezervace

Od verze 0.7.4 vede každá rezervace evidenci jednotlivých přijatých plateb (detail rezervace → Přijaté platby): datum, částka, způsob (převod automaticky / ručně, hotově), vazba na bankovní avízo, kdo platbu zapsal a poznámka. Součet platných plateb je „Uhrazeno“. Při aktualizaci se dosavadní platby převedou do evidence automaticky.

- Odpárování platby: v ResAndPay → Platby u spárovaného avíza („Odpárovat od rezervace“), nebo v detailu rezervace u konkrétní platby, i ruční či hotovostní. Vždy s potvrzením a povinným důvodem.
- Po odpárování se částka odečte a stav rezervace se přepočítá: zbude-li část zaplacena → Částečně zaplaceno, nic → Čeká na platbu (u hotovosti Platba na místě), ostatní platby dál pokrývají celou cenu → Zaplaceno. Avízo se vrátí do stavu „K ruční kontrole“ s důvodem a lze ho přiřadit jiné rezervaci nebo vyřešit (např. „vráceno“). Platba zůstane v evidenci jako odpárovaná (kdy, kdo, proč). Místo zůstává rezervované; zákazníkovi se volitelně pošlou aktuální platební údaje.
- Uplynula-li už splatnost, plugin po odpárování upozorní, že rezervaci bez platby při další kontrole (do 15 minut) zpracuje běžná logika splatnosti – expirace, příp. stav Po splatnosti (kap. 7.2b).
- Trvalé smazání rezervace: akce „Smazat trvale“ v seznamu rezervací (i hromadně) a v detailu – jen u stavu Zrušeno a jen bez platné platby. Rezervaci s přijatou platbou je nutné nejdřív odpárovat a avízo vyřešit, aby po platbě zůstala stopa (vratka, účetnictví).
- Smazání odstraní rezervaci, její evidenci plateb a záznamy v seznamu odeslaných e-mailů (GDPR). Avíza, která na rezervaci odkazovala, zůstanou s poznámkou „rezervace VS … smazána“. Variabilní symbol se znovu nepoužije.
