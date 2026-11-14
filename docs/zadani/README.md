# ReserveAndPay WP plugin – analytické zadání

> Zadání odpovídá **aktuální verzi pluginu** v tomto repozitáři (viz `Version` v `reserveandpay.php` a [DEVLOG](../DEVLOG.md)). Samostatné číslování verzí zadání skončilo verzí 6.4 (plugin 0.17.1) – archiv v [historii verzí](historie-verzi.md). Rozvojové požadavky: [GitHub Issues](https://github.com/ludektelecky/resandpay/issues). Údaje provozovatele, čísla účtů a e-mailové adresy jsou v zadání **vzorové** – skutečné hodnoty jsou jen v nastavení pluginu.

## Obsah

- [1. Účel dokumentu a shrnutí rozhodnutí](01-ucel-dokumentu-a-shrnuti-rozhodnuti.md)
- [2. Přehled architektury řešení](02-prehled-architektury-reseni.md)
- [3. 0) Databáze akcí, termínů a produktů](03-databaze-akci-terminu-a-produktu.md)
- [4. 1) Rezervační formulář, kalendář a administrace](04-rezervacni-formular-kalendar-a-administrace.md)
- [5. 2) Vystavení platebního příkazu s QR kódem](05-vystaveni-platebniho-prikazu-s-qr-kodem.md)
- [6. 3) Kontrola plateb – párování e-mailových avíz Raiffeisenbank ve WordPressu](06-kontrola-plateb-parovani-e-mailovych-aviz-raiffe.md)
- [7. 4) Správa platby (stavový model rezervace)](07-sprava-platby-stavovy-model-rezervace.md)
- [8. Datový model](08-datovy-model.md)
- [9. GDPR, bezpečnost a uchovávání dat](09-gdpr-bezpecnost-a-uchovavani-dat.md)
- [10. Otevřené otázky k doprecizování – souhrn](10-otevrene-otazky-k-doprecizovani-souhrn.md)
- [11. Rizika a doporučení](11-rizika-a-doporuceni.md)
- [12. Fázový postup implementace a odhad pracnosti](12-fazovy-postup-implementace-a-odhad-pracnosti.md)
- [13. Akceptační kritéria / testovací scénáře](13-akceptacni-kriteria-testovaci-scenare.md)
- [14. Konfigurovatelnost a rozšiřitelnost](14-konfigurovatelnost-a-rozsiritelnost.md)
- [15. Stav realizace a kniha rozvoje](15-stav-realizace.md)
- [Historie verzí 1.0–6.4](historie-verzi.md)

## Úvod (z titulní strany)

**ANALYTICKÉ ZADÁNÍ**

**ReserveAndPay WP plugin**

Rezervační a platební plugin pro WordPress · první nasazení: slehkouhlavou.cz

Rozšíření o: vlastní rezervační systém akcí a termínů, rezervační formulář, platební příkaz s QR kódem (SPD),

kontrolu plateb z e-mailových notifikací Raiffeisenbank (výhradně ve WordPressu) a správu stavu úhrady

Verze 6.4 · 13. listopadu 2026 · plugin ReserveAndPay 0.17.1

*Navazuje na projektové zadání webu (Fáze 4 – rezervace a platby)*
