<?php
defined( 'ABSPATH' ) || exit;

/**
 * Služby (např. Bukální masáž) a jejich individuální termíny.
 * Termín = akce typu „slot“ s kapacitou podle služby (masáž 1, skupinová lekce
 * např. 15; SWD kap. 3.4). Délka, pauza a kapacita jsou pevné parametry služby;
 * změna se projeví u nově generovaných termínů (kapacitu lze volitelně
 * promítnout i do nadcházejících termínů).
 */
class Resandpay_Services {

	public static function get( $service_id ) {
		$post = get_post( $service_id );
		if ( ! $post || Resandpay_Post_Types::SERVICE !== $post->post_type ) {
			return null;
		}
		$m = function ( $key, $default = '' ) use ( $service_id ) {
			$v = get_post_meta( $service_id, '_rap_' . $key, true );
			return ( '' === $v || null === $v ) ? $default : $v;
		};
		return array(
			'id'          => (int) $service_id,
			'code'        => Resandpay_Codes::get( $service_id ),
			'title'       => get_the_title( $service_id ),
			'status'      => $post->post_status,
			'duration'    => max( 5, (int) $m( 'duration', 60 ) ),
			'pause'       => max( 0, (int) $m( 'pause', 15 ) ),
			'capacity'    => max( 1, (int) $m( 'capacity', 1 ) ),
			'price'       => (float) $m( 'price', 0 ),
			'currency'    => $m( 'currency', Resandpay_Settings::get( 'currency' ) ),
			'location_id' => (int) $m( 'location', 0 ),
			'due_days'    => $m( 'due_days', '' ),
			'due_mode'    => $m( 'due_mode', '' ),
			'due_offset'  => $m( 'due_offset', '' ),
			'cash'        => $m( 'cash', '' ),
			'close_hours' => (float) $m( 'close_hours', 0 ),
			'page_id'     => (int) $m( 'page', 0 ),
		);
	}

	/** Seznam služeb (pro výběry v administraci). */
	public static function all() {
		$ids = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::SERVICE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		return array_filter( array_map( array( __CLASS__, 'get' ), $ids ) );
	}

	/** Najde službu podle ID, kódu (SL-003) nebo slugu. */
	public static function find( $id_or_slug ) {
		if ( Resandpay_Codes::looks_like_code( $id_or_slug ) ) {
			return self::get( Resandpay_Codes::find( $id_or_slug ) );
		}
		if ( is_numeric( $id_or_slug ) ) {
			return self::get( (int) $id_or_slug );
		}
		$p = get_page_by_path( sanitize_title( $id_or_slug ), OBJECT, Resandpay_Post_Types::SERVICE );
		return $p ? self::get( $p->ID ) : null;
	}

