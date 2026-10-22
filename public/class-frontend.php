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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_archives' ), 5 );
	}

	public static function assets() {
		wp_register_style( 'reserveandpay', RESANDPAY_URL . 'assets/css/frontend.css', array(), RESANDPAY_VERSION );
		$post = get_post();
		$has  = $post && is_singular() && preg_match( '/\[reserveandpay_/', (string) $post->post_content );
		if ( $has || is_singular( Resandpay_Post_Types::EVENT ) || is_post_type_archive( Resandpay_Post_Types::EVENT ) ) {
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
		);
		$errors   = array();

		if ( ! isset( $_POST['_rapnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rapnonce'] ) ), 'resandpay_book_' . $event_id ) ) {
			$errors[] = __( 'Platnost formuláře vypršela. Načtěte prosím stránku znovu a odešlete formulář ještě jednou.', 'reserveandpay' );
		}
		if ( ! empty( $_POST['rap_website'] ) ) { // Honeypot proti robotům.
			$errors[] = __( 'Formulář se nepodařilo odeslat.', 'reserveandpay' );
		}
		$ev = Resandpay_Events::get( $event_id );
		if ( $ev && $ev['members_only'] && ! is_user_logged_in() ) {
			$errors[] = __( 'Tato akce je jen pro přihlášené uživatele.', 'reserveandpay' );
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

		if ( ! $errors ) {
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
		return self::render_details( $id ) . $content . self::render_booking( $id );
	}

	public static function shortcode_booking( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'reserveandpay_booking' );
		$id   = absint( $atts['id'] ) ?: get_the_ID();
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
				'service' => '',
				'days'    => 21,
			),
			$atts,
			'reserveandpay_slots'
		);
		wp_enqueue_style( 'reserveandpay' );
		return Resandpay_Views::render_slots( $a );
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
		echo '<dt>' . esc_html__( 'Cena', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) ) . ( 'multiday' === $ev['type'] ? ' <small>' . esc_html__( '(za celou akci)', 'reserveandpay' ) . '</small>' : '' ) . '</dd>';
		if ( 'slot' === $ev['type'] && $ev['capacity'] <= 1 ) {
			echo '<dt>' . esc_html__( 'Termín', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['cancelled'] ? __( 'zrušen', 'reserveandpay' ) : ( $free > 0 ? __( 'volný', 'reserveandpay' ) : __( 'obsazený', 'reserveandpay' ) ) ) . '</dd>';
		} else {
			echo '<dt>' . esc_html__( 'Volná místa', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['cancelled'] ? __( 'akce zrušena', 'reserveandpay' ) : ( $free > 0 ? sprintf( /* translators: 1: volno, 2: kapacita */ __( '%1$d z %2$d', 'reserveandpay' ), $free, $ev['capacity'] ) : __( 'obsazeno', 'reserveandpay' ) ) ) . '</dd>';
		}
		echo '</dl>';
		if ( ! $ev['cancelled'] && $free > 0 && ! is_wp_error( Resandpay_Events::check_bookable( $event_id ) ) ) {
			echo '<p class="rap-details__cta"><a class="rap-button" href="#rap-booking">' . esc_html__( 'Rezervovat termín →', 'reserveandpay' ) . '</a></p>';
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

	public static function render_booking( $event_id ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev ) {
			return '';
		}
		ob_start();
		echo '<section id="rap-booking" class="rap-booking">';

		// Potvrzení po úspěšné rezervaci.
		$done = isset( $_GET['rap_done'] ) ? Resandpay_Reservations::get_by_token( sanitize_text_field( wp_unslash( $_GET['rap_done'] ) ) ) : null;
		if ( $done && (int) $done['event_id'] === (int) $event_id ) {
			self::render_confirmation( $done, $ev );
			echo '</section>';
			return ob_get_clean();
		}

		echo '<h2 class="rap-booking__title">' . esc_html__( 'Rezervace', 'reserveandpay' ) . '</h2>';

		$bookable = Resandpay_Events::check_bookable( $event_id );
		if ( is_wp_error( $bookable ) ) {
			echo '<p class="rap-notice">' . esc_html( $bookable->get_error_message() ) . '</p></section>';
			return ob_get_clean();
		}
		if ( Resandpay_Events::free_places( $event_id ) < 1 ) {
			echo '<p class="rap-notice">' . esc_html__( 'Akce je bohužel již obsazena.', 'reserveandpay' ) . '</p></section>';
			return ob_get_clean();
		}
		if ( $ev['members_only'] && ! is_user_logged_in() ) {
			echo '<p class="rap-notice">' . esc_html__( 'Rezervace na tuto akci je jen pro přihlášené uživatele.', 'reserveandpay' ) . ' <a href="' . esc_url( wp_login_url( get_permalink( $event_id ) ) ) . '">' . esc_html__( 'Přihlásit se', 'reserveandpay' ) . '</a></p></section>';
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
			<p class="rap-summary"><?php echo esc_html( sprintf( /* translators: %s: cena */ __( 'Cena za jedno místo: %s. Platba převodem (QR platba) – platební údaje obdržíte e-mailem.', 'reserveandpay' ), Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) ) ); ?></p>
			<p><button type="submit" class="rap-button"><?php esc_html_e( 'Závazně rezervovat místo →', 'reserveandpay' ); ?></button></p>
		</form>
		<?php
		echo '</section>';
		return ob_get_clean();
	}

	private static function render_confirmation( array $r, array $ev ) {
		$s = Resandpay_Settings::all();
		echo '<div class="rap-success" role="status">';
		echo '<h2>' . esc_html__( 'Děkujeme, rezervace je přijata', 'reserveandpay' ) . '</h2>';
		/* translators: %s: e-mail */
		echo '<p>' . esc_html( sprintf( __( 'Platební údaje jsme poslali také na e-mail %s.', 'reserveandpay' ), $r['email'] ) ) . '</p>';
		echo '<dl class="rap-pay">';
		echo '<dt>' . esc_html__( 'Akce', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev['title'] . ' – ' . Resandpay_Events::format_date( $ev ) ) . '</dd>';
		if ( $s['bank_account'] ) {
			echo '<dt>' . esc_html__( 'Číslo účtu', 'reserveandpay' ) . '</dt><dd>' . esc_html( $s['bank_account'] ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'Částka', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Variabilní symbol', 'reserveandpay' ) . '</dt><dd><strong>' . esc_html( $r['vs'] ) . '</strong></dd>';
		echo '<dt>' . esc_html__( 'Splatnost', 'reserveandpay' ) . '</dt><dd>' . esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $r['due_at'] ) ) ) . '</dd>';
		echo '</dl>';
		if ( $r['last_minute'] ) {
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
