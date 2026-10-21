<?php
defined( 'ABSPATH' ) || exit;

/**
 * Veřejné pohledy: seznam akcí, měsíční kalendář, přepínač pohledů a filtr kategorií.
 * Vše se vykresluje na serveru (funguje bez JavaScriptu), navigace přes parametry URL.
 */
class Resandpay_Views {

	public static function months_short() {
		return array( __( 'led', 'reserveandpay' ), __( 'úno', 'reserveandpay' ), __( 'bře', 'reserveandpay' ), __( 'dub', 'reserveandpay' ), __( 'kvě', 'reserveandpay' ), __( 'čvn', 'reserveandpay' ), __( 'čvc', 'reserveandpay' ), __( 'srp', 'reserveandpay' ), __( 'zář', 'reserveandpay' ), __( 'říj', 'reserveandpay' ), __( 'lis', 'reserveandpay' ), __( 'pro', 'reserveandpay' ) );
	}

	public static function months_full() {
		return array( __( 'leden', 'reserveandpay' ), __( 'únor', 'reserveandpay' ), __( 'březen', 'reserveandpay' ), __( 'duben', 'reserveandpay' ), __( 'květen', 'reserveandpay' ), __( 'červen', 'reserveandpay' ), __( 'červenec', 'reserveandpay' ), __( 'srpen', 'reserveandpay' ), __( 'září', 'reserveandpay' ), __( 'říjen', 'reserveandpay' ), __( 'listopad', 'reserveandpay' ), __( 'prosinec', 'reserveandpay' ) );
	}

	/** Zkratky dnů, index 0 = neděle. */
	public static function weekdays_short() {
		return array( __( 'ne', 'reserveandpay' ), __( 'po', 'reserveandpay' ), __( 'út', 'reserveandpay' ), __( 'st', 'reserveandpay' ), __( 'čt', 'reserveandpay' ), __( 'pá', 'reserveandpay' ), __( 'so', 'reserveandpay' ) );
	}

	private static function dt( $local ) {
		return date_create_immutable( $local, wp_timezone() );
	}

	/** Řádek „po 10. 5. 15:00 – so 15. 5. 11:00“ / „so 14. 11. · 9:00–16:00“. */
	public static function when_line( array $ev ) {
		$s  = self::dt( $ev['start'] );
		$e  = $ev['end'] ? self::dt( $ev['end'] ) : $s;
		$wd = self::weekdays_short();
		if ( ! $s ) {
			return '';
		}
		if ( $s->format( 'Y-m-d' ) === $e->format( 'Y-m-d' ) ) {
			return $wd[ (int) $s->format( 'w' ) ] . ' ' . $s->format( 'j. n. Y' ) . ' · ' . $s->format( 'G:i' ) . '–' . $e->format( 'G:i' );
		}
		return $wd[ (int) $s->format( 'w' ) ] . ' ' . $s->format( 'j. n.' ) . ' ' . $s->format( 'G:i' ) . ' – ' . $wd[ (int) $e->format( 'w' ) ] . ' ' . $e->format( 'j. n. Y' ) . ' ' . $e->format( 'G:i' );
	}

	/** Datový štítek akce (den + měsíc), u vícedenních rozsah. */
	private static function date_badge( array $ev ) {
		$s  = self::dt( $ev['start'] );
		$e  = $ev['end'] ? self::dt( $ev['end'] ) : $s;
		$ms = self::months_short();
		if ( ! $s ) {
			return '';
		}
		$day = $s->format( 'j' );
		if ( $s->format( 'Y-m-d' ) !== $e->format( 'Y-m-d' ) ) {
			$day = $s->format( 'n' ) === $e->format( 'n' ) ? $s->format( 'j' ) . '.–' . $e->format( 'j' ) . '.' : $s->format( 'j' ) . '.–';
		} else {
			$day .= '.';
		}
		$month = $ms[ (int) $s->format( 'n' ) - 1 ];
		if ( $s->format( 'n' ) !== $e->format( 'n' ) ) {
			$month .= '–' . $ms[ (int) $e->format( 'n' ) - 1 ];
		}
		return '<div class="rap-item__date" aria-hidden="true"><span class="rap-item__day">' . esc_html( $day ) . '</span><span class="rap-item__month">' . esc_html( $month . ' ' . $s->format( 'Y' ) ) . '</span></div>';
	}