	/**
	 * Spočítá termíny pro generátor (bez ukládání).
	 *
	 * @param int   $service_id Služba.
	 * @param array $args       weekdays (1=po … 7=ne), time_from, time_to (H:i), date_from, date_to (Y-m-d).
	 * @return array|WP_Error   Pole termínů: start, end, exists (bool), overlaps (pole akcí).
	 */
	public static function preview( $service_id, array $args ) {
		$svc = self::get( $service_id );
		if ( ! $svc ) {
			return new WP_Error( 'service', __( 'Vyberte službu.', 'reserveandpay' ) );
		}
		$tz       = wp_timezone();
		$weekdays = array_map( 'intval', (array) ( $args['weekdays'] ?? array() ) );
		$from     = date_create_immutable( ( $args['date_from'] ?? '' ) . ' 00:00:00', $tz );
		$to       = date_create_immutable( ( $args['date_to'] ?? '' ) . ' 00:00:00', $tz );
		$tf       = preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $args['time_from'] ?? '' ) ) ? $args['time_from'] : '';
		$tt       = preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $args['time_to'] ?? '' ) ) ? $args['time_to'] : '';
		if ( ! $weekdays || ! $from || ! $to || ! $tf || ! $tt ) {
			return new WP_Error( 'args', __( 'Vyplňte dny v týdnu, časové okno a období.', 'reserveandpay' ) );
		}
		if ( $to < $from ) {
			return new WP_Error( 'range', __( 'Konec období je před jeho začátkem.', 'reserveandpay' ) );
		}
		if ( $from->diff( $to )->days > 186 ) {
			return new WP_Error( 'range', __( 'Období může být nejvýš půl roku.', 'reserveandpay' ) );
		}
		$existing = self::existing_starts( $service_id, $from, $to->modify( '+1 day' ) );
		$now      = time();
		$out      = array();
		for ( $day = $from; $day <= $to; $day = $day->modify( '+1 day' ) ) {
			if ( ! in_array( (int) $day->format( 'N' ), $weekdays, true ) ) {
				continue;
			}
			$start = date_create_immutable( $day->format( 'Y-m-d' ) . ' ' . $tf . ':00', $tz );
			$limit = date_create_immutable( $day->format( 'Y-m-d' ) . ' ' . $tt . ':00', $tz );
			while ( $start && $limit && $start->modify( '+' . $svc['duration'] . ' minutes' ) <= $limit ) {
				$end = $start->modify( '+' . $svc['duration'] . ' minutes' );
				if ( $start->getTimestamp() > $now ) {
					$s     = $start->format( 'Y-m-d H:i:s' );
					$out[] = array(
						'start'    => $s,
						'end'      => $end->format( 'Y-m-d H:i:s' ),
						'exists'   => isset( $existing[ $s ] ),
						'overlaps' => isset( $existing[ $s ] ) ? array() : Resandpay_Events::overlaps( 0, $s, $end->format( 'Y-m-d H:i:s' ) ),
					);
				}
				$start = $end->modify( '+' . $svc['pause'] . ' minutes' );
			}
		}
		return $out;
	}

	/** Začátky již existujících termínů služby v období (klíč = Y-m-d H:i:s). */
	private static function existing_starts( $service_id, DateTimeImmutable $from, DateTimeImmutable $to ) {
		$ids = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_rap_service',
						'value' => (int) $service_id,
					),
					array(
						'key'     => '_rap_start',
						'value'   => array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[ get_post_meta( $id, '_rap_start', true ) ] = $id;
		}
		return $out;
	}

	/**
	 * Vytvoří termíny služby (publikované akce typu slot).
	 *
	 * @param array $starts Seznam začátků (Y-m-d H:i:s) vybraných v náhledu.
	 * @return array{created:int, skipped:int}
	 */
	public static function create_slots( $service_id, array $starts ) {
		$svc = self::get( $service_id );
		if ( ! $svc ) {
			return array(
				'created' => 0,
				'skipped' => count( $starts ),
			);
		}
		$tz       = wp_timezone();
		$created  = 0;
		$skipped  = 0;
		$terms    = wp_get_object_terms( $service_id, Resandpay_Post_Types::CATEGORY, array( 'fields' => 'ids' ) );
		$valid    = array();
		foreach ( $starts as $s ) {
			$dt = date_create_immutable( (string) $s, $tz );
			if ( $dt && $dt->getTimestamp() > time() ) {
				$valid[] = $dt;
			} else {
				++$skipped;
			}
		}
		if ( ! $valid ) {
			return array(
				'created' => 0,
				'skipped' => $skipped,
			);
		}
		usort( $valid, fn( $a, $b ) => $a <=> $b );
		$existing = self::existing_starts( $service_id, $valid[0], end( $valid )->modify( '+1 minute' ) );
		foreach ( $valid as $dt ) {
			$s = $dt->format( 'Y-m-d H:i:s' );
			if ( isset( $existing[ $s ] ) ) {
				++$skipped;
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => Resandpay_Post_Types::EVENT,
					'post_status' => 'publish',
					'post_title'  => $svc['title'],
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				++$skipped;
				continue;
			}
			Resandpay_Events::save_meta(
				$id,
				array(
					'rap_type'        => 'slot',
					'rap_start'       => $dt->format( 'Y-m-d H:i' ),
					'rap_end'         => $dt->modify( '+' . $svc['duration'] . ' minutes' )->format( 'Y-m-d H:i' ),
					'rap_location'    => $svc['location_id'],
					'rap_capacity'    => $svc['capacity'],
					'rap_price'       => $svc['price'],
					'rap_currency'    => $svc['currency'],
					'rap_due_days'    => $svc['due_days'],
					'rap_due_mode'    => $svc['due_mode'],
					'rap_due_offset'  => $svc['due_offset'],
					'rap_cash'        => $svc['cash'],
					'rap_close_hours' => $svc['close_hours'],
				)
			);
			update_post_meta( $id, '_rap_service', $service_id );
			Resandpay_Codes::assign( $id );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $id, $terms, Resandpay_Post_Types::CATEGORY );
			}
			$existing[ $s ] = $id;
			++$created;
		}
		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/** Termíny služby v období (včetně obsazených), seřazené. */
	public static function slots( $service_id, DateTimeImmutable $from, DateTimeImmutable $to, $only_published = true ) {
		$ids = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => $only_published ? 'publish' : array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_rap_start',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_rap_service',
						'value' => (int) $service_id,
					),
					array(
						'key'     => '_rap_start',
						'value'   => array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		return array_filter( array_map( array( 'Resandpay_Events', 'get' ), $ids ) );
	}

	/** Volné rezervovatelné termíny služby. */
	public static function free_slots( $service_id, DateTimeImmutable $from, DateTimeImmutable $to ) {
		return array_values(
			array_filter(
				self::slots( $service_id, $from, $to ),
				function ( $ev ) {
					return ! is_wp_error( Resandpay_Events::check_bookable( $ev['id'] ) ) && Resandpay_Events::free_places( $ev['id'] ) > 0;
				}
			)
		);
	}

	/**
	 * Hromadné zrušení termínů v období (nemoc, dovolená).
	 * Volné termíny se přesunou do koše; obsazené se označí jako zrušené
	 * a jejich aktivní rezervace se zruší (volitelně s e-mailem).
	 *
	 * @param bool $dry_run Jen spočítat, nic neměnit.
	 * @return array{free:array, booked:array}
	 */
	public static function bulk_cancel( $service_id, DateTimeImmutable $from, DateTimeImmutable $to, $notify = true, $reason = '', $dry_run = false ) {
		$free   = array();
		$booked = array();
		foreach ( self::slots( $service_id, $from, $to, false ) as $ev ) {
			if ( $ev['cancelled'] ) {
				continue;
			}
			if ( Resandpay_Reservations::count_active( $ev['id'] ) > 0 ) {
				$booked[] = $ev;
			} else {
				$free[] = $ev;
			}
		}
		if ( ! $dry_run ) {
			foreach ( $free as $ev ) {
				wp_trash_post( $ev['id'] );
			}
			foreach ( $booked as $ev ) {
				update_post_meta( $ev['id'], '_rap_cancelled', 1 );
				$res = Resandpay_Reservations::query(
					array(
						'event_id' => $ev['id'],
						'per_page' => 100,
					)
				)['items'];
				foreach ( $res as $r ) {
					if ( in_array( $r['status'], Resandpay_Reservations::active_statuses(), true ) ) {
						Resandpay_Reservations::set_status(
							$r['id'],
							Resandpay_Reservations::CANCELLED,
							array(
								'notify' => $notify,
								'reason' => $reason,
							)
						);
					}
				}
			}
		}
		return array(
			'free'   => $free,
			'booked' => $booked,
		);
	}

	/**
	 * Promítne kapacitu služby do nadcházejících termínů.
	 * Termín, který má víc aktivních rezervací než nová kapacita, se nemění.
	 *
	 * @return array{updated:int, skipped:array} skipped = termíny ponechané beze změny.
	 */
	public static function apply_capacity( $service_id, $capacity ) {
		$capacity = max( 1, (int) $capacity );
		$now      = new DateTimeImmutable( 'now', wp_timezone() );
		$updated  = 0;
		$skipped  = array();
		foreach ( self::slots( $service_id, $now, $now->modify( '+2 years' ), false ) as $ev ) {
			if ( (int) $ev['capacity'] === $capacity ) {
				continue;
			}
			if ( Resandpay_Reservations::count_active( $ev['id'] ) > $capacity ) {
				$skipped[] = $ev;
				continue;
			}
			update_post_meta( $ev['id'], '_rap_capacity', $capacity );
			++$updated;
		}
		return array(
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}
}
