<?php
defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api as Resandpay_Puc_Api;

/**
 * Aktualizace pluginu z prostředí WordPressu (SWD kap. 15.1, R14; Kniha rozvoje R-008).
 *
 * Zdroj: GitHub Releases veřejného repozitáře ludektelecky/resandpay. Každá vydaná verze má release
 * se značkou vX.Y.Z a přiloženým balíčkem reserveandpay-X.Y.Z.zip (jiný soubor se nestahuje).
 * Knihovna Plugin Update Checker 5.7 (MIT, lib/plugin-update-checker) kontroluje zhruba každých 12 h;
 * v Pluginech pak WordPress ukáže upozornění, „Zobrazit podrobnosti“ (popis změn z release)
 * a tlačítko „Aktualizovat nyní“. Automatické aktualizace jsou ve WordPressu ve výchozím stavu vypnuté
 * a plugin je sám nezapíná.
 *
 * Vypnutí kontroly: konstanta RESANDPAY_DISABLE_UPDATES (wp-config.php) nebo filtr resandpay_update_checker.
 */
class Resandpay_Updater {

	const REPO = 'https://github.com/ludektelecky/resandpay/';

	/** Vzor názvu balíčku v release. */
	const ASSET = '/^reserveandpay-\d+\.\d+\.\d+\.zip$/i';

	/** @var object|null */
	private static $checker = null;

	public static function init() {
		if ( null !== self::$checker || ( defined( 'RESANDPAY_DISABLE_UPDATES' ) && RESANDPAY_DISABLE_UPDATES ) ) {
			return;
		}
		if ( ! apply_filters( 'resandpay_update_checker', true ) ) {
			return;
		}
		require_once RESANDPAY_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		self::$checker = PucFactory::buildUpdateChecker( self::REPO, RESANDPAY_FILE, 'reserveandpay' );
		self::$checker->setBranch( 'main' );
		self::$checker->getVcsApi()->enableReleaseAssets( self::ASSET, Resandpay_Puc_Api::REQUIRE_RELEASE_ASSETS );
	}

	/** Instance kontroly aktualizací (null = vypnuto). */
	public static function checker() {
		return self::$checker;
	}
}
