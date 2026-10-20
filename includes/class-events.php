<?php
defined( 'ABSPATH' ) || exit;

/**
 * Práce s akcemi: čtení údajů, kapacita, rezervovatelnost, překryvy.
 */
class Resandpay_Events {

	const TYPES = array( 'single', 'multiday', 'short', 'slot' );

	public static function type_labels() {
		return array(
			'single'   => __( 'Jednodenní akce', 'reserveandpay' ),
			'multiday' => __( 'Vícedenní akce', 'reserveandpay' ),
			'short'    => __( 'Krátká akce od–do', 'reserveandpay' ),
			'slot'     => __( 'Individuální termín', 'reserveandpay' ),
		);
	}

	/**
	 * Načte údaje akce jako pole.
	 */
	public static function get( $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post || Resandpay_Post_Types::EVENT !== $post->post_type ) {
			return null;
		}
		$m = function ( $key, $default = '' ) use ( $event_id ) {
			$v = get_post_meta( $event_id, '_rap_' . $key, true );
			return ( '' === $v || null === $v ) ? $default : $v;
		};
		$location_id = (int) $m( 'location', 0 );
		return array(
			'id'            => (int) $event_id,
			'title'         => get_the_title( $event_id ),
			'status'        => $post->post_status,
			'type'          => $m( 'type', 'single' ),
			'start'         => $m( 'start' ),
			'end'           => $m( 'end' ),
			'capacity'      => max( 0, (int) $m( 'capacity', 0 ) ),
			'price'         => (float) $m( 'price', 0 ),
			'currency'      => $m( 'currency', Resandpay_Settings::get( 'currency' ) ),
			'due_days'      => '' === $m( 'due_days' ) ? (int) Resandpay_Settings::get( 'due_days' ) : (int) $m( 'due_days' ),
			'close_hours'   => (float) $m( 'close_hours', 0 ),
			'cancelled'     => (bool) $m( 'cancelled', 0 ),
			'members_only'  => (bool) $m( 'members_only', 0 ),
			'program'       => $m( 'program' ),
			'location_id'   => $location_id,
			'location'      => $location_id ? get_the_title( $location_id ) : '',
			'location_addr' => $location_id ? (string) get_post_meta( $location_id, '_rap_address', true ) : '',
			'location_map'  => $location_id ? (string) get_post_meta( $location_id, '_rap_map_url', true ) : '',
		);
	}

	/** Převod lokálního data a času (Y-m-d H:i:s) na timestamp. */
	public static function ts( $local ) {
		if ( empty( $local ) ) {
			return 0;
		}
		$dt = date_create_immutable( $local, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/** Lidsky čitelný termín akce. */
	public static function format_date( array $event ) {
		$s = self::ts( $event['start'] );
		$e = self::ts( $event['end'] );
		if ( ! $s ) {
			return '';
		}
		$df = 'j. n. Y';
		$tf = 'G:i';
		if ( ! $e ) {
			return wp_date( $df . ' ' . $tf, $s );
		}
		if ( wp_date( 'Y-m-d', $s ) === wp_date( 'Y-m-d', $e ) ) {
			return wp_date( $df, $s ) . ', ' . wp_date( $tf, $s ) . '–' . wp_date( $tf, $e );
		}
		return wp_date( $df . ' ' . $tf, $s ) . ' – ' . wp_date( $df . ' ' . $tf, $e );
	}

	public static function format_price( $amount, $currency ) {
		$num = number_format( (float) $amount, ( floor( $amount ) == $amount ) ? 0 : 2, ',', ' ' );
		return 'CZK' === $currency ? $num . ' Kč' : $num . ' ' . $currency;
	}

	public static function booked_count( $event_id ) {
		return Resandpay_Reservations::count_active( $event_id );
	}

	public static function free_places( $event_id ) {
		$ev = self::get( $event_id );
		if ( ! $ev ) {
			return 0;
		}
		return max( 0, $ev['capacity'] - self::booked_count( $event_id ) );
	}

	/**
	 * Lze akci aktuálně rezervovat? Vrací true nebo WP_Error s důvodem.
	 */
	public static function check_bookable( $event_id, $now = null, $ignore_close = false ) {
		$ev  = self::get( $event_id );
		$now = $now ?? time();
		if ( ! $ev || 'publish' !== $ev['status'] ) {
			return new WP_Error( 'not_found', __( 'Akce není k dispozici.', 'reserveandpay' ) );
		}
		if ( $ev['cancelled'] ) {
			return new WP_Error( 'cancelled', __( 'Akce byla zrušena.', 'reserveandpay' ) );
		}
		$start = self::ts( $ev['start'] );
		if ( ! $start ) {
			return new WP_Error( 'no_date', __( 'Akce nemá nastavený termín.', 'reserveandpay' ) );
		}
		if ( $now >= $start ) {
			return new WP_Error( 'started', __( 'Rezervace již není možná – akce už začala.', 'reserveandpay' ) );
		}
		if ( ! $ignore_close && $ev['close_hours'] > 0 && $now > $start - (int) round( $ev['close_hours'] * HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'closed', __( 'Rezervace na tuto akci jsou již uzavřeny.', 'reserveandpay' ) );
		}
		if ( $ev['capacity'] < 1 ) {
			return new WP_Error( 'no_capacity', __( 'Akce nemá nastavenou kapacitu.', 'reserveandpay' ) );
		}
		return true;
	}

	/**
	 * Najde publikované akce, které se časově překrývají se zadanou akcí.
	 * Překryv je pouze informativní (SWD v1.4) – nebrání uložení ani rezervaci.
	 */
	public static function overlaps( $event_id, $start = null, $end = null ) {
		$ev    = self::get( $event_id );
		$start = $start ?? ( $ev['start'] ?? '' );
		$end   = $end ?? ( $ev['end'] ?? '' );
		$s     = self::ts( $start );
		$e     = self::ts( $end ) ?: $s;
		if ( ! $s ) {
			return array();
		}
		$candidates = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => -1,
				'post__not_in'   => array( (int) $event_id ),
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_rap_start',
						'value'   => wp_date( 'Y-m-d H:i:s', $e ),
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => '_rap_end',
						'value'   => wp_date( 'Y-m-d H:i:s', $s ),
						'compare' => '>',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$out = array();
		foreach ( $candidates as $id ) {
			$o = self::get( $id );
			if ( $o && ! $o['cancelled'] ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/** Nadcházející publikované akce. */
	public static function upcoming( $args = array() ) {
		$args  = wp_parse_args( $args, array( 'limit' => 20, 'category' => '', 'include_slots' => false ) );
		$query = array(
			'post_type'      => Resandpay_Post_Types::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['limit'],
			'fields'         => 'ids',
			'meta_key'       => '_rap_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_rap_end',
					'value'   => wp_date( 'Y-m-d H:i:s' ),
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			),
		);
		if ( ! $args['include_slots'] ) {
			$query['meta_query'][] = array(
				'key'     => '_rap_type',
				'value'   => 'slot',
				'compare' => '!=',
			);
		}
		if ( $args['category'] ) {
			$query['tax_query'] = array(
				array(
					'taxonomy' => Resandpay_Post_Types::CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $args['category'] ) ),
				),
			);
		}
		return array_filter( array_map( array( __CLASS__, 'get' ), get_posts( $query ) ) );
	}
}
