<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace služeb: parametry služby, generátor termínů, hromadné rušení,
 * filtr typů v seznamu akcí.
 */
class Resandpay_Admin_Services {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Resandpay_Post_Types::SERVICE, array( __CLASS__, 'save_service' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_resandpay_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_resandpay_bulk_cancel', array( __CLASS__, 'handle_bulk_cancel' ) );
		add_filter( 'manage_' . Resandpay_Post_Types::SERVICE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Resandpay_Post_Types::SERVICE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'type_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'type_filter_query' ) );
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Generátor termínů', 'reserveandpay' ), __( 'Generátor termínů', 'reserveandpay' ), 'edit_others_posts', 'reserveandpay-generator', array( __CLASS__, 'page_generator' ) );
	}

	public static function meta_boxes() {
		add_meta_box( 'rap_service', __( 'Parametry služby', 'reserveandpay' ), array( __CLASS__, 'box_service' ), Resandpay_Post_Types::SERVICE, 'normal', 'high' );
		add_meta_box( 'rap_service_slots', __( 'Termíny', 'reserveandpay' ), array( __CLASS__, 'box_slots' ), Resandpay_Post_Types::SERVICE, 'side', 'high' );
	}

	public static function box_service( $post ) {
		wp_nonce_field( 'rap_save_service', '_rap_service_nonce' );
		$svc       = Resandpay_Services::get( $post->ID );
		$locations = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::LOCATION,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<p class="description"><?php esc_html_e( 'Popis služby výše se zobrazí u každého termínu na webu. Délka, pauza a počet míst jsou pevné – zákaznice je nevolí. Jejich změna platí pro nově vygenerované termíny.', 'reserveandpay' ); ?></p>
		<table class="form-table">
			<tr><th><label for="rap_duration"><?php esc_html_e( 'Délka termínu', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="5" step="5" id="rap_duration" name="rap_duration" value="<?php echo esc_attr( $svc['duration'] ); ?>" class="small-text"> <?php esc_html_e( 'minut', 'reserveandpay' ); ?></td></tr>
			<tr><th><label for="rap_pause"><?php esc_html_e( 'Pauza mezi termíny', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" step="5" id="rap_pause" name="rap_pause" value="<?php echo esc_attr( $svc['pause'] ); ?>" class="small-text"> <?php esc_html_e( 'minut', 'reserveandpay' ); ?></td></tr>
			<tr><th><label for="rap_capacity"><?php esc_html_e( 'Počet míst na termín', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="1" max="500" step="1" id="rap_capacity" name="rap_capacity" value="<?php echo esc_attr( $svc['capacity'] ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( '1 = individuální služba (např. masáž). Více = skupinová lekce (např. Sound Healing 15) – termín zůstává volný, dokud se nezaplní.', 'reserveandpay' ); ?></p>
					<?php if ( 'auto-draft' !== $post->post_status ) : ?>
					<label><input type="checkbox" name="rap_capacity_apply" value="1"> <?php esc_html_e( 'Změnu promítnout i do nadcházejících termínů', 'reserveandpay' ); ?></label>
					<?php endif; ?></td></tr>
			<tr><th><label for="rap_price"><?php esc_html_e( 'Cena za místo', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" step="any" id="rap_price" name="rap_price" value="<?php echo esc_attr( $svc['price'] ); ?>" class="regular-text" style="width:10em">
					<input type="text" name="rap_currency" value="<?php echo esc_attr( $svc['currency'] ); ?>" class="small-text" maxlength="3" aria-label="<?php esc_attr_e( 'Měna', 'reserveandpay' ); ?>"></td></tr>
			<tr><th><label for="rap_location"><?php esc_html_e( 'Místo konání', 'reserveandpay' ); ?></label></th>
				<td><select id="rap_location" name="rap_location"><option value="0">— <?php esc_html_e( 'nevybráno', 'reserveandpay' ); ?> —</option>
					<?php foreach ( $locations as $loc ) : ?>
						<option value="<?php echo (int) $loc->ID; ?>" <?php selected( $svc['location_id'], $loc->ID ); ?>><?php echo esc_html( $loc->post_title ); ?></option>
					<?php endforeach; ?></select></td></tr>
			<tr><th><?php esc_html_e( 'Splatnost a platba', 'reserveandpay' ); ?></th>
				<td class="rap-box"><?php Resandpay_Admin_Events::payment_fields( $svc ); ?>
					<p class="description"><?php esc_html_e( 'Platí pro nově generované termíny. Termín lze rezervovat až do začátku; nestihne-li platba dorazit, platí se na místě.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_page"><?php esc_html_e( 'Stránka s volnými termíny', 'reserveandpay' ); ?></label></th>
				<td><?php wp_dropdown_pages( array( 'name' => 'rap_page', 'id' => 'rap_page', 'selected' => $svc['page_id'], 'show_option_none' => '— ' . __( 'nevybráno', 'reserveandpay' ) . ' —', 'option_none_value' => 0 ) ); ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: shortcode */ __( 'Na stránku vložte shortcode %s. Detail termínu na ni odkazuje.', 'reserveandpay' ), '[reserveandpay_slots service="' . ( $post->post_name ?: $post->ID ) . '"]' ) ); ?></p></td></tr>
		</table>
		<?php
	}

	public static function box_slots( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Po uložení služby zde vygenerujete termíny.', 'reserveandpay' ) . '</p>';
			return;
		}
		$tz    = wp_timezone();
		$now   = new DateTimeImmutable( 'now', $tz );
		$slots = Resandpay_Services::slots( $post->ID, $now, $now->modify( '+1 year' ) );
		$free  = count( array_filter( $slots, fn( $e ) => ! $e['cancelled'] && Resandpay_Events::free_places( $e['id'] ) > 0 ) );
		/* translators: 1: volné, 2: celkem */
		echo '<p>' . esc_html( sprintf( __( 'Nadcházející termíny: %2$d, z toho volných %1$d.', 'reserveandpay' ), $free, count( $slots ) ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-generator&service=' . $post->ID ) ) . '">' . esc_html__( 'Vygenerovat termíny', 'reserveandpay' ) . '</a></p>';
		echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . Resandpay_Post_Types::EVENT . '&rap_kind=slots&rap_service=' . $post->ID ) ) . '">' . esc_html__( 'Zobrazit termíny', 'reserveandpay' ) . '</a> · ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-generator&service=' . $post->ID . '#rap-bulk' ) ) . '">' . esc_html__( 'Hromadně zrušit', 'reserveandpay' ) . '</a></p>';
	}

	public static function save_service( $post_id ) {
		if ( ! isset( $_POST['_rap_service_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rap_service_nonce'] ) ), 'rap_save_service' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in  = wp_unslash( $_POST );
		$old = (int) get_post_meta( $post_id, '_rap_capacity', true );
		self::save_service_meta( $post_id, $in );
		$cap = Resandpay_Services::get( $post_id )['capacity'];
		if ( ! empty( $in['rap_capacity_apply'] ) ) {
			$res = Resandpay_Services::apply_capacity( $post_id, $cap );
			/* translators: %d: počet */
			$msg = sprintf( __( 'Počet míst upraven u nadcházejících termínů: %d.', 'reserveandpay' ), $res['updated'] );
			if ( $res['skipped'] ) {
				/* translators: %s: seznam termínů */
				$msg .= ' ' . sprintf( __( 'Beze změny (víc rezervací než nová kapacita): %s.', 'reserveandpay' ), implode( ', ', array_map( array( 'Resandpay_Events', 'format_date' ), $res['skipped'] ) ) );
			}
			Resandpay_Admin::flash( $msg, $res['skipped'] ? 'warning' : 'success' );
		} elseif ( $old && $old !== $cap ) {
			Resandpay_Admin::flash( __( 'Nový počet míst platí pro nově vygenerované termíny. Stávající termíny zůstaly beze změny.', 'reserveandpay' ), 'info' );
		}
	}

	public static function save_service_meta( $post_id, array $in ) {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['rap_currency'] ?? '' ) ) );
		$due      = trim( (string) ( $in['rap_due_days'] ?? '' ) );
		update_post_meta( $post_id, '_rap_duration', max( 5, (int) ( $in['rap_duration'] ?? 60 ) ) );
		update_post_meta( $post_id, '_rap_pause', max( 0, (int) ( $in['rap_pause'] ?? 15 ) ) );
		update_post_meta( $post_id, '_rap_capacity', min( 500, max( 1, (int) ( $in['rap_capacity'] ?? 1 ) ) ) );
		update_post_meta( $post_id, '_rap_price', round( max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_price'] ?? 0 ) ) ), 2 ) );
		update_post_meta( $post_id, '_rap_currency', 3 === strlen( $currency ) ? $currency : Resandpay_Settings::get( 'currency' ) );
		update_post_meta( $post_id, '_rap_location', absint( $in['rap_location'] ?? 0 ) );
		update_post_meta( $post_id, '_rap_due_days', '' === $due ? '' : max( 0, (int) $due ) );
		update_post_meta( $post_id, '_rap_page', absint( $in['rap_page'] ?? 0 ) );
		$mode = (string) ( $in['rap_due_mode'] ?? '' );
		update_post_meta( $post_id, '_rap_due_mode', in_array( $mode, Resandpay_Events::DUE_MODES, true ) ? $mode : '' );
		$off = trim( (string) ( $in['rap_due_offset'] ?? '' ) );
		update_post_meta( $post_id, '_rap_due_offset', '' === $off ? '' : max( -365, min( 365, (int) $off ) ) );
		$cash = (string) ( $in['rap_cash'] ?? '' );
		update_post_meta( $post_id, '_rap_cash', in_array( $cash, array( '0', '1' ), true ) ? $cash : '' );
	}

	public static function columns( $cols ) {
		$date = $cols['date'] ?? null;
		unset( $cols['date'] );
		$cols['rap_params'] = __( 'Délka · pauza · míst · cena', 'reserveandpay' );
		$cols['rap_slots']  = __( 'Nadcházející termíny', 'reserveandpay' );
		return $cols;
	}

	public static function column( $col, $post_id ) {
		$svc = Resandpay_Services::get( $post_id );
		if ( 'rap_params' === $col ) {
			echo esc_html( $svc['duration'] . ' min · ' . $svc['pause'] . ' min · ' . $svc['capacity'] . ' · ' . Resandpay_Events::format_price( $svc['price'], $svc['currency'] ) );
		} elseif ( 'rap_slots' === $col ) {
			$now   = new DateTimeImmutable( 'now', wp_timezone() );
			$slots = Resandpay_Services::slots( $post_id, $now, $now->modify( '+1 year' ) );
			$free  = count( array_filter( $slots, fn( $e ) => ! $e['cancelled'] && Resandpay_Events::free_places( $e['id'] ) > 0 ) );
			/* translators: 1: volné, 2: celkem */
			echo esc_html( sprintf( __( '%1$d volných z %2$d', 'reserveandpay' ), $free, count( $slots ) ) );
			echo ' · <a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-generator&service=' . $post_id ) ) . '">' . esc_html__( 'generovat', 'reserveandpay' ) . '</a>';
		}
	}

	/** Filtr Akce / Individuální termíny v seznamu akcí (výchozí: bez individuálních termínů). */
	public static function type_filter( $post_type ) {
		if ( Resandpay_Post_Types::EVENT !== $post_type ) {
			return;
		}
		$cur = sanitize_key( $_GET['rap_kind'] ?? 'events' );
		echo '<select name="rap_kind">';
		foreach ( array(
			'events' => __( 'Akce (bez individuálních termínů)', 'reserveandpay' ),
			'slots'  => __( 'Individuální termíny', 'reserveandpay' ),
			'all'    => __( 'Vše', 'reserveandpay' ),
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		if ( ! empty( $_GET['rap_service'] ) ) {
			echo '<input type="hidden" name="rap_service" value="' . (int) $_GET['rap_service'] . '">';
		}
	}

	public static function type_filter_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || Resandpay_Post_Types::EVENT !== $q->get( 'post_type' ) ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$kind = sanitize_key( $_GET['rap_kind'] ?? 'events' );
		$mq   = (array) $q->get( 'meta_query' );
		if ( 'events' === $kind ) {
			$mq[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_rap_type',
					'value'   => 'slot',
					'compare' => '!=',
				),
				array(
					'key'     => '_rap_type',
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'slots' === $kind ) {
			$mq[] = array(
				'key'   => '_rap_type',
				'value' => 'slot',
			);
			if ( ! empty( $_GET['rap_service'] ) ) {
				$mq[] = array(
					'key'   => '_rap_service',
					'value' => (int) $_GET['rap_service'],
				);
			}
			if ( ! $q->get( 'orderby' ) ) {
				$q->set( 'meta_key', '_rap_start' );
				$q->set( 'orderby', 'meta_value' );
				$q->set( 'order', 'ASC' );
			}
		}
		$q->set( 'meta_query', $mq );
	}

	/** Výchozí hodnoty formuláře generátoru. */
	private static function gen_defaults() {
		$tz = wp_timezone();
		$d  = new DateTimeImmutable( 'tomorrow', $tz );
		return array(
			'weekdays'  => array( 2, 4 ),
			'time_from' => '09:00',
			'time_to'   => '17:00',
			'date_from' => $d->format( 'Y-m-d' ),
			'date_to'   => $d->modify( '+4 weeks' )->format( 'Y-m-d' ),
		);
	}

	private static function read_args( $src ) {
		$def = self::gen_defaults();
		$wd  = isset( $src['weekdays'] ) ? array_map( 'intval', (array) $src['weekdays'] ) : $def['weekdays'];
		return array(
			'weekdays'  => array_values( array_intersect( $wd, range( 1, 7 ) ) ),
			'time_from' => sanitize_text_field( $src['time_from'] ?? $def['time_from'] ),
			'time_to'   => sanitize_text_field( $src['time_to'] ?? $def['time_to'] ),
			'date_from' => sanitize_text_field( $src['date_from'] ?? $def['date_from'] ),
			'date_to'   => sanitize_text_field( $src['date_to'] ?? $def['date_to'] ),
		);
	}

	public static function page_generator() {
		$services = Resandpay_Services::all();
		$sid      = absint( $_REQUEST['service'] ?? 0 );
		if ( ! $sid && count( $services ) === 1 ) {
			$sid = reset( $services )['id'];
		}
		$args     = self::read_args( wp_unslash( $_REQUEST ) );
		$preview  = ! empty( $_REQUEST['rap_preview'] ) && $sid ? Resandpay_Services::preview( $sid, $args ) : null;
		$days     = array( 1 => 'po', 2 => 'út', 3 => 'st', 4 => 'čt', 5 => 'pá', 6 => 'so', 7 => 'ne' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Generátor termínů', 'reserveandpay' ) . '</h1>';
		Resandpay_Admin::render_flash();
		if ( ! $services ) {
			echo '<p>' . esc_html__( 'Nejdřív založte službu (např. Bukální masáž) s délkou termínu.', 'reserveandpay' ) . ' <a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . Resandpay_Post_Types::SERVICE ) ) . '">' . esc_html__( 'Přidat službu', 'reserveandpay' ) . '</a></p></div>';
			return;
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="rap-gen">
			<input type="hidden" name="page" value="reserveandpay-generator">
			<input type="hidden" name="rap_preview" value="1">
			<table class="form-table">
				<tr><th><label for="service"><?php esc_html_e( 'Služba', 'reserveandpay' ); ?></label></th><td><select name="service" id="service">
					<?php foreach ( $services as $s ) : ?>
						<option value="<?php echo (int) $s['id']; ?>" <?php selected( $sid, $s['id'] ); ?>><?php echo esc_html( $s['title'] . ' (' . $s['duration'] . ' min + ' . $s['pause'] . ' min pauza, ' . sprintf( /* translators: %d: počet */ _n( '%d místo', '%d míst', $s['capacity'], 'reserveandpay' ), $s['capacity'] ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
				<tr><th><?php esc_html_e( 'Dny v týdnu', 'reserveandpay' ); ?></th><td>
					<?php foreach ( $days as $n => $l ) : ?>
						<label class="rap-day"><input type="checkbox" name="weekdays[]" value="<?php echo (int) $n; ?>" <?php checked( in_array( $n, $args['weekdays'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?></td></tr>
				<tr><th><?php esc_html_e( 'Časové okno', 'reserveandpay' ); ?></th><td>
					<input type="time" name="time_from" value="<?php echo esc_attr( $args['time_from'] ); ?>" step="300"> – <input type="time" name="time_to" value="<?php echo esc_attr( $args['time_to'] ); ?>" step="300">
					<p class="description"><?php esc_html_e( 'Poslední termín musí skončit nejpozději v konci okna.', 'reserveandpay' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Období', 'reserveandpay' ); ?></th><td>
					<input type="date" name="date_from" value="<?php echo esc_attr( $args['date_from'] ); ?>"> – <input type="date" name="date_to" value="<?php echo esc_attr( $args['date_to'] ); ?>"></td></tr>
			</table>
			<?php submit_button( __( 'Zobrazit náhled termínů', 'reserveandpay' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
		} elseif ( is_array( $preview ) ) {
			self::render_preview( $sid, $preview );
		}
		self::render_bulk_cancel( $services, $sid );
		echo '</div>';
	}

	private static function render_preview( $sid, array $preview ) {
		$wd = Resandpay_Views::weekdays_short();
		echo '<h2>' . esc_html__( 'Náhled', 'reserveandpay' ) . '</h2>';
		if ( ! $preview ) {
			echo '<p>' . esc_html__( 'V zadaném období nevychází žádný budoucí termín.', 'reserveandpay' ) . '</p>';
			return;
		}
		$new = count( array_filter( $preview, fn( $p ) => ! $p['exists'] ) );
		$ovl = count( array_filter( $preview, fn( $p ) => ! $p['exists'] && $p['overlaps'] ) );
		/* translators: 1: nové, 2: existující, 3: překryvy */
		echo '<p>' . esc_html( sprintf( __( 'Nových termínů: %1$d · již existuje: %2$d · s překryvem: %3$d. Odškrtnutím termín vynecháte.', 'reserveandpay' ), $new, count( $preview ) - $new, $ovl ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="resandpay_generate"><input type="hidden" name="service" value="' . (int) $sid . '">';
		wp_nonce_field( 'resandpay_generate' );
		echo '<table class="widefat striped rap-preview"><thead><tr><td class="check-column"><input type="checkbox" checked onclick="document.querySelectorAll(\'.rap-preview tbody input:not(:disabled)\').forEach(c=>c.checked=this.checked)"></td><th>' . esc_html__( 'Den', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Čas', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Poznámka', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		foreach ( $preview as $p ) {
			$s    = date_create_immutable( $p['start'], wp_timezone() );
			$e    = date_create_immutable( $p['end'], wp_timezone() );
			$note = '';
			if ( $p['exists'] ) {
				$note = '<span class="rap-muted">' . esc_html__( 'Termín již existuje – přeskočí se', 'reserveandpay' ) . '</span>';
			} elseif ( $p['overlaps'] ) {
				$note = '<span class="rap-warn">' . esc_html__( 'Překryv s:', 'reserveandpay' ) . ' ' . esc_html( implode( ', ', wp_list_pluck( $p['overlaps'], 'title' ) ) ) . '</span>';
			}
			echo '<tr><th class="check-column"><input type="checkbox" name="starts[]" value="' . esc_attr( $p['start'] ) . '" ' . ( $p['exists'] ? 'disabled' : 'checked' ) . '></th>';
			echo '<td>' . esc_html( $wd[ (int) $s->format( 'w' ) ] . ' ' . $s->format( 'j. n. Y' ) ) . '</td><td>' . esc_html( $s->format( 'G:i' ) . '–' . $e->format( 'G:i' ) ) . '</td><td>' . $note . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno výše.
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Překrývání s jinými akcemi je povoleno – jde jen o upozornění.', 'reserveandpay' ) . '</p>';
		submit_button( __( 'Vytvořit vybrané termíny', 'reserveandpay' ) );
		echo '</form>';
	}

	private static function render_bulk_cancel( array $services, $sid ) {
		$tz   = wp_timezone();
		$from = sanitize_text_field( wp_unslash( $_GET['bc_from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['bc_to'] ?? '' ) );
		echo '<hr><h2 id="rap-bulk">' . esc_html__( 'Hromadné zrušení termínů (nemoc, dovolená)', 'reserveandpay' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="reserveandpay-generator">';
		echo '<p><select name="service">';
		foreach ( $services as $s ) {
			echo '<option value="' . (int) $s['id'] . '" ' . selected( $sid, $s['id'], false ) . '>' . esc_html( $s['title'] ) . '</option>';
		}
		echo '</select> <input type="date" name="bc_from" value="' . esc_attr( $from ) . '" required> – <input type="date" name="bc_to" value="' . esc_attr( $to ) . '" required> ';
		submit_button( __( 'Zobrazit termíny ke zrušení', 'reserveandpay' ), 'secondary', 'submit', false );
		echo '</p></form>';
		$f = $from ? date_create_immutable( $from . ' 00:00:00', $tz ) : null;
		$t = $to ? date_create_immutable( $to . ' 23:59:59', $tz ) : null;
		if ( ! $sid || ! $f || ! $t ) {
			return;
		}
		$res = Resandpay_Services::bulk_cancel( $sid, $f, $t, false, '', true );
		/* translators: 1: volné, 2: obsazené */
		echo '<p>' . esc_html( sprintf( __( 'Volné termíny k odstranění: %1$d · obsazené termíny: %2$d', 'reserveandpay' ), count( $res['free'] ), count( $res['booked'] ) ) ) . '</p>';
		if ( $res['booked'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Tyto termíny už mají rezervaci – zrušením se zruší i rezervace:', 'reserveandpay' ) . '</strong></p><ul>';
			foreach ( $res['booked'] as $ev ) {
				$names = wp_list_pluck(
					array_filter(
						Resandpay_Reservations::query(
							array(
								'event_id' => $ev['id'],
								'per_page' => 100,
							)
						)['items'],
						fn( $r ) => in_array( $r['status'], Resandpay_Reservations::active_statuses(), true )
					),
					'customer_name'
				);
				echo '<li>' . esc_html( Resandpay_Events::format_date( $ev ) . ' – ' . implode( ', ', $names ) ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( ! $res['free'] && ! $res['booked'] ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="resandpay_bulk_cancel"><input type="hidden" name="service" value="' . (int) $sid . '"><input type="hidden" name="bc_from" value="' . esc_attr( $from ) . '"><input type="hidden" name="bc_to" value="' . esc_attr( $to ) . '">';
		wp_nonce_field( 'resandpay_bulk_cancel' );
		if ( $res['booked'] ) {
			echo '<p><label><input type="checkbox" name="notify" value="1" checked> ' . esc_html__( 'Poslat zákaznicím e-mail o zrušení', 'reserveandpay' ) . '</label><br>';
			echo '<label>' . esc_html__( 'Důvod (zobrazí se v e-mailu):', 'reserveandpay' ) . ' <input type="text" name="reason" class="regular-text" placeholder="' . esc_attr__( 'např. nemoc lektorky', 'reserveandpay' ) . '"></label></p>';
		}
		submit_button( __( 'Zrušit termíny v období', 'reserveandpay' ), 'delete', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Opravdu zrušit všechny termíny v období?', 'reserveandpay' ) ) . "')" ) );
		echo '</form>';
	}

	public static function handle_generate() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_generate' );
		$sid    = absint( $_POST['service'] ?? 0 );
		$starts = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['starts'] ?? array() ) );
		$res    = Resandpay_Services::create_slots( $sid, $starts );
		/* translators: 1: vytvořeno, 2: přeskočeno */
		Resandpay_Admin::flash( sprintf( __( 'Vytvořeno termínů: %1$d, přeskočeno: %2$d.', 'reserveandpay' ), $res['created'], $res['skipped'] ) );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Resandpay_Post_Types::EVENT . '&rap_kind=slots&rap_service=' . $sid ) );
		exit;
	}

	public static function handle_bulk_cancel() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_bulk_cancel' );
		$tz  = wp_timezone();
		$sid = absint( $_POST['service'] ?? 0 );
		$f   = date_create_immutable( sanitize_text_field( wp_unslash( $_POST['bc_from'] ?? '' ) ) . ' 00:00:00', $tz );
		$t   = date_create_immutable( sanitize_text_field( wp_unslash( $_POST['bc_to'] ?? '' ) ) . ' 23:59:59', $tz );
		if ( $sid && $f && $t ) {
			$res = Resandpay_Services::bulk_cancel( $sid, $f, $t, ! empty( $_POST['notify'] ), sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
			/* translators: 1: odstraněno, 2: zrušeno */
			Resandpay_Admin::flash( sprintf( __( 'Odstraněno volných termínů: %1$d, zrušeno obsazených: %2$d.', 'reserveandpay' ), count( $res['free'] ), count( $res['booked'] ) ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-generator&service=' . $sid ) );
		exit;
	}
}
