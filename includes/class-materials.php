<?php
defined( 'ABSPATH' ) || exit;

/**
 * Knihovna materiálů ke stažení (SWD kap. 15.1, R11).
 *
 * Materiál = typ obsahu „Materiál“ (kód MA-NNN) s chráněnými soubory. Stažení je vždy
 * podmíněno přihlášením (kromě souborů označených jako ukázka). Přístup:
 * zdarma pro přihlášené / placený / jen vybrané role; navíc nárok z objednávky,
 * z ručního zpřístupnění a z zaplacené rezervace navázané akce či služby.
 *
 * Objednávka placeného materiálu je záznam v tabulce rezervací (item_type = material,
 * event_id = ID materiálu) – sdílí VS, QR platbu, kontrolu plateb, kupóny i e-maily.
 * Soubory leží mimo Knihovnu médií v chráněné složce a stahují se jen přes podepsaný,
 * časově omezený odkaz vázaný na přihlášeného uživatele.
 */
class Resandpay_Materials {

	const ACCESS_FREE  = 'free';
	const ACCESS_PAID  = 'paid';
	const ACCESS_ROLES = 'roles';

	/** Velikost části při nahrávání souborů po částech (bajty). */
	const CHUNK = 2097152;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ), 0 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	/* ---------------------------------------------------------------------
	 * Model
	 * ------------------------------------------------------------------- */

	public static function grants_table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_grants';
	}

	public static function downloads_table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_downloads';
	}

	public static function access_labels() {
		return array(
			self::ACCESS_FREE  => __( 'Zdarma pro přihlášené', 'reserveandpay' ),
			self::ACCESS_PAID  => __( 'Placený', 'reserveandpay' ),
			self::ACCESS_ROLES => __( 'Jen vybrané role', 'reserveandpay' ),
		);
	}

	/** Druhy materiálů z nastavení: klíč => název. */
	public static function kinds() {
		$out = array();
		foreach ( preg_split( '/\R/', (string) Resandpay_Settings::get( 'mat_kinds' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$key   = sanitize_key( $parts[0] ?? '' );
			if ( '' !== $key ) {
				$out[ $key ] = '' !== ( $parts[1] ?? '' ) ? $parts[1] : $key;
			}
		}
		return $out ?: array( 'other' => __( 'Materiál', 'reserveandpay' ) );
	}

	public static function is_material( $post_id ) {
		return Resandpay_Post_Types::MATERIAL === get_post_type( $post_id );
	}

	/**
	 * Materiál jako pole.
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( $id );
		if ( ! $post || Resandpay_Post_Types::MATERIAL !== $post->post_type ) {
			return null;
		}
		$m      = function ( $k, $def = '' ) use ( $post ) {
			$v = get_post_meta( $post->ID, '_rap_' . $k, true );
			return ( '' === $v || null === $v ) ? $def : $v;
		};
		$kinds  = self::kinds();
		$kind   = (string) $m( 'mat_kind', array_key_first( $kinds ) );
		$access = (string) $m( 'mat_access', self::ACCESS_FREE );
		$days   = $m( 'mat_days', '' );
		return array(
			'id'          => (int) $post->ID,
			'code'        => Resandpay_Codes::get( $post->ID ),
			'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'status'      => $post->post_status,
			'excerpt'     => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
			'thumb_id'    => (int) get_post_thumbnail_id( $post ),
			'kind'        => $kind,
			'kind_label'  => $kinds[ $kind ] ?? $kind,
			'access'      => isset( self::access_labels()[ $access ] ) ? $access : self::ACCESS_FREE,
			'roles'       => array_values( array_filter( explode( ',', (string) $m( 'mat_roles' ) ) ) ),
			'price'       => (float) $m( 'price', 0 ),
			'currency'    => (string) $m( 'currency', Resandpay_Settings::get( 'currency' ) ),
			'due_days'    => '' === $m( 'mat_due' ) ? (int) Resandpay_Settings::get( 'mat_due_days' ) : (int) $m( 'mat_due' ),
			'access_days' => '' === $days ? (int) Resandpay_Settings::get( 'mat_access_days' ) : max( 0, (int) $days ),
			'links'       => array_values( array_filter( array_map( 'absint', explode( ',', (string) $m( 'mat_links' ) ) ) ) ),
			'files'       => self::files( $post->ID ),
			'withdrawn'   => (bool) (int) $m( 'mat_withdrawn', 0 ),
			'url'         => get_permalink( $post ),
		);
	}

	/**
	 * Materiál jako „akce“ pro sdílený platební tok (objednávka = rezervace bez termínu a kapacity).
	 * Pole odpovídají Resandpay_Events::get().
	 */
	public static function as_event( $post ) {
		$mat = self::get( $post );
		if ( ! $mat ) {
			return null;
		}
		return array(
			'id'             => $mat['id'],
			'code'           => $mat['code'],
			'title'          => $mat['title'],
			'status'         => $mat['status'],
			'type'           => 'material',
			'kind_label'     => $mat['kind_label'],
			'start'          => '',
			'end'            => '',
			'capacity'       => PHP_INT_MAX,
			'price'          => $mat['price'],
			'currency'       => $mat['currency'],
			'due_days'       => $mat['due_days'],
			'due_mode'       => 'booking',
			'due_offset'     => 0,
			'cash'           => false,
			'part1_mode'     => 'none',
			'part1_value'    => 0.0,
			'part1_days'     => 7,
			'close_hours'    => 0.0,
			'time_display'   => '',
			'show_time'      => false,
			'cancelled'      => false,
			'members_only'   => false,
			'access'         => array(
				'mode'        => 'all',
				'roles'       => array(),
				'open_at'     => '',
				'priority_at' => '',
			),
			'program'        => '',
			'gallery'        => array(),
			'location_id'    => 0,
			'location'       => '',
			'location_addr'  => '',
			'location_map'   => '',
			'service_id'     => 0,
			'reminder_hours' => 0,
			'reminder_note'  => '',
			'external'       => null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Soubory v chráněné složce
	 * ------------------------------------------------------------------- */

	/** Kořen chráněné složky (mimo Knihovnu médií, přímý přístup zakázán). */
	public static function base_dir() {
		$up = wp_upload_dir( null, false );
		return apply_filters( 'resandpay_material_dir', trailingslashit( $up['basedir'] ) . 'resandpay-protected' );
	}

	/** Vytvoří chráněnou složku s .htaccess (Apache) a prázdným index.php. */
	public static function ensure_dir() {
		$dir = self::base_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'dir', __( 'Nepodařilo se vytvořit složku pro materiály.', 'reserveandpay' ) );
		}
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			$rules = "# ReserveAndPay – chráněné materiály, přímý přístup zakázán\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n";
			file_put_contents( $ht, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/** Složka materiálu (relativně k base_dir), vytvoří se při prvním nahrání. */
	public static function material_subdir( $material_id ) {
		$sub = (string) get_post_meta( $material_id, '_rap_mat_dir', true );
		if ( ! preg_match( '/^m\d+-[a-z0-9]{10}$/', $sub ) ) {
			$sub = 'm' . (int) $material_id . '-' . strtolower( wp_generate_password( 10, false, false ) );
			update_post_meta( $material_id, '_rap_mat_dir', $sub );
		}
		return $sub;
	}

	/** Absolutní cesta k souboru z uložené relativní cesty – jen uvnitř chráněné složky. */
	public static function abs_path( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel || false !== strpos( $rel, '..' ) || ! preg_match( '#^m\d+-[a-z0-9]{10}/[A-Za-z0-9._-]+$#', $rel ) ) {
			return '';
		}
		return self::base_dir() . '/' . $rel;
	}

	/** Seznam souborů materiálu (seřazený). */
	public static function files( $material_id ) {
		$raw   = get_post_meta( $material_id, '_rap_mat_files', true );
		$files = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: array() );
		$out   = array();
		foreach ( $files as $f ) {
			if ( empty( $f['id'] ) || empty( $f['path'] ) ) {
				continue;
			}
			$out[] = array(
				'id'     => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $f['id'] ) ),
				'label'  => (string) ( $f['label'] ?? '' ),
				'name'   => sanitize_file_name( (string) ( $f['name'] ?? basename( (string) $f['path'] ) ) ),
				'path'   => (string) $f['path'],
				'size'   => (int) ( $f['size'] ?? 0 ),
				'mime'   => (string) ( $f['mime'] ?? 'application/octet-stream' ),
				'sample' => ! empty( $f['sample'] ),
			);
		}
		return $out;
	}

	public static function save_files( $material_id, array $files ) {
		update_post_meta( $material_id, '_rap_mat_files', wp_slash( wp_json_encode( array_values( $files ) ) ) );
	}

	public static function file( $material_id, $file_id ) {
		foreach ( self::files( $material_id ) as $f ) {
			if ( $f['id'] === $file_id ) {
				return $f;
			}
		}
		return null;
	}

	/** Povolené přípony souborů (WordPress + e-knihy a běžná audio/video). */
	public static function allowed_types() {
		$types = get_allowed_mime_types();
		$types['epub']     = 'application/epub+zip';
		$types['mobi']     = 'application/x-mobipocket-ebook';
		$types['m4a']      = 'audio/mp4';
		$types['m4v']      = 'video/x-m4v';
		$types['webm']     = 'video/webm';
		$types['mp4|m4v']  = 'video/mp4';
		return apply_filters( 'resandpay_material_mime_types', $types );
	}

	/** MIME podle přípony; null = nepovolený typ. */
	public static function check_type( $name ) {
		$ft = wp_check_filetype( $name, self::allowed_types() );
		return $ft['ext'] ? $ft['type'] : null;
	}

	/**
	 * Přijme jednu část nahrávaného souboru (nahrávání po částech obchází limit velikosti
	 * jednoho požadavku na hostingu). Poslední část soubor dokončí.
	 *
	 * @param string $upload_id Náhodný identifikátor nahrávání (z prohlížeče).
	 * @param int    $offset    Pořadí bajtu, od kterého část začíná.
	 * @param int    $total     Celková velikost souboru.
	 * @param string $chunk_tmp Cesta k dočasnému souboru s částí.
	 * @return array|WP_Error ['done' => bool, 'received' => int, 'file' => záznam souboru]
	 */
	public static function receive_chunk( $material_id, $upload_id, $name, $offset, $total, $chunk_tmp ) {
		if ( ! preg_match( '/^[a-z0-9]{16,40}$/', (string) $upload_id ) ) {
			return new WP_Error( 'bad_id', __( 'Neplatné nahrávání.', 'reserveandpay' ) );
		}
		$name = sanitize_file_name( wp_basename( (string) $name ) );
		$mime = self::check_type( $name );
		if ( ! $mime ) {
			return new WP_Error( 'type', __( 'Tento typ souboru není povolen.', 'reserveandpay' ) );
		}
		$base = self::ensure_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		wp_mkdir_p( $base . '/tmp' );
		$part = $base . '/tmp/' . $upload_id . '.part';
		$have = file_exists( $part ) ? (int) filesize( $part ) : 0;
		if ( (int) $offset !== $have ) {
			return new WP_Error( 'offset', __( 'Části souboru nepřišly ve správném pořadí – nahrajte soubor znovu.', 'reserveandpay' ), array( 'received' => $have ) );
		}
		$in  = fopen( $chunk_tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = fopen( $part, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return new WP_Error( 'io', __( 'Soubor se nepodařilo uložit.', 'reserveandpay' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		clearstatcache( true, $part );
		$have = (int) filesize( $part );
		if ( $have < (int) $total ) {
			return array(
				'done'     => false,
				'received' => $have,
			);
		}
		if ( $have > (int) $total ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return new WP_Error( 'size', __( 'Velikost souboru nesouhlasí – nahrajte soubor znovu.', 'reserveandpay' ) );
		}
		$sub = self::material_subdir( $material_id );
		wp_mkdir_p( $base . '/' . $sub );
		$stored = strtolower( wp_generate_password( 12, false, false ) ) . '-' . preg_replace( '/[^A-Za-z0-9._-]/', '', remove_accents( $name ) );
		$stored = substr( $stored, 0, 120 );
		if ( ! rename( $part, $base . '/' . $sub . '/' . $stored ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'io', __( 'Soubor se nepodařilo uložit.', 'reserveandpay' ) );
		}
		return array(
			'done'     => true,
			'received' => $have,
			'file'     => array(
				'id'     => strtolower( wp_generate_password( 12, false, false ) ),
				'label'  => preg_replace( '/\.[^.]+$/', '', $name ),
				'name'   => $name,
				'path'   => $sub . '/' . $stored,
				'size'   => $have,
				'mime'   => $mime,
				'sample' => false,
			),
		);
	}

	/** Smaže soubor z disku (jen uvnitř chráněné složky). */
	public static function delete_file_path( $rel ) {
		$abs = self::abs_path( $rel );
		if ( $abs && file_exists( $abs ) ) {
			@unlink( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/** Trvalé smazání materiálu: smaže i jeho soubory. */
	public static function on_delete_post( $post_id ) {
		if ( ! self::is_material( $post_id ) ) {
			return;
		}
		foreach ( self::files( $post_id ) as $f ) {
			self::delete_file_path( $f['path'] );
		}
		$sub = (string) get_post_meta( $post_id, '_rap_mat_dir', true );
		if ( preg_match( '/^m\d+-[a-z0-9]{10}$/', $sub ) ) {
			@rmdir( self::base_dir() . '/' . $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/** Lidsky čitelná velikost. */
	public static function format_size( $bytes ) {
		$bytes = (float) $bytes;
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 1, ',', ' ' ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1, ',', ' ' ) . ' MB';
		}
		return max( 1, (int) round( $bytes / 1024 ) ) . ' kB';
	}

	/* ---------------------------------------------------------------------
	 * Přístup (nárok na materiál)
	 * ------------------------------------------------------------------- */

	private static function now_ts( $now ) {
		return null === $now ? time() : (int) $now;
	}

	/** Konec přístupu od okamžiku vzniku nároku (0 = natrvalo). */
	public static function until_from( array $mat, $from_local ) {
		if ( $mat['access_days'] <= 0 || empty( $from_local ) ) {
			return 0;
		}
		$from = Resandpay_Events::ts( $from_local );
		return ( new DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() )->modify( '+' . (int) $mat['access_days'] . ' days' )->setTime( 23, 59, 59 )->getTimestamp();
	}

	/** Zaplacené objednávky materiálu uživatelem (nejnovější první). */
	public static function orders_for( $material_id, $user_id, $statuses = null ) {
		global $wpdb;
		$statuses = $statuses ?? array( Resandpay_Reservations::PAID );
		$in       = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Resandpay_Reservations::table() . " WHERE item_type = 'material' AND event_id = %d AND user_id = %d AND status IN ({$in}) ORDER BY id DESC", $material_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Rozpracovaná (nezaplacená) objednávka uživatele, je-li. */
	public static function open_order( $material_id, $user_id ) {
		$rows = self::orders_for( $material_id, $user_id, array( Resandpay_Reservations::PENDING, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::OVERDUE ) );
		return $rows ? $rows[0] : null;
	}

	/** Aktivní ruční zpřístupnění. */
	public static function grants( $material_id = 0, $user_id = 0, $active_only = true ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( $material_id ) {
			$where[] = $wpdb->prepare( 'material_id = %d', $material_id );
		}
		if ( $user_id ) {
			$where[] = $wpdb->prepare( 'user_id = %d', $user_id );
		}
		if ( $active_only ) {
			$where[] = 'revoked_at IS NULL';
		}
		return $wpdb->get_results( 'SELECT * FROM ' . self::grants_table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Zaplacené rezervace navázaných akcí / služeb (účastnice dostávají materiál zdarma).
	 * Uživatel = rezervace s jeho ID účtu nebo s jeho e-mailem.
	 */
	public static function event_reservations( array $mat, $user_id ) {
		global $wpdb;
		if ( ! $mat['links'] || ! $user_id ) {
			return array();
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return array();
		}
		$ids  = array_map( 'intval', $mat['links'] );
		$list = implode( ',', $ids );
		$t    = Resandpay_Reservations::table();
		// Akce přímo, nebo termíny navázaných služeb (_rap_service).
		$sql = "SELECT r.* FROM {$t} r LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = r.event_id AND pm.meta_key = '_rap_service'
			WHERE r.item_type = 'event' AND r.status = %s AND ( r.user_id = %d OR r.email = %s )
			AND ( r.event_id IN ({$list}) OR pm.meta_value IN ({$list}) ) ORDER BY r.paid_at ASC";
		return $wpdb->get_results( $wpdb->prepare( $sql, Resandpay_Reservations::PAID, $user_id, $u->user_email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function user_has_role( $user_id, array $roles ) {
		$u = $user_id ? get_userdata( $user_id ) : null;
		return $u && array_intersect( (array) $u->roles, $roles );
	}

	/**
	 * Má uživatel přístup k materiálu?
	 *
	 * @return array ok (bool), reason (login|buy|pending|role|expired|withdrawn|unavailable),
	 *               source (admin|free|role|order|grant|event), until (timestamp, 0 = natrvalo),
	 *               order (otevřená / poslední objednávka), expired_at (timestamp).
	 */
	public static function access( $material, $user_id = null, $now = null ) {
		$mat     = is_array( $material ) ? $material : self::get( $material );
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$now     = self::now_ts( $now );
		$res     = array(
			'ok'         => false,
			'reason'     => 'login',
			'source'     => '',
			'until'      => 0,
			'order'      => null,
			'expired_at' => 0,
		);
		if ( ! $mat ) {
			$res['reason'] = 'unavailable';
			return $res;
		}
		if ( ! $user_id ) {
			return $res;
		}
		if ( user_can( $user_id, resandpay_cap_reservations() ) ) {
			return array_merge( $res, array( 'ok' => true, 'reason' => '', 'source' => 'admin' ) );
		}
		$published = in_array( $mat['status'], array( 'publish', 'private' ), true );
		$grant     = function ( $source, $until ) use ( $res ) {
			return array_merge( $res, array( 'ok' => true, 'reason' => '', 'source' => $source, 'until' => (int) $until ) );
		};
		$best_expired = 0;
		// Objednávka (zaplacená) – nárok zůstává i po stažení materiálu z nabídky.
		foreach ( self::orders_for( $mat['id'], $user_id ) as $o ) {
			$until = self::until_from( $mat, $o['paid_at'] ?: $o['updated_at'] );
			if ( ! $until || $until >= $now ) {
				$out          = $grant( 'order', $until );
				$out['order'] = $o;
				return $out;
			}
			$best_expired = max( $best_expired, $until );
		}
		// Ruční zpřístupnění.
		foreach ( self::grants( $mat['id'], $user_id ) as $g ) {
			$until = $g['expires_at'] ? Resandpay_Events::ts( $g['expires_at'] ) : 0;
			if ( ! $until || $until >= $now ) {
				return $grant( 'grant', $until );
			}
			$best_expired = max( $best_expired, $until );
		}
		// Účastnice navázané akce / služby.
		foreach ( self::event_reservations( $mat, $user_id ) as $r ) {
			$until = self::until_from( $mat, $r['paid_at'] ?: $r['updated_at'] );
			if ( ! $until || $until >= $now ) {
				return $grant( 'event', $until );
			}
			$best_expired = max( $best_expired, $until );
		}
		if ( ! $published ) {
			$res['reason'] = 'unavailable';
			return $res;
		}
		if ( self::ACCESS_FREE === $mat['access'] ) {
			return $grant( 'free', 0 );
		}
		if ( self::ACCESS_ROLES === $mat['access'] ) {
			if ( self::user_has_role( $user_id, $mat['roles'] ) ) {
				return $grant( 'role', 0 );
			}
			$res['reason'] = 'role';
			return $res;
		}
		// Placený materiál bez nároku.
		$open = self::open_order( $mat['id'], $user_id );
		if ( $open ) {
			$res['reason'] = 'pending';
			$res['order']  = $open;
			return $res;
		}
		$res['expired_at'] = $best_expired;
		$res['reason']     = $mat['withdrawn'] ? 'withdrawn' : ( $best_expired ? 'expired' : 'buy' );
		return $res;
	}

	/** Lze materiál koupit (placený, publikovaný, v nabídce, s cenou)? */
	public static function purchasable( array $mat ) {
		return self::ACCESS_PAID === $mat['access'] && 'publish' === $mat['status'] && ! $mat['withdrawn'] && $mat['price'] > 0;
	}

	/** Text „Přístup do …“ k objednávce (pro e-maily). */
	public static function order_until_text( array $r ) {
		$mat = self::get( $r['event_id'] );
		if ( ! $mat || $mat['access_days'] <= 0 ) {
			return '';
		}
		$from  = ! empty( $r['paid_at'] ) ? $r['paid_at'] : current_time( 'mysql' );
		$until = self::until_from( $mat, $from );
		/* translators: %s: datum */
		return sprintf( __( 'Přístup k materiálu platí do %s.', 'reserveandpay' ), wp_date( 'j. n. Y', $until ) );
	}

	/** Adresa stránky „Moje materiály“. */
	public static function my_url() {
		$p = (int) Resandpay_Settings::get( 'mat_my_page' );
		return $p && get_permalink( $p ) ? get_permalink( $p ) : home_url( '/' );
	}

	/** Odkaz na přihlášení s návratem. */
	public static function login_url( $redirect ) {
		return wp_login_url( $redirect );
	}

	/** Odkaz na registraci (stránka z nastavení, jinak registrace WordPressu, je-li povolená). */
	public static function register_url() {
		$p = (int) Resandpay_Settings::get( 'mat_register_page' );
		if ( $p && get_permalink( $p ) ) {
			return get_permalink( $p );
		}
		return get_option( 'users_can_register' ) ? wp_registration_url() : '';
	}

	/**
	 * Materiály dostupné uživateli (pro „Moje materiály“).
	 *
	 * @return array[] Pole ['mat' => …, 'access' => …].
	 */
	public static function for_user( $user_id ) {
		$ids = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::MATERIAL,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$mat = self::get( $id );
			$acc = self::access( $mat, $user_id );
			if ( $acc['ok'] ) {
				$out[] = array(
					'mat'    => $mat,
					'access' => $acc,
				);
			}
		}
		return $out;
	}

	/** Všechny objednávky materiálů uživatele. */
	public static function user_orders( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Resandpay_Reservations::table() . " WHERE item_type = 'material' AND user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A );
	}

	/* ---------------------------------------------------------------------
	 * Objednávka placeného materiálu
	 * ------------------------------------------------------------------- */

	/**
	 * Vytvoří objednávku (jen přihlášený uživatel). Sdílí řadu VS a čísel proforem s rezervacemi.
	 *
	 * @param array $data customer_name, phone, company, company_ic, company_dic, consent_gdpr, consent_terms,
	 *                    consent_digital, newsletter, coupon.
	 * @param array $opts source (web|admin), notify, now.
	 * @return array|WP_Error Objednávka (řádek rezervace).
	 */
	public static function order( $material_id, $user_id, array $data, array $opts = array() ) {
		global $wpdb;
		$opts = wp_parse_args(
			$opts,
			array(
				'source' => 'web',
				'notify' => true,
				'now'    => time(),
			)
		);
		$mat  = self::get( $material_id );
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return new WP_Error( 'login', __( 'Pro objednávku se prosím přihlaste.', 'reserveandpay' ) );
		}
		if ( ! $mat || ! self::purchasable( $mat ) ) {
			return new WP_Error( 'not_available', __( 'Materiál teď nelze objednat.', 'reserveandpay' ) );
		}
		if ( 'web' === $opts['source'] && ( empty( $data['consent_gdpr'] ) || empty( $data['consent_terms'] ) || empty( $data['consent_digital'] ) ) ) {
			return new WP_Error( 'consent', __( 'Pro objednávku jsou nutné všechny tři souhlasy.', 'reserveandpay' ) );
		}
		$name = trim( (string) ( $data['customer_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		}
		$lock = 'mat_order_' . (int) $user_id;
		if ( ! Resandpay_Lock::acquire( $lock ) ) {
			return new WP_Error( 'busy', __( 'Systém je právě vytížen, zkuste to prosím za chvíli znovu.', 'reserveandpay' ) );
		}
		$coupon_code = trim( (string) ( $data['coupon'] ?? '' ) );
		$coupon_lock = '' !== $coupon_code;
		if ( $coupon_lock && ! Resandpay_Lock::acquire( 'coupons' ) ) {
			Resandpay_Lock::release( $lock );
			return new WP_Error( 'busy', __( 'Systém je právě vytížen, zkuste to prosím za chvíli znovu.', 'reserveandpay' ) );
		}
		try {
			$acc = self::access( $mat, $user_id, $opts['now'] );
			if ( $acc['ok'] ) {
				return new WP_Error( 'owned', __( 'Materiál už máte k dispozici.', 'reserveandpay' ) );
			}
			if ( 'pending' === $acc['reason'] ) {
				return new WP_Error( 'pending', __( 'Materiál už máte objednaný – čeká se na platbu.', 'reserveandpay' ), $acc['order'] );
			}
			$ev    = self::as_event( get_post( $material_id ) );
			$price = Resandpay_Discounts::compute(
				$ev,
				array(
					'now'    => $opts['now'],
					'coupon' => $coupon_code,
					'email'  => $user->user_email,
				)
			);
			if ( $price['error'] ) {
				return $price['error'];
			}
			$seq = Resandpay_Reservations::next_sequence();
			if ( is_wp_error( $seq ) ) {
				return $seq;
			}
			$now_ts = (int) $opts['now'];
			$amount = $price['free'] ? 0.0 : round( $price['price_full'] - $price['discount'], 2 );
			$due    = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( wp_timezone() )->modify( '+' . max( 0, (int) $mat['due_days'] ) . ' days' )->setTime( 23, 59, 59 )->getTimestamp();
			$local  = wp_date( 'Y-m-d H:i:s', $now_ts );
			$row    = array(
				'event_id'           => (int) $material_id,
				'item_type'          => 'material',
				'status'             => $price['free'] ? Resandpay_Reservations::PAID : Resandpay_Reservations::PENDING,
				'pay_choice'         => 'transfer',
				'vs'                 => Resandpay_Reservations::format_vs( $seq, $now_ts ),
				'proforma_no'        => Resandpay_Reservations::format_proforma( $seq, $now_ts ),
				'token'              => wp_generate_password( 40, false, false ),
				'customer_name'      => mb_substr( sanitize_text_field( $name ), 0, 190 ),
				'email'              => $user->user_email,
				'phone'              => mb_substr( sanitize_text_field( $data['phone'] ?? '' ), 0, 50 ),
				'company'            => mb_substr( sanitize_text_field( $data['company'] ?? '' ), 0, 190 ),
				'company_ic'         => mb_substr( sanitize_text_field( $data['company_ic'] ?? '' ), 0, 20 ),
				'company_dic'        => mb_substr( sanitize_text_field( $data['company_dic'] ?? '' ), 0, 20 ),
				'note'               => '',
				'amount'             => $amount,
				'price_full'         => $price['price_full'],
				'discount_amount'    => $price['discount'],
				'discount_type'      => $price['type'],
				'discount_label'     => mb_substr( $price['label'], 0, 190 ),
				'coupon_id'          => $price['coupon_id'],
				'paid_at'            => $price['free'] ? $local : null,
				'paid_method'        => $price['free'] ? 'free' : '',
				'currency'           => $mat['currency'],
				'last_minute'        => 0,
				'due_at'             => wp_date( 'Y-m-d H:i:s', $due ),
				'part1_amount'       => 0,
				'amount_paid'        => 0,
				'consent_gdpr_at'    => ! empty( $data['consent_gdpr'] ) ? $local : null,
				'consent_terms_at'   => ! empty( $data['consent_terms'] ) ? $local : null,
				'consent_digital_at' => ! empty( $data['consent_digital'] ) ? $local : null,
				'newsletter'         => ! empty( $data['newsletter'] ) ? 1 : 0,
				'user_id'            => (int) $user_id,
				'source'             => 'admin' === $opts['source'] ? 'admin' : 'web',
				'created_at'         => $local,
				'updated_at'         => $local,
			);
			if ( ! $wpdb->insert( Resandpay_Reservations::table(), $row ) ) {
				return new WP_Error( 'db', __( 'Objednávku se nepodařilo uložit.', 'reserveandpay' ) );
			}
			$id = (int) $wpdb->insert_id;
		} finally {
			if ( $coupon_lock ) {
				Resandpay_Lock::release( 'coupons' );
			}
			Resandpay_Lock::release( $lock );
		}
		if ( $price['discount'] > 0 ) {
			/* translators: 1: popis slevy, 2: částka */
			Resandpay_Discounts::log( $id, 'discount', sprintf( __( 'Uplatněna sleva: %1$s −%2$s', 'reserveandpay' ), $price['label'], Resandpay_Events::format_price( $price['discount'], $mat['currency'] ) ) );
		}
		$order = Resandpay_Reservations::get( $id );
		do_action( 'resandpay_reservation_created', $order, $opts );
		do_action( 'resandpay_material_ordered', $order, $mat );
		if ( $opts['notify'] ) {
			Resandpay_Mailer::send_received( $order );
		}
		return $order;
	}

	/* ---------------------------------------------------------------------
	 * Ruční zpřístupnění
	 * ------------------------------------------------------------------- */

	/**
	 * Zpřístupní materiál uživateli (dárek, reklamace…).
	 *
	 * @param int $days 0 = podle materiálu (výchozí doba přístupu), -1 = natrvalo.
	 * @return int|WP_Error ID záznamu.
	 */
	public static function grant( $material_id, $user_id, $days = 0, $note = '', $notify = false ) {
		global $wpdb;
		$mat  = self::get( $material_id );
		$user = get_userdata( $user_id );
		if ( ! $mat || ! $user ) {
			return new WP_Error( 'invalid', __( 'Materiál nebo uživatel nenalezen.', 'reserveandpay' ) );
		}
		$days    = (int) $days;
		$days    = 0 === $days ? $mat['access_days'] : max( 0, $days );
		$expires = $days > 0 ? wp_date( 'Y-m-d 23:59:59', time() + $days * DAY_IN_SECONDS ) : null;
		$wpdb->insert(
			self::grants_table(),
			array(
				'material_id' => (int) $material_id,
				'user_id'     => (int) $user_id,
				'note'        => mb_substr( sanitize_text_field( $note ), 0, 190 ),
				'granted_at'  => current_time( 'mysql' ),
				'granted_by'  => get_current_user_id(),
				'expires_at'  => $expires,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $notify ) {
			$r = self::pseudo_order( $mat, $user );
			if ( $expires ) {
				/* translators: %s: datum */
				$r['access_note'] = sprintf( __( 'Přístup k materiálu platí do %s.', 'reserveandpay' ), wp_date( 'j. n. Y', Resandpay_Events::ts( $expires ) ) );
			}
			add_filter( 'resandpay_mail_placeholders', $f = fn( $ph, $rr ) => array_merge( $ph, array( '{access_until}' => (string) ( $rr['access_note'] ?? '' ) ) ), 10, 2 );
			Resandpay_Mailer::send_template( $user->user_email, 'mat_grant', $r );
			remove_filter( 'resandpay_mail_placeholders', $f, 10 );
		}
		return $id;
	}

	public static function revoke( $grant_id, $reason = '' ) {
		global $wpdb;
		return (bool) $wpdb->update(
			self::grants_table(),
			array(
				'revoked_at'    => current_time( 'mysql' ),
				'revoked_by'    => get_current_user_id(),
				'revoke_reason' => mb_substr( sanitize_text_field( $reason ), 0, 190 ),
			),
			array(
				'id'         => (int) $grant_id,
				'revoked_at' => null,
			)
		);
	}

	/** Řádek „jako objednávka“ pro e-mail bez objednávky (ruční zpřístupnění). */
	public static function pseudo_order( array $mat, WP_User $user ) {
		return array(
			'id'              => 0,
			'event_id'        => $mat['id'],
			'item_type'       => 'material',
			'status'          => Resandpay_Reservations::PAID,
			'customer_name'   => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			'email'           => $user->user_email,
			'phone'           => '',
			'note'            => '',
			'amount'          => 0,
			'price_full'      => 0,
			'amount_paid'     => 0,
			'currency'        => $mat['currency'],
			'vs'              => '',
			'proforma_no'     => '',
			'due_at'          => null,
			'paid_at'         => current_time( 'mysql' ),
			'cancel_reason'   => '',
			'pay_choice'      => 'transfer',
			'part1_amount'    => 0,
			'part1_due_at'    => null,
			'discount_amount' => 0,
			'discount_label'  => '',
			'coupon_id'       => 0,
			'last_minute'     => 0,
		);
	}

	/* ---------------------------------------------------------------------
	 * Odkazy ke stažení a doručení souboru
	 * ------------------------------------------------------------------- */

	private static function b64( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function unb64( $s ) {
		return base64_decode( strtr( (string) $s, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function sign( $payload ) {
		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'rap-dl' ), 0, 32 );
	}

	/**
	 * Podepsaný odkaz ke stažení: platí jen pro daného uživatele a omezenou dobu.
	 *
	 * @param bool $inline Přehrát v prohlížeči (video / audio / PDF) místo stažení.
	 */
	public static function download_url( $material_id, $file_id, $inline = false, $user_id = null, $now = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$exp     = self::now_ts( $now ) + max( 5, (int) Resandpay_Settings::get( 'mat_link_minutes' ) ) * MINUTE_IN_SECONDS;
		$payload = self::b64( wp_json_encode( array( (int) $user_id, (int) $material_id, (string) $file_id, $exp, $inline ? 1 : 0 ) ) );
		return add_query_arg( 'rap_dl', $payload . '.' . self::sign( $payload ), home_url( '/' ) );
	}

	/**
	 * Ověří token odkazu.
	 *
	 * @return array|WP_Error [user, material, file, exp, inline]
	 */
	public static function parse_token( $token, $now = null ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return new WP_Error( 'bad_token', __( 'Odkaz ke stažení je neplatný.', 'reserveandpay' ) );
		}
		$data = json_decode( (string) self::unb64( $parts[0] ), true );
		if ( ! is_array( $data ) || 5 !== count( $data ) ) {
			return new WP_Error( 'bad_token', __( 'Odkaz ke stažení je neplatný.', 'reserveandpay' ) );
		}
		if ( (int) $data[3] < self::now_ts( $now ) ) {
			return new WP_Error( 'expired_link', __( 'Platnost odkazu ke stažení vypršela. Načtěte prosím stránku materiálu znovu.', 'reserveandpay' ) );
		}
		return array(
			'user'     => (int) $data[0],
			'material' => (int) $data[1],
			'file'     => (string) $data[2],
			'exp'      => (int) $data[3],
			'inline'   => (bool) $data[4],
		);
	}

	/** Počet stažení souboru uživatelem za posledních 24 h (bez přehrávání). */
	public static function downloads_24h( $user_id, $file_id, $now = null ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::downloads_table() . " WHERE user_id = %d AND file_id = %s AND kind = 'download' AND result = 'ok' AND created_at > %s", $user_id, $file_id, wp_date( 'Y-m-d H:i:s', self::now_ts( $now ) - DAY_IN_SECONDS ) ) );
	}

	public static function log_download( $user_id, $material_id, $file_id, $kind, $result ) {
		global $wpdb;
		$wpdb->insert(
			self::downloads_table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'user_id'     => (int) $user_id,
				'material_id' => (int) $material_id,
				'file_id'     => (string) $file_id,
				'kind'        => $kind,
				'result'      => $result,
				'ip_hash'     => class_exists( 'Resandpay_Antibot' ) ? Resandpay_Antibot::ip_hash() : '',
			)
		);
		if ( 'limit' === $result ) {
			self::notify_limit( $user_id, $material_id );
		}
	}

	/** Upozornění správce na překročený limit stažení (nejvýš 1× denně na uživatele). */
	private static function notify_limit( $user_id, $material_id ) {
		$key = 'rap_dl_limit_' . (int) $user_id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		$to = Resandpay_Settings::get( 'admin_email' );
		$u  = get_userdata( $user_id );
		if ( ! is_email( $to ) || ! $u ) {
			return;
		}
		/* translators: 1: e-mail uživatele, 2: materiál */
		$subj = sprintf( __( 'Překročen limit stažení: %1$s – %2$s', 'reserveandpay' ), $u->user_email, get_the_title( $material_id ) );
		$body = sprintf( /* translators: 1: e-mail, 2: materiál, 3: limit */ __( "Uživatel %1\$s překročil limit stažení u materiálu %2\$s (%3\$d× za 24 h). Může jít o sdílení účtu – podrobnosti najdete u materiálu v administraci (Přístupy).", 'reserveandpay' ), $u->user_email, get_the_title( $material_id ), (int) Resandpay_Settings::get( 'mat_download_limit' ) );
		$ok   = Resandpay_Mailer::send_raw( $to, $subj, Resandpay_Mailer::html_layout( $subj, Resandpay_Mailer::text_to_html( $body ) ), $body );
		Resandpay_Mailer::record( 'security', $to, $subj, $ok, 0 );
	}

	/**
	 * Rozsah požadavku HTTP Range pro soubor dané velikosti.
	 *
	 * @return array|null|false [start, end]; null = celý soubor; false = neplatný rozsah (416).
	 */
	public static function parse_range( $header, $size ) {
		if ( ! $header || ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( (string) $header ), $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null;
		}
		if ( '' === $m[1] ) {
			$len   = min( (int) $m[2], $size );
			$start = $size - $len;
			$end   = $size - 1;
		} else {
			$start = (int) $m[1];
			$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		}
		if ( $start > $end || $start >= $size ) {
			return false;
		}
		return array( $start, $end );
	}

	/**
	 * Rozhodne o požadavku na stažení (bez odeslání souboru – testovatelné).
	 *
	 * @return array|WP_Error [file, abs, material, kind, user] nebo chyba (data: status).
	 */
	public static function authorize( $token, $user_id = null, $range_start = 0, $now = null ) {
		$t = self::parse_token( $token, $now );
		if ( is_wp_error( $t ) ) {
			$t->add_data( array( 'status' => 403 ) );
			return $t;
		}
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$mat     = self::get( $t['material'] );
		$file    = $mat ? self::file( $mat['id'], $t['file'] ) : null;
		if ( ! $mat || ! $file ) {
			return new WP_Error( 'not_found', __( 'Soubor už není k dispozici.', 'reserveandpay' ), array( 'status' => 404 ) );
		}
		if ( $t['user'] !== $user_id ) {
			return new WP_Error( 'login', __( 'Odkaz ke stažení patří jinému přihlášení. Přihlaste se a načtěte stránku materiálu znovu.', 'reserveandpay' ), array( 'status' => 403 ) );
		}
		$sample = $file['sample'] && in_array( $mat['status'], array( 'publish', 'private' ), true );
		if ( ! $sample ) {
			$acc = self::access( $mat, $user_id, $now );
			if ( ! $acc['ok'] ) {
				return new WP_Error( 'denied', 'login' === $acc['reason'] ? (string) Resandpay_Settings::get( 'mat_login_text' ) : __( 'K tomuto materiálu nemáte přístup.', 'reserveandpay' ), array( 'status' => 403 ) );
			}
		}
		$abs = self::abs_path( $file['path'] );
		if ( ! $abs || ! is_readable( $abs ) ) {
			return new WP_Error( 'not_found', __( 'Soubor se nepodařilo najít. Dejte nám prosím vědět.', 'reserveandpay' ), array( 'status' => 404 ) );
		}
		$kind  = $sample ? 'sample' : ( $t['inline'] ? 'play' : 'download' );
		$limit = (int) Resandpay_Settings::get( 'mat_download_limit' );
		// Limit jen pro skutečné stažení (první požadavek, ne pokračování přerušeného stahování).
		if ( 'download' === $kind && $limit > 0 && 0 === (int) $range_start && $user_id && ! user_can( $user_id, resandpay_cap_reservations() ) && self::downloads_24h( $user_id, $file['id'], $now ) >= $limit ) {
			self::log_download( $user_id, $mat['id'], $file['id'], $kind, 'limit' );
			/* translators: %d: limit */
			return new WP_Error( 'limit', sprintf( __( 'Soubor jste za posledních 24 hodin stáhli už %d×. Zkuste to prosím později, případně nás kontaktujte.', 'reserveandpay' ), $limit ), array( 'status' => 429 ) );
		}
		return array(
			'file'     => $file,
			'abs'      => $abs,
			'material' => $mat,
			'kind'     => $kind,
			'user'     => $user_id,
			'inline'   => $t['inline'],
		);
	}

	/** Obsluha ?rap_dl=… – ověření a odeslání souboru po částech s podporou Range. */
	public static function maybe_download() {
		if ( empty( $_GET['rap_dl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['rap_dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$start = preg_match( '/^bytes=(\d+)-/', $range, $mm ) ? (int) $mm[1] : 0;
		$auth  = self::authorize( $token, null, $start );
		if ( is_wp_error( $auth ) ) {
			$status = (int) ( $auth->get_error_data()['status'] ?? 403 );
			$back   = wp_get_referer() ?: home_url( '/' );
			wp_die( '<p>' . esc_html( $auth->get_error_message() ) . '</p><p><a href="' . esc_url( $back ) . '">' . esc_html__( '← Zpět', 'reserveandpay' ) . '</a></p>', esc_html__( 'Stažení materiálu', 'reserveandpay' ), array( 'response' => $status ) );
		}
		$size = (int) filesize( $auth['abs'] );
		$rng  = self::parse_range( $range, $size );
		if ( false === $rng ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		if ( null === $rng || 0 === $rng[0] ) {
			self::log_download( $auth['user'], $auth['material']['id'], $auth['file']['id'], $auth['kind'], 'ok' );
		}
		self::stream( $auth['abs'], $auth['file'], $auth['inline'], $rng, $size );
		exit;
	}

	/** Odeslání souboru (bez načtení do paměti). */
	private static function stream( $abs, array $file, $inline, $rng, $size ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$start = $rng ? $rng[0] : 0;
		$end   = $rng ? $rng[1] : $size - 1;
		$name  = $file['name'] ?: basename( $abs );
		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', remove_accents( $name ) );
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: ' . ( $file['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Accept-Ranges: bytes' );
		if ( $rng ) {
			status_header( 206 );
			header( "Content-Range: bytes {$start}-{$end}/{$size}" );
		} else {
			status_header( 200 );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		$fh = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return;
		}
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$buf = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $buf ) {
				break;
			}
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput
			$left -= strlen( $buf );
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Přehledy a údržba
	 * ------------------------------------------------------------------- */

	/** Počty pro administraci: objednávky (zaplaceno / celkem), stažení za 30 dní. */
	public static function counts( $material_id ) {
		global $wpdb;
		$t = Resandpay_Reservations::table();
		return array(
			'orders'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE item_type = 'material' AND event_id = %d", $material_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'paid'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE item_type = 'material' AND event_id = %d AND status = 'paid'", $material_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'revenue'   => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_paid),0) FROM {$t} WHERE item_type = 'material' AND event_id = %d", $material_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'downloads' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::downloads_table() . " WHERE material_id = %d AND result = 'ok' AND kind <> 'sample' AND created_at > %s", $material_id, wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ),
		);
	}

	/** Poslední záznamy o stažení materiálu. */
	public static function recent_downloads( $material_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::downloads_table() . ' WHERE material_id = %d ORDER BY id DESC LIMIT %d', $material_id, $limit ), ARRAY_A );
	}

	/** Úklid: staré záznamy o stažení a nedokončená nahrávání. */
	public static function prune() {
		global $wpdb;
		$days = (int) Resandpay_Settings::get( 'mat_log_days' );
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::downloads_table() . ' WHERE created_at < %s', wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		}
		$tmp = self::base_dir() . '/tmp';
		if ( is_dir( $tmp ) ) {
			foreach ( (array) glob( $tmp . '/*.part' ) as $f ) {
				if ( $f && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
					@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				}
			}
		}
	}

	/**
	 * Je chráněná složka opravdu nedostupná z webu? Zkusí stáhnout zkušební soubor přes URL.
	 *
	 * @return string ok | exposed | unknown
	 */
	public static function protection_status( $force = false ) {
		$cached = get_transient( 'resandpay_mat_protection' );
		if ( $cached && ! $force ) {
			return $cached;
		}
		$dir = self::ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return 'unknown';
		}
		$probe = 'probe-' . strtolower( wp_generate_password( 8, false, false ) ) . '.txt';
		file_put_contents( $dir . '/' . $probe, 'rap-probe' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$up   = wp_upload_dir( null, false );
		$url  = str_replace( trailingslashit( $up['basedir'] ), trailingslashit( $up['baseurl'] ), $dir . '/' . $probe );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		@unlink( $dir . '/' . $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( is_wp_error( $resp ) ) {
			$status = 'unknown';
		} else {
			$status = ( 200 === (int) wp_remote_retrieve_response_code( $resp ) && false !== strpos( (string) wp_remote_retrieve_body( $resp ), 'rap-probe' ) ) ? 'exposed' : 'ok';
		}
		set_transient( 'resandpay_mat_protection', $status, 12 * HOUR_IN_SECONDS );
		return $status;
	}
}
