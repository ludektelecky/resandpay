# 6. 3) Kontrola plateb – párování e-mailových avíz Raiffeisenbank ve WordPressu

## 6.1 Architektura

Raiffeisenbank (služba „Informuj mě“) zasílá při připsání platby e-mailové avízo „Pohyb na účtě“ na adresu platby@example.cz. Toto řešení bylo zvoleno místo napojení na účetní software nebo PSD2 API – viz kap. 11. Celá kontrola plateb je součástí rezervačního modulu a běží výhradně na hostingu Active24.

- Běh kontroly plateb spustí serverový CRON Active24 (v automatickém režimu dle nastaveného intervalu, standardně 60 min) nebo administrátorka tlačítkem v administraci (6.4). Modul se krátce připojí ke schránce přes IMAP (SSL/TLS), načte nové nezpracované e-maily a spojení ukončí.
- Modul ověří pravost avíza (kap. 6.5) a z e-mailu Raiffeisenbank vyčte částku, variabilní symbol, protiúčet, datum a typ pohybu podle formátu v kap. 6.2.
- Modul dohledá rezervaci podle VS (kap. 8) a ověří, že přijatá částka odpovídá očekávané.
- Pokud sedí VS i částka, rezervace přejde do stavu „Zaplaceno“ (logika kap. 7.2a, způsob úhrady „Převod – automaticky“ nebo „ruční běh kontroly“), zákazník dostane potvrzení a avízo se uloží jako spárované.
- Každé zpracované avízo se ve schránce přesune do složky „Zpracováno“ (vytvoří se automaticky; název je v nastavení). Jiné e-maily ve schránce modul nečte ani nepřesouvá.
- Pokud VS nelze rozpoznat nebo částka neodpovídá, avízo se označí k ruční kontrole a upozorní se administrátorka (6.3).

## 6.2 Formát avíza Raiffeisenbank

Podle vzorového avíza ze dne 21. 10. 2026 (příchozí okamžitá úhrada) má e-mail tyto vlastnosti:

| **Vlastnost e-mailu** | **Hodnota / pravidlo** |
|---|---|
| Odesílatel | Raiffeisenbank a.s. <info@rb.cz> |
| Předmět | „Pohyb na účtě“ (kódováno UTF-8) |
| Struktura | multipart/related – jen HTML část (quoted-printable, UTF-8) + vložené logo; textová část chybí |
| Úvodní věta | „… na účtě byla provedena následující příchozí platba:“ |
| Data | Dvousloupcová HTML tabulka: v levé buňce název pole, v pravé hodnota. Řádky bez hodnoty banka vynechává. |
| Identifikátor transakce | Avízo žádné ID transakce neobsahuje – pro ochranu proti dvojímu zpracování se použije Message-ID e-mailu a otisk (datum + částka + VS + protiúčet). |

| **Pole v avízu** | **Příklad** | **Zpracování** |
|---|---|---|
| Datum a čas | 21. 10. 2026 22:18 | Datum a čas připsání (přesnost na minuty), formát d. m. rrrr hh:mm |
| Na účet | 1234567890/5500 + název majitele | Musí odpovídat účtu v nastavení pluginu – jinak se avízo ignoruje |
| Částka v měně účtu | +5.000,00 CZK | Znaménko + = příchozí; tečka = oddělovač tisíců, čárka = desetinná; měna za částkou |
| Kategorie pohybu | Platba | Informativní |
| Typ pohybu | Příchozí okamžitá úhrada | Zpracují se jen příchozí platby (typ začíná „Příchozí“, kladná částka) |
| Z účtu | 123456789/2700 + jméno plátce | Protiúčet a jméno plátce – uloží se k avízu (pro ruční párování; GDPR viz kap. 9) |
| Variabilní symbol | 12345678 | Hlavní klíč párování; porovnává se bez úvodních nul |
| Konstantní symbol | 0308 | Informativní |
| Specifický symbol | (není ve vzoru) | Pokud bude uveden, uloží se |
| Zpráva pro příjemce | Prevod z vlastniho uctu UCB | Uloží se; pomůže při ručním párování (např. když plátce uvede VS do zprávy) |

