<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace akcí a lokací: metaboxy, ukládání, sloupce, upozornění na překryvy.
 */
class Resandpay_Admin_Events {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Resandpay_Post_Types::EVENT, array( __CLASS__, 'save_event' ), 10, 2 );
		add_action( 'save_post_' . Resandpay_Post_Types::LOCATION, array( __CLASS__, 'save_location' ), 10, 2 );
		add_filter( 'manage_' . Resandpay_Post_Types::EVENT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Resandpay_Post_Types::EVENT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . Resandpay_Post_Types::EVENT . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'overlap_notice' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_resandpay_duplicate', array( __CLASS__, 'handle_duplicate' ) );
	}

	public static function meta_boxes() {
		add_meta_box( 'rap_event', __( 'Termín, kapacita a cena', 'reserveandpay' ), array( __CLASS__, 'box_event' ), Resandpay_Post_Types::EVENT, 'side', 'high' );
		add_meta_box( 'rap_occupancy', __( 'Obsazenost', 'reserveandpay' ), array( __CLASS__, 'box_occupancy' ), Resandpay_Post_Types::EVENT, 'side', 'default' );
		add_meta_box( 'rap_program_box', __( 'Program po dnech (vícedenní akce)', 'reserveandpay' ), array( __CLASS__, 'box_program' ), Resandpay_Post_Types::EVENT, 'normal', 'default' );
		add_meta_box( 'rap_location', __( 'Adresa lokace', 'reserveandpay' ), array( __CLASS__, 'box_location' ), Resandpay_Post_Types::LOCATION, 'normal', 'high' );
	}

	private static function dt_input( $local ) {
		if ( ! $local ) {
			return '';
		}
		$ts = Resandpay_Events::ts( $local );
		return $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
	}

	/** Chybějící povinné údaje akce (pro upozornění v editoru a v seznamu). */
	public static function missing( array $ev ) {
		$m = array();
		if ( ! $ev['start'] ) {
			$m[] = __( 'začátek', 'reserveandpay' );
		}
		if ( ! $ev['end'] ) {
			$m[] = __( 'konec', 'reserveandpay' );
		}
		if ( $ev['capacity'] < 1 ) {
			$m[] = __( 'kapacita', 'reserveandpay' );
		}
		return $m;
	}

	public static function box_event( $post ) {
		wp_nonce_field( 'rap_save_event', '_rap_event_nonce' );
		$ev        = Resandpay_Events::get( $post->ID );
		$locations = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::LOCATION,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$raw_due   = get_post_meta( $post->ID, '_rap_due_days', true );
		$missing   = 'auto-draft' === $post->post_status ? array() : self::missing( $ev );
		if ( $missing ) {
			echo '<p class="rap-missing">' . esc_html( sprintf( /* translators: %s: seznam polí */ __( 'Doplňte: %s – bez nich nelze rezervovat.', 'reserveandpay' ), implode( ', ', $missing ) ) ) . '</p>';
		}
		?>
		<div class="rap-box">
			<p><label for="rap_type"><?php esc_html_e( 'Typ akce', 'reserveandpay' ); ?></label>
				<select id="rap_type" name="rap_type">
					<?php foreach ( Resandpay_Events::type_labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ev['type'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></p>
			<p><label for="rap_start"><?php esc_html_e( 'Začátek', 'reserveandpay' ); ?> *</label>
				<input type="datetime-local" id="rap_start" name="rap_start" value="<?php echo esc_attr( self::dt_input( $ev['start'] ) ); ?>"></p>
			<p><label for="rap_end"><?php esc_html_e( 'Konec', 'reserveandpay' ); ?> *</label>
				<input type="datetime-local" id="rap_end" name="rap_end" value="<?php echo esc_attr( self::dt_input( $ev['end'] ) ); ?>">
				<span class="rap-hint" id="rap_end_hint"></span></p>
			<p><label for="rap_location"><?php esc_html_e( 'Místo konání', 'reserveandpay' ); ?></label>
				<select id="rap_location" name="rap_location">
					<option value="0">— <?php esc_html_e( 'nevybráno', 'reserveandpay' ); ?> —</option>
					<?php foreach ( $locations as $loc ) : ?>
						<option value="<?php echo (int) $loc->ID; ?>" <?php selected( $ev['location_id'], $loc->ID ); ?>><?php echo esc_html( $loc->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<a class="rap-hint" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Resandpay_Post_Types::LOCATION ) ); ?>" target="_blank"><?php esc_html_e( '+ nové místo', 'reserveandpay' ); ?></a></p>
			<div class="rap-row">
				<p id="rap_capacity_wrap"><label for="rap_capacity"><?php esc_html_e( 'Kapacita', 'reserveandpay' ); ?> *</label>
					<input type="number" min="1" step="1" id="rap_capacity" name="rap_capacity" value="<?php echo esc_attr( $ev['capacity'] ?: '' ); ?>"></p>
				<p><label for="rap_price"><?php esc_html_e( 'Cena za místo', 'reserveandpay' ); ?></label>
					<input type="number" min="0" step="any" id="rap_price" name="rap_price" value="<?php echo esc_attr( $ev['price'] ); ?>"></p>
				<p class="rap-row__cur"><label for="rap_currency"><?php esc_html_e( 'Měna', 'reserveandpay' ); ?></label>
					<input type="text" id="rap_currency" name="rap_currency" value="<?php echo esc_attr( $ev['currency'] ); ?>" maxlength="3"></p>
			</div>
			<details class="rap-more" <?php echo ( '' !== $raw_due || $ev['close_hours'] || $ev['members_only'] || $ev['cancelled'] ) ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Další nastavení', 'reserveandpay' ); ?></summary>
				<p><label for="rap_due_days"><?php esc_html_e( 'Splatnost (dní)', 'reserveandpay' ); ?></label>
					<input type="number" min="0" step="1" id="rap_due_days" name="rap_due_days" value="<?php echo esc_attr( $raw_due ); ?>" placeholder="<?php echo esc_attr( Resandpay_Settings::get( 'due_days' ) ); ?>">
					<span class="rap-hint"><?php esc_html_e( 'Prázdné = výchozí z nastavení; nikdy po začátku akce.', 'reserveandpay' ); ?></span></p>
				<p><label for="rap_close_hours"><?php esc_html_e( 'Uzávěrka rezervací (hodin před začátkem)', 'reserveandpay' ); ?></label>
					<input type="number" min="0" step="0.5" id="rap_close_hours" name="rap_close_hours" value="<?php echo esc_attr( $ev['close_hours'] ?: '' ); ?>" placeholder="0">
					<span class="rap-hint"><?php esc_html_e( 'Prázdné = rezervovat lze až do začátku.', 'reserveandpay' ); ?></span></p>
				<p><label class="rap-inline"><input type="checkbox" name="rap_members_only" value="1" <?php checked( $ev['members_only'] ); ?>> <?php esc_html_e( 'Jen pro přihlášené uživatele', 'reserveandpay' ); ?></label>
					<label class="rap-inline"><input type="checkbox" name="rap_cancelled" value="1" <?php checked( $ev['cancelled'] ); ?>> <?php esc_html_e( 'Akce je zrušena', 'reserveandpay' ); ?></label></p>
			</details>
		</div>
		<?php
		$overlaps = $ev['start'] ? Resandpay_Events::overlaps( $post->ID ) : array();
		if ( $overlaps ) {
			echo '<div class="rap-overlap"><strong>' . esc_html__( 'Překrývá se s:', 'reserveandpay' ) . '</strong><ul>';
			foreach ( $overlaps as $o ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $o['id'] ) ) . '">' . esc_html( $o['title'] ) . '</a> – ' . esc_html( Resandpay_Events::format_date( $o ) ) . '</li>';
			}
			echo '</ul><span class="rap-hint">' . esc_html__( 'Překrývání je povoleno, jde jen o informaci.', 'reserveandpay' ) . '</span></div>';
		}
		?>
		<script>
		( function () {
			var type = document.getElementById( 'rap_type' ), s = document.getElementById( 'rap_start' ), e = document.getElementById( 'rap_end' ),
				cap = document.getElementById( 'rap_capacity' ), hint = document.getElementById( 'rap_end_hint' );
			function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
			function fmt( d ) { return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) + 'T' + pad( d.getHours() ) + ':' + pad( d.getMinutes() ); }
			function onType() {
				var slot = type.value === 'slot';
				if ( slot ) { cap.value = 1; }
				cap.readOnly = slot;
			}
			s.addEventListener( 'change', function () {
				if ( ! s.value ) { return; }
				var start = new Date( s.value ), end = e.value ? new Date( e.value ) : null;
				if ( ! end || end <= start ) {
					var add = type.value === 'multiday' ? 24 * 3 : ( type.value === 'single' ? 7 : 2 );
					e.value = fmt( new Date( start.getTime() + add * 3600000 ) );
					hint.textContent = '<?php echo esc_js( __( 'Konec doplněn automaticky – zkontrolujte ho.', 'reserveandpay' ) ); ?>';
				}
			} );
			type.addEventListener( 'change', onType );
			onType();
		} )();
		</script>
		<?php
	}

	public static function box_program( $post ) {
		$ev = Resandpay_Events::get( $post->ID );
		echo '<textarea id="rap_program" name="rap_program" rows="6" class="large-text">' . esc_textarea( $ev['program'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Zobrazí se u vícedenních akcí. Např. „Den 1: příjezd od 15:00, večerní relaxace“.', 'reserveandpay' ) . '</p>';
	}

	public static function row_actions( $actions, $post ) {
		if ( Resandpay_Post_Types::EVENT === $post->post_type && current_user_can( 'edit_posts' ) ) {
			$actions['rap_duplicate'] = '<a href="' . esc_url( self::duplicate_url( $post->ID ) ) . '">' . esc_html__( 'Duplikovat', 'reserveandpay' ) . '</a>';
		}
		return $actions;
	}

	public static function duplicate_url( $post_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=resandpay_duplicate&post=' . (int) $post_id ), 'resandpay_duplicate_' . (int) $post_id );
	}

	/** Vytvoří kopii akce jako koncept (bez rezervací). */
	public static function duplicate( $post_id ) {
		$src = get_post( $post_id );
		if ( ! $src || Resandpay_Post_Types::EVENT !== $src->post_type ) {
			return new WP_Error( 'not_found', __( 'Akce nenalezena.', 'reserveandpay' ) );
		}
		$new = wp_insert_post(
			array(
				'post_type'    => $src->post_type,
				'post_status'  => 'draft',
				/* translators: %s: název akce */
				'post_title'   => sprintf( __( '%s (kopie)', 'reserveandpay' ), $src->post_title ),
				'post_content' => $src->post_content,
				'post_excerpt' => $src->post_excerpt,
				'post_author'  => get_current_user_id() ?: $src->post_author,
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 === strpos( $key, '_rap_' ) || '_thumbnail_id' === $key ) {
				update_post_meta( $new, $key, maybe_unserialize( $values[0] ) );
			}
		}
		update_post_meta( $new, '_rap_cancelled', 0 );
		$terms = wp_get_object_terms( $post_id, Resandpay_Post_Types::CATEGORY, array( 'fields' => 'ids' ) );
		if ( $terms && ! is_wp_error( $terms ) ) {
			wp_set_object_terms( $new, $terms, Resandpay_Post_Types::CATEGORY );
		}
		return $new;
	}

	public static function handle_duplicate() {
		$id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'resandpay_duplicate_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		$new = self::duplicate( $id );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $new . '&action=edit' ) );
		exit;
	}

	public static function box_occupancy( $post ) {
		$ev = Resandpay_Events::get( $post->ID );
		$c  = Resandpay_Reservations::counts_by_status( $post->ID );
		$st = Resandpay_Reservations::status_labels();
		$booked = Resandpay_Reservations::count_active( $post->ID );
		echo '<p class="rap-occ"><strong>' . (int) $booked . ' / ' . (int) $ev['capacity'] . '</strong> ' . esc_html__( 'obsazeno', 'reserveandpay' ) . '</p><ul class="rap-occ-list">';
		foreach ( $st as $k => $l ) {
			echo '<li>' . esc_html( $l ) . ': <strong>' . (int) $c[ $k ] . '</strong></li>';
		}
		echo '</ul>';
		if ( $post->post_status !== 'auto-draft' ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=reserveandpay&event_id=' . $post->ID ) ) . '">' . esc_html__( 'Seznam účastnic', 'reserveandpay' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=resandpay_export&event_id=' . $post->ID ), 'resandpay_export' ) ) . '">' . esc_html__( 'Export CSV', 'reserveandpay' ) . '</a></p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-new&event_id=' . $post->ID ) ) . '">' . esc_html__( '+ Přidat rezervaci ručně', 'reserveandpay' ) . '</a><br>';
			echo '<a href="' . esc_url( self::duplicate_url( $post->ID ) ) . '">' . esc_html__( 'Duplikovat akci (nový termín)', 'reserveandpay' ) . '</a></p>';
		}
	}

	public static function box_location( $post ) {
		wp_nonce_field( 'rap_save_location', '_rap_location_nonce' );
		?>
		<p><label for="rap_address"><strong><?php esc_html_e( 'Adresa', 'reserveandpay' ); ?></strong></label><br>
			<input type="text" id="rap_address" name="rap_address" class="large-text" value="<?php echo esc_attr( get_post_meta( $post->ID, '_rap_address', true ) ); ?>"></p>
		<p><label for="rap_map_url"><strong><?php esc_html_e( 'Odkaz na mapu', 'reserveandpay' ); ?></strong></label><br>
			<input type="url" id="rap_map_url" name="rap_map_url" class="large-text" value="<?php echo esc_attr( get_post_meta( $post->ID, '_rap_map_url', true ) ); ?>" placeholder="https://mapy.cz/…"></p>
		<?php
	}

	public static function save_event( $post_id, $post ) {
		if ( ! isset( $_POST['_rap_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rap_event_nonce'] ) ), 'rap_save_event' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::save_event_meta( $post_id, wp_unslash( $_POST ) );
		if ( Resandpay_Events::overlaps( $post_id ) ) {
			set_transient( 'resandpay_overlap_' . get_current_user_id(), $post_id, 60 );
		}
	}

	/** Uložení údajů akce (sdílené s testy a generátorem termínů). */
	public static function save_event_meta( $post_id, array $in ) {
		Resandpay_Events::save_meta( $post_id, $in );
	}

	public static function save_location( $post_id ) {
		if ( ! isset( $_POST['_rap_location_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rap_location_nonce'] ) ), 'rap_save_location' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_rap_address', sanitize_text_field( wp_unslash( $_POST['rap_address'] ?? '' ) ) );
		update_post_meta( $post_id, '_rap_map_url', esc_url_raw( wp_unslash( $_POST['rap_map_url'] ?? '' ) ) );
	}

	public static function overlap_notice() {
		$key = 'resandpay_overlap_' . get_current_user_id();
		$id  = get_transient( $key );
		if ( ! $id ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Uloženo. Upozornění: tato akce se časově překrývá s jinou akcí – podrobnosti v části „Termín, kapacita a cena“. Překrývání je povoleno.', 'reserveandpay' ) . '</p></div>';
	}

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['rap_date']     = __( 'Termín', 'reserveandpay' );
				$new['rap_location'] = __( 'Místo', 'reserveandpay' );
				$new['rap_occ']      = __( 'Obsazeno', 'reserveandpay' );
				$new['rap_price']    = __( 'Cena', 'reserveandpay' );
			}
		}
		unset( $new['date'] );
		return $new;
	}

	public static function column( $col, $post_id ) {
		$ev = Resandpay_Events::get( $post_id );
		if ( ! $ev ) {
			return;
		}
		switch ( $col ) {
			case 'rap_date':
				$missing = self::missing( $ev );
				if ( $missing ) {
					echo '<span class="rap-tag rap-tag--off">' . esc_html( sprintf( /* translators: %s: pole */ __( 'Chybí: %s', 'reserveandpay' ), implode( ', ', $missing ) ) ) . '</span><br>';
				}
				echo esc_html( Resandpay_Events::format_date( $ev ) );
				if ( $ev['cancelled'] ) {
					echo ' <span class="rap-tag rap-tag--off">' . esc_html__( 'zrušeno', 'reserveandpay' ) . '</span>';
				}
				echo '<br><small>' . esc_html( Resandpay_Events::type_labels()[ $ev['type'] ] ?? '' ) . '</small>';
				break;
			case 'rap_location':
				echo esc_html( $ev['location'] );
				break;
			case 'rap_occ':
				$b = Resandpay_Reservations::count_active( $post_id );
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay&event_id=' . $post_id ) ) . '">' . (int) $b . ' / ' . (int) $ev['capacity'] . '</a>';
				break;
			case 'rap_price':
				echo esc_html( Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) );
				break;
		}
	}

	public static function sortable( $cols ) {
		$cols['rap_date'] = 'rap_date';
		return $cols;
	}

	public static function sort_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || Resandpay_Post_Types::EVENT !== $q->get( 'post_type' ) ) {
			return;
		}
		if ( 'rap_date' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', '_rap_start' );
			$q->set( 'orderby', 'meta_value' );
		}
	}
}
