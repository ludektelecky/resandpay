<?php
defined( 'ABSPATH' ) || exit;

/**
 * Statistika – přehled termínů akcí a služeb a jejich obsazenosti (SWD kap. 15.1, R1).
 *
 * Stejný přehled je v administraci (ResAndPay → Statistika) i na webové stránce se shortcodem
 * [reserveandpay_stats]. Přístup má jen přihlášený uživatel s oprávněním resandpay_view_stats,
 * které dostávají role vybrané v Nastavení → Statistika (výchozí Administrátor, Šéfredaktor,
 * Spolupracovník); správce webu (manage_options) ho má vždy.
 */
class Resandpay_Stats {

	const CAP = 'resandpay_view_stats';

	/** Sloupce, podle kterých lze řadit. */
	const SORTS = array( 'code', 'title', 'kind', 'start', 'booked', 'free', 'occ', 'prescribed', 'paid' );

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_cap' ), 10, 4 );
		add_shortcode( 'reserveandpay_stats', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_page' ) );
		add_action( 'admin_post_resandpay_stats_csv', array( __CLASS__, 'handle_csv' ) );
	}

	/** Oprávnění podle rolí z nastavení (nic se neukládá do rolí – změna nastavení platí hned). */
	public static function grant_cap( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( self::CAP, (array) $caps, true ) || ! empty( $allcaps[ self::CAP ] ) ) {
			return $allcaps;
		}
		$roles = array_filter( explode( ',', (string) Resandpay_Settings::get( 'stats_roles' ) ) );
		if ( ! empty( $allcaps['manage_options'] ) || ( $user instanceof WP_User && array_intersect( (array) $user->roles, $roles ) ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	public static function can_view() {
		return is_user_logged_in() && current_user_can( self::CAP );
	}

	/** Stránka s dashboardem: bez cache a bez indexování (jsou na ní osobní údaje). */
	public static function protect_page() {
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'reserveandpay_stats' ) ) {
			return;
		}
		self::no_cache();
	}

	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	/**
	 * Filtr z požadavku (GET): období OD–DO, výběr, řazení.
	 *
	 * @return array from, to (Y-m-d), scope (all|events|services|e:ID|s:ID), sort, dir.
	 */
	public static function args_from_request( ?array $q = null ) {
		$q     = $q ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- jen čtení filtru.
		$today = wp_date( 'Y-m-d' );
		$get   = fn( $k ) => isset( $q[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $q[ $k ] ) ) : '';
		$date  = fn( $v, $d ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $d;
		$from  = $date( $get( 'rap_st_from' ), $today );
		$to    = $date( $get( 'rap_st_to' ), wp_date( 'Y-m-d', strtotime( '+3 months', current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		if ( $to < $from ) {
			$to = $from;
		}
		$scope = $get( 'rap_st_scope' );
		if ( ! preg_match( '/^(all|events|services|[es]:\d+)$/', $scope ) ) {
			$scope = 'all';
		}
		$sort = in_array( $get( 'rap_st_sort' ), self::SORTS, true ) ? $get( 'rap_st_sort' ) : 'start';
		$dir  = 'desc' === $get( 'rap_st_dir' ) ? 'desc' : 'asc';
		return compact( 'from', 'to', 'scope', 'sort', 'dir' );
	}

	/**
	 * Řádky přehledu – jedna akce nebo jeden termín služby.
	 *
	 * @param array $args Viz args_from_request().
	 * @return array[]
	 */
	public static function rows( array $args ) {
		$meta = array(
			array(
				'key'     => '_rap_start',
				'value'   => array( $args['from'] . ' 00:00:00', $args['to'] . ' 23:59:59' ),
				'compare' => 'BETWEEN',
				'type'    => 'DATETIME',
			),
		);
		$scope = (string) $args['scope'];
		if ( 'events' === $scope ) {
			$meta[] = array(
				'key'     => '_rap_type',
				'value'   => 'slot',
				'compare' => '!=',
			);
		} elseif ( 'services' === $scope ) {
			$meta[] = array(
				'key'     => '_rap_service',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		} elseif ( 0 === strpos( $scope, 's:' ) ) {
			$meta[] = array(
				'key'   => '_rap_service',
				'value' => (int) substr( $scope, 2 ),
			);
		}
		$query = array(
			'post_type'      => Resandpay_Post_Types::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( 0 === strpos( $scope, 'e:' ) ) {
			$query['post__in'] = array( (int) substr( $scope, 2 ) );
		}
		$ids = get_posts( $query );
		$agg = self::aggregates( $ids );
		$out = array();
		foreach ( $ids as $id ) {
			$ev = Resandpay_Events::get( $id );
			if ( ! $ev ) {
				continue;
			}
			$svc      = $ev['service_id'] ? Resandpay_Services::get( $ev['service_id'] ) : null;
			$a        = $agg[ $id ] ?? array( 'booked' => 0, 'prescribed' => 0.0, 'paid' => 0.0 );
			$external = (bool) $ev['external'];
			$cap      = $external ? 0 : (int) $ev['capacity'];
			$out[]    = array(
				'id'         => (int) $id,
				'code'       => (string) $ev['code'],
				'title'      => $svc ? $svc['title'] : $ev['title'],
				'kind'       => $svc ? 'service' : 'event',
				'kind_label' => $svc ? __( 'služba', 'reserveandpay' ) : __( 'akce', 'reserveandpay' ),
				'start'      => (string) $ev['start'],
				'when'       => Resandpay_Events::format_date_full( $ev ),
				'location'   => (string) $ev['location'],
				'capacity'   => $cap,
				'booked'     => (int) $a['booked'],
				'free'       => $external ? null : max( 0, $cap - (int) $a['booked'] ),
				'occ'        => $external || $cap < 1 ? null : round( 100 * (int) $a['booked'] / $cap ),
				'prescribed' => (float) $a['prescribed'],
				'paid'       => (float) $a['paid'],
				'currency'   => (string) $ev['currency'],
				'external'   => $external,
				'ext_capacity' => $external ? Resandpay_Events::external_capacity( $ev ) : 0,
				'cancelled'  => (bool) $ev['cancelled'],
			);
		}
		return self::sort( $out, $args['sort'] ?? 'start', $args['dir'] ?? 'asc' );
	}

	/** Součty aktivních rezervací po termínech jedním dotazem. */
	private static function aggregates( array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$st   = "'" . implode( "','", Resandpay_Reservations::active_statuses() ) . "'";
		$rows = $wpdb->get_results( 'SELECT event_id, COUNT(*) AS booked, SUM(amount) AS prescribed, SUM(amount_paid) AS paid FROM ' . Resandpay_Reservations::table() . " WHERE event_id IN ({$in}) AND status IN ({$st}) GROUP BY event_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['event_id'] ] = $r;
		}
		return $out;
	}

	public static function sort( array $rows, $sort, $dir ) {
		$sort = in_array( $sort, self::SORTS, true ) ? $sort : 'start';
		$sign = 'desc' === $dir ? -1 : 1;
		usort(
			$rows,
			function ( $a, $b ) use ( $sort, $sign ) {
				$x = $a[ $sort ];
				$y = $b[ $sort ];
				if ( null === $x || null === $y ) {
					return ( ( null === $x ) <=> ( null === $y ) ) ?: strcmp( $a['start'], $b['start'] ); // Externí (bez údaje) vždy na konec.
				}
				$c = is_string( $x ) ? strcoll( $x, $y ) : $x <=> $y;
				return $sign * ( $c ?: strcmp( $a['start'], $b['start'] ) );
			}
		);
		return $rows;
	}

	/** Řádek Celkem (zrušené termíny se nezapočítávají; externí jen počtem). */
	public static function totals( array $rows ) {
		$t = array(
			'terms'      => 0,
			'capacity'   => 0,
			'booked'     => 0,
			'free'       => 0,
			'occ'        => null,
			'prescribed' => 0.0,
			'paid'       => 0.0,
			'cancelled'  => 0,
			'external'   => 0,
		);
		foreach ( $rows as $r ) {
			if ( $r['cancelled'] ) {
				++$t['cancelled'];
				continue;
			}
			++$t['terms'];
			if ( $r['external'] ) {
				++$t['external'];
				continue;
			}
			$t['capacity']   += $r['capacity'];
			$t['booked']     += $r['booked'];
			$t['free']       += (int) $r['free'];
			$t['prescribed'] += $r['prescribed'];
			$t['paid']       += $r['paid'];
		}
		$t['occ'] = $t['capacity'] > 0 ? round( 100 * $t['booked'] / $t['capacity'] ) : null;
		return $t;
	}

	/**
	 * Rozpad termínu: aktivní rezervace se jménem a za nimi volná místa bez jména.
	 *
	 * @return array reservations (name, vs, status, status_label, amount, paid, id), free (počet).
	 */
	public static function breakdown( $event_id ) {
		global $wpdb;
		$st     = "'" . implode( "','", Resandpay_Reservations::active_statuses() ) . "'";
		$list   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, customer_name, vs, status, amount, amount_paid, currency FROM ' . Resandpay_Reservations::table() . " WHERE event_id = %d AND status IN ({$st}) ORDER BY created_at ASC, id ASC", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$labels = Resandpay_Reservations::status_labels();
		$ev     = Resandpay_Events::get( $event_id );
		$res    = array();
		foreach ( (array) $list as $r ) {
			$res[] = array(
				'id'           => (int) $r['id'],
				'name'         => (string) $r['customer_name'],
				'vs'           => (string) $r['vs'],
				'status'       => (string) $r['status'],
				'status_label' => $labels[ $r['status'] ] ?? $r['status'],
				'amount'       => (float) $r['amount'],
				'paid'         => (float) $r['amount_paid'],
				'currency'     => (string) $r['currency'],
			);
		}
		$free = ( $ev && ! $ev['external'] ) ? max( 0, (int) $ev['capacity'] - count( $res ) ) : 0;
		return array(
			'reservations' => $res,
			'free'         => $free,
		);
	}

	/** Volby výběru: vše, všechny akce, všechny služby, konkrétní akce (v období ±1 rok) a služby. */
	public static function scope_options() {
		$opts   = array(
			'all'      => __( 'Vše (akce i služby)', 'reserveandpay' ),
			'events'   => __( 'Všechny akce', 'reserveandpay' ),
			'services' => __( 'Všechny služby', 'reserveandpay' ),
		);
		$events = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'meta_key'       => '_rap_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_rap_type',
						'value'   => 'slot',
						'compare' => '!=',
					),
					array(
						'key'     => '_rap_start',
						'value'   => wp_date( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$ev_opts = array();
		foreach ( $events as $id ) {
			$ev = Resandpay_Events::get( $id );
			if ( $ev ) {
				$ev_opts[ 'e:' . $id ] = $ev['title'] . ' – ' . Resandpay_Events::format_date( $ev );
			}
		}
		$svc_opts = array();
		foreach ( get_posts( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids' ) ) as $sid ) {
			$svc_opts[ 's:' . $sid ] = html_entity_decode( get_the_title( $sid ), ENT_QUOTES, 'UTF-8' );
		}
		return array(
			''                                    => $opts,
			__( 'Akce', 'reserveandpay' )         => $ev_opts,
			__( 'Služby', 'reserveandpay' )       => $svc_opts,
		);
	}

	private static function money( $v, $cur = '' ) {
		return Resandpay_Events::format_price( $v, $cur ?: Resandpay_Settings::get( 'currency' ) );
	}

	/** Odkaz na export CSV (termíny / rezervace) s aktuálním filtrem. */
	public static function csv_url( array $args, $type ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'resandpay_stats_csv',
					'type'         => $type,
					'rap_st_from'  => $args['from'],
					'rap_st_to'    => $args['to'],
					'rap_st_scope' => $args['scope'],
					'rap_st_sort'  => $args['sort'],
					'rap_st_dir'   => $args['dir'],
				),
				admin_url( 'admin-post.php' )
			),
			'resandpay_stats_csv'
		);
	}

	/**
	 * Vykreslí dashboard (administrace i web).
	 *
	 * @param string $ctx  admin | front.
	 * @param array  $args Filtr (výchozí z požadavku).
	 */
	public static function render( $ctx = 'front', ?array $args = null ) {
		$args  = $args ?? self::args_from_request();
		$rows  = self::rows( $args );
		$tot   = self::totals( $rows );
		$admin = 'admin' === $ctx;
		$base  = $admin ? admin_url( 'admin.php?page=reserveandpay-stats' ) : get_permalink();
		$cur   = Resandpay_Settings::get( 'currency' );
		ob_start();
		echo '<div class="rap-stats' . ( $admin ? ' rap-stats--admin' : '' ) . '" id="rap-stats">';

		// Filtr.
		echo '<form class="rap-stats__filter" method="get" action="' . esc_url( $base ) . '">';
		if ( $admin ) {
			echo '<input type="hidden" name="page" value="reserveandpay-stats">';
		}
		echo '<label>' . esc_html__( 'Od', 'reserveandpay' ) . ' <input type="date" name="rap_st_from" value="' . esc_attr( $args['from'] ) . '"></label>';
		echo '<label>' . esc_html__( 'Do', 'reserveandpay' ) . ' <input type="date" name="rap_st_to" value="' . esc_attr( $args['to'] ) . '"></label>';
		echo '<label>' . esc_html__( 'Výběr', 'reserveandpay' ) . ' <select name="rap_st_scope">';
		foreach ( self::scope_options() as $group => $opts ) {
			if ( ! $opts ) {
				continue;
			}
			if ( '' !== $group ) {
				echo '<optgroup label="' . esc_attr( $group ) . '">';
			}
			foreach ( $opts as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $args['scope'], $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			if ( '' !== $group ) {
				echo '</optgroup>';
			}
		}
		echo '</select></label>';
		echo '<input type="hidden" name="rap_st_sort" value="' . esc_attr( $args['sort'] ) . '"><input type="hidden" name="rap_st_dir" value="' . esc_attr( $args['dir'] ) . '">';
		echo '<button type="submit" class="' . ( $admin ? 'button button-primary' : 'rap-button' ) . '">' . esc_html__( 'Zobrazit', 'reserveandpay' ) . '</button>';
		echo '<span class="rap-stats__export">' . esc_html__( 'Export CSV:', 'reserveandpay' ) . ' <a href="' . esc_url( self::csv_url( $args, 'terms' ) ) . '">' . esc_html__( 'termíny', 'reserveandpay' ) . '</a> · <a href="' . esc_url( self::csv_url( $args, 'reservations' ) ) . '">' . esc_html__( 'rezervace', 'reserveandpay' ) . '</a></span>';
		echo '</form>';

		// Souhrn.
		echo '<div class="rap-stats__summary">';
		$cards = array(
			__( 'Termínů', 'reserveandpay' )     => (string) $tot['terms'],
			__( 'Rezervováno', 'reserveandpay' ) => sprintf( /* translators: 1: rezervováno, 2: míst */ __( '%1$d z %2$d', 'reserveandpay' ), $tot['booked'], $tot['capacity'] ),
			__( 'Obsazenost', 'reserveandpay' )  => null === $tot['occ'] ? '—' : $tot['occ'] . ' %',
			__( 'Zaplaceno', 'reserveandpay' )   => self::money( $tot['paid'], $cur ),
			__( 'Předepsáno', 'reserveandpay' )  => self::money( $tot['prescribed'], $cur ),
		);
		foreach ( $cards as $l => $v ) {
			echo '<div class="rap-stats__card"><span>' . esc_html( $l ) . '</span><strong>' . esc_html( $v ) . '</strong></div>';
		}
		echo '</div>';

		if ( ! $rows ) {
			echo '<p class="rap-empty">' . esc_html__( 'V zvoleném období nejsou žádné termíny.', 'reserveandpay' ) . '</p></div>';
			return ob_get_clean();
		}

		// Tabulka.
		$cols = array(
			'code'       => __( 'Kód', 'reserveandpay' ),
			'title'      => __( 'Název', 'reserveandpay' ),
			'kind'       => __( 'Druh', 'reserveandpay' ),
			'start'      => __( 'Datum', 'reserveandpay' ),
			'booked'     => __( 'Rezervováno', 'reserveandpay' ),
			'free'       => __( 'Volno', 'reserveandpay' ),
			'occ'        => __( 'Obsazenost', 'reserveandpay' ),
			'paid'       => __( 'Zaplaceno', 'reserveandpay' ),
			'prescribed' => __( 'Předepsáno', 'reserveandpay' ),
		);
		$num  = array( 'booked', 'free', 'occ', 'paid', 'prescribed' );
		echo '<div class="rap-stats__scroll"><table class="rap-stats__table' . ( $admin ? ' widefat striped' : '' ) . '"><thead><tr><th class="rap-stats__tog" aria-label="' . esc_attr__( 'Rozpad', 'reserveandpay' ) . '"></th>';
		foreach ( $cols as $k => $l ) {
			$dir  = ( $args['sort'] === $k && 'asc' === $args['dir'] ) ? 'desc' : 'asc';
			$url  = add_query_arg(
				array(
					'rap_st_from'  => $args['from'],
					'rap_st_to'    => $args['to'],
					'rap_st_scope' => $args['scope'],
					'rap_st_sort'  => $k,
					'rap_st_dir'   => $dir,
				),
				$base
			) . '#rap-stats';
			$mark = $args['sort'] === $k ? ( 'asc' === $args['dir'] ? ' ▲' : ' ▼' ) : '';
			echo '<th class="' . ( in_array( $k, $num, true ) ? 'is-num' : '' ) . '" aria-sort="' . esc_attr( $args['sort'] === $k ? ( 'asc' === $args['dir'] ? 'ascending' : 'descending' ) : 'none' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $l . $mark ) . '</a></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$tags = ( $r['cancelled'] ? ' <span class="rap-stats__tag rap-stats__tag--off">' . esc_html__( 'zrušeno', 'reserveandpay' ) . '</span>' : '' ) . ( $r['external'] ? ' <span class="rap-stats__tag">' . esc_html__( 'externí', 'reserveandpay' ) . '</span>' : '' );
			$bd   = 'rap-st-bd-' . $r['id'];
			echo '<tr class="rap-stats__row' . ( $r['cancelled'] ? ' is-cancelled' : '' ) . '">';
			echo '<td class="rap-stats__tog">' . ( $r['external'] ? '' : '<button type="button" class="rap-stats__toggle" aria-expanded="false" aria-controls="' . esc_attr( $bd ) . '" title="' . esc_attr__( 'Rezervace a volná místa', 'reserveandpay' ) . '">▸</button>' ) . '</td>';
			echo '<td><code>' . esc_html( $r['code'] ?: '—' ) . '</code></td>';
			echo '<td class="rap-stats__title">' . esc_html( $r['title'] ) . $tags . ( $r['location'] ? '<small>' . esc_html( $r['location'] ) . '</small>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- štítky escapovány výše.
			echo '<td>' . esc_html( $r['kind_label'] ) . '</td>';
			echo '<td class="rap-stats__when">' . esc_html( $r['when'] ) . '</td>';
			if ( $r['external'] ) {
				echo '<td class="is-num" colspan="3">' . esc_html( $r['ext_capacity'] ? sprintf( /* translators: %d: kapacita */ __( 'kapacita %d – rezervace v externím systému', 'reserveandpay' ), $r['ext_capacity'] ) : __( 'rezervace v externím systému', 'reserveandpay' ) ) . '</td>';
			} else {
				echo '<td class="is-num">' . esc_html( sprintf( /* translators: 1: rezervováno, 2: kapacita */ __( '%1$d z %2$d', 'reserveandpay' ), $r['booked'], $r['capacity'] ) ) . '</td>';
				echo '<td class="is-num">' . (int) $r['free'] . '</td>';
				echo '<td class="is-num">' . ( null === $r['occ'] ? '—' : '<span class="rap-stats__bar" style="--rap-occ:' . (int) min( 100, $r['occ'] ) . '%"></span>' . (int) $r['occ'] . ' %' ) . '</td>';
			}
			echo '<td class="is-num">' . esc_html( self::money( $r['paid'], $r['currency'] ) ) . '</td>';
			echo '<td class="is-num">' . esc_html( self::money( $r['prescribed'], $r['currency'] ) ) . '</td>';
			echo '</tr>';
			if ( ! $r['external'] ) {
				echo '<tr class="rap-stats__detail" id="' . esc_attr( $bd ) . '" hidden><td></td><td colspan="' . count( $cols ) . '">' . self::render_breakdown( $r['id'], $admin ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
			}
		}
		echo '</tbody><tfoot><tr><th></th><th colspan="4">' . esc_html( sprintf( /* translators: %d: počet termínů */ __( 'Celkem: %d termínů', 'reserveandpay' ), $tot['terms'] ) );
		$notes = array();
		if ( $tot['cancelled'] ) {
			/* translators: %d: počet */
			$notes[] = sprintf( __( 'zrušených %d nezapočteno', 'reserveandpay' ), $tot['cancelled'] );
		}
		if ( $tot['external'] ) {
			/* translators: %d: počet */
			$notes[] = sprintf( __( 'z toho externích %d (bez obsazenosti)', 'reserveandpay' ), $tot['external'] );
		}
		echo $notes ? ' <small>(' . esc_html( implode( '; ', $notes ) ) . ')</small>' : '';
		echo '</th><th class="is-num">' . esc_html( sprintf( __( '%1$d z %2$d', 'reserveandpay' ), $tot['booked'], $tot['capacity'] ) ) . '</th><th class="is-num">' . (int) $tot['free'] . '</th><th class="is-num">' . esc_html( null === $tot['occ'] ? '—' : $tot['occ'] . ' %' ) . '</th><th class="is-num">' . esc_html( self::money( $tot['paid'], $cur ) ) . '</th><th class="is-num">' . esc_html( self::money( $tot['prescribed'], $cur ) ) . '</th></tr></tfoot></table></div>';
		echo '<p class="rap-stats__note">' . esc_html__( 'Rezervováno = aktivní rezervace (čeká na platbu, částečně zaplaceno, zaplaceno, platba na místě, po splatnosti). Předepsáno = k úhradě po slevách, zaplaceno = přijaté platby. Šipka ▸ u řádku zobrazí rezervace se jménem a volná místa.', 'reserveandpay' ) . '</p>';
		echo '</div>';
		echo "<script>document.querySelectorAll('#rap-stats .rap-stats__toggle').forEach(function(b){b.addEventListener('click',function(){var d=document.getElementById(b.getAttribute('aria-controls'));var o=d.hidden;d.hidden=!o;b.setAttribute('aria-expanded',o?'true':'false');b.textContent=o?'▾':'▸';});});</script>";
		return ob_get_clean();
	}

	/** Tabulka rozpadu jednoho termínu. */
	public static function render_breakdown( $event_id, $admin = false ) {
		$bd  = self::breakdown( $event_id );
		$out = '<table class="rap-stats__sub"><thead><tr><th>#</th><th>' . esc_html__( 'Jméno', 'reserveandpay' ) . '</th><th>' . esc_html__( 'VS', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th><th class="is-num">' . esc_html__( 'Zaplaceno', 'reserveandpay' ) . '</th><th class="is-num">' . esc_html__( 'Předepsáno', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		$i   = 0;
		$can = current_user_can( resandpay_cap_reservations() );
		foreach ( $bd['reservations'] as $r ) {
			++$i;
			$name = esc_html( $r['name'] );
			if ( $can ) {
				$name = '<a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-detail&id=' . $r['id'] ) ) . '">' . $name . '</a>';
			}
			$out .= '<tr><td>' . $i . '</td><td>' . $name . '</td><td>' . esc_html( $r['vs'] ) . '</td><td><span class="rap-stats__st rap-stats__st--' . esc_attr( $r['status'] ) . '">' . esc_html( $r['status_label'] ) . '</span></td><td class="is-num">' . esc_html( self::money( $r['paid'], $r['currency'] ) ) . '</td><td class="is-num">' . esc_html( self::money( $r['amount'], $r['currency'] ) ) . '</td></tr>';
		}
		for ( $f = 0; $f < $bd['free']; $f++ ) {
			++$i;
			$out .= '<tr class="is-free"><td>' . $i . '</td><td colspan="5">' . esc_html__( 'volné místo', 'reserveandpay' ) . '</td></tr>';
		}
		if ( 0 === $i ) {
			$out .= '<tr><td colspan="6">' . esc_html__( 'Bez rezervací.', 'reserveandpay' ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	public static function shortcode() {
		wp_enqueue_style( 'reserveandpay' );
		self::no_cache();
		if ( ! is_user_logged_in() ) {
			return '<div class="rap-stats rap-stats--login"><p class="rap-notice">' . esc_html__( 'Přehled rezervací je jen pro přihlášené spolupracovníky.', 'reserveandpay' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Přihlásit se', 'reserveandpay' ) . '</a></p></div>';
		}
		if ( ! current_user_can( self::CAP ) ) {
			return '<div class="rap-stats"><p class="rap-notice">' . esc_html__( 'K zobrazení přehledu rezervací nemáte oprávnění.', 'reserveandpay' ) . '</p></div>';
		}
		return self::render( 'front' );
	}

	public static function page_admin() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Statistika', 'reserveandpay' ) . '</h1>';
		echo self::render( 'admin' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
		echo '</div>';
	}

	/** Data CSV (bez odeslání) – kvůli testům oddělené od výstupu. */
	public static function csv_rows( array $args, $type ) {
		$rows = self::rows( $args );
		if ( 'reservations' === $type ) {
			$out = array( array( 'Kód termínu', 'Název', 'Druh', 'Datum', 'Pořadí', 'Jméno', 'VS', 'Stav', 'Zaplaceno', 'Předepsáno' ) );
			foreach ( $rows as $r ) {
				if ( $r['external'] ) {
					continue;
				}
				$bd = self::breakdown( $r['id'] );
				$i  = 0;
				foreach ( $bd['reservations'] as $x ) {
					$out[] = array( $r['code'], $r['title'], $r['kind_label'], $r['when'], ++$i, $x['name'], $x['vs'], $x['status_label'], self::num( $x['paid'] ), self::num( $x['amount'] ) );
				}
				for ( $f = 0; $f < $bd['free']; $f++ ) {
					$out[] = array( $r['code'], $r['title'], $r['kind_label'], $r['when'], ++$i, __( 'volné místo', 'reserveandpay' ), '', '', '', '' );
				}
			}
			return $out;
		}
		$out = array( array( 'Kód', 'Název', 'Druh', 'Datum', 'Místo', 'Rezervováno', 'Kapacita', 'Volno', 'Obsazenost %', 'Zaplaceno', 'Předepsáno', 'Měna', 'Poznámka' ) );
		foreach ( $rows as $r ) {
			$out[] = array( $r['code'], $r['title'], $r['kind_label'], $r['when'], $r['location'], $r['external'] ? '' : $r['booked'], $r['external'] ? ( $r['ext_capacity'] ?: '' ) : $r['capacity'], $r['external'] ? '' : $r['free'], null === $r['occ'] ? '' : $r['occ'], self::num( $r['paid'] ), self::num( $r['prescribed'] ), $r['currency'], trim( ( $r['cancelled'] ? 'zrušeno ' : '' ) . ( $r['external'] ? 'externí' : '' ) ) );
		}
		$t     = self::totals( $rows );
		$out[] = array( 'Celkem', $t['terms'] . ' termínů', '', '', '', $t['booked'], $t['capacity'], $t['free'], null === $t['occ'] ? '' : $t['occ'], self::num( $t['paid'] ), self::num( $t['prescribed'] ), Resandpay_Settings::get( 'currency' ), $t['cancelled'] ? 'zrušené nezapočteny' : '' );
		return $out;
	}

	private static function num( $v ) {
		return str_replace( '.', ',', (string) round( (float) $v, 2 ) );
	}

	public static function handle_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'resandpay_stats_csv' );
		$args = self::args_from_request();
		$type = isset( $_GET['type'] ) && 'reservations' === $_GET['type'] ? 'reservations' : 'terms'; // phpcs:ignore WordPress.Security.NonceVerification -- ověřeno výše.
		$name = sanitize_file_name( 'statistika-' . ( 'reservations' === $type ? 'rezervace' : 'terminy' ) . '-' . $args['from'] . '-' . $args['to'] . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM pro Excel.
		foreach ( self::csv_rows( $args, $type ) as $line ) {
			fputcsv( $fh, $line, ';', '"', '\\' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
