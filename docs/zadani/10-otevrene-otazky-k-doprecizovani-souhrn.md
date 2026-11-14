# 10. Otevřené otázky k doprecizování – souhrn

| **#** | **Otázka** | **Stav / odpověď** |
|---|---|---|
| 1 | Mají být placené digitální produkty součástí 1. etapy, nebo až pozdější rozšíření (kap. 3.5)? | Zodpovězeno 31. 10. 2026: ano – knihovna materiálů, část placená; návrh R11 (kap. 15.1) ke schválení |
| 2 | Mají firemní zákazníci (B2B) jít stejným formulářem s IČ/DIČ, nebo zůstat u poptávkového formuláře? | Otevřená |
| 3 | Vzorek avíza o platbě (nyní Raiffeisenbank) – nezbytný vstup pro parser (kap. 6.2). | Částečně: vzor okamžité příchozí platby dodán 21. 10. 2026; chybí vzor standardní platby, platby bez VS a odchozí platby |
| 4 | Předání přístupu k novému účtu po platbě – heslo v e-mailu vs. odkaz na nastavení hesla (kap. 7.2a). | Otevřená |
| 5 | Potvrzení u účetního/daňového poradce, že platební pokyn bez faktury je dostačující (kap. 9). | Otevřená |
| 6 | Pouze CZK, nebo i jiná měna (např. EUR u pobytů ve Španělsku)? | Otevřená |
| 7 | Posílat připomínku platby před koncem splatnosti (kap. 7.2b)? | Zodpovězeno 22. 10. 2026: ano – připomínka N dní před splatností, počet dní v nastavení (kap. 3.6); připomínka po splatnosti od v2.0 |
| 8 | Ověření hostingu Active24: krok serverového CRONu, IMAP z PHP, odchozí port 993, max_execution_time (kap. 6.4). | Částečně: IMAP imap.example.cz:993 SSL; CRON jako volání URL (WebAdmin → CRON úlohy); IMAP bez rozšíření php-imap řeší plugin sám. Zbývá ověřit na webu testem připojení a prvním během CRONu |
| 9 | Volba transakční e-mailové služby pro spolehlivé doručování (kap. 4.5). | Otevřená |
| 10 | Jsou v 1. etapě potřeba opakované akce, čekací listina nebo slevové kódy (kap. 12)? | Částečně: pravidelné lekce řeší služba s více místy a generátor (v1.9, kap. 3.4); čekací listina a slevové kódy otevřené |
| 11 | Má se automatická kontrola plateb v noci pozastavit, nebo běžet nonstop (kap. 6.4)? | Otevřená |
| 12 | Uzávěrka a splatnost u termínů na poslední chvíli? | Zodpovězeno: rezervace kdykoli až do začátku; nestihne-li platba dorazit, platí zákazník na místě (kap. 3.6) |
| 13 | Lze rezervovat více míst jednou rezervací? | Zodpovězeno: ne – každé místo = samostatná rezervace s vlastním VS (kap. 4.1) |
| 14 | Stornopodmínky a vracení plateb. | Mimo rozsah – řeší samostatný dokument |
| 15 | Lze u vícedenní akce rezervovat jednotlivé dny? | Zodpovězeno: ne – rezervuje se vždy celá akce (kap. 3.1) |
| 16 | Potvrdit, že platby za rezervace SLH se budou přijímat na účet Raiffeisenbank 1234567890/5500 (INSYSTEMS CZ s.r.o.). | Zodpovězeno 22. 10. 2026: ano, IBAN CZ7155000000001234567890 |
