<?php
defined( 'ABSPATH' ) || exit;

/**
 * Typy obsahu: Akce, Lokace a taxonomie kategorií akcí.
 */
class Resandpay_Post_Types {

	const EVENT    = 'resandpay_event';
	const LOCATION = 'resandpay_location';
	const CATEGORY = 'resandpay_category';
	const SERVICE  = 'resandpay_service';
	const MATERIAL = 'resandpay_material';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			self::EVENT,
			array(
				'labels'       => array(
					'name'               => __( 'Akce', 'reserveandpay' ),
					'singular_name'      => __( 'Akce', 'reserveandpay' ),
					'add_new'            => __( 'Přidat akci', 'reserveandpay' ),
					'add_new_item'       => __( 'Přidat akci', 'reserveandpay' ),
					'edit_item'          => __( 'Upravit akci', 'reserveandpay' ),
					'new_item'           => __( 'Nová akce', 'reserveandpay' ),
					'view_item'          => __( 'Zobrazit akci', 'reserveandpay' ),
					'search_items'       => __( 'Hledat akce', 'reserveandpay' ),
					'not_found'          => __( 'Žádné akce', 'reserveandpay' ),
					'not_found_in_trash' => __( 'V koši nejsou žádné akce', 'reserveandpay' ),
					'all_items'          => __( 'Akce', 'reserveandpay' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => apply_filters( 'resandpay_event_slug', 'akce' ) ),
				'menu_icon'    => 'dashicons-calendar-alt',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'show_in_rest' => true,
				'show_in_menu' => 'reserveandpay',
			)
		);

		register_post_type(
			self::LOCATION,
			array(
				'labels'       => array(
					'name'          => __( 'Lokace', 'reserveandpay' ),
					'singular_name' => __( 'Lokace', 'reserveandpay' ),
					'add_new'       => __( 'Přidat lokaci', 'reserveandpay' ),
					'add_new_item'  => __( 'Přidat lokaci', 'reserveandpay' ),
					'edit_item'     => __( 'Upravit lokaci', 'reserveandpay' ),
					'all_items'     => __( 'Lokace', 'reserveandpay' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'supports'     => array( 'title' ),
				'show_in_menu' => 'reserveandpay',
			)
		);

		register_post_type(
			self::SERVICE,
			array(
				'labels'       => array(
					'name'          => __( 'Služby', 'reserveandpay' ),
					'singular_name' => __( 'Služba', 'reserveandpay' ),
					'add_new'       => __( 'Přidat službu', 'reserveandpay' ),
					'add_new_item'  => __( 'Přidat službu', 'reserveandpay' ),
					'edit_item'     => __( 'Upravit službu', 'reserveandpay' ),
					'all_items'     => __( 'Služby', 'reserveandpay' ),
					'not_found'     => __( 'Žádné služby', 'reserveandpay' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'supports'     => array( 'title', 'editor' ),
				'show_in_rest' => false,
				'show_in_menu' => 'reserveandpay',
			)
		);

		register_post_type(
			self::MATERIAL,
			array(
				'labels'       => array(
					'name'               => __( 'Materiály', 'reserveandpay' ),
					'singular_name'      => __( 'Materiál', 'reserveandpay' ),
					'add_new'            => __( 'Přidat materiál', 'reserveandpay' ),
					'add_new_item'       => __( 'Přidat materiál', 'reserveandpay' ),
					'edit_item'          => __( 'Upravit materiál', 'reserveandpay' ),
					'new_item'           => __( 'Nový materiál', 'reserveandpay' ),
					'view_item'          => __( 'Zobrazit materiál', 'reserveandpay' ),
					'search_items'       => __( 'Hledat materiály', 'reserveandpay' ),
					'not_found'          => __( 'Žádné materiály', 'reserveandpay' ),
					'not_found_in_trash' => __( 'V koši nejsou žádné materiály', 'reserveandpay' ),
					'all_items'          => __( 'Materiály', 'reserveandpay' ),
				),
				'public'       => true,
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => apply_filters( 'resandpay_material_slug', 'materialy' ) ),
				'menu_icon'    => 'dashicons-download',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'show_in_rest' => true,
				'show_in_menu' => 'reserveandpay',
			)
		);

		register_taxonomy(
			self::CATEGORY,
			array( self::EVENT, self::SERVICE, self::MATERIAL ),
			array(
				'labels'            => array(
					'name'          => __( 'Kategorie akcí', 'reserveandpay' ),
					'singular_name' => __( 'Kategorie akce', 'reserveandpay' ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => apply_filters( 'resandpay_category_slug', 'kategorie-akci' ) ),
			)
		);
	}
}
