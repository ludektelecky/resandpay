<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace napojení na MailerLite (Issue #22): nástroje v Nastavení → Napojení,
 * boční panel „MailerLite“ u akce, služby a materiálu.
 */
class Resandpay_Admin_MailerLite {

	public static function init() {
		add_action( 'admin_post_resandpay_ml', array( __CLASS__, 'handle' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		foreach ( self::post_types() as $pt ) {
			add_action( 'save_post_' . $pt, array( __CLASS__, 'save_post' ), 20 );
		}
	}

	private static function post_types() {
		return array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::SERVICE, Resandpay_Post_Types::MATERIAL );
	}

	public static function action_url( $do ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'resandpay_ml', 'do' => $do ), admin_url( 'admin-post.php' ) ), 'resandpay_ml_' . $do );
	}

	/** Zaškrtávací seznam skupin z účtu (uložená, ale už neexistující skupina zůstane vidět). */
	public static function groups_checkboxes( $name, array $selected ) {
		$groups = Resandpay_MailerLite::groups();
		foreach ( $selected as $id ) {
			if ( ! isset( $groups[ $id ] ) ) {
				/* translators: %s: id skupiny */
				$groups[ $id ] = sprintf( __( 'skupina %s (v účtu nenalezena)', 'reserveandpay' ), $id );
			}
		}
		$out = '<input type="hidden" name="' . esc_attr( $name ) . '" value="">';
		if ( ! $groups ) {
			return $out . '<p class="description">' . esc_html__( 'Skupiny zatím nejsou načtené – uložte API klíč a klikněte na „Ověřit připojení a načíst skupiny“.', 'reserveandpay' ) . '</p>';
		}
		$out .= '<div class="rap-ml-groups" style="max-height:14em;overflow:auto">';
		foreach ( $groups as $id => $label ) {
			$out .= '<label style="display:block"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $id ) . '" ' . checked( in_array( (string) $id, $selected, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		return $out . '</div>';
	}

	/** Stav a tlačítka pod nastavením MailerLite. */
	public static function settings_tools() {
		global $wpdb;
		$cache = get_option( Resandpay_MailerLite::GROUPS_OPT, array() );
		$out   = '<div class="rap-ml-tools"><p>';
		if ( '' === Resandpay_MailerLite::api_key() ) {
			$out .= esc_html__( 'API klíč není uložený.', 'reserveandpay' );
		} else {
			/* translators: 1: počet skupin, 2: datum */
			$out .= esc_html( ! empty( $cache['at'] ) ? sprintf( __( 'Načteno skupin: %1$d (%2$s).', 'reserveandpay' ), count( Resandpay_MailerLite::groups() ), wp_date( 'j. n. Y G:i', (int) $cache['at'] ) ) : __( 'Skupiny zatím nejsou načtené.', 'reserveandpay' ) );
		}
		$t      = Resandpay_Reservations::table();
		$counts = array();
		foreach ( $wpdb->get_results( "SELECT ml_status, COUNT(*) AS c FROM {$t} WHERE ml_status <> '' GROUP BY ml_status", ARRAY_A ) as $row ) {
			$counts[ $row['ml_status'] ] = (int) $row['c'];
		}
		if ( $counts ) {
			$parts = array();
			foreach ( Resandpay_MailerLite::status_labels() as $k => $l ) {
				if ( ! empty( $counts[ $k ] ) ) {
					$parts[] = $l . ' ' . $counts[ $k ];
				}
			}
			$out .= ' ' . esc_html__( 'Rezervace:', 'reserveandpay' ) . ' ' . esc_html( implode( ' · ', $parts ) ) . '.';
		}
		$out .= '</p><p>';
		$out .= '<a class="button" href="' . esc_url( self::action_url( 'verify' ) ) . '">' . esc_html__( 'Ověřit připojení a načíst skupiny', 'reserveandpay' ) . '</a> ';
		if ( Resandpay_MailerLite::enabled() ) {
			$n = count( Resandpay_MailerLite::import_candidates() );
			/* translators: %d: počet zákazníků */
			$out .= '<a class="button" href="' . esc_url( self::action_url( 'import' ) ) . '" onclick="return confirm(\'' . esc_js( sprintf( __( 'Zařadit %d stávajících zákazníků se souhlasem s novinkami k odeslání do MailerLite?', 'reserveandpay' ), $n ) ) . '\')"' . ( $n ? '' : ' aria-disabled="true"' ) . '>' . esc_html( sprintf( __( 'Importovat stávající zákazníky (%d)', 'reserveandpay' ), $n ) ) . '</a> ';
			if ( ! empty( $counts[ Resandpay_MailerLite::PENDING ] ) ) {
				$out .= '<a class="button" href="' . esc_url( self::action_url( 'process' ) ) . '">' . esc_html__( 'Odeslat čekající teď', 'reserveandpay' ) . '</a>';
			}
		}
		$out .= '</p><p class="description">' . esc_html__( 'Import: zákazníci, jejichž poslední rezervace má souhlas s novinkami a kteří ještě nebyli odesláni; zařadí se podle své poslední rezervace se souhlasem (při „Po zaplacení“ poslední zaplacené). Odesílá se na pozadí po dávkách 50. Tlačítka neukládají ostatní změny nastavení.', 'reserveandpay' ) . '</p></div>';
		return $out;
	}

	/** Akce z nastavení: verify, import, process. */
	public static function handle() {
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- ověřeno níže.
		if ( ! current_user_can( resandpay_cap_settings() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_ml_' . $do );
		$msg = self::run( $do );
		Resandpay_Admin::flash( $msg[0], $msg[1] );
		wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-settings&tab=napojeni#rap-sec-mailerlite' ) );
		exit;
	}

	/** Provede akci z nastavení. @return array [zpráva, typ] */
	public static function run( $do ) {
		switch ( $do ) {
			case 'verify':
				$g = Resandpay_MailerLite::refresh_groups();
				if ( is_wp_error( $g ) ) {
					return array( $g->get_error_message(), 'error' );
				}
				/* translators: %d: počet skupin */
				return array( sprintf( __( 'Připojení k MailerLite funguje. Načteno skupin: %d.', 'reserveandpay' ), count( $g ) ), 'success' );
			case 'import':
				$n = Resandpay_MailerLite::import_existing();
				/* translators: %d: počet zákazníků */
				return array( sprintf( __( 'K odeslání do MailerLite zařazeno zákazníků: %d. Odesílá se na pozadí po dávkách.', 'reserveandpay' ), $n ), 'success' );
			case 'process':
				$r = Resandpay_MailerLite::process_queue();
				/* translators: 1: odesláno, 2: chyby, 3: neodesláno, 4: zbývá */
				return array( sprintf( __( 'MailerLite: odesláno %1$d, chyby %2$d, neodesláno %3$d, ve frontě zbývá %4$d.', 'reserveandpay' ), $r['sent'], $r['failed'], $r['skipped'], $r['left'] ), $r['failed'] ? 'warning' : 'success' );
		}
		return array( __( 'Neznámá akce.', 'reserveandpay' ), 'error' );
	}

	/* ------------------------------------------------ Boční panel u položky */

	public static function meta_boxes() {
		if ( ! Resandpay_MailerLite::enabled() ) {
			return;
		}
		foreach ( self::post_types() as $pt ) {
			add_meta_box( 'rap_mailerlite', __( 'MailerLite', 'reserveandpay' ), array( __CLASS__, 'box' ), $pt, 'side', 'default' );
		}
	}

	public static function box( $post ) {
		$s   = Resandpay_MailerLite::item_setting( $post->ID );
		$svc = (int) get_post_meta( $post->ID, '_rap_service', true );
		wp_nonce_field( 'rap_ml_item', 'rap_ml_nonce' );
		$default = $svc
			/* translators: %s: popis nastavení služby */
			? sprintf( __( 'ze služby (%s)', 'reserveandpay' ), self::describe( Resandpay_MailerLite::item_setting( $svc ), true ) )
			/* translators: %s: názvy skupin */
			: sprintf( __( 'výchozí (%s)', 'reserveandpay' ), self::describe( array( 'mode' => 'default' ), false ) );
		echo '<p><label><input type="radio" name="rap_ml_mode" value="default" ' . checked( $s['mode'], 'default', false ) . '> ' . esc_html( $default ) . '</label><br>';
		echo '<label><input type="radio" name="rap_ml_mode" value="custom" ' . checked( $s['mode'], 'custom', false ) . '> ' . esc_html__( 'vybrané skupiny:', 'reserveandpay' ) . '</label></p>';
		echo self::groups_checkboxes( 'rap_ml_groups[]', $s['groups'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
		echo '<p><label><input type="radio" name="rap_ml_mode" value="none" ' . checked( $s['mode'], 'none', false ) . '> ' . esc_html__( 'neposílat do MailerLite', 'reserveandpay' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Zákazníci se souhlasem s novinkami se přidají do zvolených skupin.', 'reserveandpay' ) . '</p>';
	}

	/** Popis nastavení pro panel („Skupina A, Skupina B“ / „neposílat“ / „bez skupiny“). */
	public static function describe( array $s, $resolve_default ) {
		if ( 'none' === $s['mode'] ) {
			return __( 'neposílat', 'reserveandpay' );
		}
		$ids = 'custom' === $s['mode'] ? $s['groups'] : Resandpay_MailerLite::parse_ids( Resandpay_Settings::get( 'ml_groups' ) );
		if ( 'default' === $s['mode'] && $resolve_default ) {
			/* translators: %s: názvy skupin */
			return sprintf( __( 'výchozí: %s', 'reserveandpay' ), $ids ? implode( ', ', Resandpay_MailerLite::group_names( $ids ) ) : __( 'bez skupiny', 'reserveandpay' ) );
		}
		return $ids ? implode( ', ', Resandpay_MailerLite::group_names( $ids ) ) : __( 'bez skupiny', 'reserveandpay' );
	}

	public static function save_post( $post_id ) {
		if ( ! isset( $_POST['rap_ml_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rap_ml_nonce'] ), 'rap_ml_item' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		Resandpay_MailerLite::save_item_setting( $post_id, sanitize_key( wp_unslash( $_POST['rap_ml_mode'] ?? 'default' ) ), array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['rap_ml_groups'] ?? array() ) ) );
	}

	/** Řádek „MailerLite“ v detailu rezervace (HTML). */
	public static function detail_row( array $r ) {
		$labels = Resandpay_MailerLite::status_labels();
		$st     = (string) ( $r['ml_status'] ?? '' );
		if ( '' === $st ) {
			$out = esc_html( empty( $r['newsletter'] ) ? __( 'bez souhlasu s novinkami – neposílá se', 'reserveandpay' ) : __( 'zatím neodesláno', 'reserveandpay' ) );
		} else {
			$out = '<span class="rap-ml rap-ml--' . esc_attr( $st ) . '">' . esc_html( $labels[ $st ] ?? $st ) . '</span>';
			if ( Resandpay_MailerLite::SENT === $st ) {
				$ids  = Resandpay_MailerLite::parse_ids( $r['ml_groups'] );
				$out .= ' · ' . esc_html( $ids ? implode( ', ', Resandpay_MailerLite::group_names( $ids ) ) : __( 'bez skupiny', 'reserveandpay' ) );
			}
			if ( '' !== (string) $r['ml_error'] ) {
				$out .= ' · <span class="' . ( Resandpay_MailerLite::SKIPPED === $st ? '' : 'rap-err' ) . '">' . esc_html( $r['ml_error'] ) . '</span>';
			}
			if ( $r['ml_at'] ) {
				$out .= ' · ' . esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $r['ml_at'] ) ) );
			}
		}
		if ( Resandpay_MailerLite::enabled() && ! empty( $r['newsletter'] ) && Resandpay_MailerLite::PENDING !== $st ) {
			$out .= ' · <a href="' . esc_url( Resandpay_Admin_Reservations::action_url( 'ml_resend', $r['id'] ) ) . '">' . esc_html( '' === $st ? __( 'Odeslat teď', 'reserveandpay' ) : __( 'Odeslat znovu', 'reserveandpay' ) ) . '</a>';
		}
		return $out;
	}
}