	private static function categories_of( $event_id ) {
		$terms = get_the_terms( $event_id, Resandpay_Post_Types::CATEGORY );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/** Jedna položka seznamu akcí. */
	public static function render_item( array $ev ) {
		$free  = Resandpay_Events::free_places( $ev['id'] );
		$cats  = wp_list_pluck( self::categories_of( $ev['id'] ), 'name' );
		$brow  = $cats ? implode( ' · ', $cats ) : ( Resandpay_Events::type_labels()[ $ev['type'] ] ?? '' );
		$url   = get_permalink( $ev['id'] );
		$state = $ev['cancelled'] ? 'cancelled' : ( $free < 1 ? 'full' : 'open' );
		ob_start();
		?>
		<article class="rap-item rap-item--<?php echo esc_attr( $state ); ?>">
			<?php echo self::date_badge( $ev ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř. ?>
			<div class="rap-item__main">
				<?php if ( $brow ) : ?><p class="rap-eyebrow"><?php echo esc_html( $brow ); ?></p><?php endif; ?>
				<h3 class="rap-item__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $ev['title'] ); ?></a></h3>
				<p class="rap-item__meta"><?php echo esc_html( self::when_line( $ev ) ); ?><?php if ( $ev['location'] ) : ?> · <?php echo esc_html( $ev['location'] ); ?><?php endif; ?></p>
			</div>
			<div class="rap-item__side">
				<p class="rap-item__price"><?php echo esc_html( Resandpay_Events::format_price( $ev['price'], $ev['currency'] ) ); ?></p>
				<?php if ( 'cancelled' === $state ) : ?>
					<p class="rap-item__free rap-item__free--off"><?php esc_html_e( 'Zrušeno', 'reserveandpay' ); ?></p>
					<a class="rap-button rap-button--ghost" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Zobrazit detail →', 'reserveandpay' ); ?></a>
				<?php elseif ( 'full' === $state ) : ?>
					<p class="rap-item__free rap-item__free--off"><?php esc_html_e( 'Obsazeno', 'reserveandpay' ); ?></p>
					<a class="rap-button rap-button--ghost" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Zobrazit detail →', 'reserveandpay' ); ?></a>
				<?php else : ?>
					<p class="rap-item__free"><?php echo esc_html( sprintf( /* translators: 1: volno, 2: kapacita */ __( 'Volná místa: %1$d z %2$d', 'reserveandpay' ), $free, $ev['capacity'] ) ); ?></p>
					<a class="rap-button" href="<?php echo esc_url( $url . '#rap-booking' ); ?>"><?php esc_html_e( 'Rezervovat termín →', 'reserveandpay' ); ?></a>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/** Filtr kategorií (štítky). */
	public static function render_filter( $current, $base_url ) {
		$terms = get_terms(
			array(
				'taxonomy'   => Resandpay_Post_Types::CATEGORY,
				'hide_empty' => true,
			)
		);
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		$out  = '<nav class="rap-filter" aria-label="' . esc_attr__( 'Filtr kategorií', 'reserveandpay' ) . '">';
		$out .= '<a class="rap-chip' . ( '' === $current ? ' is-active' : '' ) . '" href="' . esc_url( remove_query_arg( 'rap_cat', $base_url ) ) . '">' . esc_html__( 'Vše', 'reserveandpay' ) . '</a>';
		foreach ( $terms as $t ) {
			$out .= '<a class="rap-chip' . ( $current === $t->slug ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'rap_cat', $t->slug, $base_url ) ) . '">' . esc_html( $t->name ) . '</a>';
		}
		return $out . '</nav>';
	}

	/** Seznam nadcházejících akcí. */
	public static function render_list( array $args ) {
		$events = Resandpay_Events::upcoming(
			array(
				'limit'         => (int) $args['limit'],
				'category'      => $args['category'],
				'include_slots' => ! empty( $args['include_slots'] ),
			)
		);
		if ( ! $events ) {
			return '<p class="rap-empty">' . esc_html__( 'Momentálně nejsou vypsané žádné termíny. Sledujte novinky – brzy přibudou.', 'reserveandpay' ) . '</p>';
		}
		$out = '<div class="rap-list">';
		foreach ( $events as $ev ) {
			$out .= self::render_item( $ev );
		}
		return $out . '</div>';
	}

	/** Akce, které zasahují do daného období. */
	public static function events_between( DateTimeImmutable $from, DateTimeImmutable $to, $category = '', $include_slots = false ) {
		$query = array(
			'post_type'      => Resandpay_Post_Types::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'meta_key'       => '_rap_start',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_rap_start',
					'value'   => $to->format( 'Y-m-d H:i:s' ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => '_rap_end',
					'value'   => $from->format( 'Y-m-d H:i:s' ),
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			),
		);
		if ( ! $include_slots ) {
			$query['meta_query'][] = array(
				'key'     => '_rap_type',
				'value'   => 'slot',
				'compare' => '!=',
			);
		}
		if ( $category ) {
			$query['tax_query'] = array(
				array(
					'taxonomy' => Resandpay_Post_Types::CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $category ) ),
				),
			);
		}
		return array_filter( array_map( array( 'Resandpay_Events', 'get' ), get_posts( $query ) ) );
	}

