<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace materiálů ke stažení (SWD kap. 15.1, R11): parametry, soubory
 * (nahrávání po částech do chráněné složky), přístupy a ruční zpřístupnění.
 */
class Resandpay_Admin_Materials {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Resandpay_Post_Types::MATERIAL, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_ajax_resandpay_mat_upload', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'admin_post_resandpay_mat_grant', array( __CLASS__, 'handle_grant' ) );
		add_action( 'admin_post_resandpay_mat_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . Resandpay_Post_Types::MATERIAL . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Resandpay_Post_Types::MATERIAL . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || Resandpay_Post_Types::MATERIAL !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'resandpay-mat-upload', RESANDPAY_URL . 'assets/js/admin-materials.js', array(), RESANDPAY_VERSION, true );
		wp_localize_script(
			'resandpay-mat-upload',
			'rapMat',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'resandpay_mat_upload' ),
				'chunk' => self::chunk_size(),
				'i18n'  => array(
					'uploading' => __( 'Nahrávám', 'reserveandpay' ),
					'done'      => __( 'Nahráno – nezapomeňte materiál uložit.', 'reserveandpay' ),
					'error'     => __( 'Chyba nahrávání', 'reserveandpay' ),
					'remove'    => __( 'odstranit', 'reserveandpay' ),
					'sample'    => __( 'ukázka (bez přihlášení)', 'reserveandpay' ),
				),
			)
		);
	}

	/** Velikost části: nejvýš 2 MB a pod limitem hostingu pro jeden požadavek. */
	public static function chunk_size() {
		$limit = min( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ?: '2M' ), wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ?: '8M' ) );
		return (int) max( 262144, min( Resandpay_Materials::CHUNK, $limit - 65536 ) );
	}

	public static function meta_boxes() {
		add_meta_box( 'rap_material', __( 'Parametry materiálu', 'reserveandpay' ), array( __CLASS__, 'box_params' ), Resandpay_Post_Types::MATERIAL, 'normal', 'high' );
		add_meta_box( 'rap_material_files', __( 'Soubory ke stažení', 'reserveandpay' ), array( __CLASS__, 'box_files' ), Resandpay_Post_Types::MATERIAL, 'normal', 'high' );
		add_meta_box( 'rap_material_access', __( 'Přístupy', 'reserveandpay' ), array( __CLASS__, 'box_access' ), Resandpay_Post_Types::MATERIAL, 'normal', 'default' );
	}

	public static function box_params( $post ) {
		wp_nonce_field( 'rap_save_material', '_rap_material_nonce' );
		$mat   = Resandpay_Materials::get( $post->ID );
		$raw   = fn( $k ) => (string) get_post_meta( $post->ID, '_rap_' . $k, true );
		$roles = $mat['roles'];
		$links = $mat['links'];
		$svcs  = get_posts( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$evs   = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => array( 'publish', 'draft', 'future' ),
				'posts_per_page' => 300,
				'meta_key'       => '_rap_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_rap_type',
						'value'   => 'slot',
						'compare' => '!=',
					),
				),
			)
		);
		?>
		<p class="rap-code"><?php esc_html_e( 'Kód:', 'reserveandpay' ); ?> <?php echo $mat['code'] ? '<code>' . esc_html( $mat['code'] ) . '</code> · <code>[reserveandpay_material id="' . esc_html( $mat['code'] ) . '"]</code>' : esc_html__( 'přidělí se při uložení', 'reserveandpay' ); ?></p>
		<p class="description"><?php esc_html_e( 'Text v editoru výše je popis materiálu na webu. Náhledový obrázek (poměr 3:4) se zobrazí v katalogu; bez obrázku se použije zástupný motiv.', 'reserveandpay' ); ?></p>
		<table class="form-table">
			<tr><th><label for="rap_mat_kind"><?php esc_html_e( 'Druh', 'reserveandpay' ); ?></label></th>
				<td><select id="rap_mat_kind" name="rap_mat_kind">
					<?php foreach ( Resandpay_Materials::kinds() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $mat['kind'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?></select>
					<p class="description"><?php esc_html_e( 'Seznam druhů je v ResAndPay → Nastavení → Materiály ke stažení.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Přístup', 'reserveandpay' ); ?></th>
				<td><?php foreach ( Resandpay_Materials::access_labels() as $k => $l ) : ?>
					<label style="margin-right:1.4em"><input type="radio" name="rap_mat_access" value="<?php echo esc_attr( $k ); ?>" <?php checked( $mat['access'], $k ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Stažení je vždy jen pro přihlášené (kromě souborů označených jako ukázka). Placený: materiál se odemkne po zaplacení objednávky (QR platba, kontrola plateb).', 'reserveandpay' ); ?></p>
					<div style="margin-top:.6em"><strong><?php esc_html_e( 'Role (pro „Jen vybrané role“):', 'reserveandpay' ); ?></strong><br>
					<?php foreach ( wp_roles()->get_names() as $rk => $rl ) : ?>
						<label style="margin-right:1.2em"><input type="checkbox" name="rap_mat_roles[]" value="<?php echo esc_attr( $rk ); ?>" <?php checked( in_array( $rk, $roles, true ) ); ?>> <?php echo esc_html( translate_user_role( $rl ) ); ?></label>
					<?php endforeach; ?></div></td></tr>
			<tr><th><label for="rap_price"><?php esc_html_e( 'Cena', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" step="any" id="rap_price" name="rap_price" value="<?php echo esc_attr( $raw( 'price' ) ); ?>" class="regular-text" style="width:10em">
					<input type="text" name="rap_currency" value="<?php echo esc_attr( $mat['currency'] ); ?>" class="small-text" maxlength="3" aria-label="<?php esc_attr_e( 'Měna', 'reserveandpay' ); ?>">
					<p class="description"><?php esc_html_e( 'Jen u placeného materiálu. Platí i slevové kupóny (rozsah „všechny“, kategorie nebo konkrétní materiál).', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_mat_due"><?php esc_html_e( 'Splatnost objednávky', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" max="90" id="rap_mat_due" name="rap_mat_due" value="<?php echo esc_attr( $raw( 'mat_due' ) ); ?>" class="small-text" placeholder="<?php echo esc_attr( (string) Resandpay_Settings::get( 'mat_due_days' ) ); ?>"> <?php esc_html_e( 'dní', 'reserveandpay' ); ?>
					<p class="description"><?php esc_html_e( 'Prázdné = výchozí z nastavení. Nezaplacená objednávka po splatnosti vyprší; pozdní platba ji obnoví.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_mat_days"><?php esc_html_e( 'Doba přístupu', 'reserveandpay' ); ?></label></th>
				<td><input type="number" min="0" id="rap_mat_days" name="rap_mat_days" value="<?php echo esc_attr( $raw( 'mat_days' ) ); ?>" class="small-text" placeholder="<?php echo esc_attr( (string) Resandpay_Settings::get( 'mat_access_days' ) ); ?>"> <?php esc_html_e( 'dní', 'reserveandpay' ); ?>
					<p class="description"><?php esc_html_e( '0 = natrvalo, prázdné = výchozí z nastavení. Počítá se od zaplacení objednávky (u účastnic akce od zaplacení rezervace, u ručního zpřístupnění od jeho data).', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><label for="rap_mat_links"><?php esc_html_e( 'Zdarma pro účastnice', 'reserveandpay' ); ?></label></th>
				<td><select id="rap_mat_links" name="rap_mat_links[]" multiple size="8" style="min-width:28em;max-width:100%">
					<optgroup label="<?php esc_attr_e( 'Služby (všechny termíny)', 'reserveandpay' ); ?>">
					<?php foreach ( $svcs as $sv ) : ?>
						<option value="<?php echo (int) $sv->ID; ?>" <?php selected( in_array( $sv->ID, $links, true ) ); ?>><?php echo esc_html( $sv->post_title . ( Resandpay_Codes::get( $sv->ID ) ? ' (' . Resandpay_Codes::get( $sv->ID ) . ')' : '' ) ); ?></option>
					<?php endforeach; ?></optgroup>
					<optgroup label="<?php esc_attr_e( 'Akce', 'reserveandpay' ); ?>">
					<?php foreach ( $evs as $e ) : $ev = Resandpay_Events::get( $e->ID ); ?>
						<option value="<?php echo (int) $e->ID; ?>" <?php selected( in_array( $e->ID, $links, true ) ); ?>><?php echo esc_html( $e->post_title . ( $ev ? ' – ' . Resandpay_Events::format_date_only( Resandpay_Events::ts( $ev['start'] ), Resandpay_Events::ts( $ev['end'] ) ) : '' ) ); ?></option>
					<?php endforeach; ?></optgroup>
				</select>
					<p class="description"><?php esc_html_e( 'Kdo má zaplacenou rezervaci vybrané akce nebo termínu služby, má materiál zdarma (podle účtu nebo stejného e-mailu). Výběr více položek s Ctrl / Cmd.', 'reserveandpay' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Nabídka', 'reserveandpay' ); ?></th>
				<td><label><input type="checkbox" name="rap_mat_withdrawn" value="1" <?php checked( $mat['withdrawn'] ); ?>> <?php esc_html_e( 'Staženo z nabídky', 'reserveandpay' ); ?></label>
					<p class="description"><?php esc_html_e( 'Materiál zmizí z katalogu a nelze ho objednat; kdo už ho má, stahuje dál.', 'reserveandpay' ); ?></p></td></tr>
		</table>
		<?php
	}

	public static function box_files( $post ) {
		$files  = Resandpay_Materials::files( $post->ID );
		$status = Resandpay_Materials::protection_status();
		if ( 'exposed' === $status ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Pozor: chráněná složka je z webu dostupná (hosting nečte soubor .htaccess). Soubory nejsou chráněné – kontaktujte správce hostingu.', 'reserveandpay' ) . '</p></div>';
		}
		echo '<input type="hidden" name="rap_files_present" value="1">';
		echo '<table class="widefat striped rap-mat-files-admin"><thead><tr><th>' . esc_html__( 'Název na webu', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Soubor', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Ukázka', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Pořadí', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Odstranit', 'reserveandpay' ) . '</th></tr></thead><tbody id="rap-mat-files">';
		foreach ( $files as $i => $f ) {
			echo self::file_row( $f, $i ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
		}
		echo '</tbody></table>';
		if ( ! $files ) {
			echo '<p class="rap-mat-empty description">' . esc_html__( 'Zatím žádné soubory.', 'reserveandpay' ) . '</p>';
		}
		echo '<p style="margin-top:1em"><input type="file" id="rap-mat-upload" multiple data-post="' . (int) $post->ID . '"> <span id="rap-mat-progress" class="description"></span></p>';
		echo '<p class="description">' . esc_html__( 'Soubory se ukládají mimo Knihovnu médií do chráněné složky a stahují se jen přes odkaz pro přihlášeného uživatele s přístupem. Velké soubory (videa) se nahrávají po částech – limit velikosti je dán jen místem na hostingu. Odstraněný soubor se smaže z disku po uložení materiálu.', 'reserveandpay' ) . '</p>';
	}

	/** Řádek tabulky souborů (i pro nově nahraný soubor – JS vkládá stejnou strukturu). */
	public static function file_row( array $f, $i ) {
		$n   = 'rap_files[' . esc_attr( $f['id'] ) . ']';
		$out = '<tr><td><input type="text" class="widefat" name="' . $n . '[label]" value="' . esc_attr( $f['label'] ) . '">';
		$out .= '<input type="hidden" name="' . $n . '[data]" value="' . esc_attr( wp_json_encode( array_intersect_key( $f, array_flip( array( 'id', 'name', 'path', 'size', 'mime' ) ) ) ) ) . '"></td>';
		$out .= '<td>' . esc_html( $f['name'] ) . '<br><small>' . esc_html( Resandpay_Materials::format_size( $f['size'] ) . ' · ' . $f['mime'] ) . '</small></td>';
		$out .= '<td><label><input type="checkbox" name="' . $n . '[sample]" value="1" ' . checked( $f['sample'], true, false ) . '> ' . esc_html__( 'bez přihlášení', 'reserveandpay' ) . '</label></td>';
		$out .= '<td><input type="number" class="small-text" name="' . $n . '[order]" value="' . (int) ( $i + 1 ) . '"></td>';
		$out .= '<td><label><input type="checkbox" name="' . $n . '[remove]" value="1"> ' . esc_html__( 'odstranit', 'reserveandpay' ) . '</label></td></tr>';
		return $out;
	}

	public static function box_access( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Po uložení materiálu zde uvidíte objednávky, přístupy a stažení.', 'reserveandpay' ) . '</p>';
			return;
		}
		$mat = Resandpay_Materials::get( $post->ID );
		$c   = Resandpay_Materials::counts( $post->ID );
		/* translators: 1: zaplaceno, 2: objednávek, 3: tržba, 4: stažení */
		echo '<p>' . esc_html( sprintf( __( 'Objednávky: %1$d zaplaceno z %2$d · přijato %3$s · stažení za 30 dní: %4$d', 'reserveandpay' ), $c['paid'], $c['orders'], Resandpay_Events::format_price( $c['revenue'], $mat['currency'] ), $c['downloads'] ) ) . ' · <a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay&event_id=' . $post->ID ) ) . '">' . esc_html__( 'objednávky v přehledu rezervací →', 'reserveandpay' ) . '</a></p>';

		$grants = Resandpay_Materials::grants( $post->ID, 0, false );
		echo '<h4>' . esc_html__( 'Ručně zpřístupněno', 'reserveandpay' ) . '</h4>';
		if ( $grants ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Uživatel', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Od', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Do', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Poznámka', 'reserveandpay' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $grants as $g ) {
				$u = get_userdata( $g['user_id'] );
				echo '<tr><td>' . esc_html( $u ? $u->display_name . ' <' . $u->user_email . '>' : '#' . $g['user_id'] ) . '</td><td>' . esc_html( wp_date( 'j. n. Y', Resandpay_Events::ts( $g['granted_at'] ) ) ) . '</td><td>' . esc_html( $g['expires_at'] ? wp_date( 'j. n. Y', Resandpay_Events::ts( $g['expires_at'] ) ) : __( 'natrvalo', 'reserveandpay' ) ) . '</td><td>' . esc_html( $g['note'] ) . '</td><td>';
				if ( $g['revoked_at'] ) {
					/* translators: %s: datum */
					echo '<em>' . esc_html( sprintf( __( 'odebráno %s', 'reserveandpay' ), wp_date( 'j. n. Y', Resandpay_Events::ts( $g['revoked_at'] ) ) ) . ( $g['revoke_reason'] ? ' – ' . $g['revoke_reason'] : '' ) ) . '</em>';
				} else {
					$url = wp_nonce_url( admin_url( 'admin-post.php?action=resandpay_mat_revoke&grant=' . (int) $g['id'] . '&material=' . (int) $post->ID ), 'rap_mat_revoke_' . (int) $g['id'] );
					echo '<a href="' . esc_url( $url ) . '" onclick="var r=prompt(\'' . esc_js( __( 'Důvod odebrání přístupu:', 'reserveandpay' ) ) . '\');if(r===null)return false;this.href+=\'&reason=\'+encodeURIComponent(r);">' . esc_html__( 'Odebrat', 'reserveandpay' ) . '</a>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'Nikomu.', 'reserveandpay' ) . '</p>';
		}
		echo '<p style="margin-top:.8em"><label>' . esc_html__( 'E-mail uživatele', 'reserveandpay' ) . ' <input type="email" name="rap_grant_email" class="regular-text"></label> ';
		echo '<label>' . esc_html__( 'dní', 'reserveandpay' ) . ' <input type="number" name="rap_grant_days" min="-1" class="small-text" placeholder="0"></label> ';
		echo '<label>' . esc_html__( 'poznámka', 'reserveandpay' ) . ' <input type="text" name="rap_grant_note" class="regular-text" style="width:14em"></label> ';
		echo '<label><input type="checkbox" name="rap_grant_notify" value="1" checked> ' . esc_html__( 'poslat e-mail', 'reserveandpay' ) . '</label> ';
		echo '<input type="hidden" name="rap_grant_nonce" value="' . esc_attr( wp_create_nonce( 'rap_mat_grant_' . $post->ID ) ) . '">';
		echo '<button type="submit" class="button" formaction="' . esc_url( admin_url( 'admin-post.php?action=resandpay_mat_grant&material=' . (int) $post->ID ) ) . '" formnovalidate>' . esc_html__( 'Zpřístupnit', 'reserveandpay' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Uživatel musí mít na webu účet. Dní: 0 / prázdné = doba přístupu materiálu, −1 = natrvalo. Pozor: tlačítko neuloží ostatní změny materiálu.', 'reserveandpay' ) . '</p>';

		$rows = Resandpay_Materials::recent_downloads( $post->ID, 20 );
		echo '<h4>' . esc_html__( 'Poslední stažení', 'reserveandpay' ) . '</h4>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Zatím žádná.', 'reserveandpay' ) . '</p>';
			return;
		}
		$names = array();
		foreach ( $mat['files'] as $f ) {
			$names[ $f['id'] ] = $f['label'] ?: $f['name'];
		}
		$kinds   = array(
			'download' => __( 'stažení', 'reserveandpay' ),
			'play'     => __( 'přehrání', 'reserveandpay' ),
			'sample'   => __( 'ukázka', 'reserveandpay' ),
		);
		$results = array(
			'ok'    => __( 'OK', 'reserveandpay' ),
			'limit' => __( 'zablokováno – limit', 'reserveandpay' ),
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Čas', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Uživatel', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Soubor', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Druh', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Výsledek', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
			echo '<tr><td>' . esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $r['created_at'] ) ) ) . '</td><td>' . esc_html( $u ? $u->user_email : '—' ) . '</td><td>' . esc_html( $names[ $r['file_id'] ] ?? $r['file_id'] ) . '</td><td>' . esc_html( $kinds[ $r['kind'] ] ?? $r['kind'] ) . '</td><td>' . esc_html( $results[ $r['result'] ] ?? $r['result'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Uložení
	 * ------------------------------------------------------------------- */

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['_rap_material_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rap_material_nonce'] ) ), 'rap_save_material' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::save_meta( $post_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitizace v save_meta().
	}

	/** Uloží parametry a soubory materiálu (sdíleno s testy). */
	public static function save_meta( $post_id, array $in ) {
		$kinds = Resandpay_Materials::kinds();
		$kind  = sanitize_key( $in['rap_mat_kind'] ?? '' );
		update_post_meta( $post_id, '_rap_mat_kind', isset( $kinds[ $kind ] ) ? $kind : array_key_first( $kinds ) );
		$access = sanitize_key( $in['rap_mat_access'] ?? Resandpay_Materials::ACCESS_FREE );
		update_post_meta( $post_id, '_rap_mat_access', isset( Resandpay_Materials::access_labels()[ $access ] ) ? $access : Resandpay_Materials::ACCESS_FREE );
		$known = array_keys( wp_roles()->get_names() );
		update_post_meta( $post_id, '_rap_mat_roles', implode( ',', array_intersect( array_map( 'sanitize_key', (array) ( $in['rap_mat_roles'] ?? array() ) ), $known ) ) );
		$price = str_replace( ',', '.', (string) ( $in['rap_price'] ?? '' ) );
		update_post_meta( $post_id, '_rap_price', '' === $price ? '' : max( 0, round( (float) $price, 2 ) ) );
		$cur = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['rap_currency'] ?? '' ) ), 0, 3 ) );
		update_post_meta( $post_id, '_rap_currency', $cur ?: Resandpay_Settings::get( 'currency' ) );
		foreach ( array( 'rap_mat_due' => '_rap_mat_due', 'rap_mat_days' => '_rap_mat_days' ) as $k => $meta ) {
			$v = trim( (string) ( $in[ $k ] ?? '' ) );
			update_post_meta( $post_id, $meta, '' === $v ? '' : max( 0, (int) $v ) );
		}
		update_post_meta( $post_id, '_rap_mat_links', implode( ',', array_filter( array_map( 'absint', (array) ( $in['rap_mat_links'] ?? array() ) ) ) ) );
		update_post_meta( $post_id, '_rap_mat_withdrawn', empty( $in['rap_mat_withdrawn'] ) ? 0 : 1 );

		// Soubory: popisky, ukázka, pořadí, odstranění; nově nahrané (JS) mají data v hidden poli.
		if ( isset( $in['rap_files'] ) || isset( $in['rap_files_present'] ) ) {
			$old   = array();
			foreach ( Resandpay_Materials::files( $post_id ) as $f ) {
				$old[ $f['id'] ] = $f;
			}
			$sub   = Resandpay_Materials::material_subdir( $post_id );
			$keep  = array();
			foreach ( (array) ( $in['rap_files'] ?? array() ) as $id => $row ) {
				$id   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) );
				$data = $old[ $id ] ?? json_decode( (string) ( $row['data'] ?? '' ), true );
				if ( ! is_array( $data ) || empty( $data['path'] ) || 0 !== strpos( (string) $data['path'], $sub . '/' ) || ! Resandpay_Materials::abs_path( $data['path'] ) || ! file_exists( Resandpay_Materials::abs_path( $data['path'] ) ) ) {
					continue; // Jen soubory z chráněné složky tohoto materiálu.
				}
				if ( ! empty( $row['remove'] ) ) {
					Resandpay_Materials::delete_file_path( $data['path'] );
					unset( $old[ $id ] );
					continue;
				}
				$keep[] = array(
					'id'     => $id,
					'label'  => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'name'   => sanitize_file_name( (string) ( $data['name'] ?? '' ) ),
					'path'   => (string) $data['path'],
					'size'   => (int) filesize( Resandpay_Materials::abs_path( $data['path'] ) ),
					'mime'   => Resandpay_Materials::check_type( (string) ( $data['name'] ?? '' ) ) ?: 'application/octet-stream',
					'sample' => ! empty( $row['sample'] ),
					'order'  => (int) ( $row['order'] ?? 999 ),
				);
				unset( $old[ $id ] );
			}
			usort( $keep, fn( $a, $b ) => $a['order'] <=> $b['order'] );
			foreach ( $keep as &$k ) {
				unset( $k['order'] );
			}
			unset( $k );
			Resandpay_Materials::save_files( $post_id, $keep );
		}
	}

	/* ---------------------------------------------------------------------
	 * Nahrávání po částech (AJAX)
	 * ------------------------------------------------------------------- */

	public static function ajax_upload() {
		check_ajax_referer( 'resandpay_mat_upload', 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! Resandpay_Materials::is_material( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nemáte oprávnění.', 'reserveandpay' ) ), 403 );
		}
		if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'Část souboru nedorazila (limit hostingu?).', 'reserveandpay' ) ), 400 );
		}
		$res = Resandpay_Materials::receive_chunk(
			$post_id,
			sanitize_key( wp_unslash( $_POST['upload_id'] ?? '' ) ),
			sanitize_file_name( wp_unslash( $_POST['name'] ?? '' ) ),
			absint( $_POST['offset'] ?? 0 ),
			absint( $_POST['total'] ?? 0 ),
			$_FILES['chunk']['tmp_name'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		if ( $res['done'] ) {
			$res['row'] = self::file_row( $res['file'], 998 );
		}
		wp_send_json_success( $res );
	}

	/* ---------------------------------------------------------------------
	 * Ruční zpřístupnění / odebrání
	 * ------------------------------------------------------------------- */

	public static function handle_grant() {
		$mid = absint( $_GET['material'] ?? 0 );
		if ( ! $mid || ! isset( $_POST['rap_grant_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rap_grant_nonce'] ) ), 'rap_mat_grant_' . $mid ) || ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		$email = sanitize_email( wp_unslash( $_POST['rap_grant_email'] ?? '' ) );
		$user  = $email ? get_user_by( 'email', $email ) : null;
		$msg   = 'grant_nouser';
		if ( $user ) {
			$res = Resandpay_Materials::grant( $mid, $user->ID, (int) ( $_POST['rap_grant_days'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['rap_grant_note'] ?? '' ) ), ! empty( $_POST['rap_grant_notify'] ) );
			$msg = is_wp_error( $res ) ? 'grant_error' : 'granted';
		}
		wp_safe_redirect( add_query_arg( 'rap_msg', $msg, get_edit_post_link( $mid, 'url' ) ) . '#rap_material_access' );
		exit;
	}

	public static function handle_revoke() {
		$gid = absint( $_GET['grant'] ?? 0 );
		$mid = absint( $_GET['material'] ?? 0 );
		check_admin_referer( 'rap_mat_revoke_' . $gid );
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		Resandpay_Materials::revoke( $gid, sanitize_text_field( wp_unslash( $_GET['reason'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( 'rap_msg', 'revoked', get_edit_post_link( $mid, 'url' ) ) . '#rap_material_access' );
		exit;
	}

	public static function notices() {
		$msg = sanitize_key( $_GET['rap_msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$map = array(
			'granted'      => array( 'success', __( 'Materiál byl zpřístupněn.', 'reserveandpay' ) ),
			'revoked'      => array( 'success', __( 'Přístup byl odebrán.', 'reserveandpay' ) ),
			'grant_nouser' => array( 'error', __( 'Uživatel s tímto e-mailem na webu nemá účet.', 'reserveandpay' ) ),
			'grant_error'  => array( 'error', __( 'Materiál se nepodařilo zpřístupnit.', 'reserveandpay' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $msg ][1] ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Seznam materiálů
	 * ------------------------------------------------------------------- */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['rap_code']   = __( 'Kód', 'reserveandpay' );
				$out['rap_kind']   = __( 'Druh', 'reserveandpay' );
				$out['rap_access'] = __( 'Přístup', 'reserveandpay' );
				$out['rap_files']  = __( 'Souborů', 'reserveandpay' );
				$out['rap_orders'] = __( 'Objednávky', 'reserveandpay' );
				$out['rap_dl']     = __( 'Stažení (30 dní)', 'reserveandpay' );
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		static $cache = array();
		$mat = $cache[ $post_id ] ??= Resandpay_Materials::get( $post_id );
		if ( ! $mat ) {
			return;
		}
		switch ( $col ) {
			case 'rap_code':
				echo '<code>' . esc_html( $mat['code'] ) . '</code>';
				break;
			case 'rap_kind':
				echo esc_html( $mat['kind_label'] );
				break;
			case 'rap_access':
				echo esc_html( Resandpay_Materials::ACCESS_PAID === $mat['access'] ? Resandpay_Events::format_price( $mat['price'], $mat['currency'] ) : Resandpay_Materials::access_labels()[ $mat['access'] ] );
				if ( $mat['withdrawn'] ) {
					echo '<br><em>' . esc_html__( 'staženo z nabídky', 'reserveandpay' ) . '</em>';
				}
				break;
			case 'rap_files':
				echo (int) count( $mat['files'] );
				break;
			case 'rap_orders':
				$c = Resandpay_Materials::counts( $post_id );
				/* translators: 1: zaplaceno, 2: celkem */
				echo esc_html( sprintf( __( '%1$d / %2$d', 'reserveandpay' ), $c['paid'], $c['orders'] ) );
				break;
			case 'rap_dl':
				echo (int) Resandpay_Materials::counts( $post_id )['downloads'];
				break;
		}
	}
}
