#!/usr/bin/env bash
# Připraví testovací WordPress pro ReserveAndPay jedním příkazem (SQLite, bez MySQL).
#
#   bash tests/setup-wp.sh            # připraví (nebo doplní) testovací web
#   bash tests/setup-wp.sh --reset    # smaže a připraví znovu
#   bash tests/run.sh                 # spustí testy (run-tests.php + souběh)
#
# Proměnné prostředí:
#   WP_DIR      složka testovacího webu (výchozí /tmp/rap-wp)
#   WP_VERSION  verze WordPressu – značka repozitáře WordPress/WordPress (výchozí nejnovější stabilní)
#   SQLITE_TAG  verze pluginu SQLite Database Integration (výchozí v3.1.3)
#
# Zdroje se stahují z GitHubu (git a raw.githubusercontent.com), ne z wordpress.org –
# funguje i v prostředích, kde je wordpress.org nedostupný (cloudové relace Claude Code).
# Přihlášení do administrace: admin / TestHeslo123 (jen pro lokální testovací web).
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${WP_DIR:-${TMPDIR:-/tmp}/rap-wp}"
SQLITE_TAG="${SQLITE_TAG:-v3.1.3}"
BIN="$WP_DIR/.bin"
RESET=0
[ "${1:-}" = "--reset" ] && RESET=1

say() { printf '\n== %s\n' "$*"; }
die() { printf 'CHYBA: %s\n' "$*" >&2; exit 1; }

say "Kontrola požadavků"
command -v php >/dev/null || die "chybí PHP (doporučeno 8.3+)"
command -v git >/dev/null || die "chybí git"
command -v curl >/dev/null || die "chybí curl"
for ext in pdo_sqlite mbstring zlib; do
	php -m | grep -qi "^$ext$" || die "PHP nemá rozšíření $ext"
done
php -m | grep -qi '^gd$' || echo "Upozornění: PHP nemá rozšíření gd (obrázky v PDF)."
php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || die "plugin vyžaduje PHP 8.0+"

if [ "$RESET" = 1 ] && [ -d "$WP_DIR" ]; then
	say "Mažu $WP_DIR"
	rm -rf "$WP_DIR"
fi
mkdir -p "$WP_DIR" "$BIN"

# WP-CLI: použije se nainstalované „wp“, jinak se stáhne phar do $WP_DIR/.bin.
if command -v wp >/dev/null 2>&1; then
	WP_BIN="$(command -v wp)"
else
	if [ ! -x "$BIN/wp" ]; then
		say "Stahuji WP-CLI"
		curl -fsSL -o "$BIN/wp" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
		chmod +x "$BIN/wp"
	fi
	WP_BIN="$BIN/wp"
fi
wp() { "$WP_BIN" --allow-root --path="$WP_DIR" "$@"; }

if [ ! -f "$WP_DIR/wp-load.php" ]; then
	if [ -z "${WP_VERSION:-}" ]; then
		WP_VERSION="$(git ls-remote --tags https://github.com/WordPress/WordPress | awk -F/ '{print $3}' | grep -E '^[0-9]+\.[0-9]+(\.[0-9]+)?$' | sort -V | tail -1)"
	fi
	say "Stahuji WordPress $WP_VERSION"
	tmp="$(mktemp -d)"
	git -c advice.detachedHead=false clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress "$tmp/wp"
	rm -rf "$tmp/wp/.git"
	cp -R "$tmp/wp/." "$WP_DIR/"
	rm -rf "$tmp"
fi

SQ="$WP_DIR/wp-content/plugins/sqlite-database-integration"
if [ ! -f "$SQ/load.php" ]; then
	say "Instaluji SQLite Database Integration $SQLITE_TAG"
	tmp="$(mktemp -d)"
	git -c advice.detachedHead=false clone -q --depth 1 --branch "$SQLITE_TAG" https://github.com/WordPress/sqlite-database-integration "$tmp/sq"
	if [ -d "$tmp/sq/packages/plugin-sqlite-database-integration" ]; then
		# Verze 3.x: monorepo – plugin + ovladač (stejně jako bin/build-sqlite-plugin-zip.sh).
		cp -R "$tmp/sq/packages/plugin-sqlite-database-integration" "$SQ"
		rm -rf "$SQ/wp-includes/database"
		cp -R "$tmp/sq/packages/mysql-on-sqlite/src" "$SQ/wp-includes/database"
	else
		cp -R "$tmp/sq" "$SQ"
		rm -rf "$SQ/.git"
	fi
	rm -rf "$tmp"
fi
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$SQ/db.copy" > "$WP_DIR/wp-content/db.php"

if [ ! -f "$WP_DIR/wp-config.php" ]; then
	say "Vytvářím wp-config.php"
	wp config create --dbname=wp --dbuser=x --dbpass=x --dbhost=localhost --skip-check --quiet
	wp config set WP_DEBUG true --raw --quiet
	wp config set WP_DEBUG_LOG true --raw --quiet
	wp config set WP_DEBUG_DISPLAY false --raw --quiet
	wp config set WP_ENVIRONMENT_TYPE local --quiet
fi

if ! wp core is-installed >/dev/null 2>&1; then
	say "Instaluji WordPress"
	wp core install --url=http://localhost:8080 --title="RAP Test" --admin_user=admin --admin_password=TestHeslo123 --admin_email=admin@example.com --skip-email --quiet
	wp option update timezone_string Europe/Prague --quiet
	wp option update permalink_structure '/%postname%/' --quiet
	wp theme activate twentytwentyfive --quiet || true
fi

say "Připojuji plugin z repozitáře"
P="$WP_DIR/wp-content/plugins/reserveandpay"
if [ ! -e "$P" ]; then
	ln -s "$REPO" "$P" 2>/dev/null || die "nelze vytvořit odkaz $P → $REPO"
fi
wp plugin activate sqlite-database-integration --quiet 2>/dev/null || true
wp plugin activate reserveandpay --quiet
wp rewrite flush --quiet

echo "$WP_DIR" > "$REPO/tests/.wp-dir"
say "Hotovo"
echo "Testovací web: $WP_DIR (WordPress $(wp core version), ReserveAndPay $(wp plugin get reserveandpay --field=version))"
echo "Testy:         bash tests/run.sh"
echo "Web v prohlížeči (volitelně): php -S localhost:8080 -t \"$WP_DIR\"  → http://localhost:8080/wp-admin (admin / TestHeslo123)"
