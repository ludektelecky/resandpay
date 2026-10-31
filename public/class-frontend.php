<?php
defined( 'ABSPATH' ) || exit;

/**
 * Veřejná část: detail akce, rezervační formulář, výpis akcí.
 */
class Resandpay_Frontend {

	/** @var array Stav formuláře pro aktuální request (chyby, hodnoty). */
	private static $state = array();

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_filter( 'the_content', array( __CLASS__, 'event_content' ), 20 );
		add_shortcode( 'reserveandpay_booking', array( __CLASS__, 'shortcode_booking' ) );
		add_shortcode( 'reserveandpay_events', array( __CLASS__, 'shortcode_events' ) );
		add_shortcode( 'reserveandpay_calendar', array( __CLASS__, 'shortcode_calendar' ) );
		add_shortcode( 'reserveandpay_program', array( __CLASS__, 'shortcode_program' ) );
		add_shortcode( 'reserveandpay_slots', array( __CLASS__, 'shortcode_slots' ) );
		add_shortcode( 'reserveandpay_upcoming', array( __CLASS__, 'shortcode_upcoming' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_archives' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'detail_theme_hooks' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'remember_coupon' ), 2 );
		add_action( 'wp_ajax_resandpay_coupon', array( __CLASS__, 'ajax_coupon' ) );
		add_action( 'wp_ajax_nopriv_resandpay_coupon', array( __CLASS__, 'ajax_coupon' ) );
	}

	/** Kupón z odkazu / QR (?kupon=…) si web zapamatuje na 7 dní, aby se vyplnil v rezervaci. */
	public static function remember_coupon() {
		if ( empty( $_GET['kupon'] ) || headers_sent() ) {
			return;
		}
		$code = substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_GET['kupon'] ) ), 0, 60 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		setcookie( 'rap_kupon', $code, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE['rap_kupon'] = $code;
	}

	/** Kód kupónu předvyplněný do formuláře (z odkazu nebo zapamatovaný). */
	public static function prefilled_coupon() {
		$src = $_GET['kupon'] ?? ( $_COOKIE['rap_kupon'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return substr( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $src ) ), 0, 60 );
	}

