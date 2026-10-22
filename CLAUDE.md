# Pokyny pro práci na ReserveAndPay

- Zadání: `docs/ReserveAndPay_WP_plugin_v*.docx` (nejnovější verze je závazná), vývojový deník `docs/DEVLOG.md`.
- Komunikace s vlastníkem (Luděk) v češtině.
- Vývojový web: slehkouhlavou.cz (WordPress 7.1, PHP 8.5, MySQL 8.4, hosting Active24, časové pásmo Europe/Prague).
  Plugin na web nahrává vlastník ručně (.zip přes Pluginy → Nahrát plugin → Nahradit stávající).
- Testy na lokálním WordPressu: `wp eval-file tests/run-tests.php` a `bash tests/concurrency.sh 12`. Složka `tests/` se do .zip nebalí.
- Vzhled: grafický manuál „s lehkou hlavou“ v1 (barvy a písma přes CSS proměnné `--rap-*`).
- Banka pro příjem plateb: Raiffeisenbank (avíza „Pohyb na účtě“ z info@rb.cz). Vzorové avízo: `tests/fixtures/rb-prichozi-okamzita-platba.eml`.
- Pravidla fáze 1 (SWD kap. 14.1): nic natvrdo – identifikace, schránka, banka, vzhled, texty a měna jsou v nastavení.

## Ukládání výstupů (požadavek vlastníka)
Po každé nové verzi pluginu nebo dokumentace uložit soubory také do Google Disku:
`INSYS/Yoga/SLehkouhlavou/ReserveAndPay/` (na počítači vlastníka `G:\Můj disk\INSYS\Yoga\SLehkouhlavou\ReserveAndPay\`)
- `Dokumentace/` – zadání .docx, DEVLOG, README
- `Instalační balíčky/` – `reserveandpay-X.Y.Z.zip`
Starší verze se nepřepisují.

## Verze dokumentace
- Analytické zadání uvádí na titulní straně „Verze X.Y · datum · plugin ReserveAndPay A.B.C“ a v historii verzí sloupec „Verze pluginu“ – verze pluginu, ke které byla dokumentace vydána.
- README uvádí aktuální verzi pluginu a verzi zadání; DEVLOG je členěn podle verzí pluginu.