**Otevřená otázka 3:** Vzorek avíza Raiffeisenbank (příchozí okamžitá úhrada s VS) je k dispozici. Pro úplné otestování parseru prosíme ještě o avízo standardní (neokamžité) příchozí platby, platby bez VS a případně odchozí platby, pokud je služba „Informuj mě“ posílá také.

**Pozn.:** Schránka platby@example.cz je hostovaná u Active24 (platforma WebSupport): IMAP server imap.example.cz, port 993, SSL/TLS, uživatel = celá e-mailová adresa. Heslo zadává provozovatel sám do nastavení pluginu (ukládá se šifrovaně a už se nezobrazuje), případně jako konstantu RESANDPAY_IMAP_PASSWORD ve wp-config.php. Připojení ověří tlačítko „Otestovat připojení ke schránce“ – nic nemění.

## 6.3 Rizika a záložní mechanismus (fallback)

- Formát e-mailu se může kdykoli změnit (redesign šablony banky) – parser se tím rozbije bez varování. Proto alert, pokud modul nějakou dobu nespáruje žádnou platbu.
- Ne všichni plátci zadají VS správně – i přes QR kód může platba dorazit bez VS nebo s jiným VS.
- Proto je v administraci stránka ResAndPay → Platby (počet avíz k ruční kontrole je vidět přímo v menu): seznam avíz s filtrem podle stavu (K ruční kontrole / Spárováno automaticky / Přiřazeno ručně / Vyřešeno bez přiřazení / Ignorováno), u každého částka, VS, plátce, protiúčet, zpráva pro příjemce a důvod.
- Ruční přiřazení: zadáním VS rezervace (modul nabídne rezervaci, pokud plátce uvedl VS do zprávy pro příjemce a sedí částka). Při rozdílu částky nebo měny je nutné výslovné potvrzení; zaplacenou ani zrušenou rezervaci přiřadit nelze. Druhá možnost: „Vyřešit bez přiřazení“ s povinnou poznámkou (např. vráceno plátci).
- K ruční kontrole jde: platba bez VS, neexistující VS, jiná částka nebo měna, platba k již zaplacené nebo zrušené rezervaci, nepotvrzená pravost avíza, nečitelný formát avíza a možná duplicita (stejný čas, částka, VS a protiúčet z jiného e-mailu – nic se automaticky nezahazuje). Ignoruje se jen odchozí platba a platba na jiný účet.
- Zpracování avíza ze souboru (.eml) – pro avízo, které nedorazilo do schránky; platí stejné kontroly.
- Upozornění na e-mail pro upozornění (nastavení): souhrn avíz k ruční kontrole po každém běhu, který nějaké našel, a upozornění na výpadek kontroly.
- Evidují se i zamítnuté/nejasné notifikace, aby žádná přijatá platba nezůstala bez záznamu.

## 6.4 Spouštění a parametrizace kontroly plateb

Kontrola plateb nespoléhá na vestavěný WP-Cron (spouští se jen při návštěvě webu), ale na serverový CRON Active24. Způsob kontroly je parametrizovatelný v nastavení modulu:

| **Parametr** | **Popis a výchozí hodnota** |
|---|---|
| Režim kontroly | Ručně / Automaticky v intervalu. Výchozí: Automaticky. V režimu „Ručně“ modul volání CRONu ignoruje. |
| Interval kontroly | Konfigurovatelná hodnota v minutách (např. 15 / 30 / 60 / 120 / 240 min / 1× denně). Standardní nastavení: 60 min. Nejkratší interval je dán krokem CRONu Active24. |
| Ruční spuštění | Tlačítko „Zkontrolovat platby nyní“ – dostupné v obou režimech; zobrazí výsledek běhu. |
| Kontrola splatnosti | Při každém volání CRONu (a záložně hodinovým plánovačem WordPressu) – přechody „Po splatnosti“ a „Platba na místě“ (kap. 3.6), od E6 i expirace (kap. 7.2b). |
| Přístup ke schránce | IMAP server (imap.example.cz), port (993), šifrování (SSL/TLS), uživatel, heslo (šifrovaně v DB – AES-256-GCM s klíčem odvozeným z bezpečnostních klíčů WordPressu – nebo konstanta ve wp-config.php), složka s avízy (INBOX) a složka pro zpracovaná avíza (Zpracováno). |
| Max. avíz na běh | Výchozí 50; zbytek převezme další běh. |
| Filtr odesílatele | Adresa odesílatele avíz – pro Raiffeisenbank info@rb.cz; ostatní e-maily se ignorují. |
| Účet příjemce | Číslo účtu, na který musí avízo znít (1234567890/5500); avíza na jiný účet se ignorují. |
| Upozornění při výpadku | Po jaké době bez úspěšného běhu upozornit administrátorku. Výchozí: 3× interval. |
| Log běhů | Doba uchovávání záznamů o bězích. Výchozí: 90 dní. |

