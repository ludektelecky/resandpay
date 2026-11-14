# 2. Přehled architektury řešení

Všechny komponenty běží uvnitř WordPressu na hostingu Active24. Jádrem je vlastní plugin ReserveAndPay (repozitář ludektelecky/resandpay), který je jediným zdrojem pravdy pro akce, termíny, rezervace i platby. Kontrola bankovní schránky probíhá krátkými dávkovými běhy spouštěnými serverovým CRONem Active24 nebo ručně administrátorkou.

| **Komponenta** | **Role v řešení** | **Poznámka** |
|---|---|---|
| WordPress + GeneratePress/GenerateBlocks | Prezentační vrstva, stránky akcí a služeb | Beze změny oproti základnímu zadání webu |
| ReserveAndPay (vlastní plugin) | Evidence akcí a termínů, kalendář a výpis na webu, rezervační formulář, hlídání kapacity, stavy rezervací, administrace, e-maily | Nová komponenta – nahrazuje Amelii (kap. 3 a 4) |
| Generátor QR platby (SPD) | Generování QR kódu a PDF platebního pokynu | Součást pluginu, standard SPD (kap. 5) |
| Modul kontroly plateb | Dávkové čtení schránky (IMAP), parsování přes bankovní adaptér, párování na rezervaci, změna stavu | Součást pluginu, spouští CRON Active24 nebo ručně (kap. 6); adaptér Raiffeisenbank (kap. 14.2) |
| Ultimate Member | Účty, role, Content Restriction | Napojení přes interní hook pluginu při změně stavu platby |
| MailerLite | Newsletter a automatizace | Nový rezervující se souhlasem → skupiny vybrané administrátorem ze skupin účtu → automatizace v MailerLite; volitelný modul (kap. 15.1 R17, od 0.19.0) |

**Zjednodušený tok dat:** Zákazník vybere akci v kalendáři nebo volný termín masáže → vyplní rezervační formulář → modul ověří volnou kapacitu, vytvoří rezervaci ve stavu „Čeká na platbu“, zablokuje místo a přidělí unikátní VS → vygeneruje QR platební pokyn a pošle e-mail → banka doručí avízo o platbě do schránky → při nejbližším běhu kontroly plateb modul avízo přečte, rozpozná VS a částku a nastaví rezervaci na „Zaplaceno“ → hook nastaví roli v Ultimate Member a odešle potvrzení. Neuhrazená rezervace po splatnosti expiruje a místo se uvolní (kap. 7.2b).
