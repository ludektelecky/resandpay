#!/usr/bin/env bash
# Spustí testy ReserveAndPay na testovacím WordPressu z tests/setup-wp.sh:
# integrační testy (run-tests.php) a test souběhu rezervací (concurrency.sh).
#
#   bash tests/run.sh            # obojí
#   bash tests/run.sh unit       # jen run-tests.php
#   N=20 bash tests/run.sh       # souběh s 20 požadavky (výchozí 12)
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-$(cat "$REPO/tests/.wp-dir" 2>/dev/null || echo "${TMPDIR:-/tmp}/rap-wp")}"
[ -f "$WP_DIR/wp-load.php" ] || { echo "Testovací WordPress nenalezen v $WP_DIR – spusťte nejdřív: bash tests/setup-wp.sh" >&2; exit 1; }

# WP-CLI z PATH, jinak phar ze setup-wp.sh (concurrency.sh volá „wp“).
if ! command -v wp >/dev/null 2>&1; then
	export PATH="$WP_DIR/.bin:$PATH"
fi
command -v wp >/dev/null || { echo "Chybí WP-CLI – spusťte bash tests/setup-wp.sh" >&2; exit 1; }

cd "$WP_DIR"
echo "== Integrační testy"
LOG="$(mktemp)"
set +e
wp --allow-root eval-file wp-content/plugins/reserveandpay/tests/run-tests.php > "$LOG" 2>&1
STATUS=$?
set -e
grep -E "^== |^  FAIL |Fatal|PHP (Warning|Notice|Deprecated)" "$LOG" || true
tail -1 "$LOG"
grep -q "Výsledek: .* 0 FAIL" "$LOG" || STATUS=1
rm -f "$LOG"
[ "$STATUS" = 0 ] || exit "$STATUS"
[ "${1:-}" = "unit" ] && exit 0

echo "== Souběh rezervací"
bash wp-content/plugins/reserveandpay/tests/concurrency.sh "${N:-12}"
