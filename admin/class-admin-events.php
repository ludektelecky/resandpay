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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'gallery_assets' ) );
	}

	/** Knihovna médií pro výběr fotek galerie (jen při úpravě akce). */
	public static function gallery_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && Resandpay_Post_Types::EVENT === $screen->post_type ) {
			wp_enqueue_media();
		}
	}

	public static function meta_boxes() {
		add_meta_box( 'rap_event', __( 'Termín, kapacita a cena', 'reserveandpay' ), array( __CLASS__, 'box_event' ), Resandpay_Post_Types::EVENT, 'side', 'high' );
		add_meta_box( 'rap_occupancy', __( 'Obsazenost', 'reserveandpay' ), array( __CLASS__, 'box_occupancy' ), Resandpay_Post_Types::EVENT, 'side', 'default' );
		add_meta_box( 'rap_gallery_box', __( 'Fotky akce', 'reserveandpay' ), array( __CLASS__, 'box_gallery' ), Resandpay_Post_Types::EVENT, 'normal', 'high' );
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
		if ( ! empty( $ev['external'] ) ) {
			if ( '' === $ev['external']['url'] ) {
				$m[] = __( 'adresa externí rezervace', 'reserveandpay' );
			}
		} elseif ( $ev['capacity'] < 1 ) {
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
		$code      = Resandpay_Codes::get( $post->ID );
		echo '<p class="rap-code">' . esc_html__( 'Kód:', 'reserveandpay' ) . ' ' . ( $code ? '<code>' . esc_html( $code ) . '</code>' : '<span class="rap-hint">' . esc_html__( 'přidělí se při uložení', 'reserveandpay' ) . '</span>' ) . '</p>';
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
			<?php
			if ( $ev['service_id'] ) {
				if ( $ev['external'] ) {
					Resandpay_Events::booking_fields( $post->ID, true, $ev['external'] );
				}
			} else {
				Resandpay_Events::booking_fields( $post->ID );
			}
			?>
			<details class="rap-more" <?php echo ( '' !== $raw_due || '' !== get_post_meta( $post->ID, '_rap_due_mode', true ) || '' !== get_post_meta( $post->ID, '_rap_cash', true ) || in_array( get_post_meta( $post->ID, '_rap_part1_mode', true ), array( 'fixed', 'percent' ), true ) || '' !== get_post_meta( $post->ID, '_rap_time_display', true ) || '' !== get_post_meta( $post->ID, '_rap_early_mode', true ) || '' !== (string) get_post_meta( $post->ID, '_rap_reminder_hours', true ) || '' !== (string) get_post_meta( $post->ID, '_rap_reminder_note', true ) || $ev['close_hours'] || $ev['members_only'] || $ev['cancelled'] ) ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Další nastavení', 'reserveandpay' ); ?></summary>
				<?php
				self::payment_fields(
					array(
						'due_mode'   => get_post_meta( $post->ID, '_rap_due_mode', true ),
						'due_days'   => $raw_due,
						'due_offset' => get_post_meta( $post->ID, '_rap_due_offset', true ),
						'cash'       => get_post_meta( $post->ID, '_rap_cash', true ),
						'part1_mode'  => get_post_meta( $post->ID, '_rap_part1_mode', true ),
						'part1_value' => get_post_meta( $post->ID, '_rap_part1_value', true ),
						'part1_days'  => get_post_meta( $post->ID, '_rap_part1_days', true ),
					)
				);
				Resandpay_Events::time_display_field( get_post_meta( $post->ID, '_rap_time_display', true ) );
				if ( 'slot' !== get_post_meta( $post->ID, '_rap_type', true ) ) {
					Resandpay_Discounts::early_fields( $post->ID );
				}

				?>
				<?php
				$rsvc = (int) get_post_meta( $post->ID, '_rap_service', true );
				Resandpay_Events::reminder_fields( $post->ID, $rsvc ? sprintf( /* translators: %d: hodin */ __( 'ze služby (%d h)', 'reserveandpay' ), Resandpay_Events::resolve_reminder_hours( '', $rsvc ) ) : '' );
				?>
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
				echo '<li><a href="' . esc_url( get_edit_post_link( $o['id'] ) ) . '">' . esc_html( $o['title'] ) . '</a> – ' . esc_html( Resandpay_Events::format_date_full( $o ) ) . '</li>';
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
				if ( type.value === 'slot' && ! cap.value ) { cap.value = 1; }
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

	/**
	 * Pole „Splatnost a platba“ (sdílené editací akce a služby).
	 * Prázdná hodnota = výchozí z nastavení.
	 *
	 * @param array $raw due_mode, due_days, due_offset, cash – hodnoty uložené u akce/služby.
	 */
	public static function payment_fields( array $raw ) {
		$s     = Resandpay_Settings::all();
		$modes = Resandpay_Events::due_mode_labels();
		$def   = Resandpay_Events::due_rule_text( Resandpay_Events::default_due_mode(), $s['due_days'], $s['due_offset'] );
		$uid   = wp_unique_id( 'rap-pay-' );
		?>
		<div class="rap-payfields" id="<?php echo esc_attr( $uid ); ?>">
			<p><label for="<?php echo esc_attr( $uid ); ?>-mode"><?php esc_html_e( 'Splatnost', 'reserveandpay' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-mode" name="rap_due_mode" data-rap-due-mode>
					<option value=""><?php echo esc_html( sprintf( /* translators: %s: výchozí pravidlo */ __( 'Výchozí z nastavení (%s)', 'reserveandpay' ), $def ) ); ?></option>
					<?php foreach ( $modes as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $raw['due_mode'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></p>
			<p data-rap-due="booking"><label for="<?php echo esc_attr( $uid ); ?>-days"><?php esc_html_e( 'Dní od rezervace', 'reserveandpay' ); ?></label>
				<input type="number" min="0" step="1" id="<?php echo esc_attr( $uid ); ?>-days" name="rap_due_days" value="<?php echo esc_attr( (string) $raw['due_days'] ); ?>" placeholder="<?php echo esc_attr( $s['due_days'] ); ?>" class="small-text"></p>
			<p data-rap-due="event"><label for="<?php echo esc_attr( $uid ); ?>-off"><?php esc_html_e( 'Dní vůči termínu', 'reserveandpay' ); ?></label>
				<input type="number" min="-365" max="365" step="1" id="<?php echo esc_attr( $uid ); ?>-off" name="rap_due_offset" value="<?php echo esc_attr( (string) $raw['due_offset'] ); ?>" placeholder="<?php echo esc_attr( $s['due_offset'] ); ?>" class="small-text">
				<span class="rap-hint"><?php esc_html_e( '−1 = den před termínem, 0 = v den termínu, +1 = den po termínu. Splatnost je vždy do konce dne. Kdo rezervuje až po této splatnosti, platí celou částku do 7 dní (u platby ve dvou částech podle „1. část splatná do“), nejpozději den před akcí.', 'reserveandpay' ); ?></span></p>
			<p><label for="<?php echo esc_attr( $uid ); ?>-cash"><?php esc_html_e( 'Platba v hotovosti na místě', 'reserveandpay' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-cash" name="rap_cash">
					<option value=""><?php echo esc_html( $s['cash_enabled'] ? __( 'Výchozí z nastavení (povolena)', 'reserveandpay' ) : __( 'Výchozí z nastavení (vypnuta)', 'reserveandpay' ) ); ?></option>
					<option value="1" <?php selected( (string) $raw['cash'], '1' ); ?>><?php esc_html_e( 'Povolit jako doplňkovou možnost', 'reserveandpay' ); ?></option>
					<option value="0" <?php selected( (string) $raw['cash'], '0' ); ?>><?php esc_html_e( 'Nepovolit', 'reserveandpay' ); ?></option>
				</select>
				<span class="rap-hint"><?php esc_html_e( 'Převod (QR) je vždy hlavní a předvybraná možnost.', 'reserveandpay' ); ?></span></p>
			<p><label for="<?php echo esc_attr( $uid ); ?>-p1"><?php esc_html_e( 'Platba ve dvou částech', 'reserveandpay' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-p1" name="rap_part1_mode" data-rap-p1-mode>
					<option value="none" <?php selected( (string) ( $raw['part1_mode'] ?? 'none' ), 'none' ); ?>><?php esc_html_e( 'Ne – platba najednou', 'reserveandpay' ); ?></option>
					<option value="fixed" <?php selected( (string) ( $raw['part1_mode'] ?? '' ), 'fixed' ); ?>><?php esc_html_e( '1. část jako pevná částka', 'reserveandpay' ); ?></option>
					<option value="percent" <?php selected( (string) ( $raw['part1_mode'] ?? '' ), 'percent' ); ?>><?php esc_html_e( '1. část jako procento z ceny', 'reserveandpay' ); ?></option>
				</select></p>
			<p data-rap-p1><label for="<?php echo esc_attr( $uid ); ?>-p1v"><?php esc_html_e( '1. část (Kč nebo %)', 'reserveandpay' ); ?></label>
				<input type="number" min="0" step="any" id="<?php echo esc_attr( $uid ); ?>-p1v" name="rap_part1_value" value="<?php echo esc_attr( (string) ( $raw['part1_value'] ?? '' ) ); ?>" class="small-text"></p>
			<p data-rap-p1><label for="<?php echo esc_attr( $uid ); ?>-p1d"><?php esc_html_e( '1. část splatná do (dní od rezervace)', 'reserveandpay' ); ?></label>
				<input type="number" min="0" step="1" id="<?php echo esc_attr( $uid ); ?>-p1d" name="rap_part1_days" value="<?php echo esc_attr( (string) ( '' === (string) ( $raw['part1_days'] ?? '' ) ? 7 : $raw['part1_days'] ) ); ?>" class="small-text">
				<span class="rap-hint"><?php esc_html_e( 'Doplatek se řídí splatností výše. Zaplacením 1. části je místo potvrzené; nezaplacený doplatek rezervaci neruší. Nepoužije se u hotovosti, u rezervace na poslední chvíli a u rezervace až po splatnosti doplatku (pak se platí vše najednou v této lhůtě).', 'reserveandpay' ); ?></span></p>
		</div>
		<script>
		( function () {
			var box = document.getElementById( '<?php echo esc_js( $uid ); ?>' ), sel = box.querySelector( '[data-rap-due-mode]' ), def = '<?php echo esc_js( Resandpay_Events::default_due_mode() ); ?>';
			function upd() {
				var m = sel.value || def;
				box.querySelector( '[data-rap-due="booking"]' ).hidden = m === 'event';
				box.querySelector( '[data-rap-due="event"]' ).hidden = m === 'booking';
			}
			sel.addEventListener( 'change', upd ); upd();
			var p1 = box.querySelector( '[data-rap-p1-mode]' );
			function upd1() { box.querySelectorAll( '[data-rap-p1]' ).forEach( function ( el ) { el.hidden = p1.value === 'none'; } ); }
			p1.addEventListener( 'change', upd1 ); upd1();
		} )();
		</script>
		<?php
	}

	public static function box_program( $post ) {
		$ev = Resandpay_Events::get( $post->ID );
		echo '<textarea id="rap_program" name="rap_program" rows="6" class="large-text">' . esc_textarea( $ev['program'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Zobrazí se u vícedenních akcí. Např. „Den 1: příjezd od 15:00, večerní relaxace“.', 'reserveandpay' ) . '</p>';
	}

	/** Galerie akce: fotky z Knihovny médií, pořadí přetažením v okně knihovny. */
	public static function box_gallery( $post ) {
		$ids = Resandpay_Events::get( $post->ID )['gallery'];
		echo '<input type="hidden" id="rap_gallery" name="rap_gallery" value="' . esc_attr( implode( ',', $ids ) ) . '">';
		echo '<ul class="rap-gallery-admin" id="rap_gallery_list">';
		foreach ( $ids as $id ) {
			echo '<li>' . wp_get_attachment_image( $id, 'thumbnail' ) . '</li>';
		}
		echo '</ul>';
		echo '<p><button type="button" class="button" id="rap_gallery_pick">' . esc_html( $ids ? __( 'Upravit fotky', 'reserveandpay' ) : __( 'Vybrat fotky', 'reserveandpay' ) ) . '</button> ';
		echo '<button type="button" class="button-link button-link-delete" id="rap_gallery_clear"' . ( $ids ? '' : ' hidden' ) . '>' . esc_html__( 'Odebrat všechny', 'reserveandpay' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Zobrazí se v záhlaví stránky akce (poměr 4:3, náhledy pod ní). První je vždy Náhledový obrázek akce, pokud je nastavený. Pořadí změníte přetažením v okně knihovny. Nejvýše 12 fotek. Popisek fotky (pole Popisek v knihovně) se zobrazí pod ní.', 'reserveandpay' ) . '</p>';
		?>
		<style>.rap-gallery-admin{display:flex;flex-wrap:wrap;gap:8px;margin:0}.rap-gallery-admin li{margin:0;width:80px;height:80px}.rap-gallery-admin img{width:80px;height:80px;object-fit:cover;display:block}</style>
		<script>
		( function () {
			var input = document.getElementById( 'rap_gallery' ), list = document.getElementById( 'rap_gallery_list' );
			var pick = document.getElementById( 'rap_gallery_pick' ), clear = document.getElementById( 'rap_gallery_clear' ), frame;
			if ( ! input || ! window.wp || ! wp.media ) { return; }
			function render( items ) {
				list.innerHTML = '';
				items.forEach( function ( a ) {
					var li = document.createElement( 'li' ), img = document.createElement( 'img' );
					var s = a.sizes && ( a.sizes.thumbnail || a.sizes.medium || a.sizes.full );
					img.src = s ? s.url : a.url; img.alt = '';
					li.appendChild( img ); list.appendChild( li );
				} );
				input.value = items.map( function ( a ) { return a.id; } ).join( ',' );
				clear.hidden = ! items.length;
				pick.textContent = items.length ? <?php echo wp_json_encode( __( 'Upravit fotky', 'reserveandpay' ) ); ?> : <?php echo wp_json_encode( __( 'Vybrat fotky', 'reserveandpay' ) ); ?>;
			}
			pick.addEventListener( 'click', function () {
				// Standardní editor galerie WordPressu: výběr, pořadí přetažením, popisky.
				if ( input.value ) {
					frame = wp.media.gallery.edit( '[gallery ids="' + input.value + '"]' );
				} else {
					frame = wp.media( { frame: 'post', state: 'gallery-library', multiple: true } );
					frame.open();
				}
				frame.on( 'update', function ( selection ) {
					render( selection.models.slice( 0, 12 ).map( function ( m ) { return m.toJSON(); } ) );
				} );
			} );
			clear.addEventListener( 'click', function () { render( [] ); } );
		} )();
		</script>
		<?php
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
			if ( ( 0 === strpos( $key, '_rap_' ) && Resandpay_Codes::META !== $key ) || '_thumbnail_id' === $key ) {
				update_post_meta( $new, $key, maybe_unserialize( $values[0] ) );
			}
		}
		update_post_meta( $new, '_rap_cancelled', 0 );
		Resandpay_Codes::assign( $new );
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
			$cash = array_filter( Resandpay_Reservations::for_event( $post->ID ), fn( $r ) => Resandpay_Reservations::PAID !== $r['status'] && 'cash' === ( $r['pay_choice'] ?? '' ) );
			if ( $cash ) {
				/* translators: 1: počet, 2: částka */
				echo '<p>' . esc_html( sprintf( __( 'Zvolilo hotovost: %1$d (%2$s)', 'reserveandpay' ), count( $cash ), Resandpay_Events::format_price( array_sum( wp_list_pluck( $cash, 'amount' ) ), $ev['currency'] ) ) ) . '</p>';
			}
			if ( $ev['start'] ) {
				echo '<p><a class="button" href="' . esc_url( Resandpay_Admin_Checkin::url( array( 'day' => substr( $ev['start'], 0, 10 ) ) ) ) . '">' . esc_html__( 'Odbavení na místě', 'reserveandpay' ) . '</a></p>';
			}
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
		$bad = 'resandpay_ext_url_bad_' . get_current_user_id();
		if ( get_transient( $bad ) ) {
			delete_transient( $bad );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Adresa externího rezervačního systému nebyla uložena – musí začínat https:// (proměnné %DATE%, %TIME%, %CODE% jsou povolené).', 'reserveandpay' ) . '</p></div>';
		}
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
				$new['rap_code']     = __( 'Kód', 'reserveandpay' );
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
			case 'rap_code':
				echo '<code>' . esc_html( $ev['code'] ?: '—' ) . '</code>';
				break;
			case 'rap_date':
				$missing = self::missing( $ev );
				if ( $missing ) {
					echo '<span class="rap-tag rap-tag--off">' . esc_html( sprintf( /* translators: %s: pole */ __( 'Chybí: %s', 'reserveandpay' ), implode( ', ', $missing ) ) ) . '</span><br>';
				}
				echo esc_html( Resandpay_Events::format_date_full( $ev ) );
				if ( $ev['cancelled'] ) {
					echo ' <span class="rap-tag rap-tag--off">' . esc_html__( 'zrušeno', 'reserveandpay' ) . '</span>';
				}
				if ( $ev['external'] ) {
					echo ' <span class="rap-tag rap-tag--ext">' . esc_html__( 'Externí', 'reserveandpay' ) . '</span>';
				}
				echo '<br><small>' . esc_html( Resandpay_Events::type_labels()[ $ev['type'] ] ?? '' ) . '</small>';
				break;
			case 'rap_location':
				echo esc_html( $ev['location'] );
				break;
			case 'rap_occ':
				$b = Resandpay_Reservations::count_active( $post_id );
				if ( $ev['external'] && ! $b ) {
					echo '<span class="rap-hint">' . esc_html__( 'externí', 'reserveandpay' ) . '</span>';
					break;
				}
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
