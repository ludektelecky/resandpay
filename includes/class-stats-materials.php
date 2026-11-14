<?php
defined( 'ABSPATH' ) || exit;

/**
 * Statistika materiálů (Issue #28, kap. 15.1 R20): objednávky, tržby, přístupy zdarma a stažení
 * za zvolené období, s grafem a exportem CSV. Část „Materiály“ stránky Statistika – v administraci
 * i na webu se shortcodem [reserveandpay_stats] (stejná oprávnění).
 *
 * Období: objednávky podle data vytvoření, ruční zpřístupnění podle data zpřístupnění, účastníci
 * podle data zaplacení rezervace navázané akce / služby, stažení podle času stažení.
 */
class Resandpay_Stats_Materials {

	const SORTS = array( 'code', 'title', 'kind', 'paid', 'open', 'revenue', 'free', 'downloads', 'plays', 'users' );

	/** Filtr: období (výchozí poslední 3 měsíce), řazení. */
	public static function args_from_request( ?array $q = null ) {
		$q     = $q ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- jen čtení filtru.
		$today = wp_date( 'Y-m-d' );
		$get   = fn( $k ) => isset( $q[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $q[ $k ] ) ) : '';
		$date  = fn( $v, $d ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $d;
		$from  = $date( $get( 'rap_st_from' ), wp_date( 'Y-m-d', strtotime( '-3 months', current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$to    = $date( $get( 'rap_st_to' ), $today );
		if ( $to < $from ) {
			$to = $from;
		}
		$sort = in_array( $get( 'rap_st_sort' ), self::SORTS, true ) ? $get( 'rap_st_sort' ) : 'downloads';
		$dir  = 'asc' === $get( 'rap_st_dir' ) ? 'asc' : 'desc';
		return compact( 'from', 'to', 'sort', 'dir' ) + array(
			'scope' => 'all',
			'view'  => 'materials',
		);
	}

	private static function range( array $args ) {
		return array( $args['from'] . ' 00:00:00', $args['to'] . ' 23:59:59' );
	}

	/**
	 * Řádky – jeden materiál. Zahrnuje zveřejněné materiály a materiály s aktivitou v období.
	 *
	 * @return array[]
	 */
	public static function rows( array $args ) {
		global $wpdb;
		list( $a, $b ) = self::range( $args );
		$t     = Resandpay_Reservations::table();
		$by    = function ( $sql ) use ( $wpdb ) {
			$out = array();
			foreach ( $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL -- připraveno níže.
				$out[ (int) $row['mid'] ] = $row;
			}
			return $out;
		};
		$open  = "'" . implode( "','", array( Resandpay_Reservations::PENDING, Resandpay_Reservations::PARTIAL, Resandpay_Reservations::OVERDUE ) ) . "'";
		$orders = $by(
			$wpdb->prepare(
				"SELECT event_id AS mid,
					SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
					SUM(CASE WHEN status IN ({$open}) THEN 1 ELSE 0 END) AS open_n,
					SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired,
					SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
					SUM(CASE WHEN status <> 'cancelled' THEN amount_paid ELSE 0 END) AS revenue,
					SUM(CASE WHEN status <> 'cancelled' THEN discount_amount ELSE 0 END) AS discount,
					SUM(CASE WHEN status <> 'cancelled' AND coupon_id > 0 THEN 1 ELSE 0 END) AS coupons,
					SUM(CASE WHEN status = 'paid' AND amount <= 0 THEN 1 ELSE 0 END) AS free_orders
				FROM {$t} WHERE item_type = 'material' AND created_at BETWEEN %s AND %s GROUP BY event_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$a,
				$b
			)
		);
		$grants = $by( $wpdb->prepare( 'SELECT material_id AS mid, COUNT(*) AS n FROM ' . Resandpay_Materials::grants_table() . ' WHERE granted_at BETWEEN %s AND %s GROUP BY material_id', $a, $b ) );
		$dl     = $by(
			$wpdb->prepare(
				"SELECT material_id AS mid,
					SUM(CASE WHEN kind = 'download' THEN 1 ELSE 0 END) AS downloads,
					SUM(CASE WHEN kind = 'play' THEN 1 ELSE 0 END) AS plays,
					SUM(CASE WHEN kind = 'sample' THEN 1 ELSE 0 END) AS samples,
					COUNT(DISTINCT CASE WHEN kind IN ('download', 'play') THEN user_id END) AS users
				FROM " . Resandpay_Materials::downloads_table() . " WHERE result = 'ok' AND created_at BETWEEN %s AND %s GROUP BY material_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$a,
				$b
			)
		);
		$ids  = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::MATERIAL,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			$mat = Resandpay_Materials::get( $id );
			if ( ! $mat ) {
				continue;
			}
			$o   = $orders[ $id ] ?? array();
			$d   = $dl[ $id ] ?? array();
			$act = ! empty( $o ) || ! empty( $d ) || ! empty( $grants[ $id ] );
			if ( ! $act && 'publish' !== $mat['status'] ) {
				continue;
			}
			$part   = self::participants( $mat, $a, $b );
			$rows[] = array(
				'id'          => $id,
				'code'        => $mat['code'],
				'title'       => $mat['title'],
				'kind'        => $mat['kind'],
				'kind_label'  => $mat['kind_label'],
				'access'      => Resandpay_Materials::ACCESS_PAID === $mat['access'] ? Resandpay_Events::format_price( $mat['price'], $mat['currency'] ) : Resandpay_Materials::access_labels()[ $mat['access'] ],
				'currency'    => $mat['currency'],
				'state'       => $mat['disabled'] ? 'disabled' : ( $mat['withdrawn'] ? 'withdrawn' : '' ),
				'paid'        => (int) ( $o['paid'] ?? 0 ),
				'open'        => (int) ( $o['open_n'] ?? 0 ),
				'expired'     => (int) ( $o['expired'] ?? 0 ),
				'cancelled'   => (int) ( $o['cancelled'] ?? 0 ),
				'revenue'     => round( (float) ( $o['revenue'] ?? 0 ), 2 ),
				'discount'    => round( (float) ( $o['discount'] ?? 0 ), 2 ),
				'coupons'     => (int) ( $o['coupons'] ?? 0 ),
				'free_orders' => (int) ( $o['free_orders'] ?? 0 ),
				'grants'      => (int) ( $grants[ $id ]['n'] ?? 0 ),
				'participants' => $part,
				'free'        => (int) ( $o['free_orders'] ?? 0 ) + (int) ( $grants[ $id ]['n'] ?? 0 ) + $part,
				'downloads'   => (int) ( $d['downloads'] ?? 0 ),
				'plays'       => (int) ( $d['plays'] ?? 0 ),
				'samples'     => (int) ( $d['samples'] ?? 0 ),
				'users'       => (int) ( $d['users'] ?? 0 ),
			);
		}
		return self::sort( $rows, $args['sort'], $args['dir'] );
	}

	/** Účastníci navázaných akcí / služeb, jejichž rezervace byla zaplacena v období (různé e-maily). */
	private static function participants( array $mat, $a, $b ) {
		global $wpdb;
		if ( ! $mat['links'] ) {
			return 0;
		}
		$list = implode( ',', array_map( 'intval', $mat['links'] ) );
		$t    = Resandpay_Reservations::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT LOWER(TRIM(r.email))) FROM {$t} r LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = r.event_id AND pm.meta_key = '_rap_service'
				WHERE r.item_type = 'event' AND r.status = 'paid' AND r.paid_at BETWEEN %s AND %s AND ( r.event_id IN ({$list}) OR pm.meta_value IN ({$list}) )", // phpcs:ignore WordPress.DB.PreparedSQL
				$a,
				$b
			)
		);
	}

