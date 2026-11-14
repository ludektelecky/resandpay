# 12. Fázový postup implementace a odhad pracnosti

Zadání se zařazuje jako rozšíření Fáze 4 projektového zadání webu. Etapy fáze 1 jsou seřazeny tak, aby co nejdříve vznikl použitelný výsledek – po etapách E1 a E2 lze rezervace spustit s ručním potvrzováním plateb. Všechny etapy fáze 1 dodržují pravidla připravenosti na zobecnění (kap. 14.1).

| **Etapa** | **Obsah** | **Odhad (člověkodny)** |
|---|---|---|
| E0 | Doprecizování otevřených otázek (kap. 10), vzorek bankovního avíza, ověření hostingu Active24 | 1–2 |
| E1 | Jádro rezervací: CPT akce a lokace, typy akcí (jednodenní, vícedenní, od–do), kapacita, rezervační formulář, stavy, administrace účastnic, e-maily | 6–9 |
| E2 | Kalendář a výpis akcí na webu, detail akce, přestylování dle grafického manuálu | 3–4 |
| E3 | Služby a individuální termíny: definice služby (pevná délka, pauza a počet míst jako parametry), generátor termínů, hromadné rušení, upozornění na překryvy, pohled „Volné termíny“ | 2–4 |
| E4 | Generátor SPD QR kódu, VS, PDF platební pokyn, transakční e-mail, načtení QR kódu kamerou při odbavení | 2–3 |
| E5 | Kontrola plateb: IMAP čtení, parser, párování, nespárované platby, nastavení (ruční/automatický režim, interval), log, CRON Active24 | 4–6 |
| E6 | E6a: expirace, připomínky, platba ve dvou částech (kap. 3.9); E6b: napojení na Ultimate Member (po spuštění) a MailerLite (hotovo v 0.19.0) | 2–4 |
| E7 | Testování dle akceptačních kritérií (kap. 13) a nasazení na slehkouhlavou.cz | 2–3 |
| Celkem fáze 1 | Etapy E0–E7 vč. 1–3 dnů na pravidla připravenosti na zobecnění (kap. 14.1) | 23–37 |
| F2 | Fáze 2 – zobecnění pluginu (kap. 14.3) | 6–10 |

**Pozn.:** Odhad je orientační. Fáze 1 nezahrnuje 2. etapu (opakované akce, čekací listina, slevové kódy – cca 5–8 člověkodní) ani placené digitální produkty, pokud budou požadovány hned (otázka 1). Upřesní se po zodpovězení otevřených otázek.
