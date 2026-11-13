<?php
defined( 'ABSPATH' ) || exit;

/**
 * Web: detail materiálu, objednávka placeného materiálu, katalog a „Moje materiály“
 * (SWD kap. 15.1, R11).
 */
class Resandpay_Materials_Views {

	/** @var array Chyby a hodnoty odeslaného formuláře objednávky. */
	private static $state = array();

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'material_content' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
		add_shortcode( 'reserveandpay_materials', array( __CLASS__, 'shortcode_catalog' ) );
		add_shortcode( 'reserveandpay_material', array( __CLASS__, 'shortcode_card' ) );
		add_shortcode( 'reserveandpay_my_materials', array( __CLASS__, 'shortcode_my' ) );
		add_filter( 'resandpay_enqueue_assets', array( __CLASS__, 'enqueue' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	public static function enqueue( $on ) {
		return $on || is_singular( Resandpay_Post_Types::MATERIAL );
	}

	/** Stránky s materiály se liší podle přihlášeného uživatele – neukládat do cache. */
	public static function no_cache() {
		$post = get_post();
		if ( is_singular( Resandpay_Post_Types::MATERIAL ) || ( $post && is_singular() && preg_match( '/\[reserveandpay_(my_materials|materials?)\b/', (string) $post->post_content ) ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/** „Moje materiály“ se neindexuje. */
	public static function robots( $robots ) {
		$p = (int) Resandpay_Settings::get( 'mat_my_page' );
		if ( $p && is_page( $p ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/* ---------------------------------------------------------------------
	 * Objednávka (PRG)
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'mat_order' !== ( $_POST['resandpay_action'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$mid    = absint( $_POST['rap_material_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$values = array(
			'customer_name'   => sanitize_text_field( wp_unslash( $_POST['rap_name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'phone'           => sanitize_text_field( wp_unslash( $_POST['rap_phone'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'is_company'      => ! empty( $_POST['rap_is_company'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'company'         => sanitize_text_field( wp_unslash( $_POST['rap_company'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'company_ic'      => sanitize_text_field( wp_unslash( $_POST['rap_ic'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'company_dic'     => sanitize_text_field( wp_unslash( $_POST['rap_dic'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'consent_gdpr'    => ! empty( $_POST['rap_gdpr'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'consent_terms'   => ! empty( $_POST['rap_terms'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'consent_digital' => ! empty( $_POST['rap_digital'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'newsletter'      => ! empty( $_POST['rap_newsletter'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'coupon'          => substr( sanitize_text_field( wp_unslash( $_POST['rap_coupon'] ?? '' ) ), 0, 60 ), // phpcs:ignore WordPress.Security.NonceVerification
		);
		$errors = array();
		if ( ! isset( $_POST['_rapnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rapnonce'] ) ), 'resandpay_mat_order_' . $mid ) ) {
			$errors[] = __( 'Platnost formuláře vypršela. Načtěte prosím stránku znovu a odešlete formulář ještě jednou.', 'reserveandpay' );
		}
		if ( ! is_user_logged_in() ) {
			$errors[] = __( 'Pro objednávku se prosím přihlaste.', 'reserveandpay' );
		}
		if ( '' === $values['customer_name'] ) {
			$errors[] = __( 'Vyplňte jméno a příjmení.', 'reserveandpay' );
		}
		if ( $values['is_company'] && ( '' === $values['company'] || '' === $values['company_ic'] ) ) {
			$errors[] = __( 'Při nákupu na firmu vyplňte název firmy a IČ.', 'reserveandpay' );
		}
		if ( ! $values['consent_gdpr'] ) {
			$errors[] = __( 'Pro objednávku je nutný souhlas se zpracováním osobních údajů.', 'reserveandpay' );
		}
		if ( ! $values['consent_terms'] ) {
			$errors[] = __( 'Pro objednávku je nutný souhlas s obchodními podmínkami.', 'reserveandpay' );
		}
		if ( ! $values['consent_digital'] ) {
			$errors[] = __( 'Pro objednávku je nutný souhlas s okamžitým poskytnutím digitálního obsahu.', 'reserveandpay' );
		}
		if ( ! $values['is_company'] ) {
			$values['company'] = $values['company_ic'] = $values['company_dic'] = '';
		}
		if ( ! $errors ) {
			$res = Resandpay_Materials::order( $mid, get_current_user_id(), $values, array( 'source' => 'web' ) );
			if ( is_wp_error( $res ) && 'pending' !== $res->get_error_code() ) {
				$errors[] = $res->get_error_message();
			} else {
				wp_safe_redirect( add_query_arg( 'rap_ordered', 1, get_permalink( $mid ) ) . '#rap-material' );
				exit;
			}
		}
		self::$state[ $mid ] = array(
			'errors' => $errors,
			'values' => $values,
		);
	}

	/* ---------------------------------------------------------------------
	 * Detail materiálu
	 * ------------------------------------------------------------------- */

	public static function material_content( $content ) {
		if ( ! is_singular( Resandpay_Post_Types::MATERIAL ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$mat = Resandpay_Materials::get( get_the_ID() );
		if ( ! $mat ) {
			return $content;
		}
		$head = '<p class="rap-eyebrow">' . esc_html( $mat['kind_label'] ) . ( $mat['code'] ? ' · ' . esc_html( $mat['code'] ) : '' ) . '</p>';
		return '<div class="rap-material">' . '<div class="rap-material__main">' . $head . $content . '</div>' . self::render_box( $mat ) . '</div>';
	}

	/** Štítek ceny / přístupu. */
	public static function price_label( array $mat ) {
		if ( Resandpay_Materials::ACCESS_PAID === $mat['access'] ) {
			return Resandpay_Events::format_price( $mat['price'], $mat['currency'] );
		}
		if ( Resandpay_Materials::ACCESS_ROLES === $mat['access'] ) {
			return (string) Resandpay_Settings::get( 'access_tag' );
		}
		return __( 'Zdarma pro přihlášené', 'reserveandpay' );
	}

	/** Karta s akcí: přihlásit / objednat / zaplatit / stáhnout. */
	public static function render_box( array $mat ) {
		$acc  = Resandpay_Materials::access( $mat );
		$url  = get_permalink( $mat['id'] );
		$out  = '<aside id="rap-material" class="rap-booking rap-booking--card rap-mat-box">';
		$out .= '<p class="rap-eyebrow">' . esc_html__( 'Ke stažení', 'reserveandpay' ) . '</p>';
		$out .= '<p class="rap-mat-box__price">' . esc_html( self::price_label( $mat ) ) . '</p>';

		if ( isset( $_GET['rap_ordered'] ) && in_array( $acc['reason'], array( 'pending', '' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$out .= '<div class="rap-success" role="status"><p><strong>' . esc_html( $acc['ok'] ? __( 'Hotovo – materiál je Váš.', 'reserveandpay' ) : __( 'Děkujeme, objednávka je přijata.', 'reserveandpay' ) ) . '</strong> ' . esc_html( $acc['ok'] ? '' : __( 'Platební údaje jsme poslali i na Váš e-mail. Materiál se odemkne po přijetí platby.', 'reserveandpay' ) ) . '</p></div>';
		}

		if ( $acc['ok'] ) {
			if ( $acc['until'] ) {
				/* translators: %s: datum */
				$out .= '<p class="rap-small">' . esc_html( sprintf( __( 'Přístup máte do %s.', 'reserveandpay' ), wp_date( 'j. n. Y', $acc['until'] ) ) ) . '</p>';
			}
			$out .= self::render_files( $mat, false );
		} else {
			switch ( $acc['reason'] ) {
				case 'login':
					$out .= '<p>' . esc_html( (string) Resandpay_Settings::get( 'mat_login_text' ) ) . '</p>';
					$out .= '<p class="rap-mat-box__actions"><a class="rap-button" href="' . esc_url( Resandpay_Materials::login_url( $url ) ) . '">' . esc_html__( 'Přihlásit se', 'reserveandpay' ) . '</a>';
					$reg  = Resandpay_Materials::register_url();
					if ( $reg ) {
						$out .= ' <a class="rap-button rap-button--ghost" href="' . esc_url( $reg ) . '">' . esc_html__( 'Založit účet', 'reserveandpay' ) . '</a>';
					}
					$out .= '</p>';
					break;
				case 'pending':
					$out .= self::render_payment( $acc['order'] );
					break;
				case 'role':
					$out .= '<p class="rap-notice">' . esc_html( (string) Resandpay_Settings::get( 'mat_roles_text' ) ) . '</p>';
					break;
				case 'withdrawn':
				case 'unavailable':
					$out .= '<p class="rap-notice">' . esc_html__( 'Materiál už není v nabídce.', 'reserveandpay' ) . '</p>';
					break;
				case 'expired':
					/* translators: %s: datum */
					$out .= '<p class="rap-notice">' . esc_html( sprintf( __( 'Váš přístup skončil %s. Materiál si můžete objednat znovu.', 'reserveandpay' ), wp_date( 'j. n. Y', $acc['expired_at'] ) ) ) . '</p>';
					$out .= self::render_order_form( $mat );
					break;
				default:
					$out .= self::render_order_form( $mat );
			}
			$out .= self::render_files( $mat, true );
		}
		if ( is_user_logged_in() && (int) Resandpay_Settings::get( 'mat_my_page' ) ) {
			$out .= '<p class="rap-small"><a href="' . esc_url( Resandpay_Materials::my_url() ) . '">' . esc_html__( 'Všechny moje materiály →', 'reserveandpay' ) . '</a></p>';
		}
		return $out . '</aside>';
	}

	/**
	 * Seznam souborů. $locked = bez přístupu: vypíše názvy (a ukázky ke stažení).
	 */
	public static function render_files( array $mat, $locked ) {
		if ( ! $mat['files'] ) {
			return $locked ? '' : '<p class="rap-small">' . esc_html__( 'Soubory se připravují.', 'reserveandpay' ) . '</p>';
		}
		$uid = get_current_user_id();
		$out = '<ul class="rap-mat-files">';
		foreach ( $mat['files'] as $f ) {
			$label = $f['label'] ?: $f['name'];
			$meta  = strtoupper( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) . ' · ' . Resandpay_Materials::format_size( $f['size'] );
			$out  .= '<li class="rap-mat-file' . ( $locked && ! $f['sample'] ? ' is-locked' : '' ) . '"><span class="rap-mat-file__name">' . esc_html( $label ) . ( $f['sample'] ? ' <span class="rap-tag">' . esc_html__( 'ukázka', 'reserveandpay' ) . '</span>' : '' ) . '<small>' . esc_html( $meta ) . '</small></span>';
			if ( ! $locked || $f['sample'] ) {
				$media = 0 === strpos( $f['mime'], 'video/' ) ? 'video' : ( 0 === strpos( $f['mime'], 'audio/' ) ? 'audio' : '' );
				if ( $media ) {
					$src  = Resandpay_Materials::download_url( $mat['id'], $f['id'], true, $uid );
					$out .= 'video' === $media
						? '<video class="rap-mat-player" controls preload="metadata" controlsList="nodownload" src="' . esc_url( $src ) . '"></video>'
						: '<audio class="rap-mat-player" controls preload="metadata" controlsList="nodownload" src="' . esc_url( $src ) . '"></audio>';
				}
				$out .= '<a class="rap-button rap-button--ghost rap-mat-file__dl" href="' . esc_url( Resandpay_Materials::download_url( $mat['id'], $f['id'], false, $uid ) ) . '" rel="nofollow">' . esc_html__( 'Stáhnout', 'reserveandpay' ) . '</a>';
			} else {
				$out .= '<span class="rap-mat-file__lock" aria-label="' . esc_attr__( 'zamčeno', 'reserveandpay' ) . '">🔒</span>';
			}
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/** Platební údaje nezaplacené objednávky (QR, VS, splatnost, PDF). */
	public static function render_payment( array $r ) {
		$s   = Resandpay_Settings::all();
		$out = '<p>' . esc_html__( 'Materiál máte objednaný. Odemkne se po přijetí platby.', 'reserveandpay' ) . '</p><div class="rap-paybox">';
		$spd = Resandpay_QR::applicable( $r ) ? Resandpay_QR::spd( $r ) : '';
		if ( $spd ) {
			$out .= '<figure class="rap-qr"><img src="' . esc_attr( Resandpay_QR::data_uri( $spd, 5 ) ) . '" width="160" height="160" alt="' . esc_attr__( 'QR kód pro platbu', 'reserveandpay' ) . '"><figcaption>' . esc_html__( 'Naskenujte v bankovní aplikaci', 'reserveandpay' ) . '</figcaption></figure>';
		}
		$out .= '<dl class="rap-pay">';
		if ( $s['bank_account'] ) {
			$out .= '<dt>' . esc_html__( 'Číslo účtu', 'reserveandpay' ) . '</dt><dd>' . esc_html( $s['bank_account'] ) . '</dd>';
		}
		$out .= '<dt>' . esc_html( (float) $r['amount_paid'] > 0 ? __( 'Zbývá zaplatit', 'reserveandpay' ) : __( 'Částka', 'reserveandpay' ) ) . '</dt><dd>' . esc_html( Resandpay_Events::format_price( Resandpay_Reservations::amount_now( $r ), $r['currency'] ) ) . '</dd>';
		$out .= '<dt>' . esc_html__( 'Variabilní symbol', 'reserveandpay' ) . '</dt><dd><strong>' . esc_html( $r['vs'] ) . '</strong></dd>';
		$out .= '<dt>' . esc_html__( 'Splatnost', 'reserveandpay' ) . '</dt><dd>' . esc_html( wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ) . '</dd>';
		$out .= '</dl></div>';
		$out .= '<p><a class="rap-button rap-button--ghost" href="' . esc_url( Resandpay_PDF::url( $r ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Platební pokyn (PDF) →', 'reserveandpay' ) . '</a></p>';
		return $out;
	}

	/** Formulář objednávky placeného materiálu (jen přihlášený uživatel). */
	public static function render_order_form( array $mat ) {
		if ( ! Resandpay_Materials::purchasable( $mat ) ) {
			return '<p class="rap-notice">' . esc_html__( 'Materiál teď nelze objednat.', 'reserveandpay' ) . '</p>';
		}
		$state  = self::$state[ $mat['id'] ] ?? array();
		$values = $state['values'] ?? array();
		$u      = wp_get_current_user();
		if ( ! $values ) {
			$values = array( 'customer_name' => trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name );
		}
		$v     = fn( $k ) => esc_attr( (string) ( $values[ $k ] ?? '' ) );
		$link  = function ( $page_id, $text ) {
			return $page_id ? '<a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>' : esc_html( $text );
		};
		$out = '';
		if ( ! empty( $state['errors'] ) ) {
			$out .= '<div class="rap-errors" role="alert"><ul>';
			foreach ( $state['errors'] as $e ) {
				$out .= '<li>' . esc_html( $e ) . '</li>';
			}
			$out .= '</ul></div>';
		}
		$out .= '<form class="rap-form" method="post" action="' . esc_url( get_permalink( $mat['id'] ) ) . '#rap-material" novalidate>';
		$out .= '<input type="hidden" name="resandpay_action" value="mat_order"><input type="hidden" name="rap_material_id" value="' . (int) $mat['id'] . '">';
		$out .= wp_nonce_field( 'resandpay_mat_order_' . $mat['id'], '_rapnonce', true, false );
		$out .= '<p class="rap-field"><label for="rap_name">' . esc_html__( 'Jméno a příjmení', 'reserveandpay' ) . ' *</label><input id="rap_name" type="text" name="rap_name" value="' . $v( 'customer_name' ) . '" required autocomplete="name"></p>';
		/* translators: %s: e-mail */
		$out .= '<p class="rap-small">' . esc_html( sprintf( __( 'Objednávka a platební údaje přijdou na e-mail Vašeho účtu: %s', 'reserveandpay' ), $u->user_email ) ) . '</p>';
		$out .= '<p class="rap-check"><label><input type="checkbox" name="rap_is_company" value="1" ' . checked( ! empty( $values['is_company'] ), true, false ) . ' onchange="this.closest(\'form\').querySelector(\'.rap-company\').hidden=!this.checked"> ' . esc_html__( 'Nakupuji na firmu', 'reserveandpay' ) . '</label></p>';
		$out .= '<div class="rap-company" ' . ( empty( $values['is_company'] ) ? 'hidden' : '' ) . '>';
		$out .= '<p class="rap-field"><label for="rap_company">' . esc_html__( 'Název firmy', 'reserveandpay' ) . '</label><input id="rap_company" type="text" name="rap_company" value="' . $v( 'company' ) . '"></p>';
		$out .= '<p class="rap-field rap-field--half"><label for="rap_ic">' . esc_html__( 'IČ', 'reserveandpay' ) . '</label><input id="rap_ic" type="text" name="rap_ic" value="' . $v( 'company_ic' ) . '" inputmode="numeric"></p>';
		$out .= '<p class="rap-field rap-field--half"><label for="rap_dic">' . esc_html__( 'DIČ', 'reserveandpay' ) . '</label><input id="rap_dic" type="text" name="rap_dic" value="' . $v( 'company_dic' ) . '"></p></div>';
		$out .= '<p class="rap-check"><label><input type="checkbox" name="rap_gdpr" value="1" ' . checked( ! empty( $values['consent_gdpr'] ), true, false ) . ' required> ' . wp_kses_post( sprintf( /* translators: %s: odkaz */ __( 'Souhlasím se %s *', 'reserveandpay' ), $link( (int) Resandpay_Settings::get( 'gdpr_page' ), __( 'zpracováním osobních údajů', 'reserveandpay' ) ) ) ) . '</label></p>';
		$out .= '<p class="rap-check"><label><input type="checkbox" name="rap_terms" value="1" ' . checked( ! empty( $values['consent_terms'] ), true, false ) . ' required> ' . wp_kses_post( sprintf( /* translators: %s: odkaz */ __( 'Souhlasím s %s *', 'reserveandpay' ), $link( (int) Resandpay_Settings::get( 'terms_page' ), __( 'obchodními podmínkami', 'reserveandpay' ) ) ) ) . '</label></p>';
		$out .= '<p class="rap-check"><label><input type="checkbox" name="rap_digital" value="1" ' . checked( ! empty( $values['consent_digital'] ), true, false ) . ' required> ' . esc_html( (string) Resandpay_Settings::get( 'mat_digital_consent' ) ) . ' *</label></p>';
		$out .= '<p class="rap-check"><label><input type="checkbox" name="rap_newsletter" value="1" ' . checked( ! empty( $values['newsletter'] ), true, false ) . '> ' . esc_html__( 'Chci dostávat novinky e-mailem', 'reserveandpay' ) . '</label></p>';
		$out .= '<p class="rap-summary">' . esc_html( sprintf( /* translators: %s: cena */ __( 'Cena: %s. Platba převodem (QR platba) – platební údaje obdržíte e-mailem, materiál se odemkne po přijetí platby.', 'reserveandpay' ), Resandpay_Events::format_price( $mat['price'], $mat['currency'] ) ) ) . '</p>';
		$out .= Resandpay_Frontend::coupon_field( Resandpay_Materials::as_event( get_post( $mat['id'] ) ), $values );
		/* translators: %s: cena */
		$out .= '<p><button type="submit" class="rap-button">' . esc_html( sprintf( __( 'Objednat za %s →', 'reserveandpay' ), Resandpay_Events::format_price( $mat['price'], $mat['currency'] ) ) ) . '</button></p>';
		return $out . '</form>';
	}

	/* ---------------------------------------------------------------------
	 * Katalog a karta
	 * ------------------------------------------------------------------- */

	/** Karta materiálu do katalogu. */
	public static function card( array $mat ) {
		$acc   = is_user_logged_in() ? Resandpay_Materials::access( $mat ) : array( 'ok' => false, 'reason' => 'login' );
		$thumb = $mat['thumb_id'] ? wp_get_attachment_image( $mat['thumb_id'], 'medium_large', false, array( 'class' => 'rap-mat-card__img', 'loading' => 'lazy' ) ) : '';
		$badge = $acc['ok'] ? __( 'Máte k dispozici', 'reserveandpay' ) : ( 'pending' === $acc['reason'] ? __( 'Čeká na platbu', 'reserveandpay' ) : self::price_label( $mat ) );
		$out   = '<article class="rap-mat-card' . ( $acc['ok'] ? ' is-owned' : '' ) . '">';
		$out  .= '<a class="rap-mat-card__thumb" href="' . esc_url( $mat['url'] ) . '" tabindex="-1" aria-hidden="true">' . ( $thumb ?: '<span class="rap-mat-card__ph"></span>' ) . '</a>';
		$out  .= '<div class="rap-mat-card__body"><p class="rap-eyebrow">' . esc_html( $mat['kind_label'] ) . '</p>';
		$out  .= '<h3 class="rap-mat-card__title"><a href="' . esc_url( $mat['url'] ) . '">' . esc_html( $mat['title'] ) . '</a></h3>';
		if ( $mat['excerpt'] ) {
			$out .= '<p class="rap-mat-card__excerpt">' . esc_html( wp_trim_words( $mat['excerpt'], 28 ) ) . '</p>';
		}
		$out .= '<p class="rap-mat-card__foot"><span class="rap-mat-badge' . ( $acc['ok'] ? ' is-ok' : '' ) . '">' . esc_html( $badge ) . '</span> <a href="' . esc_url( $mat['url'] ) . '">' . esc_html__( 'Detail →', 'reserveandpay' ) . '</a></p>';
		return $out . '</div></article>';
	}

	/**
	 * [reserveandpay_materials category="" type="" access="" filter="yes" limit=""]
	 */
	public static function shortcode_catalog( $atts ) {
		$a     = shortcode_atts(
			array(
				'category' => '',
				'type'     => '',
				'access'   => '',
				'filter'   => 'yes',
				'limit'    => 0,
			),
			$atts,
			'reserveandpay_materials'
		);
		$cat   = isset( $_GET['rap_mcat'] ) ? sanitize_title( wp_unslash( $_GET['rap_mcat'] ) ) : sanitize_title( $a['category'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$kind  = isset( $_GET['rap_mkind'] ) ? sanitize_key( wp_unslash( $_GET['rap_mkind'] ) ) : sanitize_key( $a['type'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$args  = array(
			'post_type'      => Resandpay_Post_Types::MATERIAL,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $a['limit'] > 0 ? (int) $a['limit'] : -1,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
		);
		if ( $cat ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => Resandpay_Post_Types::CATEGORY,
					'field'    => 'slug',
					'terms'    => $cat,
				),
			);
		}
		$items = array();
		foreach ( get_posts( $args ) as $p ) {
			$mat = Resandpay_Materials::get( $p );
			if ( ! $mat || $mat['withdrawn'] || ( $kind && $mat['kind'] !== $kind ) || ( $a['access'] && $mat['access'] !== $a['access'] ) ) {
				continue;
			}
			$items[] = $mat;
		}
		$out = '<div class="rap-materials">';
		if ( 'no' !== $a['filter'] ) {
			$base  = remove_query_arg( array( 'rap_mcat', 'rap_mkind' ) );
			$chips = '<a class="rap-chip' . ( ! $kind ? ' is-active' : '' ) . '" href="' . esc_url( remove_query_arg( 'rap_mkind' ) ) . '">' . esc_html__( 'Vše', 'reserveandpay' ) . '</a>';
			$used  = array();
			foreach ( get_posts( array( 'post_type' => Resandpay_Post_Types::MATERIAL, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
				$used[ (string) get_post_meta( $id, '_rap_mat_kind', true ) ] = true;
			}
			foreach ( Resandpay_Materials::kinds() as $k => $l ) {
				if ( isset( $used[ $k ] ) ) {
					$chips .= ' <a class="rap-chip' . ( $kind === $k ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'rap_mkind', $k ) ) . '">' . esc_html( $l ) . '</a>';
				}
			}
			$terms = get_terms(
				array(
					'taxonomy'   => Resandpay_Post_Types::CATEGORY,
					'hide_empty' => false,
					'object_ids' => get_posts( array( 'post_type' => Resandpay_Post_Types::MATERIAL, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) ?: array( 0 ),
				)
			);
			if ( ! is_wp_error( $terms ) && $terms ) {
				$chips .= '<span class="rap-materials__sep"></span><a class="rap-chip' . ( ! $cat ? ' is-active' : '' ) . '" href="' . esc_url( remove_query_arg( 'rap_mcat' ) ) . '">' . esc_html__( 'Všechna témata', 'reserveandpay' ) . '</a>';
				foreach ( $terms as $t ) {
					$chips .= ' <a class="rap-chip' . ( $cat === $t->slug ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'rap_mcat', $t->slug ) ) . '">' . esc_html( $t->name ) . '</a>';
				}
			}
			unset( $base );
			$out .= '<nav class="rap-filter" aria-label="' . esc_attr__( 'Filtr materiálů', 'reserveandpay' ) . '">' . $chips . '</nav>';
		}
		if ( ! $items ) {
			return $out . '<p class="rap-notice">' . esc_html__( 'Zatím tu nejsou žádné materiály.', 'reserveandpay' ) . '</p></div>';
		}
		$out .= '<div class="rap-mat-grid">';
		foreach ( $items as $mat ) {
			$out .= self::card( $mat );
		}
		return $out . '</div></div>';
	}

	/** [reserveandpay_material id="MA-001"] – karta jednoho materiálu (kód, slug nebo ID). */
	public static function shortcode_card( $atts ) {
		$a  = shortcode_atts( array( 'id' => '' ), $atts, 'reserveandpay_material' );
		$id = self::resolve( $a['id'] );
		$m  = $id ? Resandpay_Materials::get( $id ) : null;
		return $m && 'publish' === $m['status'] ? '<div class="rap-materials rap-mat-grid rap-mat-grid--single">' . self::card( $m ) . '</div>' : '';
	}

	/** Kód (MA-001), slug nebo ID → ID materiálu. */
	public static function resolve( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return 0;
		}
		if ( Resandpay_Codes::looks_like_code( $ref ) ) {
			$id = Resandpay_Codes::find( $ref );
			return $id && Resandpay_Materials::is_material( $id ) ? $id : 0;
		}
		if ( ctype_digit( $ref ) ) {
			return Resandpay_Materials::is_material( (int) $ref ) ? (int) $ref : 0;
		}
		$p = get_page_by_path( sanitize_title( $ref ), OBJECT, Resandpay_Post_Types::MATERIAL );
		return $p ? (int) $p->ID : 0;
	}

	/* ---------------------------------------------------------------------
	 * Moje materiály
	 * ------------------------------------------------------------------- */

	public static function shortcode_my() {
		if ( ! is_user_logged_in() ) {
			$out  = '<div class="rap-materials rap-my"><p>' . esc_html( (string) Resandpay_Settings::get( 'mat_login_text' ) ) . '</p>';
			$out .= wp_login_form(
				array(
					'echo'     => false,
					'redirect' => get_permalink() ?: Resandpay_Materials::my_url(),
				)
			);
			$reg  = Resandpay_Materials::register_url();
			if ( $reg ) {
				$out .= '<p><a href="' . esc_url( $reg ) . '">' . esc_html__( 'Nemáte účet? Založte si ho zdarma →', 'reserveandpay' ) . '</a></p>';
			}
			return $out . '</div>';
		}
		$uid    = get_current_user_id();
		$items  = Resandpay_Materials::for_user( $uid );
		$orders = Resandpay_Materials::user_orders( $uid );
		$out    = '<div class="rap-materials rap-my">';
		$open   = array_filter( $orders, fn( $o ) => in_array( $o['status'], array( Resandpay_Reservations::PENDING, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::OVERDUE ), true ) );
		if ( $open ) {
			$out .= '<h2 class="rap-my__title">' . esc_html__( 'Čeká na platbu', 'reserveandpay' ) . '</h2><ul class="rap-my__open">';
			foreach ( $open as $o ) {
				$out .= '<li><a href="' . esc_url( get_permalink( $o['event_id'] ) ) . '#rap-material">' . esc_html( get_the_title( $o['event_id'] ) ) . '</a> – ' . esc_html( Resandpay_Events::format_price( Resandpay_Reservations::remaining( $o ), $o['currency'] ) ) . ', VS ' . esc_html( $o['vs'] ) . ', ' . esc_html( sprintf( /* translators: %s: datum */ __( 'splatnost %s', 'reserveandpay' ), wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $o ) ) ) ) ) . ' · <a href="' . esc_url( Resandpay_PDF::url( $o ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'platební pokyn', 'reserveandpay' ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		$out .= '<h2 class="rap-my__title">' . esc_html__( 'Moje materiály', 'reserveandpay' ) . '</h2>';
		if ( ! $items ) {
			$out .= '<p class="rap-notice">' . esc_html__( 'Zatím tu nemáte žádné materiály.', 'reserveandpay' ) . '</p>';
		}
		foreach ( $items as $it ) {
			$m    = $it['mat'];
			$out .= '<section class="rap-my__item"><h3><a href="' . esc_url( $m['url'] ) . '">' . esc_html( $m['title'] ) . '</a> <small>' . esc_html( $m['kind_label'] ) . '</small></h3>';
			if ( $it['access']['until'] ) {
				/* translators: %s: datum */
				$out .= '<p class="rap-small">' . esc_html( sprintf( __( 'Přístup do %s', 'reserveandpay' ), wp_date( 'j. n. Y', $it['access']['until'] ) ) ) . '</p>';
			}
			$out .= self::render_files( $m, false ) . '</section>';
		}
		$done = array_filter( $orders, fn( $o ) => ! in_array( $o['status'], array( Resandpay_Reservations::PENDING, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::OVERDUE ), true ) );
		if ( $done ) {
			$labels = Resandpay_Reservations::status_labels();
			$out   .= '<h2 class="rap-my__title">' . esc_html__( 'Historie objednávek', 'reserveandpay' ) . '</h2><table class="rap-my__orders"><thead><tr><th>' . esc_html__( 'Datum', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Materiál', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Částka', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th></tr></thead><tbody>';
			foreach ( $done as $o ) {
				$out .= '<tr><td>' . esc_html( wp_date( 'j. n. Y', Resandpay_Events::ts( $o['created_at'] ) ) ) . '</td><td>' . esc_html( get_the_title( $o['event_id'] ) ) . '</td><td>' . esc_html( Resandpay_Events::format_price( $o['amount'], $o['currency'] ) ) . '</td><td>' . esc_html( $labels[ $o['status'] ] ?? $o['status'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		return $out . '</div>';
	}
}