	/** Cena rezervace pro formulář (bez ukládání) – kupón / sleva za včasnou rezervaci. */
	public static function quote( $event_id, $code = '', $email = '', $cash = false ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev ) {
			return new WP_Error( 'not_found', __( 'Akce nenalezena.', 'reserveandpay' ) );
		}
		$due   = Resandpay_Events::compute_due( $ev, time() );
		$part1 = $cash || $due['last_minute'] || ! empty( $due['late'] ) ? 0.0 : Resandpay_Events::part1_amount( $ev );
		return Resandpay_Discounts::compute( $ev, array( 'cash' => $cash, 'last_minute' => $due['last_minute'], 'part1' => $part1, 'coupon' => $code, 'email' => $email ) ) + array( 'currency' => $ev['currency'] );
	}

	/** AJAX „Použít“ u slevového kupónu. */
	public static function ajax_coupon() {
		$event_id = absint( $_POST['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- jen výpočet ceny, nic se neukládá.
		$code     = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $code ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Zadejte kód kupónu.', 'reserveandpay' ) ) );
		}
		$q = self::quote( $event_id, $code, $email, 'cash' === ( $_POST['pay'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $q ) || $q['error'] ) {
			wp_send_json( array( 'ok' => false, 'message' => is_wp_error( $q ) ? $q->get_error_message() : $q['error']->get_error_message() ) );
		}
		wp_send_json( array( 'ok' => true, 'message' => self::quote_text( $q ) ) );
	}

	public static function quote_text( array $q ) {
		if ( $q['free'] ) {
			/* translators: %s: popis slevy */
			return sprintf( __( '%s uplatněn – rezervace bude zdarma.', 'reserveandpay' ), $q['label'] );
		}
		/* translators: 1: popis slevy, 2: sleva, 3: cena po slevě, 4: plná cena */
		return sprintf( __( '%1$s: −%2$s. Cena po slevě %3$s (místo %4$s).', 'reserveandpay' ), $q['label'], Resandpay_Events::format_price( $q['discount'], $q['currency'] ), Resandpay_Events::format_price( $q['price_full'] - $q['discount'], $q['currency'] ), Resandpay_Events::format_price( $q['price_full'], $q['currency'] ) );
	}

	/** Cena na webu s informací o slevě za včasnou rezervaci (escapované HTML). */
	public static function price_html( array $ev, $suffix = '' ) {
		$o   = Resandpay_Discounts::early_offer( $ev );
		$out = '<span class="rap-price-base">' . esc_html( Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) ) . '</span>' . ( $suffix ? ' <small>' . esc_html( $suffix ) . '</small>' : '' );
		if ( $o ) {
			// Cena po slevě zlatě a stejně velkým písmem jako základní cena, podmínka menším písmem (vše ke slevě zlatě).
			$out .= ' <span class="rap-early-price">' . esc_html( $o['price_text'] ) . '</span> <span class="rap-early">' . esc_html( $o['condition'] ) . '</span>';
		}
		return $out;
	}

	/** Vzhled detailu akce s galerií (nastavení „Detail akce“). */
	public static function full_layout() {
		return 'basic' !== Resandpay_Settings::get( 'detail_layout' );
	}

	/**
	 * Úpravy šablony na detailu akce ve vzhledu s galerií: třída na body,
	 * celá šířka bez postranního panelu, skrytý titulek a náhledový obrázek šablony
	 * (nadpis i fotky vykresluje plugin v záhlaví). Filtry jsou GeneratePress – jinde nic nedělají.
	 */
	public static function detail_theme_hooks() {
		if ( ! is_singular( Resandpay_Post_Types::EVENT ) || ! self::full_layout() ) {
			return;
		}
		add_filter( 'body_class', fn( $c ) => array_merge( $c, array( 'rap-detail-full' ) ) );
		add_filter( 'generate_sidebar_layout', fn() => 'no-sidebar' );
		if ( Resandpay_Settings::get( 'detail_own_title' ) ) {
			add_filter( 'body_class', fn( $c ) => array_merge( $c, array( 'rap-detail-own-title' ) ) );
			add_filter( 'generate_show_title', '__return_false' );
		}
	}

	public static function assets() {
		wp_register_style( 'reserveandpay', RESANDPAY_URL . 'assets/css/frontend.css', array(), RESANDPAY_VERSION );
		$post = get_post();
		$has  = $post && is_singular() && preg_match( '/\[reserveandpay_/', (string) $post->post_content );
		$on   = $has || is_singular( Resandpay_Post_Types::EVENT ) || is_post_type_archive( Resandpay_Post_Types::EVENT );
		/**
		 * Načíst styly pluginu i na stránce, kde výpis vkládá šablona (např. úvodní stránka).
		 * add_filter( 'resandpay_enqueue_assets', fn( $on ) => $on || is_front_page() );
		 */
		if ( apply_filters( 'resandpay_enqueue_assets', $on ) ) {
			wp_enqueue_style( 'reserveandpay' );
		}
	}

	/** Zpracování odeslaného formuláře (PRG – po úspěchu přesměrování). */
	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['resandpay_action'] ) || 'book' !== $_POST['resandpay_action'] ) {
			return;
		}
		$event_id = absint( $_POST['rap_event_id'] ?? 0 );
		$values   = array(
			'customer_name' => sanitize_text_field( wp_unslash( $_POST['rap_name'] ?? '' ) ),
			'email'         => sanitize_email( wp_unslash( $_POST['rap_email'] ?? '' ) ),
			'phone'         => sanitize_text_field( wp_unslash( $_POST['rap_phone'] ?? '' ) ),
			'is_company'    => ! empty( $_POST['rap_is_company'] ),
			'company'       => sanitize_text_field( wp_unslash( $_POST['rap_company'] ?? '' ) ),
			'company_ic'    => sanitize_text_field( wp_unslash( $_POST['rap_ic'] ?? '' ) ),
			'company_dic'   => sanitize_text_field( wp_unslash( $_POST['rap_dic'] ?? '' ) ),
			'note'          => sanitize_textarea_field( wp_unslash( $_POST['rap_note'] ?? '' ) ),
			'consent_gdpr'  => ! empty( $_POST['rap_gdpr'] ),
			'consent_terms' => ! empty( $_POST['rap_terms'] ),
			'newsletter'    => ! empty( $_POST['rap_newsletter'] ),
			'pay_choice'    => 'cash' === ( $_POST['rap_pay'] ?? '' ) ? 'cash' : 'transfer',
			'coupon'        => substr( sanitize_text_field( wp_unslash( $_POST['rap_coupon'] ?? '' ) ), 0, 60 ),
		);
		$errors   = array();

		if ( ! isset( $_POST['_rapnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rapnonce'] ) ), 'resandpay_book_' . $event_id ) ) {
			$errors[] = __( 'Platnost formuláře vypršela. Načtěte prosím stránku znovu a odešlete formulář ještě jednou.', 'reserveandpay' );
		}
		$ev = Resandpay_Events::get( $event_id );
		$gate = $ev ? Resandpay_Events::gate( $ev ) : true;
		if ( is_wp_error( $gate ) ) {
			$errors[] = $gate->get_error_message();
		}
		if ( '' === $values['customer_name'] ) {
			$errors[] = __( 'Vyplňte jméno a příjmení.', 'reserveandpay' );
		}
		if ( ! is_email( $values['email'] ) ) {
			$errors[] = __( 'Vyplňte platný e-mail.', 'reserveandpay' );
		}
		if ( '' === $values['phone'] ) {
			$errors[] = __( 'Vyplňte telefon.', 'reserveandpay' );
		}
		if ( $values['is_company'] && ( '' === $values['company'] || '' === $values['company_ic'] ) ) {
			$errors[] = __( 'Při nákupu na firmu vyplňte název firmy a IČ.', 'reserveandpay' );
		}
		if ( ! $values['consent_gdpr'] ) {
			$errors[] = __( 'Pro rezervaci je nutný souhlas se zpracováním osobních údajů.', 'reserveandpay' );
		}
		if ( ! $values['consent_terms'] ) {
			$errors[] = __( 'Pro rezervaci je nutný souhlas s obchodními a stornopodmínkami.', 'reserveandpay' );
		}
		if ( ! $values['is_company'] ) {
			$values['company'] = $values['company_ic'] = $values['company_dic'] = '';
		}

		if ( ! $errors && ! current_user_can( resandpay_cap_reservations() ) ) {
			// Ochrana proti robotům (skryté pole, doba vyplnění, Turnstile, limity četnosti) – SWD kap. 9.1.
			$guard = Resandpay_Antibot::check( wp_unslash( $_POST ), $event_id, $values['email'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $guard ) ) {
				$errors[] = $guard->get_error_message();
			}
		}
		if ( ! $errors ) {
			$values['ip_hash'] = Resandpay_Antibot::ip_hash();
			$res = Resandpay_Reservations::create( $event_id, $values, array( 'source' => 'web' ) );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
			} else {
				$url = add_query_arg( 'rap_done', rawurlencode( $res['token'] ), get_permalink( $event_id ) );
				wp_safe_redirect( $url . '#rap-booking' );
				exit;
			}
		}
		self::$state[ $event_id ] = array(
			'errors' => $errors,
			'values' => $values,
		);
	}

	/** Doplnění detailu akce a formuláře do obsahu stránky akce. */
	public static function event_content( $content ) {
		if ( ! is_singular( Resandpay_Post_Types::EVENT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id  = get_the_ID();
		$sid = (int) get_post_meta( $id, '_rap_service', true );
		if ( $sid && '' === trim( wp_strip_all_tags( $content ) ) ) {
			$svc_post = get_post( $sid );
			if ( $svc_post ) {
				remove_filter( 'the_content', array( __CLASS__, 'event_content' ), 20 );
				$content = apply_filters( 'the_content', $svc_post->post_content );
				add_filter( 'the_content', array( __CLASS__, 'event_content' ), 20 );
			}
		}
		if ( self::full_layout() ) {
			return self::render_event_page( $id, $content, (bool) Resandpay_Settings::get( 'detail_own_title' ) );
		}
		return self::render_details( $id ) . $content . self::render_booking( $id );
	}

	/**
	 * Detail akce ve vzhledu s galerií:
	 * záhlaví (eyebrow, nadpis, perex, termín, místo, cena, tlačítko) vedle fotek,
	 * dechová linka, pod ní popis akce a vedle něj rezervační karta s obsazeností.
	 *
	 * @param int    $event_id  ID akce.
	 * @param string $content   Popis akce (obsah z blokového editoru, už zpracovaný).
	 * @param bool   $own_title Vykreslit nadpis H1 (titulek šablony je skrytý).
	 */
	public static function render_event_page( $event_id, $content, $own_title = true ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev ) {
			return $content;
		}
		$free    = Resandpay_Events::free_places( $event_id );
		$page    = (int) Resandpay_Settings::get( 'events_page' );
		$cats    = get_the_terms( $event_id, Resandpay_Post_Types::CATEGORY );
		$brow    = ( $cats && ! is_wp_error( $cats ) ) ? implode( ' · ', wp_list_pluck( $cats, 'name' ) ) : ( Resandpay_Events::type_labels()[ $ev['type'] ] ?? '' );
		$is_slot = 'slot' === $ev['type'] && $ev['capacity'] <= 1;
		$open    = ! $ev['cancelled'] && Resandpay_Events::is_open( $ev );
		$photos  = Resandpay_Events::photos( $event_id );
		$excerpt = has_excerpt( $event_id ) ? get_the_excerpt( $event_id ) : '';

		ob_start();
		echo '<div class="rap-event' . ( $ev['cancelled'] ? ' rap-event--cancelled' : '' ) . '">';

		// Záhlaví.
		echo '<header class="rap-event__hero">';
		echo '<div class="rap-event__intro">';
		$svc = 'slot' === $ev['type'] ? Resandpay_Services::get( (int) get_post_meta( $event_id, '_rap_service', true ) ) : null;
		if ( $svc && $svc['page_id'] ) {
			echo '<p class="rap-back"><a href="' . esc_url( get_permalink( $svc['page_id'] ) . '#rap-slots' ) . '">← ' . esc_html__( 'Jiný termín', 'reserveandpay' ) . '</a></p>';
		} elseif ( $page && 'slot' !== $ev['type'] ) {
			echo '<p class="rap-back"><a href="' . esc_url( get_permalink( $page ) ) . '">← ' . esc_html__( 'Všechny termíny', 'reserveandpay' ) . '</a></p>';
		}
		if ( $brow ) {
			echo '<p class="rap-eyebrow">' . esc_html( $brow ) . '</p>';
		}
		if ( $own_title ) {
			echo '<h1 class="rap-event__title">' . esc_html( get_the_title( $event_id ) ) . '</h1>';
		}
		if ( $ev['cancelled'] ) {
			echo '<p class="rap-notice">' . esc_html__( 'Akce byla zrušena.', 'reserveandpay' ) . '</p>';
		}
		if ( '' !== $excerpt ) {
			echo '<p class="rap-event__lead">' . esc_html( $excerpt ) . '</p>';
		}
		echo '<dl class="rap-event__facts">';
		echo '<dt>' . esc_html__( 'Kdy', 'reserveandpay' ) . '</dt><dd class="rap-event__when">' . esc_html( Resandpay_Views::when_line( $ev ) ) . '</dd>';
		if ( $ev['location'] ) {
			echo '<dt>' . esc_html__( 'Kde', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['location'] );
			if ( $ev['location_addr'] ) {
				echo '<small>' . esc_html( $ev['location_addr'] );
				if ( $ev['location_map'] ) {
					echo ' · <a href="' . esc_url( $ev['location_map'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'mapa', 'reserveandpay' ) . '</a>';
				}
				echo '</small>';
			} elseif ( $ev['location_map'] ) {
				echo ' · <a href="' . esc_url( $ev['location_map'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'mapa', 'reserveandpay' ) . '</a>';
			}
			echo '</dd>';
		}
		if ( ! $ev['cancelled'] && Resandpay_Events::external_capacity( $ev ) ) {
			echo '<dt>' . esc_html__( 'Kapacita', 'reserveandpay' ) . '</dt><dd>' . esc_html( preg_replace( '/^[^:]+:\s*/u', '', Resandpay_Events::capacity_label( Resandpay_Events::external_capacity( $ev ) ) ) ) . '</dd>';
		}
		if ( $is_slot && ! $ev['external'] ) {
			echo '<dt>' . esc_html__( 'Termín', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['cancelled'] ? __( 'zrušen', 'reserveandpay' ) : ( $free > 0 ? __( 'volný', 'reserveandpay' ) : __( 'obsazený', 'reserveandpay' ) ) ) . '</dd>';
		}
		echo '</dl>';
		echo '<div class="rap-event__cta">';
		echo '<p class="rap-event__price">' . self::price_html( $ev, 'multiday' === $ev['type'] ? __( 'za celou akci', 'reserveandpay' ) : __( '/ osoba', 'reserveandpay' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno v price_html().
		$egate = $open ? Resandpay_Events::gate( $ev ) : true;
		if ( $open && is_wp_error( $egate ) && 'not_open' === $egate->get_error_code() ) {
			echo '<span class="rap-event__opens">' . esc_html( $egate->get_error_message() ) . '</span>';
		} elseif ( $open && ( true === $egate || ! $ev['external'] ) ) {
			echo '<a class="rap-button" href="' . esc_url( $ev['external'] && true === $egate ? $ev['external']['url'] : '#rap-booking' ) . '"' . ( true === $egate ? Resandpay_Views::book_attrs( $ev ) : '' ) . '>' . esc_html( Resandpay_Views::book_label( $ev, __( 'Rezervovat místo →', 'reserveandpay' ) ) ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pevné atributy.
		} elseif ( ! $ev['cancelled'] && ! $ev['external'] && $free < 1 ) {
			echo '<span class="rap-event__full">' . esc_html__( 'Obsazeno', 'reserveandpay' ) . '</span>';
		}
		echo '</div>';
		echo '</div>';
		echo self::render_gallery( $event_id, $photos ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
		echo '</header>';

		// Dechová linka – podpis značky (dekor).
		echo '<div class="rap-breath" aria-hidden="true"><svg viewBox="0 0 220 28" focusable="false"><path d="M2 14 C30 2 50 26 80 14 S130 2 160 14 S200 26 218 14"/></svg></div>';

		// Popis + rezervační karta.
		echo '<div class="rap-event__body">';
		echo '<div class="rap-event__desc">';
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- obsah příspěvku po the_content.
		if ( 'multiday' === $ev['type'] && $ev['program'] ) {
			echo '<div class="rap-program"><p class="rap-eyebrow">' . esc_html__( 'Program', 'reserveandpay' ) . '</p>' . wp_kses_post( wpautop( $ev['program'] ) ) . '</div>';
		}
		echo '</div>';
		echo '<aside class="rap-event__aside">';
		echo self::render_booking( $event_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
		echo '</aside>';
		echo '</div>';

		echo '</div>';
		return ob_get_clean();
	}

	/** Galerie v záhlaví detailu: velká fotka 4:3 a náhledy; bez fotek tlumená plocha se vlnami. */
	public static function render_gallery( $event_id, array $photos ) {
		if ( ! $photos ) {
			return '<div class="rap-gallery rap-gallery--empty" aria-hidden="true"><div class="rap-gallery__main"><svg class="rap-gallery__waves" viewBox="0 0 200 120" focusable="false"><path d="M-10 70 C40 50 80 90 120 70 S180 50 230 70"/><path d="M-10 84 C40 64 80 104 120 84 S180 64 230 84"/><path d="M-10 98 C40 78 80 118 120 98 S180 78 230 98"/></svg></div></div>';
		}
		$title = get_the_title( $event_id );
		$alt   = function ( $id, $n ) use ( $title ) {
			$a = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			/* translators: 1: název akce, 2: pořadí fotky */
			return '' !== $a ? $a : sprintf( __( '%1$s – fotka %2$d', 'reserveandpay' ), $title, $n );
		};
		$main = $photos[0];
		$out  = '<div class="rap-gallery" data-rap-gallery>';
		$out .= '<figure class="rap-gallery__main">' . wp_get_attachment_image( $main, 'large', false, array( 'alt' => $alt( $main, 1 ), 'data-rap-main' => '1', 'loading' => 'eager', 'sizes' => '(max-width: 860px) 100vw, 600px' ) );
		$cap  = wp_get_attachment_caption( $main );
		$out .= '<figcaption data-rap-cap' . ( $cap ? '' : ' hidden' ) . '>' . esc_html( (string) $cap ) . '</figcaption></figure>';
		if ( count( $photos ) > 1 ) {
			$out .= '<div class="rap-gallery__thumbs" role="group" aria-label="' . esc_attr__( 'Fotky z akce', 'reserveandpay' ) . '">';
			foreach ( $photos as $i => $pid ) {
				$large = wp_get_attachment_image_src( $pid, 'large' );
				$full  = wp_get_attachment_image_url( $pid, 'full' );
				if ( ! $large ) {
					continue;
				}
				$out .= '<a class="rap-gallery__thumb" href="' . esc_url( $full ?: $large[0] ) . '" data-src="' . esc_url( $large[0] ) . '" data-srcset="' . esc_attr( (string) wp_get_attachment_image_srcset( $pid, 'large' ) ) . '" data-alt="' . esc_attr( $alt( $pid, $i + 1 ) ) . '" data-cap="' . esc_attr( (string) wp_get_attachment_caption( $pid ) ) . '" aria-current="' . ( 0 === $i ? 'true' : 'false' ) . '">'
					. wp_get_attachment_image( $pid, 'thumbnail', false, array( 'alt' => $alt( $pid, $i + 1 ), 'loading' => 'lazy' ) ) . '</a>';
			}
			$out .= '</div>';
			// Přepnutí velké fotky bez načtení stránky; bez JavaScriptu odkaz otevře fotku.
			$out .= '<script>(function(g){if(!g||g.dataset.rapReady)return;g.dataset.rapReady=1;var m=g.querySelector("[data-rap-main]"),c=g.querySelector("[data-rap-cap]");g.querySelectorAll(".rap-gallery__thumb").forEach(function(a){a.addEventListener("click",function(e){if(!m)return;e.preventDefault();m.src=a.dataset.src;if(a.dataset.srcset){m.srcset=a.dataset.srcset}else{m.removeAttribute("srcset")}m.alt=a.dataset.alt;if(c){c.textContent=a.dataset.cap;c.hidden=!a.dataset.cap}g.querySelectorAll(".rap-gallery__thumb").forEach(function(x){x.setAttribute("aria-current",x===a?"true":"false")})})})})(document.currentScript&&document.currentScript.closest("[data-rap-gallery]"));</script>';
		}
		$out .= '</div>';
		return $out;
	}

	/** Obsazenost v rezervační kartě: „5 volných míst z 16“, tečky po místech (do 40 míst) a uzávěrka. */
	public static function render_seats( array $ev, $free ) {
		if ( 'slot' === $ev['type'] && $ev['capacity'] <= 1 ) {
			return '';
		}
		$cap   = (int) $ev['capacity'];
		$taken = max( 0, $cap - (int) $free );
		$out   = '<div class="rap-seats">';
		$out  .= '<p class="rap-seats__row"><span>';
		if ( $free > 0 ) {
			/* translators: 1: „5 volných míst“, 2: kapacita */
			$out .= wp_kses( sprintf( __( '%1$s z %2$d', 'reserveandpay' ), '<strong>' . esc_html( Resandpay_Views::places_label( $free ) ) . '</strong>', $cap ), array( 'strong' => array() ) );
			if ( $free <= 3 && $cap > 3 ) {
				$out .= ' · ' . esc_html__( 'poslední místa', 'reserveandpay' );
			}
		} else {
			$out .= '<strong>' . esc_html__( 'Obsazeno', 'reserveandpay' ) . '</strong>';
		}
		$out .= '</span>';
		$start = Resandpay_Events::ts( $ev['start'] );
		if ( $ev['close_hours'] > 0 && $start ) {
			/* translators: %s: datum a čas uzávěrky */
			$out .= '<span class="rap-small">' . esc_html( sprintf( __( 'Uzávěrka %s', 'reserveandpay' ), wp_date( 'j. n. G:i', $start - (int) round( $ev['close_hours'] * HOUR_IN_SECONDS ) ) ) ) . '</span>';
		}
		$out .= '</p>';
		if ( $cap > 0 && $cap <= 40 ) {
			$out .= '<p class="rap-seats__dots" aria-hidden="true">' . str_repeat( '<i class="is-taken"></i>', $taken ) . str_repeat( '<i></i>', max( 0, $cap - $taken ) ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}

	public static function shortcode_booking( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'reserveandpay_booking' );
		$id   = Resandpay_Codes::looks_like_code( $atts['id'] ) ? Resandpay_Codes::find( $atts['id'] ) : absint( $atts['id'] );
		$id   = $id ?: get_the_ID();
		wp_enqueue_style( 'reserveandpay' );
		return self::render_details( $id ) . self::render_booking( $id );
	}

	private static function list_atts( $atts, $tag ) {
		return shortcode_atts(
			array(
				'limit'         => 50,
				'category'      => '',
				'filter'        => 'yes',
				'view'          => 'list',
				'include_slots' => '',
				'services'      => 'yes',
			),
			$atts,
			$tag
		);
	}

	public static function shortcode_events( $atts ) {
		$a = self::list_atts( $atts, 'reserveandpay_events' );
		wp_enqueue_style( 'reserveandpay' );
		$cat = isset( $_GET['rap_cat'] ) ? sanitize_title( wp_unslash( $_GET['rap_cat'] ) ) : $a['category'];
		$out = 'yes' === $a['filter'] ? Resandpay_Views::render_filter( $cat, remove_query_arg( array( 'rap_done', 'rap_again' ) ) ) : '';
		$a['category'] = $cat;
		return '<div class="rap-program-view">' . $out . Resandpay_Views::render_list( $a ) . '</div>';
	}

	public static function shortcode_calendar( $atts ) {
		$a = self::list_atts( $atts, 'reserveandpay_calendar' );
		wp_enqueue_style( 'reserveandpay' );
		return '<div class="rap-program-view">' . Resandpay_Views::render_calendar( $a ) . '</div>';
	}

	public static function shortcode_slots( $atts ) {
		$a = shortcode_atts(
			array(
				'service'     => '',
				'days'        => 21,
				'description' => '', // yes = vždy zobrazit, toggle = rozbalovací, no = nezobrazovat; prázdné = volba u služby.
			),
			$atts,
			'reserveandpay_slots'
		);
		wp_enqueue_style( 'reserveandpay' );
		return Resandpay_Views::render_slots( $a );
	}

	/** [reserveandpay_upcoming limit="3" first_service="yes" category="" more="yes"] – krátký přehled (úvodní stránka). */
	public static function shortcode_upcoming( $atts ) {
		$a = shortcode_atts(
			array(
				'limit'         => 3,
				'category'      => '',
				'first_service' => 'yes',
				'more'          => 'yes',
			),
			$atts,
			'reserveandpay_upcoming'
		);
		wp_enqueue_style( 'reserveandpay' );
		return Resandpay_Views::render_upcoming( $a );
	}

	public static function shortcode_program( $atts ) {
		$a = self::list_atts( $atts, 'reserveandpay_program' );
		wp_enqueue_style( 'reserveandpay' );
		return Resandpay_Views::render_program( $a );
	}

	/** Archiv /akce/ a kategorie přesměruje na stránku s přehledem akcí (je-li nastavena). */
	public static function redirect_archives() {
		$page = (int) Resandpay_Settings::get( 'events_page' );
		if ( ! $page || 'publish' !== get_post_status( $page ) ) {
			return;
		}
		if ( is_post_type_archive( Resandpay_Post_Types::EVENT ) ) {
			wp_safe_redirect( get_permalink( $page ), 302 );
			exit;
		}
		if ( is_tax( Resandpay_Post_Types::CATEGORY ) ) {
			$term = get_queried_object();
			wp_safe_redirect( add_query_arg( 'rap_cat', $term->slug, get_permalink( $page ) ), 302 );
			exit;
		}
	}

	public static function render_details( $event_id ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev ) {
			return '';
		}
		$free = Resandpay_Events::free_places( $event_id );
		$page = (int) Resandpay_Settings::get( 'events_page' );
		$cats = get_the_terms( $event_id, Resandpay_Post_Types::CATEGORY );
		$brow = ( $cats && ! is_wp_error( $cats ) ) ? implode( ' · ', wp_list_pluck( $cats, 'name' ) ) : ( Resandpay_Events::type_labels()[ $ev['type'] ] ?? '' );
		ob_start();
		echo '<div class="rap-details">';
		if ( $brow ) {
			echo '<p class="rap-eyebrow">' . esc_html( $brow ) . '</p>';
		}
		echo '<p class="rap-details__when">' . esc_html( Resandpay_Views::when_line( $ev ) ) . '</p>';
		echo '<dl>';
		if ( $ev['location'] ) {
			echo '<dt>' . esc_html__( 'Místo', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['location'] );
			if ( $ev['location_addr'] ) {
				echo ', ' . esc_html( $ev['location_addr'] );
			}
			if ( $ev['location_map'] ) {
				echo ' · <a href="' . esc_url( $ev['location_map'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'mapa', 'reserveandpay' ) . '</a>';
			}
			echo '</dd>';
		}
		echo '<dt>' . esc_html__( 'Cena', 'reserveandpay' ) . '</dt><dd>' . self::price_html( $ev, 'multiday' === $ev['type'] ? __( '(za celou akci)', 'reserveandpay' ) : '' ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $ev['external'] ) {
			if ( $ev['cancelled'] ) {
				echo '<dt>' . esc_html__( 'Termín', 'reserveandpay' ) . '</dt><dd>' . esc_html__( 'zrušen', 'reserveandpay' ) . '</dd>';
			} elseif ( Resandpay_Events::external_capacity( $ev ) ) {
				echo '<dt>' . esc_html__( 'Kapacita', 'reserveandpay' ) . '</dt><dd>' . esc_html( preg_replace( '/^[^:]+:\s*/u', '', Resandpay_Events::capacity_label( Resandpay_Events::external_capacity( $ev ) ) ) ) . '</dd>';
			}
		} elseif ( 'slot' === $ev['type'] && $ev['capacity'] <= 1 ) {
			echo '<dt>' . esc_html__( 'Termín', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['cancelled'] ? __( 'zrušen', 'reserveandpay' ) : ( $free > 0 ? __( 'volný', 'reserveandpay' ) : __( 'obsazený', 'reserveandpay' ) ) ) . '</dd>';
		} else {
			echo '<dt>' . esc_html__( 'Volná místa', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['cancelled'] ? __( 'akce zrušena', 'reserveandpay' ) : ( $free > 0 ? sprintf( /* translators: 1: volno, 2: kapacita */ __( '%1$d z %2$d', 'reserveandpay' ), $free, $ev['capacity'] ) : __( 'obsazeno', 'reserveandpay' ) ) ) . '</dd>';
		}
		echo '</dl>';
		$dgate = Resandpay_Events::gate( $ev );
		if ( ! $ev['cancelled'] && Resandpay_Events::is_open( $ev ) && is_wp_error( $dgate ) && 'not_open' === $dgate->get_error_code() ) {
			echo '<p class="rap-details__cta rap-details__opens">' . esc_html( $dgate->get_error_message() ) . '</p>';
		} elseif ( ! $ev['cancelled'] && Resandpay_Events::is_open( $ev ) && ( true === $dgate || ! $ev['external'] ) ) {
			echo '<p class="rap-details__cta"><a class="rap-button" href="' . esc_url( $ev['external'] ? $ev['external']['url'] : '#rap-booking' ) . '"' . Resandpay_Views::book_attrs( $ev ) . '>' . esc_html( Resandpay_Views::book_label( $ev, __( 'Rezervovat termín →', 'reserveandpay' ) ) ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pevné atributy.
		}
		if ( 'multiday' === $ev['type'] && $ev['program'] ) {
			echo '<div class="rap-program"><p class="rap-eyebrow">' . esc_html__( 'Program', 'reserveandpay' ) . '</p>' . wp_kses_post( wpautop( $ev['program'] ) ) . '</div>';
		}
		$svc = 'slot' === $ev['type'] ? Resandpay_Services::get( (int) get_post_meta( $event_id, '_rap_service', true ) ) : null;
		if ( $svc && $svc['page_id'] && is_singular( Resandpay_Post_Types::EVENT ) ) {
			echo '<p class="rap-back"><a href="' . esc_url( get_permalink( $svc['page_id'] ) . '#rap-slots' ) . '">← ' . esc_html__( 'Jiný termín', 'reserveandpay' ) . '</a></p>';
		} elseif ( $page && is_singular( Resandpay_Post_Types::EVENT ) && 'slot' !== $ev['type'] ) {
			echo '<p class="rap-back"><a href="' . esc_url( get_permalink( $page ) ) . '">← ' . esc_html__( 'Všechny termíny', 'reserveandpay' ) . '</a></p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * @param int  $event_id ID akce.
	 * @param bool $card     Rezervační karta vedle popisu (vzhled s galerií): nadpis „Rezervovat místo“ a obsazenost.
	 */
	/**
	 * Rezervační karta termínu v externím rezervačním systému (SWD kap. 3.11):
	 * termín, cena, poznámka a tlačítko ven – bez formuláře a bez volných míst.
	 */
	/** Upozornění místo formuláře: rezervace od … / přihlaste se / jen pro členy. */
	public static function gate_notice( WP_Error $gate, $event_id ) {
		$cls = 'not_open' === $gate->get_error_code() ? 'rap-notice rap-notice--opens' : 'rap-notice';
		$out = '<p class="' . $cls . '">' . esc_html( $gate->get_error_message() );
		if ( 'login' === $gate->get_error_code() || ( 'not_open' !== $gate->get_error_code() && ! is_user_logged_in() ) ) {
			$out .= ' <a href="' . esc_url( wp_login_url( get_permalink( $event_id ) ) ) . '">' . esc_html__( 'Přihlásit se', 'reserveandpay' ) . '</a>';
		}
		return $out . '</p>';
	}

	public static function render_external_card( array $ev, $card = false ) {
		$out  = $card ? '<p class="rap-eyebrow">' . esc_html__( 'Rezervace', 'reserveandpay' ) . '</p>' : '';
		$out .= '<h2 class="rap-booking__title">' . esc_html( $card ? __( 'Rezervovat místo', 'reserveandpay' ) : __( 'Rezervace', 'reserveandpay' ) ) . '</h2>';
		$out .= '<dl class="rap-external__facts"><dt>' . esc_html__( 'Termín', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Views::when_line( $ev ) ) . '</dd>';
		$out .= '<dt>' . esc_html__( 'Cena', 'reserveandpay' ) . '</dt><dd>' . self::price_html( $ev ) . '</dd>';
		if ( Resandpay_Events::external_capacity( $ev ) ) {
			/* translators: %d: počet míst */
			$out .= '<dt>' . esc_html__( 'Kapacita', 'reserveandpay' ) . '</dt><dd>' . esc_html( preg_replace( '/^[^:]+:\s*/u', '', Resandpay_Events::capacity_label( Resandpay_Events::external_capacity( $ev ) ) ) ) . '</dd>';
		}
		$out .= '</dl>';
		$bookable = Resandpay_Events::check_bookable( $ev['id'] );
		if ( is_wp_error( $bookable ) ) {
			return $out . '<p class="rap-notice">' . esc_html( $bookable->get_error_message() ) . '</p>';
		}
		if ( '' === $ev['external']['url'] ) {
			return $out . '<p class="rap-notice">' . esc_html__( 'Rezervace zatím není k dispozici.', 'reserveandpay' ) . '</p>';
		}
		$gate = Resandpay_Events::gate( $ev );
		if ( is_wp_error( $gate ) ) {
			return $out . self::gate_notice( $gate, $ev['id'] );
		}
		if ( '' !== $ev['external']['note'] ) {
			$out .= '<p class="rap-external__note">' . nl2br( esc_html( $ev['external']['note'] ) ) . '</p>';
		}
		$out .= '<p class="rap-external__cta"><a class="rap-button rap-button--block" href="' . esc_url( $ev['external']['url'] ) . '"' . Resandpay_Views::book_attrs( $ev ) . '>' . esc_html( Resandpay_Views::book_label( $ev, '' ) ) . '</a></p>';
		return $out;
	}

	public static function render_booking( $event_id, $card = false ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev ) {
			return '';
		}
		ob_start();
		echo '<section id="rap-booking" class="rap-booking' . ( $card ? ' rap-booking--card' : '' ) . '">';

		// Potvrzení po úspěšné rezervaci.
		$done = isset( $_GET['rap_done'] ) ? Resandpay_Reservations::get_by_token( sanitize_text_field( wp_unslash( $_GET['rap_done'] ) ) ) : null;
		if ( $done && (int) $done['event_id'] === (int) $event_id ) {
			self::render_confirmation( $done, $ev );
			echo '</section>';
			return ob_get_clean();
		}

		if ( $ev['external'] ) {
			echo self::render_external_card( $ev, $card ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
			echo '</section>';
			return ob_get_clean();
		}

		if ( $card ) {
			echo '<p class="rap-eyebrow">' . esc_html__( 'Rezervace', 'reserveandpay' ) . '</p>';
			echo '<h2 class="rap-booking__title">' . esc_html__( 'Rezervovat místo', 'reserveandpay' ) . '</h2>';
			if ( ! $ev['cancelled'] ) {
				echo self::render_seats( $ev, Resandpay_Events::free_places( $event_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
			}
		} else {
			echo '<h2 class="rap-booking__title">' . esc_html__( 'Rezervace', 'reserveandpay' ) . '</h2>';
		}

		$bookable = Resandpay_Events::check_bookable( $event_id );
		if ( is_wp_error( $bookable ) ) {
			echo '<p class="rap-notice">' . esc_html( $bookable->get_error_message() ) . '</p></section>';
			return ob_get_clean();
		}
		if ( Resandpay_Events::free_places( $event_id ) < 1 ) {
			echo '<p class="rap-notice">' . esc_html__( 'Akce je bohužel již obsazena.', 'reserveandpay' ) . '</p></section>';
			return ob_get_clean();
		}
		$gate = Resandpay_Events::gate( $ev );
		if ( is_wp_error( $gate ) ) {
			echo self::gate_notice( $gate, $event_id ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
			return ob_get_clean();
		}

		$state  = self::$state[ $event_id ] ?? array();
		$values = $state['values'] ?? array();
		// Předvyplnění „Rezervovat další místo“.
		if ( ! $values && isset( $_GET['rap_again'] ) ) {
			$prev = Resandpay_Reservations::get_by_token( sanitize_text_field( wp_unslash( $_GET['rap_again'] ) ) );
			if ( $prev ) {
				$values = array(
					'phone'       => $prev['phone'],
					'is_company'  => '' !== $prev['company'],
					'company'     => $prev['company'],
					'company_ic'  => $prev['company_ic'],
					'company_dic' => $prev['company_dic'],
				);
			}
		}
		if ( ! $values && is_user_logged_in() ) {
			$u      = wp_get_current_user();
			$values = array(
				'customer_name' => trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name,
				'email'         => $u->user_email,
			);
		}
		$v = function ( $k ) use ( $values ) {
			return esc_attr( $values[ $k ] ?? '' );
		};

		if ( ! empty( $state['errors'] ) ) {
			echo '<div class="rap-errors" role="alert"><ul>';
			foreach ( $state['errors'] as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}

		$gdpr  = (int) Resandpay_Settings::get( 'gdpr_page' );
		$terms = (int) Resandpay_Settings::get( 'terms_page' );
		$link  = function ( $page_id, $text ) {
			return $page_id ? '<a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>' : esc_html( $text );
		};
		?>
		<form class="rap-form" method="post" action="<?php echo esc_url( get_permalink( $event_id ) ); ?>#rap-booking" novalidate>
			<input type="hidden" name="resandpay_action" value="book">
			<input type="hidden" name="rap_event_id" value="<?php echo (int) $event_id; ?>">
			<?php wp_nonce_field( 'resandpay_book_' . $event_id, '_rapnonce' ); ?>
			<p class="rap-hp" aria-hidden="true"><label>Web <input type="text" name="rap_website" tabindex="-1" autocomplete="off"></label></p>
			<?php echo Resandpay_Antibot::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř. ?>
			<p class="rap-field"><label for="rap_name"><?php esc_html_e( 'Jméno a příjmení', 'reserveandpay' ); ?> *</label>
				<input id="rap_name" type="text" name="rap_name" value="<?php echo $v( 'customer_name' ); ?>" required autocomplete="name"></p>
			<p class="rap-field"><label for="rap_email"><?php esc_html_e( 'E-mail', 'reserveandpay' ); ?> *</label>
				<input id="rap_email" type="email" name="rap_email" value="<?php echo $v( 'email' ); ?>" required autocomplete="email"></p>
			<p class="rap-field"><label for="rap_phone"><?php esc_html_e( 'Telefon', 'reserveandpay' ); ?> *</label>
				<input id="rap_phone" type="tel" name="rap_phone" value="<?php echo $v( 'phone' ); ?>" required autocomplete="tel"></p>
			<p class="rap-check"><label><input type="checkbox" name="rap_is_company" value="1" <?php checked( ! empty( $values['is_company'] ) ); ?> onchange="this.closest('form').querySelector('.rap-company').hidden=!this.checked"> <?php esc_html_e( 'Nakupuji na firmu', 'reserveandpay' ); ?></label></p>
			<div class="rap-company" <?php echo empty( $values['is_company'] ) ? 'hidden' : ''; ?>>
				<p class="rap-field"><label for="rap_company"><?php esc_html_e( 'Název firmy', 'reserveandpay' ); ?></label>
					<input id="rap_company" type="text" name="rap_company" value="<?php echo $v( 'company' ); ?>" autocomplete="organization"></p>
				<p class="rap-field rap-field--half"><label for="rap_ic"><?php esc_html_e( 'IČ', 'reserveandpay' ); ?></label>
					<input id="rap_ic" type="text" name="rap_ic" value="<?php echo $v( 'company_ic' ); ?>" inputmode="numeric"></p>
				<p class="rap-field rap-field--half"><label for="rap_dic"><?php esc_html_e( 'DIČ', 'reserveandpay' ); ?></label>
					<input id="rap_dic" type="text" name="rap_dic" value="<?php echo $v( 'company_dic' ); ?>"></p>
			</div>
			<p class="rap-field"><label for="rap_note"><?php esc_html_e( 'Poznámka pro lektorku', 'reserveandpay' ); ?></label>
				<textarea id="rap_note" name="rap_note" rows="3"><?php echo esc_textarea( $values['note'] ?? '' ); ?></textarea>
				<small><?php esc_html_e( 'Např. zdravotní omezení. Údaje vidí jen lektorka.', 'reserveandpay' ); ?></small></p>
			<p class="rap-check"><label><input type="checkbox" name="rap_gdpr" value="1" <?php checked( ! empty( $values['consent_gdpr'] ) ); ?> required> <?php echo wp_kses_post( sprintf( /* translators: %s: odkaz */ __( 'Souhlasím se %s *', 'reserveandpay' ), $link( $gdpr, __( 'zpracováním osobních údajů', 'reserveandpay' ) ) ) ); ?></label></p>
			<p class="rap-check"><label><input type="checkbox" name="rap_terms" value="1" <?php checked( ! empty( $values['consent_terms'] ) ); ?> required> <?php echo wp_kses_post( sprintf( /* translators: %s: odkaz */ __( 'Souhlasím s %s *', 'reserveandpay' ), $link( $terms, __( 'obchodními a stornopodmínkami', 'reserveandpay' ) ) ) ); ?></label></p>
			<p class="rap-check"><label><input type="checkbox" name="rap_newsletter" value="1" <?php checked( ! empty( $values['newsletter'] ) ); ?>> <?php esc_html_e( 'Chci dostávat novinky e-mailem', 'reserveandpay' ); ?></label></p>
			<?php if ( $ev['cash'] ) : ?>
			<fieldset class="rap-pay-choice">
				<legend><?php esc_html_e( 'Způsob platby', 'reserveandpay' ); ?></legend>
				<label class="rap-radio"><input type="radio" name="rap_pay" value="transfer" <?php checked( 'cash' !== ( $values['pay_choice'] ?? '' ) ); ?>> <span><strong><?php esc_html_e( 'Převodem – QR platba', 'reserveandpay' ); ?></strong> <small><?php esc_html_e( 'doporučujeme · platební údaje a QR kód obdržíte e-mailem, na místě pak nic neřešíte', 'reserveandpay' ); ?></small></span></label>
				<label class="rap-radio"><input type="radio" name="rap_pay" value="cash" <?php checked( 'cash' === ( $values['pay_choice'] ?? '' ) ); ?>> <span><strong><?php esc_html_e( 'Hotově na místě', 'reserveandpay' ); ?></strong> <small><?php esc_html_e( 'i tak dostanete e-mailem QR kód, kdybyste se rozhodli zaplatit předem', 'reserveandpay' ); ?></small></span></label>
			</fieldset>
			<p class="rap-summary"><?php echo esc_html( sprintf( /* translators: %s: cena */ __( 'Cena za jedno místo: %s.', 'reserveandpay' ), Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) ) ); ?></p>
			<?php else : ?>
			<p class="rap-summary"><?php echo esc_html( sprintf( /* translators: %s: cena */ __( 'Cena za jedno místo: %s. Platba převodem (QR platba) – platební údaje obdržíte e-mailem.', 'reserveandpay' ), Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) ) ); ?></p>
			<?php endif; ?>
			<?php echo self::coupon_field( $ev, $values ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř. ?>
			<p><button type="submit" class="rap-button"><?php esc_html_e( 'Závazně rezervovat místo →', 'reserveandpay' ); ?></button></p>
		</form>
		<?php
		echo '</section>';
		return ob_get_clean();
	}

	/** Pole „Slevový kupón“ s tlačítkem „Použít“ (skryté, platí-li sleva za včasnou rezervaci). */
	public static function coupon_field( array $ev, array $values ) {
		if ( (float) $ev['price'] <= 0 ) {
			return '';
		}
		$early = Resandpay_Discounts::early_open( $ev );
		if ( $early ) {
			/* translators: %s: popis slevy */
			$q = self::quote( $ev['id'] );
			/* translators: 1: popis slevy, 2: cena po slevě */
			$after = str_replace( ' ', "\u{00A0}", Resandpay_Events::format_price( is_wp_error( $q ) ? $ev['price'] : $q['price_full'] - $q['discount'], $ev['currency'] ) );
			/* translators: %s: popis slevy */
			return '<p class="rap-summary rap-summary--early">' . esc_html( sprintf( __( '%s – cena po slevě', 'reserveandpay' ), $early['label'] ) ) . ' <strong class="rap-early-price">' . esc_html( $after ) . '</strong><br>' . esc_html__( 'Uplatní se automaticky při platbě převodem ve splatnosti. Nelze ji kombinovat se slevovým kupónem.', 'reserveandpay' ) . '</p>';
		}
		$code = (string) ( $values['coupon'] ?? self::prefilled_coupon() );
		$uid  = wp_unique_id( 'rap-coupon-' );
		$out  = '<div class="rap-coupon" id="' . esc_attr( $uid ) . '"><p class="rap-field"><label for="' . esc_attr( $uid ) . '-in">' . esc_html__( 'Slevový kupón', 'reserveandpay' ) . '</label>';
		$out .= '<span class="rap-coupon__row"><input id="' . esc_attr( $uid ) . '-in" type="text" name="rap_coupon" value="' . esc_attr( $code ) . '" autocomplete="off" autocapitalize="characters" spellcheck="false"> <button type="button" class="rap-button rap-button--ghost" data-rap-coupon-apply>' . esc_html__( 'Použít', 'reserveandpay' ) . '</button></span>';
		$out .= '<small class="rap-coupon__msg" aria-live="polite"></small></p></div>';
		$out .= '<script>(function(){var b=document.getElementById(' . wp_json_encode( $uid ) . ');if(!b||!window.fetch)return;var f=b.closest("form"),i=b.querySelector("input"),m=b.querySelector(".rap-coupon__msg");function go(){var d=new FormData();d.append("action","resandpay_coupon");d.append("event_id",' . (int) $ev['id'] . ');d.append("code",i.value);var e=f.querySelector("[name=rap_email]");if(e)d.append("email",e.value);var p=f.querySelector("[name=rap_pay]:checked");if(p)d.append("pay",p.value);m.textContent="…";fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){m.textContent=j.message;m.className="rap-coupon__msg "+(j.ok?"is-ok":"is-err");}).catch(function(){m.textContent="";});}b.querySelector("[data-rap-coupon-apply]").addEventListener("click",go);i.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();go();}});if(i.value)go();})();</script>';
		return $out;
	}

	private static function render_confirmation( array $r, array $ev ) {
		$s = Resandpay_Settings::all();
		if ( Resandpay_Mailer::is_free( $r ) ) {
			echo '<div class="rap-success" role="status"><h2>' . esc_html__( 'Děkujeme, rezervace je potvrzena', 'reserveandpay' ) . '</h2>';
			/* translators: 1: akce, 2: e-mail */
			echo '<p>' . esc_html( sprintf( __( 'Díky slevovému kupónu je rezervace akce %1$s zdarma – nic neplatíte. Potvrzení jsme poslali na e-mail %2$s.', 'reserveandpay' ), $ev['title'] . ' (' . Resandpay_Events::format_date( $ev ) . ')', $r['email'] ) ) . '</p>';
			/* translators: %s: VS */
			echo '<p class="rap-small">' . esc_html( sprintf( __( 'Číslo rezervace (VS): %s', 'reserveandpay' ), $r['vs'] ) ) . '</p></div>';
			return;
		}
		echo '<div class="rap-success" role="status">';
		echo '<h2>' . esc_html__( 'Děkujeme, rezervace je přijata', 'reserveandpay' ) . '</h2>';
		$cash = 'cash' === ( $r['pay_choice'] ?? '' );
		if ( $cash ) {
			/* translators: 1: částka, 2: e-mail */
			echo '<p>' . esc_html( sprintf( __( 'Zvolili jste platbu hotově na místě (%1$s). Potvrzení jsme poslali na e-mail %2$s – najdete v něm i údaje pro platbu převodem, kdybyste chtěli zaplatit předem.', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ), $r['email'] ) ) . '</p>';
		} else {
			/* translators: %s: e-mail */
			echo '<p>' . esc_html( sprintf( __( 'Platební údaje jsme poslali také na e-mail %s.', 'reserveandpay' ), $r['email'] ) ) . '</p>';
		}
		$spd = Resandpay_QR::applicable( $r ) ? Resandpay_QR::spd( $r ) : '';
		echo '<div class="rap-paybox">';
		if ( $spd ) {
			echo '<figure class="rap-qr"><img src="' . esc_attr( Resandpay_QR::data_uri( $spd, 5 ) ) . '" width="180" height="180" alt="' . esc_attr__( 'QR kód pro platbu', 'reserveandpay' ) . '"><figcaption>' . esc_html__( 'Naskenujte v bankovní aplikaci', 'reserveandpay' ) . '</figcaption></figure>';
		}
		echo '<dl class="rap-pay">';
		echo '<dt>' . esc_html__( 'Akce', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['title'] . ' – ' . Resandpay_Events::format_date( $ev ) ) . '</dd>';
		if ( $s['bank_account'] ) {
			echo '<dt>' . esc_html__( 'Číslo účtu', 'reserveandpay' ) . '</dt><dd>' . esc_html( $s['bank_account'] ) . '</dd>';
		}
		$split = Resandpay_Reservations::is_split( $r ) && ! $cash;
		echo '<dt>' . esc_html( $split ? __( '1. část platby', 'reserveandpay' ) : __( 'Částka', 'reserveandpay' ) ) . '</dt><dd>' . esc_html( Resandpay_Events::format_price( $split ? Resandpay_Reservations::amount_now( $r ) : $r['amount'], $r['currency'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Variabilní symbol', 'reserveandpay' ) . '</dt><dd><strong>' . esc_html( $r['vs'] ) . '</strong></dd>';
		if ( ! $cash ) {
			echo '<dt>' . esc_html__( 'Splatnost', 'reserveandpay' ) . '</dt><dd>' . esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ) . '</dd>';
		}
		if ( Resandpay_Reservations::discount_now( $r ) > 0 ) {
			echo '<dt>' . esc_html__( 'Cena', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Events::format_price( $r['price_full'], $r['currency'] ) ) . '</dd>';
			echo '<dt class="rap-is-discount">' . esc_html( $r['discount_label'] ) . '</dt><dd class="rap-is-discount">−' . esc_html( Resandpay_Events::format_price( $r['discount_amount'], $r['currency'] ) ) . ( Resandpay_Reservations::early_active( $r ) ? ' <small>' . esc_html__( '(platí při úhradě ve splatnosti)', 'reserveandpay' ) . '</small>' : '' ) . '</dd>';
			echo '<dt class="rap-is-discount">' . esc_html__( 'Cena po slevě', 'reserveandpay' ) . '</dt><dd class="rap-is-discount rap-early-price">' . esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) . '</dd>';
		}
		if ( $split ) {
			/* translators: 1: doplatek, 2: datum */
			echo '<dt>' . esc_html__( 'Doplatek', 'reserveandpay' ) . '</dt><dd>' . esc_html( sprintf( __( '%1$s do %2$s', 'reserveandpay' ), Resandpay_Events::format_price( (float) $r['amount'] - (float) $r['part1_amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( $r['due_at'] ) ) ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Celkem', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) . '</dd>';
		}
		echo '</dl>';
		$spd2 = $split ? Resandpay_QR::spd_full( $r ) : '';
		if ( $spd2 ) {
			/* translators: 1: celá částka, 2: splatnost */
			echo '<div class="rap-qr-full"><img src="' . esc_attr( Resandpay_QR::data_uri( $spd2, 4 ) ) . '" width="120" height="120" alt="' . esc_attr__( 'QR kód – vše najednou', 'reserveandpay' ) . '"><p><strong>' . esc_html__( 'Nebo zaplaťte vše najednou', 'reserveandpay' ) . '</strong><br>' . esc_html( sprintf( __( '%1$s do %2$s – stejný variabilní symbol', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount'], $r['currency'] ), wp_date( 'j. n. Y', Resandpay_Events::ts( Resandpay_Reservations::due_now( $r ) ) ) ) ) . '</p></div>';
		}
		echo '</div>';
		echo '<p><a class="rap-button" href="' . esc_url( Resandpay_PDF::url( $r ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Stáhnout platební pokyn (PDF) →', 'reserveandpay' ) . '</a></p>';
		if ( $cash ) {
			echo '<p class="rap-small">' . esc_html__( 'Variabilní symbol mějte prosím u sebe i na místě – podle něj Vás rychle odbavíme.', 'reserveandpay' ) . '</p>';
		} elseif ( $r['last_minute'] ) {
			echo '<p>' . esc_html__( 'Rezervujete krátce před začátkem. Pokud platba nestihne dorazit, můžete zaplatit na místě.', 'reserveandpay' ) . '</p>';
		}
		if ( Resandpay_Events::free_places( $ev['id'] ) > 0 ) {
			$again = add_query_arg( 'rap_again', rawurlencode( $r['token'] ), get_permalink( $ev['id'] ) ) . '#rap-booking';
			echo '<p><a class="rap-button rap-button--ghost" href="' . esc_url( $again ) . '">' . esc_html__( 'Rezervovat další místo →', 'reserveandpay' ) . '</a></p>';
			echo '<p class="rap-small">' . esc_html__( 'Každé místo je samostatná rezervace s vlastním variabilním symbolem.', 'reserveandpay' ) . '</p>';
		}
		echo '</div>';
	}
}
