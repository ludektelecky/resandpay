<?php
defined( 'ABSPATH' ) || exit;

/**
 * Jednoznačné čitelné kódy akcí, služeb a termínů (SWD v2.2, kap. 3.8).
 *
 * Akce AK-RRRR-NNNN, služba SL-NNN, termín služby TE-RRRR-NNNN (předpony a počet
 * číslic v nastavení). Kód vzniká při prvním uložení, je neměnný (nemění se se
 * změnou data, názvu ani typu) a nikdy se nepoužije znovu – řady se jen zvyšují.
 * Rok v kódu = rok založení; řada akcí a termínů začíná každý rok znovu od 1.
 */
class Resandpay_Codes {

	const META = '_rap_code';

	public static function init() {
		add_action( 'save_post_' . Resandpay_Post_Types::EVENT, array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'save_post_' . Resandpay_Post_Types::SERVICE, array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'save_post_' . Resandpay_Post_Types::MATERIAL, array( __CLASS__, 'on_save' ), 20, 2 );
	}

	/** Druh kódu: event (akce) / service (služba) / slot (termín služby). */
	public static function kind_of( $post_id ) {
		$type = get_post_type( $post_id );
		if ( Resandpay_Post_Types::SERVICE === $type ) {
			return 'service';
		}
		if ( Resandpay_Post_Types::MATERIAL === $type ) {
			return 'material';
		}
		if ( Resandpay_Post_Types::EVENT === $type ) {
			return 'slot' === get_post_meta( $post_id, '_rap_type', true ) ? 'slot' : 'event';
		}
		return '';
	}

	/** Kód příspěvku ('' pokud ještě nebyl přidělen). */
	public static function get( $post_id ) {
		return (string) get_post_meta( $post_id, self::META, true );
	}

	/**
	 * Sestaví kód z řady.
	 *
	 * @param string $kind event|service|slot.
	 */
	public static function format( $kind, $seq, $year ) {
		$s      = Resandpay_Settings::all();
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $s[ 'code_prefix_' . $kind ] ?? '' ) ) );
		$digits = max( 2, min( 8, (int) ( $s[ 'code_digits_' . $kind ] ?? 4 ) ) );
		$num    = str_pad( (string) (int) $seq, $digits, '0', STR_PAD_LEFT );
		return in_array( $kind, array( 'service', 'material' ), true ) ? $prefix . '-' . $num : $prefix . '-' . (int) $year . '-' . $num;
	}

	/** Uložení akce/služby: kód se přidělí, jakmile je známý typ (nejde o automatický koncept). */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
			return;
		}
		// Akce vytvořené kódem (generátor, duplikace) ukládají typ až po wp_insert_post – kód přidělí volající.
		if ( Resandpay_Post_Types::EVENT === $post->post_type && '' === get_post_meta( $post_id, '_rap_type', true ) ) {
			return;
		}
		self::assign( $post_id );
	}

	/**
	 * Přidělí kód, pokud ho příspěvek ještě nemá. Pod zámkem – řada se nikdy neopakuje.
	 *
	 * @return string Kód (existující nebo nový), '' pokud nejde o akci/službu.
	 */
	public static function assign( $post_id, $year = null ) {
		$existing = self::get( $post_id );
		if ( '' !== $existing ) {
			return $existing;
		}
		$kind = self::kind_of( $post_id );
		if ( '' === $kind ) {
			return '';
		}
		if ( null === $year ) {
			$created = get_post_time( 'Y', false, $post_id );
			$year    = $created ? (int) $created : (int) wp_date( 'Y' );
		}
		$lock = 'code_' . $kind;
		if ( ! Resandpay_Lock::acquire( $lock ) ) {
			return '';
		}
		try {
			$existing = self::get( $post_id ); // Mohl ho mezitím přidělit souběžný požadavek.
			if ( '' !== $existing ) {
				return $existing;
			}
			$opt = 'resandpay_code_seq_' . $kind . ( in_array( $kind, array( 'service', 'material' ), true ) ? '' : '_' . (int) $year );
			global $wpdb;
			$seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
			do {
				++$seq;
				$code = self::format( $kind, $seq, $year );
			} while ( self::find( $code ) );
			wp_cache_delete( $opt, 'options' );
			update_option( $opt, $seq, false );
			update_post_meta( $post_id, self::META, $code );
			return $code;
		} finally {
			Resandpay_Lock::release( $lock );
		}
	}

	/** Najde akci/službu podle kódu (bez ohledu na velikost písmen a mezery). */
	public static function find( $code ) {
		$code = strtoupper( preg_replace( '/\s+/', '', (string) $code ) );
		if ( ! preg_match( '/^[A-Z0-9]+-(\d{4}-)?\d+$/', $code ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::SERVICE, Resandpay_Post_Types::MATERIAL ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META,
				'meta_value'     => $code,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/** Je řetězec kódem (tvarem)? */
	public static function looks_like_code( $s ) {
		return (bool) preg_match( '/^\s*[A-Za-z]{1,6}-(\d{4}-)?\d{2,8}\s*$/', (string) $s );
	}

	/**
	 * Doplní kódy existujícím akcím, službám a termínům (po aktualizaci na verzi
	 * s kódy) v pořadí jejich založení.
	 *
	 * @return int Počet nově přidělených kódů.
	 */
	public static function backfill() {
		$ids = get_posts(
			array(
				'post_type'      => array( Resandpay_Post_Types::SERVICE, Resandpay_Post_Types::EVENT, Resandpay_Post_Types::MATERIAL ),
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
				'meta_query'     => array(
					array(
						'key'     => self::META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$n = 0;
		foreach ( $ids as $id ) {
			if ( '' !== self::assign( $id ) ) {
				++$n;
			}
		}
		return $n;
	}
}