### Technické požadavky na běh

- CRON Active24 (WebAdmin → doména → CRON úlohy) volá každých 15 minut (*/15 * * * *) adresu https://slehkouhlavou.cz/?rap_cron=KLÍČ – typ úlohy „volání URL“. Tajný klíč vygeneruje plugin, adresu zobrazí stránka Platby. Neplatný klíč = odpověď 403. Modul sám podle režimu a intervalu rozhodne, zda je čas na kontrolu (tolerance 2 min) – změna intervalu se provádí jen v administraci.
- Při úspěchu volání nic nevypíše, při chybě vypíše důvod; v CRONu se nastaví e-mailové upozornění „jen při výstupu“, takže chyba přijde e-mailem.
- Zámek proti souběhu – každé avízo se zpracuje právě jednou (evidence Message-ID/UID, přesun do složky „Zpracováno“).
- Omezení dávky (např. max. 50 e-mailů na běh) kvůli limitům sdíleného hostingu; zbytek převezme další běh.
- Log každého běhu (čas, režim, počty načtených/spárovaných/nejasných, chyby) a čas posledního úspěšného běhu v administraci.
- IMAP: rozšíření php-imap už od PHP 8.4 není součástí PHP, proto plugin obsahuje vlastní minimální IMAP klienta nad šifrovaným spojením (LOGIN, SELECT, UID SEARCH FROM, UID FETCH BODY.PEEK – zpráva se neoznačí jako přečtená, UID MOVE / COPY + EXPUNGE, CREATE; názvy složek v modifikovaném UTF-7). Ověřeno proti serveru Dovecot v automatických testech; na hostingu ověří test připojení.
- Upozornění při výpadku: bez úspěšného běhu déle než 3× interval → e-mail na adresu pro upozornění, nejvýš 1× denně.

## 6.5 Ověření pravosti avíza

Platba se automaticky označí jako zaplacená jen z avíza, které prokazatelně odeslala banka. Ochrana proti podvrženému e-mailu (někdo by mohl na adresu schránky poslat falešné „avízo“):

- Odesílatel musí být info@rb.cz a zároveň musí poštovní server příjemce potvrdit pravost: výsledek kontroly SPF „pass“ (hlavička Received-SPF) a platný podpis DKIM domény rb.cz s politikou DMARC (hlavička výsledků antispamu). Avízo bez kladných výsledků se nespáruje automaticky, ale zařadí se k ruční kontrole.
- Konkrétní pravidla (dle hlaviček serveru WebSupport ve vzorovém avízu): From i Return-Path = info@rb.cz; první hlavička Received-SPF začíná „Pass“ a envelope-from je z domény rb.cz; ve zprávě je právě jedna hlavička X-Spamd-Result (rspamd) a obsahuje R_DKIM_ALLOW[rb.cz] a DMARC_POLICY_ALLOW[rb.cz]. Více výskytů X-Spamd-Result znamená, že hlavičku vložil odesílatel – avízo se pak nepovažuje za ověřené.
- Ověření lze v nastavení vypnout (není doporučeno) – avíza pak projdou bez kontroly pravosti.
- Pole „Na účet“ musí odpovídat účtu v nastavení.
- Částka i VS musí přesně odpovídat rezervaci (kap. 6.1); jinak ruční kontrola.
- Kontroly jsou součástí bankovního adaptéru Raiffeisenbank a jsou pokryté automatickými testy na vzorových avízech (platné, podvržené, jiný účet, odchozí platba).