	public static function sort( array $rows, $sort, $dir ) {
		$sign = 'asc' === $dir ? 1 : -1;
		usort(
			$rows,
			function ( $x, $y ) use ( $sort, $sign ) {
				$cmp = in_array( $sort, array( 'code', 'title', 'kind' ), true ) ? strcoll( (string) $x[ $sort ], (string) $y[ $sort ] ) : ( $x[ $sort ] <=> $y[ $sort ] );
				return $cmp ? $sign * $cmp : strcoll( $x['title'], $y['title'] );
			}
		);
		return $rows;
	}

	public static function totals( array $rows ) {
		$t = array( 'materials' => count( $rows ) );
		foreach ( array( 'paid', 'open', 'expired', 'cancelled', 'revenue', 'discount', 'coupons', 'free', 'grants', 'participants', 'free_orders', 'downloads', 'plays', 'samples', 'users' ) as $k ) {
			$t[ $k ] = array_sum( array_column( $rows, $k ) );
		}
		$t['revenue'] = round( $t['revenue'], 2 );
		return $t;
	}

	/** Počet různých uživatelů, kteří v období stahovali nebo přehrávali (přes všechny materiály). */
	public static function distinct_users( array $args ) {
		global $wpdb;
		list( $a, $b ) = self::range( $args );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM ' . Resandpay_Materials::downloads_table() . " WHERE result = 'ok' AND kind IN ('download', 'play') AND created_at BETWEEN %s AND %s", $a, $b ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Časová řada pro graf: zaplacené objednávky (podle data zaplacení) a stažení + přehrání.
	 * Krok den / týden / měsíc podle délky období.
	 *
	 * @return array{step:string, buckets:array}
	 */
	public static function series( array $args ) {
		global $wpdb;
		list( $a, $b ) = self::range( $args );
		$days = (int) round( ( strtotime( $args['to'] ) - strtotime( $args['from'] ) ) / DAY_IN_SECONDS ) + 1;
		$step = $days > 370 ? 'month' : ( $days > 62 ? 'week' : 'day' );
		$key  = function ( $date ) use ( $step ) {
			$ts = strtotime( substr( (string) $date, 0, 10 ) );
			if ( 'month' === $step ) {
				return gmdate( 'Y-m', $ts );
			}
			if ( 'week' === $step ) {
				return gmdate( 'Y-m-d', strtotime( 'monday this week', $ts ) );
			}
			return gmdate( 'Y-m-d', $ts );
		};
		$buckets = array();
		for ( $ts = strtotime( $args['from'] ); $ts <= strtotime( $args['to'] ); $ts += DAY_IN_SECONDS ) {
			$buckets[ $key( gmdate( 'Y-m-d', $ts ) ) ] = array(
				'orders'    => 0,
				'downloads' => 0,
			);
		}
		$t = Resandpay_Reservations::table();
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT paid_at FROM {$t} WHERE item_type = 'material' AND status = 'paid' AND paid_at BETWEEN %s AND %s", $a, $b ) ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $buckets[ $key( $d ) ] ) ) {
				++$buckets[ $key( $d ) ]['orders'];
			}
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT created_at FROM ' . Resandpay_Materials::downloads_table() . " WHERE result = 'ok' AND kind IN ('download', 'play') AND created_at BETWEEN %s AND %s", $a, $b ) ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $buckets[ $key( $d ) ] ) ) {
				++$buckets[ $key( $d ) ]['downloads'];
			}
		}
		return array(
			'step'    => $step,
			'buckets' => $buckets,
		);
	}

	/** Graf (inline SVG): sloupce stažení a zaplacených objednávek v čase. */
	public static function chart( array $series ) {
		$b   = $series['buckets'];
		$n   = count( $b );
		$max = max( 1, max( array_merge( array( 0 ), array_column( $b, 'orders' ), array_column( $b, 'downloads' ) ) ) );
		if ( ! $n ) {
			return '';
		}
		$w      = 720;
		$h      = 200;
		$pad    = 28;
		$slot   = ( $w - $pad ) / $n;
		$bw     = max( 1, min( 14, $slot / 2.6 ) );
		$label  = array(
			'day'   => __( 'po dnech', 'reserveandpay' ),
			'week'  => __( 'po týdnech', 'reserveandpay' ),
			'month' => __( 'po měsících', 'reserveandpay' ),
		)[ $series['step'] ];
		$fmt    = fn( $k ) => 'month' === $series['step'] ? wp_date( 'n/Y', strtotime( $k . '-01' ) ) : wp_date( 'j. n.', strtotime( $k ) );
		$out    = '<figure class="rap-stats__chart"><figcaption><strong>' . esc_html__( 'Vývoj v čase', 'reserveandpay' ) . '</strong> <small>(' . esc_html( $label ) . ')</small> '
			. '<span class="rap-stats__legend"><i class="is-dl"></i>' . esc_html__( 'stažení a přehrání', 'reserveandpay' ) . ' <i class="is-ord"></i>' . esc_html__( 'zaplacené objednávky', 'reserveandpay' ) . '</span></figcaption>';
		$out   .= '<svg viewBox="0 0 ' . $w . ' ' . ( $h + 22 ) . '" role="img" aria-label="' . esc_attr( sprintf( /* translators: 1: stažení, 2: objednávky */ __( 'Graf: celkem %1$d stažení a přehrání, %2$d zaplacených objednávek', 'reserveandpay' ), array_sum( array_column( $b, 'downloads' ) ), array_sum( array_column( $b, 'orders' ) ) ) ) . '" preserveAspectRatio="none">';
		$out   .= '<line x1="' . $pad . '" y1="' . $h . '" x2="' . $w . '" y2="' . $h . '" class="rap-stats__axis"/>';
		$out   .= '<text x="0" y="12" class="rap-stats__tick">' . (int) $max . '</text><text x="0" y="' . $h . '" class="rap-stats__tick">0</text>';
		$i      = 0;
		$every  = max( 1, (int) ceil( $n / 8 ) );
		foreach ( $b as $k => $v ) {
			$x    = $pad + $i * $slot + ( $slot - 2 * $bw ) / 2;
			$hd   = round( ( $h - 6 ) * $v['downloads'] / $max, 1 );
			$ho   = round( ( $h - 6 ) * $v['orders'] / $max, 1 );
			$tip  = $fmt( $k ) . ': ' . sprintf( /* translators: 1: stažení, 2: objednávky */ __( '%1$d stažení, %2$d objednávek', 'reserveandpay' ), $v['downloads'], $v['orders'] );
			$out .= '<g><title>' . esc_html( $tip ) . '</title><rect class="rap-stats__bar-dl" x="' . round( $x, 1 ) . '" y="' . ( $h - $hd ) . '" width="' . round( $bw, 1 ) . '" height="' . $hd . '"/><rect class="rap-stats__bar-ord" x="' . round( $x + $bw, 1 ) . '" y="' . ( $h - $ho ) . '" width="' . round( $bw, 1 ) . '" height="' . $ho . '"/></g>';
			if ( 0 === $i % $every ) {
				$out .= '<text x="' . round( $x, 1 ) . '" y="' . ( $h + 16 ) . '" class="rap-stats__tick">' . esc_html( $fmt( $k ) ) . '</text>';
			}
			++$i;
		}
		return $out . '</svg></figure>';
	}

	private static function money( $v, $cur = '' ) {
		return Resandpay_Events::format_price( $v, $cur ?: Resandpay_Settings::get( 'currency' ) );
	}

	/** Vykreslí část „Materiály“ (administrace i web). */
	public static function render( $ctx = 'front', ?array $args = null ) {
		$args  = $args ?? self::args_from_request();
		$rows  = self::rows( $args );
		$tot   = self::totals( $rows );
		$tot['users'] = self::distinct_users( $args );
		$admin = 'admin' === $ctx;
		$base  = $admin ? admin_url( 'admin.php?page=reserveandpay-stats' ) : get_permalink();
		$cur   = Resandpay_Settings::get( 'currency' );
		$out   = '<form class="rap-stats__filter" method="get" action="' . esc_url( $base ) . '">';
		$out  .= $admin ? '<input type="hidden" name="page" value="reserveandpay-stats">' : '';
		$out  .= '<input type="hidden" name="rap_st_view" value="materials">';
		$out  .= '<label>' . esc_html__( 'Od', 'reserveandpay' ) . ' <input type="date" name="rap_st_from" value="' . esc_attr( $args['from'] ) . '"></label>';
		$out  .= '<label>' . esc_html__( 'Do', 'reserveandpay' ) . ' <input type="date" name="rap_st_to" value="' . esc_attr( $args['to'] ) . '"></label>';
		$out  .= '<input type="hidden" name="rap_st_sort" value="' . esc_attr( $args['sort'] ) . '"><input type="hidden" name="rap_st_dir" value="' . esc_attr( $args['dir'] ) . '">';
		$out  .= '<button type="submit" class="' . ( $admin ? 'button button-primary' : 'rap-button' ) . '">' . esc_html__( 'Zobrazit', 'reserveandpay' ) . '</button>';
		$out  .= '<span class="rap-stats__export"><a href="' . esc_url( Resandpay_Stats::csv_url( $args, 'materials' ) ) . '">' . esc_html__( 'Export CSV', 'reserveandpay' ) . '</a></span></form>';

		$out  .= '<div class="rap-stats__summary">';
		$cards = array(
			__( 'Zaplacené objednávky', 'reserveandpay' ) => (string) $tot['paid'],
			__( 'Tržby', 'reserveandpay' )                => self::money( $tot['revenue'], $cur ),
			__( 'Zdarma', 'reserveandpay' )               => (string) $tot['free'],
			__( 'Stažení a přehrání', 'reserveandpay' )   => (string) ( $tot['downloads'] + $tot['plays'] ),
			__( 'Uživatelů stahovalo', 'reserveandpay' )  => (string) $tot['users'],
		);
		foreach ( $cards as $l => $v ) {
			$out .= '<div class="rap-stats__card"><span>' . esc_html( $l ) . '</span><strong>' . esc_html( $v ) . '</strong></div>';
		}
		$out .= '</div>' . self::chart( self::series( $args ) );
		if ( ! $rows ) {
			return $out . '<p class="rap-empty">' . esc_html__( 'Žádné materiály.', 'reserveandpay' ) . '</p>';
		}
		$cols = array(
			'code'      => __( 'Kód', 'reserveandpay' ),
			'title'     => __( 'Název', 'reserveandpay' ),
			'kind'      => __( 'Druh', 'reserveandpay' ),
			'paid'      => __( 'Zaplaceno', 'reserveandpay' ),
			'open'      => __( 'Čeká', 'reserveandpay' ),
			'revenue'   => __( 'Tržby', 'reserveandpay' ),
			'free'      => __( 'Zdarma', 'reserveandpay' ),
			'downloads' => __( 'Stažení', 'reserveandpay' ),
			'plays'     => __( 'Přehrání', 'reserveandpay' ),
			'users'     => __( 'Uživatelů', 'reserveandpay' ),
		);
		$num  = array( 'paid', 'open', 'revenue', 'free', 'downloads', 'plays', 'users' );
		$out .= '<div class="rap-stats__scroll"><table class="rap-stats__table' . ( $admin ? ' widefat striped' : '' ) . '"><thead><tr>';
		foreach ( $cols as $k => $l ) {
			$dir  = ( $args['sort'] === $k && 'desc' === $args['dir'] ) ? 'asc' : 'desc';
			$url  = add_query_arg(
				array(
					'rap_st_view' => 'materials',
					'rap_st_from' => $args['from'],
					'rap_st_to'   => $args['to'],
					'rap_st_sort' => $k,
					'rap_st_dir'  => $dir,
				),
				$base
			) . '#rap-stats';
			$mark = $args['sort'] === $k ? ( 'asc' === $args['dir'] ? ' ▲' : ' ▼' ) : '';
			$out .= '<th class="' . ( in_array( $k, $num, true ) ? 'is-num' : '' ) . '" aria-sort="' . esc_attr( $args['sort'] === $k ? ( 'asc' === $args['dir'] ? 'ascending' : 'descending' ) : 'none' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $l . $mark ) . '</a></th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$tag  = 'disabled' === $r['state'] ? __( 'znepřístupněno', 'reserveandpay' ) : ( 'withdrawn' === $r['state'] ? __( 'staženo z nabídky', 'reserveandpay' ) : '' );
			$out .= '<tr class="rap-stats__row"><td><code>' . esc_html( $r['code'] ?: '—' ) . '</code></td>';
			$out .= '<td class="rap-stats__title">' . esc_html( $r['title'] ) . ( $tag ? ' <span class="rap-stats__tag rap-stats__tag--off">' . esc_html( $tag ) . '</span>' : '' ) . '<small>' . esc_html( $r['access'] ) . '</small></td>';
			$out .= '<td>' . esc_html( $r['kind_label'] ) . '</td>';
			$out .= '<td class="is-num">' . (int) $r['paid'] . '</td><td class="is-num">' . (int) $r['open'] . '</td>';
			$out .= '<td class="is-num">' . esc_html( self::money( $r['revenue'], $r['currency'] ) ) . ( $r['discount'] > 0 ? '<small>' . esc_html( sprintf( /* translators: %s: sleva */ __( 'slevy −%s', 'reserveandpay' ), self::money( $r['discount'], $r['currency'] ) ) ) . '</small>' : '' ) . '</td>';
			$out .= '<td class="is-num" title="' . esc_attr( sprintf( /* translators: 1: kupón 100 %, 2: ručně, 3: účastníci */ __( 'zdarma objednávky (kupón 100 %% / po zadání e-mailu): %1$d, ručně: %2$d, účastníci: %3$d', 'reserveandpay' ), $r['free_orders'], $r['grants'], $r['participants'] ) ) . '">' . (int) $r['free'] . '</td>';
			$out .= '<td class="is-num">' . (int) $r['downloads'] . '</td><td class="is-num">' . (int) $r['plays'] . '</td><td class="is-num">' . (int) $r['users'] . '</td></tr>';
		}
		$out .= '</tbody><tfoot><tr><th colspan="3">' . esc_html( sprintf( /* translators: %d: počet */ __( 'Celkem: %d materiálů', 'reserveandpay' ), $tot['materials'] ) ) . '</th>';
		$out .= '<th class="is-num">' . (int) $tot['paid'] . '</th><th class="is-num">' . (int) $tot['open'] . '</th><th class="is-num">' . esc_html( self::money( $tot['revenue'], $cur ) ) . '</th><th class="is-num">' . (int) $tot['free'] . '</th><th class="is-num">' . (int) $tot['downloads'] . '</th><th class="is-num">' . (int) $tot['plays'] . '</th><th class="is-num">' . (int) $tot['users'] . '</th></tr></tfoot></table></div>';
		$out .= '<p class="rap-stats__note">' . esc_html__( 'Za zvolené období: objednávky podle data objednání (Zaplaceno / Čeká = čeká na platbu, částečně zaplaceno, po splatnosti), tržby = přijaté platby těchto objednávek. Zdarma = objednávky s kupónem 100 % + ruční zpřístupnění + účastníci navázaných akcí a služeb (zaplacená rezervace v období). Stažení a přehrání podle logu stažení (bez ukázek); Uživatelů = různí uživatelé, kteří stahovali nebo přehrávali. Materiály „zdarma pro přihlášené“ jsou vidět jen ve staženích.', 'reserveandpay' ) . '</p>';
		return $out;
	}

	/** Data CSV. */
	public static function csv_rows( array $args ) {
		$rows = self::rows( $args );
		$out  = array( array( 'Kód', 'Název', 'Druh', 'Přístup', 'Stav', 'Zaplacené objednávky', 'Čeká na platbu', 'Expirováno', 'Zrušeno', 'Tržby', 'Slevy', 'S kupónem', 'Zdarma celkem', 'Kupón 100 %', 'Ručně zpřístupněno', 'Účastníci', 'Stažení', 'Přehrání', 'Ukázky', 'Uživatelů', 'Měna' ) );
		$num  = fn( $v ) => str_replace( '.', ',', (string) round( (float) $v, 2 ) );
		foreach ( $rows as $r ) {
			$out[] = array( $r['code'], $r['title'], $r['kind_label'], $r['access'], $r['state'], $r['paid'], $r['open'], $r['expired'], $r['cancelled'], $num( $r['revenue'] ), $num( $r['discount'] ), $r['coupons'], $r['free'], $r['free_orders'], $r['grants'], $r['participants'], $r['downloads'], $r['plays'], $r['samples'], $r['users'], $r['currency'] );
		}
		$t          = self::totals( $rows );
		$t['users'] = self::distinct_users( $args );
		$out[]      = array( 'Celkem', $t['materials'] . ' materiálů', '', '', '', $t['paid'], $t['open'], $t['expired'], $t['cancelled'], $num( $t['revenue'] ), $num( $t['discount'] ), $t['coupons'], $t['free'], $t['free_orders'], $t['grants'], $t['participants'], $t['downloads'], $t['plays'], $t['samples'], $t['users'], Resandpay_Settings::get( 'currency' ) );
		return $out;
	}
}
