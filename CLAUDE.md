# Pokyny pro práci na ReserveAndPay

- Zadání: `docs/zadani/` (Markdown po kapitolách, obsah v `docs/zadani/README.md`) – odpovídá aktuální verzi pluginu; vývojový deník `docs/DEVLOG.md`.
- Rozvojové požadavky: GitHub Issues (štítky `rozvoj`, `stav: návrh` / `stav: schváleno` / `stav: hotovo`, chyby `bug`). Nový požadavek = nové Issue; neimplementovat, dokud nemá `stav: schváleno`. Commit, který požadavek dokončí, uvádí `closes #N`; po vydání štítek `stav: hotovo` a verze pluginu v komentáři.
- Komunikace s vlastníkem (Luděk) v češtině.
- Vývojový web: slehkouhlavou.cz (WordPress 7.1, PHP 8.5, MySQL 8.4, hosting Active24, časové pásmo Europe/Prague).
  Plugin se na webu aktualizuje tlačítkem „Aktualizovat nyní“ z GitHub Releases (od 0.16.0).
- Testy na lokálním WordPressu: `wp eval-file tests/run-tests.php` a `bash tests/concurrency.sh 12`. Složka `tests/` se do .zip nebalí.
- Vzhled: Nastavení → Vzhled (ze šablony webu / vlastní paleta, CSS proměnné `--rap-*`); slehkouhlavou.cz má vlastní paletu dle grafického manuálu „s lehkou hlavou“ v1.
- Banka pro příjem plateb: Raiffeisenbank (avíza „Pohyb na účtě“ z info@rb.cz). Vzorové avízo: `tests/fixtures/rb-prichozi-okamzita-platba.eml`.
- Pravidla fáze 1 (zadání kap. 14.1): nic natvrdo – identifikace, schránka, banka, vzhled, texty a měna jsou v nastavení.

## Vydání verze (od 0.16.0)
- Push na `main` se změněným číslem verze v `reserveandpay.php` spustí `.github/workflows/release.yml` → GitHub release `vX.Y.Z` s balíčkem a popisem z DEVLOGu; z něj se plugin aktualizuje ve WordPressu. Tagy a releasy ze session vytvářet nejde – vše dělá workflow. Číslo verze měnit jen při vydání; sekce `## X.Y.Z – …` v DEVLOGu musí existovat. Změny vyžadující zásah označit v DEVLOGu „⚠ Po aktualizaci:“.

## Dokumentace
- Dokumentace se upravuje ve stejném commitu jako kód: zadání (`docs/zadani/`, u nové funkce kap. 15.1 a dotčené kapitoly), DEVLOG (sekce `## X.Y.Z – …`), README (aktuální verze pluginu, shortcody, nastavení).
- Samostatné číslo verze zadání se už nevede (archiv 1.0–6.4: `docs/zadani/historie-verzi.md`); dokumentace odpovídá verzi pluginu, změny jsou v historii gitu.
- Repozitář je veřejný: v dokumentaci a testech jen vzorové údaje (účty `123456789/2700`, adresy `@example.cz`, IČ `12345678`), nikdy skutečná čísla účtů, schránky ani hesla.
- Google Disk se už nepoužívá; balíčky jsou v GitHub Releases.

## Pojmenování (SWD kap. 14.5)
- Plný název **ReserveAndPay** – kde se pojmenovává produkt (seznam pluginů, titulky stránek, dokumentace, CRON, PDF).
- **ResAndPay** – název v menu administrace a repozitář; cesty v textech psát „ResAndPay → Platby“.
- **RAP** – jen kde je nutné nejkratší označení (předmět testovacích e-mailů, log, technické předpony).
- Technické identifikátory neměnit (reserveandpay, resandpay_, shortcody).