	/** Měsíční kalendář (na úzkých displejích se zobrazí jako seznam dnů). */
	public static function render_calendar( array $args ) {
		$tz    = wp_timezone();
		$param = isset( $_GET['rap_m'] ) ? sanitize_text_field( wp_unslash( $_GET['rap_m'] ) ) : '';
		$first = preg_match( '/^\d{4}-\d{2}$/', $param ) ? date_create_immutable( $param . '-01 00:00:00', $tz ) : false;
		if ( ! $first ) {
			$first = ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		}
		$next   = $first->modify( '+1 month' );
		$prev   = $first->modify( '-1 month' );
		$events = self::events_between( $first, $next, $args['category'], ! empty( $args['include_slots'] ) );
		$today  = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$mf     = self::months_full();
		$wd     = self::weekdays_short();

		// Rozdělení akcí do dnů.
		$by_day = array();
		foreach ( $events as $ev ) {
			$s = self::dt( $ev['start'] );
			$e = $ev['end'] ? self::dt( $ev['end'] ) : $s;
			for ( $d = $s->setTime( 0, 0 ); $d <= $e; $d = $d->modify( '+1 day' ) ) {
				if ( $d >= $first && $d < $next ) {
					$by_day[ $d->format( 'Y-m-d' ) ][] = array(
						'ev'    => $ev,
						'first' => $d->format( 'Y-m-d' ) === $s->format( 'Y-m-d' ),
					);
				}
			}
		}

		$base = remove_query_arg( array( 'rap_m', 'rap_done', 'rap_again' ) );
		ob_start();
		echo '<div class="rap-calendar" id="rap-calendar">';
		echo '<div class="rap-calendar__head">';
		echo '<a class="rap-calendar__nav" rel="nofollow" href="' . esc_url( add_query_arg( 'rap_m', $prev->format( 'Y-m' ), $base ) . '#rap-calendar' ) . '" aria-label="' . esc_attr__( 'Předchozí měsíc', 'reserveandpay' ) . '">←</a>';
		echo '<h3 class="rap-calendar__title">' . esc_html( $mf[ (int) $first->format( 'n' ) - 1 ] ) . ' <em>' . esc_html( $first->format( 'Y' ) ) . '</em></h3>';
		echo '<a class="rap-calendar__nav" rel="nofollow" href="' . esc_url( add_query_arg( 'rap_m', $next->format( 'Y-m' ), $base ) . '#rap-calendar' ) . '" aria-label="' . esc_attr__( 'Další měsíc', 'reserveandpay' ) . '">→</a>';
		echo '</div>';

		// Mřížka (pondělí první).
		echo '<div class="rap-calendar__grid" role="grid">';
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $w ) {
			echo '<div class="rap-calendar__wd" role="columnheader">' . esc_html( $wd[ $w ] ) . '</div>';
		}
		$offset = ( (int) $first->format( 'N' ) ) - 1;
		$start  = $first->modify( '-' . $offset . ' days' );
		$cells  = (int) ceil( ( $offset + (int) $first->format( 't' ) ) / 7 ) * 7;
		for ( $i = 0; $i < $cells; $i++ ) {
			$d     = $start->modify( '+' . $i . ' days' );
			$key   = $d->format( 'Y-m-d' );
			$class = 'rap-calendar__day';
			if ( $d < $first || $d >= $next ) {
				$class .= ' is-outside';
			}
			if ( $key === $today ) {
				$class .= ' is-today';
			}
			if ( $key < $today ) {
				$class .= ' is-past';
			}
			echo '<div class="' . esc_attr( $class ) . '" role="gridcell"><span class="rap-calendar__num">' . esc_html( $d->format( 'j' ) ) . '</span>';
			foreach ( $by_day[ $key ] ?? array() as $it ) {
				$ev   = $it['ev'];
				$time = $it['first'] ? self::dt( $ev['start'] )->format( 'G:i' ) . ' ' : '';
				$cls  = 'rap-calendar__ev' . ( $it['first'] ? '' : ' is-cont' ) . ( $ev['cancelled'] || Resandpay_Events::free_places( $ev['id'] ) < 1 ? ' is-off' : '' );
				echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( get_permalink( $ev['id'] ) ) . '" title="' . esc_attr( $ev['title'] . ' – ' . self::when_line( $ev ) ) . '">' . esc_html( $time . $ev['title'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div>';

		// Agenda pro mobily.
		echo '<div class="rap-calendar__agenda">';
		if ( ! $by_day ) {
			echo '<p class="rap-empty">' . esc_html__( 'V tomto měsíci nejsou žádné akce.', 'reserveandpay' ) . '</p>';
		}
		ksort( $by_day );
		$month_first = $first->format( 'Y-m-d' );
		foreach ( $by_day as $key => $items ) {
			// V seznamu dnů se vícedenní akce ukáže jen jednou (první den, resp. první den měsíce).
			$items = array_filter(
				$items,
				function ( $it ) use ( $key, $month_first ) {
					return $it['first'] || $key === $month_first;
				}
			);
			if ( ! $items ) {
				continue;
			}
			$d = self::dt( $key . ' 00:00:00' );
			echo '<div class="rap-agenda__day' . ( $key < $today ? ' is-past' : '' ) . '"><p class="rap-eyebrow">' . esc_html( $wd[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'j. n.' ) ) . '</p>';
			foreach ( $items as $it ) {
				$ev = $it['ev'];
				echo '<a class="rap-agenda__ev" href="' . esc_url( get_permalink( $ev['id'] ) ) . '"><span>' . esc_html( $ev['title'] ) . '</span> <small>' . esc_html( self::when_line( $ev ) ) . '</small></a>';
			}
			echo '</div>';
		}
		echo '</div>';
		if ( ! $events ) {
			echo '<p class="rap-empty rap-calendar__empty">' . esc_html__( 'V tomto měsíci nejsou žádné akce.', 'reserveandpay' ) . '</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/** Kompletní přehled: přepínač Seznam/Kalendář + filtr kategorií. */
	public static function render_program( array $args ) {
		$view = isset( $_GET['rap_view'] ) && 'calendar' === $_GET['rap_view'] ? 'calendar' : ( 'calendar' === $args['view'] && ! isset( $_GET['rap_view'] ) ? 'calendar' : 'list' );
		$cat  = isset( $_GET['rap_cat'] ) ? sanitize_title( wp_unslash( $_GET['rap_cat'] ) ) : $args['category'];
		$base = remove_query_arg( array( 'rap_done', 'rap_again', 'rap_m' ) );
		$args['category'] = $cat;
		$out  = '<div class="rap-program-view" id="rap-program">';
		$out .= '<div class="rap-toolbar">';
		if ( 'yes' === $args['filter'] ) {
			$out .= self::render_filter( $cat, $base );
		}
		$out .= '<div class="rap-switch" role="tablist">';
		$out .= '<a role="tab" class="rap-switch__btn' . ( 'list' === $view ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'rap_view', 'list', $base ) . '#rap-program' ) . '">' . esc_html__( 'Seznam', 'reserveandpay' ) . '</a>';
		$out .= '<a role="tab" class="rap-switch__btn' . ( 'calendar' === $view ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'rap_view', 'calendar', $base ) . '#rap-program' ) . '">' . esc_html__( 'Kalendář', 'reserveandpay' ) . '</a>';
		$out .= '</div></div>';
		$out .= 'calendar' === $view ? self::render_calendar( $args ) : self::render_list( $args );
		return $out . '</div>';
	}

	/**
	 * Volné termíny služby: dny s časy jako tlačítka (odkaz na detail termínu s formulářem).
	 *
	 * @param array $args service (ID/slug), days (počet dní dopředu na stránku).
	 */
	public static function render_slots( array $args ) {
		$svc = Resandpay_Services::find( $args['service'] );
		if ( ! $svc ) {
			return current_user_can( 'edit_posts' ) ? '<p class="rap-notice">' . esc_html__( 'Shortcode [reserveandpay_slots]: služba nenalezena.', 'reserveandpay' ) . '</p>' : '';
		}
		$tz    = wp_timezone();
		$days  = max( 7, min( 90, (int) $args['days'] ) );
		$param = isset( $_GET['rap_from'] ) ? sanitize_text_field( wp_unslash( $_GET['rap_from'] ) ) : '';
		$today = new DateTimeImmutable( 'today', $tz );
		$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param ) ? date_create_immutable( $param . ' 00:00:00', $tz ) : $today;
		if ( ! $from || $from < $today ) {
			$from = $today;
		}
		$to    = $from->modify( '+' . $days . ' days' );
		$slots = Resandpay_Services::free_slots( $svc['id'], $from > $today ? $from : new DateTimeImmutable( 'now', $tz ), $to );
		$wd    = self::weekdays_short();
		$by    = array();
		foreach ( $slots as $ev ) {
			$by[ substr( $ev['start'], 0, 10 ) ][] = $ev;
		}
		$base = remove_query_arg( array( 'rap_from' ) );
		ob_start();
		echo '<div class="rap-program-view rap-slots" id="rap-slots">';
		echo '<p class="rap-slots__info"><span class="rap-eyebrow">' . esc_html( $svc['title'] ) . '</span> ';
		echo esc_html( sprintf( /* translators: 1: délka, 2: cena */ __( '%1$d min · %2$s', 'reserveandpay' ), $svc['duration'], Resandpay_Events::format_price( $svc['price'], $svc['currency'] ) ) ) . '</p>';
		if ( ! $by ) {
			echo '<p class="rap-empty">' . esc_html__( 'V tomto období nejsou volné termíny. Zkuste další týdny, nebo mi napište – rádi se domluvíme.', 'reserveandpay' ) . '</p>';
		}
		foreach ( $by as $day => $items ) {
			$d = date_create_immutable( $day . ' 00:00:00', $tz );
			echo '<div class="rap-slots__day"><p class="rap-slots__date">' . esc_html( $wd[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'j. n.' ) ) . '</p><div class="rap-slots__times">';
			foreach ( $items as $ev ) {
				$t = date_create_immutable( $ev['start'], $tz )->format( 'G:i' );
				echo '<a class="rap-chip rap-slot" href="' . esc_url( get_permalink( $ev['id'] ) . '#rap-booking' ) . '" aria-label="' . esc_attr( sprintf( /* translators: 1: den, 2: čas */ __( 'Rezervovat %1$s v %2$s', 'reserveandpay' ), $d->format( 'j. n.' ), $t ) ) . '">' . esc_html( $t ) . '</a>';
			}
			echo '</div></div>';
		}
		echo '<p class="rap-slots__nav">';
		if ( $from > $today ) {
			$prev = $from->modify( '-' . $days . ' days' );
			echo '<a class="rap-button rap-button--ghost" rel="nofollow" href="' . esc_url( add_query_arg( 'rap_from', ( $prev < $today ? $today : $prev )->format( 'Y-m-d' ), $base ) . '#rap-slots' ) . '">' . esc_html__( '← Předchozí termíny', 'reserveandpay' ) . '</a> ';
		}
		$later = Resandpay_Services::free_slots( $svc['id'], $to, $to->modify( '+1 year' ) );
		if ( $later ) {
			echo '<a class="rap-button rap-button--ghost" rel="nofollow" href="' . esc_url( add_query_arg( 'rap_from', $to->format( 'Y-m-d' ), $base ) . '#rap-slots' ) . '">' . esc_html__( 'Další termíny →', 'reserveandpay' ) . '</a>';
		}
		echo '</p></div>';
		return ob_get_clean();
	}
}
