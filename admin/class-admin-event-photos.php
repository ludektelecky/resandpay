<?php
defined( 'ABSPATH' ) || exit;

/**
 * Editor akce – záložka „Fotky z akce“ (#45): kdo fotky uvidí, nahrání (v prohlížeči se fotky
 * zmenší a zbaví údajů o poloze), pořadí, odebrání a e-mail účastníkům.
 */
class Resandpay_Admin_Event_Photos {

	public static function init() {
		add_action( 'wp_ajax_resandpay_photo_upload', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'admin_post_resandpay_photos_notify', array( __CLASS__, 'handle_notify' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Resandpay_Post_Types::EVENT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'resandpay-event-photos-admin', RESANDPAY_URL . 'assets/js/admin-event-photos.js', array(), RESANDPAY_VERSION, true );
		wp_localize_script(
			'resandpay-event-photos-admin',
			'rapPh',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'resandpay_photo_upload' ),
				'max'   => Resandpay_Event_Photos::MAX_SIZE,
				'i18n'  => array(
					'uploading' => __( 'Nahrávám fotku', 'reserveandpay' ),
					'done'      => __( 'Hotovo. Pořadí a odebrání fotek se uloží s akcí.', 'reserveandpay' ),
					'error'     => __( 'Chyba nahrávání', 'reserveandpay' ),
					'remove'    => __( 'Odebrat fotku', 'reserveandpay' ),
					'confirm'   => __( 'Poslat účastníkům e-mail s odkazem na fotky?', 'reserveandpay' ),
				),
			)
		);
	}

	/** Náhled fotky v administraci (správce akce fotky vidí vždy). */
	private static function item( $event_id, array $p ) {
		$src = Resandpay_Event_Photos::url( $event_id, $p['id'], 't', get_current_user_id() );
		return '<li class="rap-ph-admin__item" draggable="true" data-id="' . esc_attr( $p['id'] ) . '"><img src="' . esc_url( $src ) . '" alt="" loading="lazy">'
			. '<button type="button" class="rap-ph-admin__remove" aria-label="' . esc_attr__( 'Odebrat fotku', 'reserveandpay' ) . '">×</button></li>';
	}

	/** Obsah záložky v rámečku Parametry akce. */
	public static function panel( $post ) {
		$ev     = Resandpay_Events::get( $post->ID );
		$photos = Resandpay_Event_Photos::photos( $post->ID );
		$vis    = Resandpay_Event_Photos::visibility( $post->ID );
		$out    = '<div' . Resandpay_Admin::tab( 'fotky' ) . ' class="rap-ph-admin">';
		$out   .= '<table class="form-table" role="presentation">';
		$out   .= '<tr><th><label for="rap_photos_vis">' . esc_html__( 'Kdo fotky uvidí', 'reserveandpay' ) . '</label></th><td><select id="rap_photos_vis" name="rap_photos_vis">';
		foreach ( Resandpay_Event_Photos::visibility_labels() as $k => $label ) {
			$out .= '<option value="' . esc_attr( $k ) . '"' . selected( $vis, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select><p class="description">' . esc_html__( 'Účastník = zákazník se zaplacenou rezervací (i zaplacenou na místě). Prokáže se přihlášením k účtu se stejným e-mailem, nebo osobním odkazem z e-mailu.', 'reserveandpay' ) . '</p></td></tr>';
		$out .= '<tr><th>' . esc_html__( 'Fotky', 'reserveandpay' ) . '</th><td>';
		$out .= '<input type="hidden" name="rap_photos_order" id="rap_photos_order" value="' . esc_attr( implode( ',', wp_list_pluck( $photos, 'id' ) ) ) . '">';
		$out .= '<ul class="rap-ph-admin__list" id="rap-ph-list">';
		foreach ( $photos as $p ) {
			$out .= self::item( $post->ID, $p );
		}
		$out .= '</ul>';
		$out .= '<p><label class="button" for="rap-ph-upload">' . esc_html__( 'Nahrát fotky…', 'reserveandpay' ) . '</label> <input type="file" id="rap-ph-upload" class="screen-reader-text" accept="image/jpeg,image/png,image/webp" multiple data-post="' . (int) $post->ID . '"> <span id="rap-ph-count">' . esc_html( sprintf( /* translators: %d: počet */ __( 'Fotek: %d', 'reserveandpay' ), count( $photos ) ) ) . '</span></p>';
		$out .= '<p id="rap-ph-progress" class="description" aria-live="polite"></p>';
		$out .= '<p class="description">' . esc_html__( 'Fotky se zobrazí na stránce akce po jejím skončení – místo rezervačního formuláře, přes celou šířku pod popisem akce. Před nahráním se zmenší (delší strana 2048 px) a odstraní se z nich údaje o poloze (GPS). Ukládají se mimo Knihovnu médií, do chráněné složky. Pořadí změníte přetažením.', 'reserveandpay' ) . '</p>';
		$out .= '</td></tr>';
		$out .= '<tr><th>' . esc_html__( 'E-mail účastníkům', 'reserveandpay' ) . '</th><td>';
		$n    = count( Resandpay_Event_Photos::attendees( $post->ID ) );
		$sent = get_post_meta( $post->ID, '_rap_photos_notified', true );
		if ( $ev && ! Resandpay_Event_Photos::is_over( $ev ) ) {
			$out .= '<p class="description">' . esc_html__( 'Tlačítko bude k dispozici po skončení akce.', 'reserveandpay' ) . '</p>';
		} elseif ( ! $photos || ! $n ) {
			$out .= '<p class="description">' . esc_html( $photos ? __( 'Akce nemá žádné účastníky se zaplacenou rezervací.', 'reserveandpay' ) : __( 'Nejdřív nahrajte fotky a akci uložte.', 'reserveandpay' ) ) . '</p>';
		} else {
			$url  = wp_nonce_url( admin_url( 'admin-post.php?action=resandpay_photos_notify&post=' . (int) $post->ID ), 'resandpay_photos_notify_' . (int) $post->ID );
			/* translators: %d: počet účastníků */
			$out .= '<a class="button" id="rap-ph-notify" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Poslat účastníkům e-mail, že jsou fotky k dispozici (%d)', 'reserveandpay' ), $n ) ) . '</a>';
			$out .= '<p class="description">' . esc_html__( 'Každý účastník dostane osobní odkaz na fotky (bez přihlášení). Text e-mailu: Nastavení → E-maily → Fotky z akce. Neuložené změny akce nejdřív uložte.', 'reserveandpay' ) . '</p>';
		}
		if ( is_array( $sent ) && ! empty( $sent['at'] ) ) {
			/* translators: 1: datum a čas, 2: počet */
			$out .= '<p class="description">' . esc_html( sprintf( __( 'Naposledy odesláno %1$s (%2$d e-mailů).', 'reserveandpay' ), wp_date( 'j. n. Y G:i', (int) $sent['at'] ), (int) $sent['count'] ) ) . '</p>';
		}
		$out .= '</td></tr></table></div>';
		return $out;
	}

	public static function ajax_upload() {
		check_ajax_referer( 'resandpay_photo_upload', 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || Resandpay_Post_Types::EVENT !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemáte oprávnění.', 'reserveandpay' ) ), 403 );
		}
		if ( empty( $_FILES['photo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['photo']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'Fotka nedorazila (limit velikosti na hostingu?).', 'reserveandpay' ) ), 400 );
		}
		$res = Resandpay_Event_Photos::add( $post_id, $_FILES['photo']['tmp_name'], sanitize_file_name( wp_unslash( $_FILES['photo']['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'id'   => $res['id'],
				'html' => self::item( $post_id, $res ),
			)
		);
	}

	public static function handle_notify() {
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'resandpay_photos_notify_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		$res = Resandpay_Event_Photos::notify( $post_id );
		if ( is_wp_error( $res ) ) {
			Resandpay_Admin::flash( $res->get_error_message(), 'error' );
		} else {
			/* translators: %d: počet e-mailů */
			Resandpay_Admin::flash( sprintf( __( 'E-mail s odkazem na fotky odeslán účastníkům: %d.', 'reserveandpay' ), $res ) );
		}
		wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
		exit;
	}
}
