<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace – hlavní menu a společné prvky.
 */
class Resandpay_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'test_mode_notice' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_detail_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'cpt_flash' ) );
		add_filter( 'update_footer', array( __CLASS__, 'footer_version' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_version' ) );
	}

	/** Obrazovka ReserveAndPay (stránky pluginu, akce, lokace, služby, materiály, kategorie akcí). */
	public static function is_our_screen( $hook = '' ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( false !== strpos( (string) $hook, 'reserveandpay' ) || ( $screen && false !== strpos( (string) $screen->id, 'reserveandpay' ) ) ) {
			return true;
		}
		return $screen && ( in_array( $screen->post_type, array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::LOCATION, Resandpay_Post_Types::SERVICE, Resandpay_Post_Types::MATERIAL ), true ) || Resandpay_Post_Types::CATEGORY === $screen->taxonomy );
	}

	/** Označení verze: „ReserveAndPay 0.16.1“. */
	public static function version_label() {
		/* translators: %s: verze pluginu */
		return sprintf( __( 'ReserveAndPay %s', 'reserveandpay' ), RESANDPAY_VERSION );
	}

	/** Verze v záhlaví každé obrazovky ReserveAndPay – štítek za nadpisem stránky. */
	private static function version_script() {
		$label = wp_json_encode( self::version_label() );
		$title = wp_json_encode( __( 'Nainstalovaná verze pluginu ReserveAndPay', 'reserveandpay' ) );
		return "document.addEventListener('DOMContentLoaded',function(){var h=document.querySelector('#wpbody-content .wrap h1');if(!h||document.querySelector('.rap-version')){return;}var s=document.createElement('span');s.className='rap-version';s.textContent=$label;s.title=$title;h.appendChild(s);});";
	}

	/** Patička administrace na obrazovkách ReserveAndPay: verze pluginu vedle verze WordPressu. */
	public static function footer_version( $text ) {
		if ( ! self::is_our_screen() ) {
			return $text;
		}
		return esc_html( self::version_label() ) . ' · ' . $text;
	}

	/** Blokový editor (akce, materiál): verze v panelu Shrnutí / Stav a viditelnost. */
	public static function block_editor_version() {
		if ( ! self::is_our_screen() ) {
			return;
		}
		$label = wp_json_encode( self::version_label() );
		$js    = "(function(wp){if(!wp||!wp.plugins||!wp.element){return;}var Info=(wp.editor&&wp.editor.PluginPostStatusInfo)||(wp.editPost&&wp.editPost.PluginPostStatusInfo);if(!Info){return;}var el=wp.element.createElement;wp.plugins.registerPlugin('resandpay-version',{render:function(){return el(Info,{className:'rap-version-info'},el('span',{className:'rap-version'},$label));}});})(window.wp);";
		wp_register_script( 'resandpay-version', '', array( 'wp-plugins', 'wp-element', 'wp-editor', 'wp-edit-post' ), RESANDPAY_VERSION, true );
		wp_enqueue_script( 'resandpay-version' );
		wp_add_inline_script( 'resandpay-version', $js );
		wp_enqueue_style( 'reserveandpay-admin', RESANDPAY_URL . 'assets/css/admin.css', array(), RESANDPAY_VERSION );
	}

	public static function menu() {
		$cap = resandpay_cap_reservations();
		add_menu_page(
			__( 'ReserveAndPay', 'reserveandpay' ),
			__( 'ResAndPay', 'reserveandpay' ), // Název v menu (SWD kap. 14.5: ReserveAndPay / ResAndPay / RAP).
			$cap,
			'reserveandpay',
			array( 'Resandpay_Admin_Reservations', 'page_list' ),
			'dashicons-tickets-alt',
			26
		);
		add_submenu_page( 'reserveandpay', __( 'Rezervace', 'reserveandpay' ), __( 'Rezervace', 'reserveandpay' ), $cap, 'reserveandpay', array( 'Resandpay_Admin_Reservations', 'page_list' ) );
		add_submenu_page( 'reserveandpay', __( 'Nová rezervace', 'reserveandpay' ), __( 'Nová rezervace', 'reserveandpay' ), $cap, 'reserveandpay-new', array( 'Resandpay_Admin_Reservations', 'page_new' ) );
		add_submenu_page( 'reserveandpay', __( 'Statistika', 'reserveandpay' ), __( 'Statistika', 'reserveandpay' ), Resandpay_Stats::CAP, 'reserveandpay-stats', array( 'Resandpay_Stats', 'page_admin' ) );
		add_submenu_page( 'reserveandpay', __( 'Kategorie akcí', 'reserveandpay' ), __( 'Kategorie akcí', 'reserveandpay' ), 'manage_categories', 'edit-tags.php?taxonomy=' . Resandpay_Post_Types::CATEGORY . '&post_type=' . Resandpay_Post_Types::EVENT );
		// Detail rezervace – skrytá stránka bez položky v menu.
		add_submenu_page( 'reserveandpay', __( 'Detail rezervace', 'reserveandpay' ), __( 'Detail rezervace', 'reserveandpay' ), $cap, 'reserveandpay-detail', array( 'Resandpay_Admin_Reservations', 'page_detail' ) );
	}

	public static function hide_detail_menu() {
		remove_submenu_page( 'reserveandpay', 'reserveandpay-detail' );
	}

	public static function parent_file( $parent ) {
		global $current_screen;
		if ( $current_screen && Resandpay_Post_Types::CATEGORY === $current_screen->taxonomy ) {
			return 'reserveandpay';
		}
		return $parent;
	}

	public static function assets( $hook ) {
		if ( self::is_our_screen( $hook ) ) {
			wp_enqueue_style( 'reserveandpay-admin', RESANDPAY_URL . 'assets/css/admin.css', array(), RESANDPAY_VERSION );
			$screen = get_current_screen();
			if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return; // Blokový editor: verze v panelu Shrnutí (block_editor_version()).
			}
			wp_register_script( 'resandpay-version-badge', '', array(), RESANDPAY_VERSION, false );
			wp_enqueue_script( 'resandpay-version-badge' );
			wp_add_inline_script( 'resandpay-version-badge', self::version_script() );
		}
	}

	public static function test_mode_notice() {
		if ( ! Resandpay_Settings::is_test_mode() || ! current_user_can( resandpay_cap_reservations() ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && ( false !== strpos( $screen->id, 'reserveandpay' ) || in_array( $screen->post_type, array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::LOCATION ), true ) );
		if ( ! $ours ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'ReserveAndPay běží v testovacím režimu.', 'reserveandpay' ) . '</strong> ';
		/* translators: %s: e-mail */
		echo esc_html( sprintf( __( 'Všechny e-maily pluginu se odesílají jen na %s.', 'reserveandpay' ), Resandpay_Settings::get( 'test_email' ) ) );
		echo '</p></div>';
	}

	/** Zobrazí jednorázovou zprávu i na seznamech akcí/služeb (např. po generování termínů). */
	public static function cpt_flash() {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->base, array( 'edit', 'post' ), true ) && in_array( $screen->post_type, array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::SERVICE ), true ) ) {
			self::render_flash();
		}
	}

	/** Uloží jednorázovou zprávu pro další načtení stránky. */
	public static function flash( $message, $type = 'success' ) {
		set_transient( 'resandpay_flash_' . get_current_user_id(), array( $message, $type ), 60 );
	}

	public static function render_flash() {
		$key = 'resandpay_flash_' . get_current_user_id();
		$f   = get_transient( $key );
		if ( $f ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $f[1] ), esc_html( $f[0] ) );
		}
	}
}
