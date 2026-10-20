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
	}

	public static function meta_boxes() {
		add_meta_box( 'rap_event', __( 'Termín, kapacita a cena', 'reserveandpay' ), array( __CLASS__, 'box_event' ), Resandpay_Post_Types::EVENT, 'normal', 'high' );
		add_meta_box( 'rap_occupancy', __( 'Obsazenost', 'reserveandpay' ), array( __CLASS__, 'box_occupancy' ), Resandpay_Post_Types::EVENT, 'side', 'high' );
		add_meta_box( 'rap_location', __( 'Adresa lokace', 'reserveandpay' ), array( __CLASS__, 'box_location' ), Resandpay_Post_Types::LOCATION, 'normal', 'high' );
	}

	private static function dt_input( $local ) {
		if ( ! $local ) {
			return '';
		}
		$ts = Resandpay_Events::ts( $local );
		return $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
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
		?>
		<table class="form-table rap-form-table">
			<tr><th><label for="rap_type"><?php esc_html_e( 'Typ akce', 'reserveandpay' ); ?></label></th>
				<td><select id="rap_type" name="rap_type">
					<?php foreach ( Resandpay_Events::type_labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ev['type'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Vícedenní akce se rezervuje vždy celá. Individuální termín (např. masáž) má kapacitu 1.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_start"><?php esc_html_e( 'Začátek', 'reserveandpay' ); ?> *</label></th>
				<td><input type="datetime-local" id="rap_start" name="rap_start" value="<?php echo esc_attr( self::dt_input( $ev['start'] ) ); ?>" required></td></tr>
			<tr><th><label for="rap_end"><?php esc_html_e( 'Konec', 'reserveandpay' ); ?> *</label></th>
				<td><input type="datetime-local" id="rap_end" name="rap_end" value="<?php echo esc_attr( self::dt_input( $ev['end'] ) ); ?>" required></td></tr>
			<tr><th><label for="rap_location"><?php esc_html_e( 'Místo konání', 'reserveandpay' ); ?></label></th>
				<td><select id="rap_location" name="rap_location">
					<option value="0">— <?php esc_html_e( 'nevybráno', 'reserveandpay' ); ?> —</option>
					<?php foreach ( $locations as $loc ) : ?>
						<option value="<?php echo (int) $loc->ID; ?>" <?php selected( $ev['location_id'], $loc->ID ); ?>><?php echo esc_html( $loc->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Resandpay_Post_Types::LOCATION ) ); ?>" target="_blank"><?php esc_html_e( 'Přidat lokaci', 'reserveandpay' ); ?></a></td></tr>
			<tr><th><label for="rap_capacity"><?php esc_html_e( 'Kapacita (míst)', 'reserveandpay' ); ?> *</label></th>
				<td><input type="number" min="1" step="1" id="rap_capacity" name="rap_capacity" value="<?php echo esc_attr( $ev['capacity'] ?: '' ); ?>" class="small-text" required></td></tr>
			<tr><th><label for="rap_price"><?php esc_html_e( 'Cena za jedno místo', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" step="0.01" id="rap_price" name="rap_price" value="<?php echo esc_attr( $ev['price'] ); ?>" class="regular-text" style="width:10em">
					<input type="text" name="rap_currency" value="<?php echo esc_attr( $ev['currency'] ); ?>" class="small-text" maxlength="3" aria-label="<?php esc_attr_e( 'Měna', 'reserveandpay' ); ?>">
					<p class="description"><?php esc_html_e( 'U vícedenní akce cena za celou akci.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_due_days"><?php esc_html_e( 'Splatnost (dní)', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" step="1" id="rap_due_days" name="rap_due_days" value="<?php echo esc_attr( $raw_due ); ?>" class="small-text" placeholder="<?php echo esc_attr( Resandpay_Settings::get( 'due_days' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Prázdné = výchozí hodnota z nastavení. Splatnost nikdy nepřesáhne začátek akce.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_close_hours"><?php esc_html_e( 'Uzávěrka rezervací', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" step="0.5" id="rap_close_hours" name="rap_close_hours" value="<?php echo esc_attr( $ev['close_hours'] ?: '' ); ?>" class="small-text" placeholder="0"> <?php esc_html_e( 'hodin před začátkem', 'reserveandpay' ); ?>
					<p class="description"><?php esc_html_e( 'Prázdné nebo 0 = rezervovat lze až do začátku akce.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Další volby', 'reserveandpay' ); ?></th>
				<td><label><input type="checkbox" name="rap_members_only" value="1" <?php checked( $ev['members_only'] ); ?>> <?php esc_html_e( 'Rezervace jen pro přihlášené uživatele', 'reserveandpay' ); ?></label><br>
					<label><input type="checkbox" name="rap_cancelled" value="1" <?php checked( $ev['cancelled'] ); ?>> <?php esc_html_e( 'Akce je zrušena (rezervace nejsou možné)', 'reserveandpay' ); ?></label></td></tr>
			<tr class="rap-program-row"><th><label for="rap_program"><?php esc_html_e( 'Program po dnech', 'reserveandpay' ); ?></label></th>
				<td><textarea id="rap_program" name="rap_program" rows="5" class="large-text"><?php echo esc_textarea( $ev['program'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Informativní text pro vícedenní akce (např. Den 1: příjezd od 15:00…).', 'reserveandpay' ); ?></p></td></tr>
		</table>
		<?php
		$overlaps = $ev['start'] ? Resandpay_Events::overlaps( $post->ID ) : array();
		if ( $overlaps ) {
			echo '<div class="rap-overlap"><strong>' . esc_html__( 'Upozornění: akce se časově překrývá s:', 'reserveandpay' ) . '</strong><ul>';
			foreach ( $overlaps as $o ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $o['id'] ) ) . '">' . esc_html( $o['title'] ) . '</a> – ' . esc_html( Resandpay_Events::format_date( $o ) ) . '</li>';
			}
			echo '</ul><p class="description">' . esc_html__( 'Překrývání je povoleno, jde jen o informaci.', 'reserveandpay' ) . '</p></div>';
		}
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
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-new&event_id=' . $post->ID ) ) . '">' . esc_html__( '+ Přidat rezervaci ručně', 'reserveandpay' ) . '</a></p>';
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
		$to_local = function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			if ( '' === $v ) {
				return '';
			}
			$dt = date_create_immutable( str_replace( 'T', ' ', $v ), wp_timezone() );
			return $dt ? $dt->format( 'Y-m-d H:i:s' ) : '';
		};
		$type  = in_array( $in['rap_type'] ?? '', Resandpay_Events::TYPES, true ) ? $in['rap_type'] : 'single';
		$start = $to_local( $in['rap_start'] ?? '' );
		$end   = $to_local( $in['rap_end'] ?? '' );
		if ( $start && ( ! $end || Resandpay_Events::ts( $end ) < Resandpay_Events::ts( $start ) ) ) {
			$end = $start;
		}
		$capacity = max( 0, (int) ( $in['rap_capacity'] ?? 0 ) );
		if ( 'slot' === $type ) {
			$capacity = 1;
		}
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['rap_currency'] ?? '' ) ) );
		update_post_meta( $post_id, '_rap_type', $type );
		update_post_meta( $post_id, '_rap_start', $start );
		update_post_meta( $post_id, '_rap_end', $end );
		update_post_meta( $post_id, '_rap_location', absint( $in['rap_location'] ?? 0 ) );
		update_post_meta( $post_id, '_rap_capacity', $capacity );
		update_post_meta( $post_id, '_rap_price', round( max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_price'] ?? 0 ) ) ), 2 ) );
		update_post_meta( $post_id, '_rap_currency', strlen( $currency ) === 3 ? $currency : Resandpay_Settings::get( 'currency' ) );
		$due = (string) ( $in['rap_due_days'] ?? '' );
		update_post_meta( $post_id, '_rap_due_days', '' === trim( $due ) ? '' : max( 0, (int) $due ) );
		update_post_meta( $post_id, '_rap_close_hours', max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_close_hours'] ?? 0 ) ) ) );
		update_post_meta( $post_id, '_rap_members_only', empty( $in['rap_members_only'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_rap_cancelled', empty( $in['rap_cancelled'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_rap_program', wp_kses_post( (string) ( $in['rap_program'] ?? '' ) ) );
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
