# 11. Rizika a doporučení

| **Riziko** | **Dopad** | **Doporučené zmírnění** |
|---|---|---|
| Vlastní rezervační modul = vyšší objem vývoje a vlastní údržba | Delší čas do spuštění, chyby nejsou odchyceny komunitou pluginu | Etapizace (rezervace nejdřív s ručním potvrzením), automatizované testy kapacity a stavů, dokumentace; bez licence a bez závislosti na aktualizacích cizího pluginu |
| Přeplnění akce při souběžných rezervacích | Více rezervací než míst | Atomická kontrola kapacity (kap. 3.3), test souběhu v akceptaci |
| Formát e-mailu Raiffeisenbank se změní nebo je nekonzistentní | Platby přestanou být automaticky spárovány | Alert při výpadku párování + ruční fallback (6.3) |
| Zákazník nezadá/smaže VS při platbě | Platba nejde spárovat automaticky | SPD QR kód VS předvyplní; ruční přiřazení |
| Rezervace na poslední chvíli | Platba nestihne dorazit před začátkem | Splatnost nejpozději začátek akce, stav „Platba na místě“ (kap. 3.6), ruční spuštění kontroly |
| Neuhrazené rezervace na poslední chvíli neexpirují | Místo může zůstat blokované osobou, která nepřijde | Přehled rezervací „Platba na místě“ v administraci; Hanka může rezervaci ručně zrušit |
| Chybí formální faktura při růstu prodejů | Riziko z pohledu daní/účetnictví | Prověřit s účetním hned (otázka 5); datový model umožní pozdější doplnění faktur |
| Kontrola plateb závisí na CRONu Active24 a IMAP z hostingu | Výpadek = žádné automatické potvrzování plateb | Log a upozornění při absenci běhu, ruční spuštění; ověřit hosting před vývojem (otázka 8) |
| Delší interval kontroly (např. 60 min) | Zákazník čeká na potvrzení platby do příštího běhu | Interval je konfigurovatelný; e-mail s pokynem uvede, že potvrzení může chvíli trvat; ruční spuštění kdykoli |
