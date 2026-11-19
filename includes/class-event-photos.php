<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fotky z proběhlé akce (R28, Issue #45).
 *
 * Samostatná komponenta vedle galerie v záhlaví: po skončení akce se na stránce akce místo
 * rezervačního formuláře zobrazí hláška „akce již proběhla“ a sekce s náhledy fotek.
 * Kdo fotky uvidí, se volí u akce: jen účastníci (zaplacená rezervace) · přihlášení · veřejné.
 *
 * Stejný princip jako materiály ke stažení: fotky leží mimo Knihovnu médií v chráněné složce
 * a posílá je plugin přes podepsaný odkaz vázaný na návštěvníka; přístup se ověří u každého
 * obrázku znovu. Zákazník bez účtu se prokáže osobním odkazem z e-mailu (Resandpay_Profile).
 */
class Resandpay_Event_Photos {

	const VIS_ATTENDEES = 'attendees';
	const VIS_LOGGED    = 'logged';
	const VIS_PUBLIC    = 'public';

	/** Parametr podepsaného odkazu na obrázek. */
	const QUERY = 'rap_ph';

	/** Delší strana uložené fotky a náhledu (px). */
	const MAX_SIZE   = 2048;
	const THUMB_SIZE = 480;

	/** Nejvýše fotek u jedné akce. */
	const MAX_PHOTOS = 500;

	/** Kolik náhledů je vidět hned (další po „Zobrazit další“). */
	const PAGE = 24;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_personal' ), 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_filter( 'resandpay_mail_placeholders', array( __CLASS__, 'placeholders' ), 10, 2 );
	}

	public static function visibility_labels() {
		return array(
			self::VIS_ATTENDEES => __( 'Jen účastníci akce (zaplacená rezervace)', 'reserveandpay' ),
			self::VIS_LOGGED    => __( 'Přihlášení uživatelé', 'reserveandpay' ),
			self::VIS_PUBLIC    => __( 'Veřejné – všichni návštěvníci', 'reserveandpay' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Stav akce
	 * ------------------------------------------------------------------- */

	/** Konec akce (timestamp): konec z akce, bez něj půlnoc po dni konání. */
	public static function ends_at( array $ev ) {
		$start = Resandpay_Events::ts( $ev['start'] ?? '' );
		$end   = Resandpay_Events::ts( $ev['end'] ?? '' );
		if ( $end > $start ) {
			return $end;
		}
		if ( ! $start ) {
			return 0;
		}
		// Bez konce (při uložení se konec vyplní začátkem) = půlnoc po dni konání.
		return ( new DateTimeImmutable( '@' . $start ) )->setTimezone( wp_timezone() )->setTime( 0, 0 )->modify( '+1 day' )->getTimestamp();
	}

	/** Akce už skončila? */
	public static function is_over( array $ev, $now = null ) {
		$end = self::ends_at( $ev );
		return $end && ( $now ?? time() ) >= $end;
	}

	/** Fotky z akce mají jen akce (ne termíny služeb). */
	public static function supported( array $ev ) {
		return 'slot' !== ( $ev['type'] ?? '' ) && ! Resandpay_Materials::is_material( (int) $ev['id'] );
	}

	public static function visibility( $event_id ) {
		$v = (string) get_post_meta( $event_id, '_rap_photos_vis', true );
		return array_key_exists( $v, self::visibility_labels() ) ? $v : self::VIS_ATTENDEES;
	}

	/* ---------------------------------------------------------------------
	 * Soubory
	 * ------------------------------------------------------------------- */

	/** Seznam fotek akce v uloženém pořadí. */
	public static function photos( $event_id ) {
		$raw  = get_post_meta( $event_id, '_rap_photos', true );
		$list = is_array( $raw ) ? $raw : ( json_decode( (string) $raw, true ) ?: array() );
		$out  = array();
		foreach ( $list as $p ) {
			if ( empty( $p['id'] ) || empty( $p['file'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $p['id'] ) ),
				'file'  => (string) $p['file'],
				'thumb' => (string) ( $p['thumb'] ?? $p['file'] ),
				'mime'  => (string) ( $p['mime'] ?? 'image/jpeg' ),
				'w'     => (int) ( $p['w'] ?? 0 ),
				'h'     => (int) ( $p['h'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function photo( $event_id, $photo_id ) {
		foreach ( self::photos( $event_id ) as $p ) {
			if ( $p['id'] === $photo_id ) {
				return $p;
			}
		}
		return null;
	}

	private static function save_list( $event_id, array $list ) {
		update_post_meta( $event_id, '_rap_photos', wp_slash( wp_json_encode( array_values( $list ) ) ) );
	}

	/** Složka fotek akce (relativně ke chráněné složce materiálů). */
	public static function subdir( $event_id ) {
		$sub = (string) get_post_meta( $event_id, '_rap_photos_dir', true );
		if ( ! preg_match( '/^e\d+-[a-z0-9]{10}$/', $sub ) ) {
			$sub = 'e' . (int) $event_id . '-' . strtolower( wp_generate_password( 10, false, false ) );
			update_post_meta( $event_id, '_rap_photos_dir', $sub );
		}
		return $sub;
	}

	/** Absolutní cesta z relativní – jen uvnitř chráněné složky a složky fotek akce. */
	public static function abs_path( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( false !== strpos( $rel, '..' ) || ! preg_match( '#^e\d+-[a-z0-9]{10}/[a-z0-9-]+\.(jpg|png|webp)$#', $rel ) ) {
			return '';
		}
		return Resandpay_Materials::base_dir() . '/' . $rel;
	}

	public static function mime_types() {
		return array(
			'jpg'  => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
	}

	/**
	 * Přidá fotku k akci. Fotku zmenší na MAX_SIZE (prohlížeč ji obvykle zmenšil už před
	 * nahráním) a vytvoří čtvercový náhled. Bez knihovny pro úpravu obrázků na hostingu se
	 * použije nahraný soubor i jako náhled.
	 *
	 * @param string $tmp  Cesta k nahranému souboru (po přidání se smaže).
	 * @param string $name Původní název (kvůli příponě).
	 * @return array|WP_Error Záznam fotky.
	 */
	public static function add( $event_id, $tmp, $name ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev || ! self::supported( $ev ) ) {
			return new WP_Error( 'no_event', __( 'Fotky lze přidat jen k akci.', 'reserveandpay' ) );
		}
		$list = self::photos( $event_id );
		if ( count( $list ) >= self::MAX_PHOTOS ) {
			/* translators: %d: počet */
			return new WP_Error( 'too_many', sprintf( __( 'Akce může mít nejvýše %d fotek.', 'reserveandpay' ), self::MAX_PHOTOS ) );
		}
		$info = is_readable( $tmp ) ? @getimagesize( $tmp ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ext  = $info ? array_search( $info['mime'], self::mime_types(), true ) : false;
		if ( ! $info || ! $ext ) {
			return new WP_Error( 'type', __( 'Soubor není fotka ve formátu JPG, PNG nebo WebP.', 'reserveandpay' ) );
		}
		$dir = Resandpay_Materials::ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$sub = self::subdir( $event_id );
		if ( ! wp_mkdir_p( $dir . '/' . $sub ) ) {
			return new WP_Error( 'dir', __( 'Nepodařilo se vytvořit složku pro fotky.', 'reserveandpay' ) );
		}
		$id   = strtolower( wp_generate_password( 12, false, false ) );
		$file = $sub . '/' . $id . '.' . $ext;
		$abs  = self::abs_path( $file );
		if ( ! $abs || ! copy( $tmp, $abs ) ) {
			return new WP_Error( 'copy', __( 'Fotku se nepodařilo uložit.', 'reserveandpay' ) );
		}
		wp_delete_file( $tmp );
		$thumb = $file;
		$ed    = function_exists( 'wp_get_image_editor' ) ? wp_get_image_editor( $abs ) : new WP_Error( 'no_editor' );
		if ( ! is_wp_error( $ed ) ) {
			if ( method_exists( $ed, 'maybe_exif_rotate' ) ) {
				$ed->maybe_exif_rotate();
			}
			$sz = $ed->get_size();
			if ( max( $sz['width'], $sz['height'] ) > self::MAX_SIZE ) {
				$ed->resize( self::MAX_SIZE, self::MAX_SIZE, false );
			}
			$ed->set_quality( 85 );
			$ed->save( $abs, $info['mime'] );
			$te = wp_get_image_editor( $abs );
			if ( ! is_wp_error( $te ) && ! is_wp_error( $te->resize( self::THUMB_SIZE, self::THUMB_SIZE, true ) ) ) {
				$te->set_quality( 80 );
				$t_rel = $sub . '/' . $id . '-t.' . $ext;
				if ( ! is_wp_error( $te->save( self::abs_path( $t_rel ), $info['mime'] ) ) ) {
					$thumb = $t_rel;
				}
			}
			clearstatcache();
			$info = @getimagesize( $abs ) ?: $info; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$rec    = array(
			'id'    => $id,
			'file'  => $file,
			'thumb' => $thumb,
			'mime'  => $info['mime'],
			'w'     => (int) $info[0],
			'h'     => (int) $info[1],
		);
		$list[] = $rec;
		self::save_list( $event_id, $list );
		return $rec;
	}

	/** Smaže soubory jedné fotky z disku. */
	private static function delete_files( array $p ) {
		foreach ( array_unique( array( $p['file'], $p['thumb'] ) ) as $rel ) {
			$abs = self::abs_path( $rel );
			if ( $abs && file_exists( $abs ) ) {
				wp_delete_file( $abs );
			}
		}
	}

	/**
	 * Uloží pořadí fotek podle seznamu ID; fotky, které v seznamu nejsou, se smažou i z disku.
	 */
	public static function set_order( $event_id, array $ids ) {
		$ids  = array_values( array_unique( array_map( fn( $x ) => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $x ) ), $ids ) ) );
		$by   = array();
		foreach ( self::photos( $event_id ) as $p ) {
			$by[ $p['id'] ] = $p;
		}
		$keep = array();
		foreach ( $ids as $id ) {
			if ( isset( $by[ $id ] ) ) {
				$keep[] = $by[ $id ];
				unset( $by[ $id ] );
			}
		}
		foreach ( $by as $p ) {
			self::delete_files( $p );
		}
		self::save_list( $event_id, $keep );
	}

	/** Uložení voleb z editoru akce (jen pole, která formulář posílá). */
	public static function save_meta( $event_id, array $in ) {
		if ( array_key_exists( 'rap_photos_vis', $in ) ) {
			$v = sanitize_key( (string) $in['rap_photos_vis'] );
			update_post_meta( $event_id, '_rap_photos_vis', array_key_exists( $v, self::visibility_labels() ) ? $v : self::VIS_ATTENDEES );
		}
		if ( array_key_exists( 'rap_photos_order', $in ) ) {
			self::set_order( $event_id, array_filter( explode( ',', (string) $in['rap_photos_order'] ) ) );
		}
	}

	/** Smazání akce = smazání jejích fotek. */
	public static function on_delete_post( $post_id ) {
		if ( Resandpay_Post_Types::EVENT !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( self::photos( $post_id ) as $p ) {
			self::delete_files( $p );
		}
		$sub = (string) get_post_meta( $post_id, '_rap_photos_dir', true );
		if ( preg_match( '/^e\d+-[a-z0-9]{10}$/', $sub ) && is_dir( Resandpay_Materials::base_dir() . '/' . $sub ) ) {
			@rmdir( Resandpay_Materials::base_dir() . '/' . $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/* ---------------------------------------------------------------------
	 * Přístup
	 * ------------------------------------------------------------------- */

	/**
	 * Účastník = zákazník se zaplacenou rezervací akce (i zaplacenou na místě); zrušené,
	 * propadlé a nezaplacené se nepočítají. Podle účtu nebo e-mailu (i e-mailu účtu).
	 */
	public static function is_attendee( $event_id, $user_id, $email = '' ) {
		global $wpdb;
		$or    = array();
		$email = Resandpay_Materials::email_of( (int) $user_id, $email );
		if ( $user_id ) {
			$or[] = $wpdb->prepare( 'user_id = %d', $user_id );
		}
		if ( '' !== $email ) {
			$or[] = $wpdb->prepare( 'LOWER(TRIM(email)) = %s', $email );
		}
		if ( ! $or ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Resandpay_Reservations::table() . " WHERE item_type = 'event' AND event_id = %d AND status = %s AND (" . implode( ' OR ', $or ) . ')', $event_id, Resandpay_Reservations::PAID ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Smí návštěvník (účet / e-mail z osobního odkazu) fotky akce vidět? */
	public static function can_view( $event_id, $user_id, $email = '' ) {
		$vis = self::visibility( $event_id );
		if ( self::VIS_PUBLIC === $vis ) {
			return true;
		}
		$user_id = $user_id && get_userdata( $user_id ) ? (int) $user_id : 0;
		if ( $user_id && ( self::VIS_LOGGED === $vis || user_can( $user_id, 'edit_post', $event_id ) ) ) {
			return true;
		}
		return self::is_attendee( $event_id, $user_id, $email );
	}

	/** Návštěvník aktuálního požadavku: přihlášený uživatel a e-mail (z osobního odkazu nebo účtu). */
	public static function viewer() {
		$cur = Resandpay_Profile::current();
		return array(
			'user'  => get_current_user_id(),
			'email' => 'link' === $cur['via'] ? $cur['email'] : '',
			'token' => $cur['token'],
			'error' => $cur['error'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Podepsané odkazy na obrázky
	 * ------------------------------------------------------------------- */

	private static function sign( $payload ) {
		return substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'rap-ph' ), 0, 32 );
	}

	private static function b64( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	/**
	 * Odkaz na obrázek. Veřejné fotky: odkaz bez omezení; ostatní: vázaný na návštěvníka,
	 * platí 6–7 hodin (zaokrouhleno na hodinu, aby se obrázky daly uložit do mezipaměti prohlížeče).
	 *
	 * @param string $size t = náhled, l = fotka.
	 */
	public static function url( $event_id, $photo_id, $size, $user_id, $email = '', $now = null ) {
		$public = self::VIS_PUBLIC === self::visibility( $event_id );
		$exp    = $public ? 0 : (int) ( ceil( ( ( $now ?? time() ) + 6 * HOUR_IN_SECONDS ) / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS );
		$data   = array( (int) $event_id, (string) $photo_id, 't' === $size ? 't' : 'l', $public ? 0 : (int) $user_id, $public ? '' : Resandpay_Customers::key( $email ), $exp );
		$pay    = self::b64( wp_json_encode( $data ) );
		return add_query_arg( self::QUERY, $pay . '.' . self::sign( $pay ), home_url( '/' ) );
	}

	/**
	 * Ověří odkaz na obrázek a přístup návštěvníka (znovu, při každém obrázku).
	 *
	 * @return array|WP_Error abs (cesta), mime, public.
	 */
	public static function authorize( $token, $now = null ) {
		$parts = explode( '.', (string) $token );
		$bad   = new WP_Error( 'bad', __( 'Odkaz na fotku je neplatný.', 'reserveandpay' ), array( 'status' => 403 ) );
		if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return $bad;
		}
		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		if ( ! is_array( $data ) || 6 !== count( $data ) ) {
			return $bad;
		}
		list( $event_id, $photo_id, $size, $user_id, $email, $exp ) = $data;
		if ( $exp && (int) $exp < ( $now ?? time() ) ) {
			return new WP_Error( 'expired', __( 'Platnost odkazu na fotku vypršela. Načtěte prosím stránku akce znovu.', 'reserveandpay' ), array( 'status' => 403 ) );
		}
		$ev = Resandpay_Events::get( (int) $event_id );
		$p  = $ev ? self::photo( (int) $event_id, (string) $photo_id ) : null;
		if ( ! $p || 'publish' !== $ev['status'] && ! ( $user_id && user_can( (int) $user_id, 'edit_post', (int) $event_id ) ) ) {
			return new WP_Error( 'not_found', __( 'Fotka není k dispozici.', 'reserveandpay' ), array( 'status' => 404 ) );
		}
		$public = self::VIS_PUBLIC === self::visibility( (int) $event_id );
		if ( ! $public && ( ! $exp || ! self::can_view( (int) $event_id, (int) $user_id, (string) $email ) ) ) {
			return new WP_Error( 'denied', __( 'K fotkám z této akce nemáte přístup.', 'reserveandpay' ), array( 'status' => 403 ) );
		}
		$abs = self::abs_path( 't' === $size ? $p['thumb'] : $p['file'] );
		if ( ! $abs || ! is_readable( $abs ) ) {
			return new WP_Error( 'not_found', __( 'Fotka není k dispozici.', 'reserveandpay' ), array( 'status' => 404 ) );
		}
		return array(
			'abs'    => $abs,
			'mime'   => $p['mime'],
			'public' => $public,
		);
	}

	/** Obsluha ?rap_ph=… – odeslání obrázku. */
	public static function maybe_serve() {
		if ( empty( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$auth = self::authorize( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $auth ) ) {
			status_header( (int) ( $auth->get_error_data()['status'] ?? 403 ) );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			echo esc_html( $auth->get_error_message() );
			exit;
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: ' . $auth['mime'] );
		header( 'Content-Length: ' . (int) filesize( $auth['abs'] ) );
		header( 'Cache-Control: ' . ( $auth['public'] ? 'public, max-age=604800' : 'private, max-age=21600' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Disposition: inline' );
		readfile( $auth['abs'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Stránka akce otevřená osobním odkazem obsahuje osobní odkazy na fotky – neukládat do mezipaměti. */
	public static function no_cache_personal() {
		if ( empty( $_GET[ Resandpay_Profile::QUERY ] ) || ! is_singular( Resandpay_Post_Types::EVENT ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/* ---------------------------------------------------------------------
	 * Zobrazení na webu
	 * ------------------------------------------------------------------- */

	/** Má se na proběhlé akci ukázat tlačítko / odkaz „Fotky z akce ↓“? (fotky jsou a návštěvník je smí vidět) */
	public static function cta_visible( array $ev ) {
		if ( ! self::supported( $ev ) || ! self::photos( $ev['id'] ) ) {
			return false;
		}
		$v = self::viewer();
		return self::can_view( $ev['id'], $v['user'], $v['email'] );
	}

	public static function cta( array $ev, $class = 'rap-button' ) {
		if ( ! self::cta_visible( $ev ) ) {
			return '';
		}
		return '<a class="' . esc_attr( $class ) . '" href="#rap-photos">' . esc_html( sprintf( '%s ↓', Resandpay_Settings::get( 'photos_title' ) ) ) . '</a>';
	}

	/**
	 * Sekce „Fotky z akce (N)“: mřížka náhledů, nebo výzva k přihlášení. Prázdná, dokud
	 * akce nemá fotky (pak stačí hláška o proběhlé akci).
	 */
	public static function section( $event_id ) {
		$ev     = Resandpay_Events::get( $event_id );
		$photos = $ev && self::supported( $ev ) ? self::photos( $event_id ) : array();
		if ( ! $photos ) {
			return '';
		}
		$s     = Resandpay_Settings::all();
		$v     = self::viewer();
		$title = (string) $s['photos_title'];
		$out   = '<section id="rap-photos" class="rap-photos" data-rap-photos>';
		/* translators: 1: nadpis, 2: počet fotek */
		$out .= '<h2 class="rap-photos__title">' . esc_html( sprintf( __( '%1$s (%2$d)', 'reserveandpay' ), $title, count( $photos ) ) ) . '</h2>';
		if ( ! self::can_view( $event_id, $v['user'], $v['email'] ) ) {
			return $out . self::locked( $ev, $v ) . '</section>';
		}
		self::enqueue();
		$out .= '<ul class="rap-photos__grid">';
		foreach ( $photos as $i => $p ) {
			/* translators: 1: název akce, 2: pořadí fotky */
			$alt  = sprintf( __( '%1$s – fotka %2$d', 'reserveandpay' ), $ev['title'], $i + 1 );
			$out .= '<li class="rap-photos__cell"' . ( $i >= self::PAGE ? ' hidden' : '' ) . '><a class="rap-photos__item" href="' . esc_url( self::url( $event_id, $p['id'], 'l', $v['user'], $v['email'] ) ) . '" data-w="' . (int) $p['w'] . '" data-h="' . (int) $p['h'] . '">'
				. '<img src="' . esc_url( self::url( $event_id, $p['id'], 't', $v['user'], $v['email'] ) ) . '" alt="' . esc_attr( $alt ) . '" width="' . (int) self::THUMB_SIZE . '" height="' . (int) self::THUMB_SIZE . '" loading="lazy" decoding="async"></a></li>';
		}
		$out .= '</ul>';
		if ( count( $photos ) > self::PAGE ) {
			$out .= '<p class="rap-photos__more"><button type="button" class="rap-button rap-button--ghost" data-rap-more="' . (int) self::PAGE . '">' . esc_html__( 'Zobrazit další', 'reserveandpay' ) . '</button></p>';
		}
		return $out . '</section>';
	}

	/** Výzva pro návštěvníka bez přístupu. */
	private static function locked( array $ev, array $v ) {
		$s    = Resandpay_Settings::all();
		$vis  = self::visibility( $ev['id'] );
		$out  = '<div class="rap-photos__locked">';
		if ( $v['error'] ) {
			$out .= '<p class="rap-notice">' . esc_html( $v['error']->get_error_message() ) . '</p>';
		}
		$out .= '<p>' . esc_html( self::VIS_LOGGED === $vis ? $s['photos_login_text'] : $s['photos_locked_text'] ) . '</p><p class="rap-photos__actions">';
		if ( ! is_user_logged_in() ) {
			$out .= '<a class="rap-button" href="' . esc_url( wp_login_url( get_permalink( $ev['id'] ) . '#rap-photos' ) ) . '">' . esc_html__( 'Přihlásit se', 'reserveandpay' ) . '</a> ';
		}
		$profile = Resandpay_Profile::page_id();
		if ( $profile && self::VIS_LOGGED !== $vis ) {
			$out .= '<a class="rap-button rap-button--ghost" href="' . esc_url( get_permalink( $profile ) ) . '">' . esc_html__( 'Moje rezervace', 'reserveandpay' ) . '</a>';
		}
		$out .= '</p>';
		if ( self::VIS_LOGGED !== $vis && get_post_meta( $ev['id'], '_rap_photos_notified', true ) ) {
			$out .= '<p class="rap-small">' . esc_html__( 'Účastníkům jsme poslali odkaz e-mailem.', 'reserveandpay' ) . '</p>';
		}
		return $out . '</div>';
	}

	public static function enqueue() {
		Resandpay_Style::enqueue();
		wp_enqueue_script( 'reserveandpay-photos', RESANDPAY_URL . 'assets/js/event-photos.js', array(), RESANDPAY_VERSION, true );
		wp_localize_script(
			'reserveandpay-photos',
			'rapPhotos',
			array(
				'close' => __( 'Zavřít', 'reserveandpay' ),
				'prev'  => __( 'Předchozí fotka', 'reserveandpay' ),
				'next'  => __( 'Další fotka', 'reserveandpay' ),
				'label' => (string) Resandpay_Settings::get( 'photos_title' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * E-mail účastníkům
	 * ------------------------------------------------------------------- */

	/** Zaplacené rezervace akce – jedna na e-mail (účastníci). */
	public static function attendees( $event_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Resandpay_Reservations::table() . " WHERE item_type = 'event' AND event_id = %d AND status = %s ORDER BY id ASC", $event_id, Resandpay_Reservations::PAID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$k = Resandpay_Customers::key( $r['email'] );
			if ( '' !== $k && ! isset( $out[ $k ] ) ) {
				$out[ $k ] = $r;
			}
		}
		return array_values( $out );
	}

	/** {photos_url} = stránka akce s osobním odkazem (zákazník bez účtu fotky uvidí bez přihlášení). */
	public static function placeholders( $ph, $r ) {
		if ( 'event' === ( $r['item_type'] ?? 'event' ) && ! empty( $r['event_id'] ) && get_permalink( (int) $r['event_id'] ) ) {
			$ph['{photos_url}'] = Resandpay_Profile::link_with_token( get_permalink( (int) $r['event_id'] ), $r ) . '#rap-photos';
		} else {
			$ph['{photos_url}'] = '';
		}
		return $ph;
	}

	/**
	 * Pošle účastníkům e-mail, že jsou fotky k dispozici.
	 *
	 * @return int|WP_Error Počet odeslaných e-mailů.
	 */
	public static function notify( $event_id ) {
		$ev = Resandpay_Events::get( $event_id );
		if ( ! $ev || ! self::supported( $ev ) || ! self::photos( $event_id ) ) {
			return new WP_Error( 'no_photos', __( 'Akce nemá žádné fotky.', 'reserveandpay' ) );
		}
		$sent = 0;
		foreach ( self::attendees( $event_id ) as $r ) {
			if ( Resandpay_Mailer::send_template( $r['email'], 'event_photos', $r ) ) {
				++$sent;
			}
		}
		update_post_meta(
			$event_id,
			'_rap_photos_notified',
			array(
				'at'    => time(),
				'count' => $sent,
			)
		);
		return $sent;
	}
}
