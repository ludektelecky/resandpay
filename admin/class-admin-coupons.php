<?php
defined( 'ABSPATH' ) || exit;

/**
 * ResAndPay → Slevové kupóny (SWD kap. 3.10): seznam, úprava, hromadné generování,
 * zlatý QR kód (PNG), PDF poukaz, export CSV a ZIP.
 */
class Resandpay_Admin_Coupons {

	const PAGE = 'reserveandpay-coupons';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 14 );
		foreach ( array( 'save', 'batch', 'delete', 'qr', 'voucher', 'export' ) as $a ) {
			add_action( 'admin_post_resandpay_coupon_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Slevové kupóny', 'reserveandpay' ), __( 'Slevové kupóny', 'reserveandpay' ), resandpay_cap_reservations(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	private static function guard( $action, $id = 0 ) {
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_coupon_' . $action . ( $id ? '_' . $id : '' ) );
	}

	private static function action_url( $action, $id = 0, array $extra = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'resandpay_coupon_' . $action, 'id' => (int) $id ), $extra ), admin_url( 'admin-post.php' ) ), 'resandpay_coupon_' . $action . ( $id ? '_' . $id : '' ) );
	}

	/** Data z formuláře kupónu. */
	private static function input() {
		// phpcs:disable WordPress.Security.NonceVerification -- ověřeno v guard().
		$p = wp_unslash( $_POST );
		return array(
			'code'           => sanitize_text_field( $p['code'] ?? '' ),
			'mode'           => sanitize_key( $p['mode'] ?? 'percent' ),
			'value'          => sanitize_text_field( $p['value'] ?? '' ),
			'valid_from'     => sanitize_text_field( $p['valid_from'] ?? '' ),
			'valid_to'       => sanitize_text_field( $p['valid_to'] ?? '' ),
			'scope_all'      => ! empty( $p['scope_all'] ),
			'scope_events'   => array_map( 'absint', (array) ( $p['scope_events'] ?? array() ) ),
			'scope_services' => array_map( 'absint', (array) ( $p['scope_services'] ?? array() ) ),
			'scope_cats'     => array_map( 'absint', (array) ( $p['scope_cats'] ?? array() ) ),
			'max_uses'       => absint( $p['max_uses'] ?? 0 ),
			'max_per_email'  => absint( $p['max_per_email'] ?? 0 ),
			'active'         => ! empty( $p['active'] ),
			'note'           => sanitize_text_field( $p['note'] ?? '' ),
		);
		// phpcs:enable
	}

	public static function handle_save() {
		$id = absint( $_POST['id'] ?? 0 );
		self::guard( 'save', $id );
		$c = Resandpay_Discounts::save( self::input(), $id );
		if ( is_wp_error( $c ) ) {
			Resandpay_Admin::flash( $c->get_error_message(), 'error' );
			wp_safe_redirect( self::url( $id ? array( 'edit' => $id ) : array( 'edit' => 'new' ) ) );
			exit;
		}
		/* translators: %s: kód */
		Resandpay_Admin::flash( sprintf( __( 'Kupón %s uložen.', 'reserveandpay' ), $c['code'] ) . self::cap_warning( $c ) );
		wp_safe_redirect( self::url( array( 'edit' => $c['id'] ) ) );
		exit;
	}

	public static function handle_batch() {
		self::guard( 'batch' );
		$n   = absint( $_POST['count'] ?? 0 );
		$out = Resandpay_Discounts::generate_batch( self::input(), $n );
		if ( ! $out ) {
			Resandpay_Admin::flash( __( 'Kupóny se nepodařilo vytvořit – zkontrolujte výši slevy.', 'reserveandpay' ), 'error' );
			wp_safe_redirect( self::url( array( 'edit' => 'batch' ) ) );
			exit;
		}
		/* translators: 1: počet, 2: označení dávky */
		Resandpay_Admin::flash( sprintf( __( 'Vytvořeno %1$d jednorázových kupónů (dávka %2$s). Stáhněte si je jako CSV nebo ZIP s QR kódy a PDF poukazy.', 'reserveandpay' ), count( $out ), $out[0]['batch'] ) );
		wp_safe_redirect( self::url( array( 'batch' => $out[0]['batch'] ) ) );
		exit;
	}

	public static function handle_delete() {
		$id = absint( $_GET['id'] ?? 0 );
		self::guard( 'delete', $id );
		$res = Resandpay_Discounts::delete( $id );
		Resandpay_Admin::flash( is_wp_error( $res ) ? $res->get_error_message() : __( 'Kupón smazán.', 'reserveandpay' ), is_wp_error( $res ) ? 'error' : 'success' );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Zlatý QR kód kupónu (PNG). */
	public static function handle_qr() {
		$id = absint( $_GET['id'] ?? 0 );
		self::guard( 'qr', $id );
		$c = Resandpay_Discounts::get( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Kupón nenalezen.', 'reserveandpay' ) );
		}
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Disposition: attachment; filename="kupon-' . sanitize_file_name( $c['code'] ) . '.png"' );
		echo self::qr_png( $c ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function qr_png( array $c, $scale = 10 ) {
		return Resandpay_QR::png_color( Resandpay_Discounts::coupon_url( $c ), $scale );
	}

	/** PDF poukaz – jednoho kupónu, nebo celé dávky. */
	public static function handle_voucher() {
		$id = absint( $_GET['id'] ?? 0 );
		self::guard( 'voucher', $id );
		$batch   = sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) );
		$coupons = $batch ? Resandpay_Discounts::all( array( 'batch' => $batch ) ) : array_filter( array( Resandpay_Discounts::get( $id ) ) );
		$pdf     = $coupons ? Resandpay_PDF::voucher( array_reverse( $coupons ) ) : '';
		if ( '' === $pdf ) {
			wp_die( esc_html__( 'Poukaz se nepodařilo vytvořit.', 'reserveandpay' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'poukaz-' . ( $batch ?: $coupons[0]['code'] ) . '.pdf' ) . '"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Export dávky / všech kupónů: CSV, nebo ZIP (CSV + QR PNG + PDF poukazy). */
	public static function handle_export() {
		self::guard( 'export' );
		$batch   = sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) );
		$format  = 'zip' === ( $_GET['format'] ?? '' ) ? 'zip' : 'csv';
		$coupons = array_reverse( Resandpay_Discounts::all( $batch ? array( 'batch' => $batch ) : array() ) );
		$csv     = self::csv( $coupons );
		$base    = sanitize_file_name( 'kupony-' . ( $batch ?: wp_date( 'Y-m-d' ) ) );
		nocache_headers();
		if ( 'csv' === $format || ! class_exists( 'ZipArchive' ) ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $base . '.csv"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		$tmp = wp_tempnam( $base . '.zip' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( $base . '.csv', $csv );
		foreach ( $coupons as $c ) {
			$zip->addFromString( 'qr/kupon-' . sanitize_file_name( $c['code'] ) . '.png', self::qr_png( $c ) );
			$zip->addFromString( 'poukazy/poukaz-' . sanitize_file_name( $c['code'] ) . '.pdf', Resandpay_PDF::voucher( array( $c ) ) );
		}
		$zip->close();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $base . '.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );
		exit;
	}

	public static function csv( array $coupons ) {
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $fh, array( 'Kód', 'Odkaz (QR)', 'Sleva', 'Platí od', 'Platí do', 'Na co platí', 'Limit použití', 'Použito', 'Aktivní', 'Dávka', 'GUID', 'Poznámka' ), ';' );
		foreach ( $coupons as $c ) {
			fputcsv( $fh, array( $c['code'], Resandpay_Discounts::coupon_url( $c ), Resandpay_Discounts::value_label( $c['mode'], $c['value'] ), $c['valid_from'], $c['valid_to'], Resandpay_Discounts::scope_label( $c ), $c['max_uses'] ?: '', Resandpay_Discounts::uses( $c['id'] ), $c['active'] ? 'ano' : 'ne', $c['batch'], $c['guid'], $c['note'] ), ';' );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $out;
	}

	/** Upozornění, když pevná sleva kupónu přesahuje doplatek některé akce v rozsahu (OP01). */
	private static function cap_warning( array $c ) {
		if ( 'fixed' !== $c['mode'] || $c['scope_all'] ) {
			return '';
		}
		$over = array();
		foreach ( $c['scope_events'] as $id ) {
			$ev = Resandpay_Events::get( $id );
			if ( $ev ) {
				$p1 = Resandpay_Events::part1_amount( $ev );
				if ( $p1 > 0 && $c['value'] > $ev['price'] - $p1 && $c['value'] < $ev['price'] ) {
					$over[] = $ev['title'];
				}
			}
		}
		/* translators: %s: akce */
		return $over ? ' ' . sprintf( __( 'Pozor: sleva je vyšší než doplatek u akce %s – uplatní se jen do výše doplatku.', 'reserveandpay' ), implode( ', ', $over ) ) : '';
	}

	public static function page() {
		echo '<div class="wrap rap-coupons"><h1 class="wp-heading-inline">' . esc_html__( 'Slevové kupóny', 'reserveandpay' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Nový kupón', 'reserveandpay' ) . '</a> ';
		echo '<a class="page-title-action" href="' . esc_url( self::url( array( 'edit' => 'batch' ) ) ) . '">' . esc_html__( 'Hromadně vygenerovat', 'reserveandpay' ) . '</a><hr class="wp-header-end">';
		Resandpay_Admin::render_flash();
		$edit = sanitize_key( $_GET['edit'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'new' === $edit || 'batch' === $edit ) {
			self::form( null, 'batch' === $edit );
		} elseif ( $edit && ( $c = Resandpay_Discounts::get( absint( $edit ) ) ) ) {
			self::detail( $c );
			self::form( $c, false );
		} else {
			self::list_table();
		}
		echo '</div>';
	}

	private static function list_table() {
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$batch  = sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = Resandpay_Discounts::all( array( 'search' => $search, 'batch' => $batch ) );
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'kód, poznámka, dávka', 'reserveandpay' ) . '"> ';
		submit_button( __( 'Hledat', 'reserveandpay' ), '', '', false );
		echo '</p></form>';
		if ( $batch ) {
			/* translators: %s: dávka */
			echo '<p><strong>' . esc_html( sprintf( __( 'Dávka %s', 'reserveandpay' ), $batch ) ) . '</strong> · <a class="button" href="' . esc_url( self::action_url( 'export', 0, array( 'batch' => $batch, 'format' => 'csv' ) ) ) . '">' . esc_html__( 'Stáhnout CSV', 'reserveandpay' ) . '</a> <a class="button button-primary" href="' . esc_url( self::action_url( 'export', 0, array( 'batch' => $batch, 'format' => 'zip' ) ) ) . '">' . esc_html__( 'Stáhnout ZIP (CSV, QR kódy, PDF poukazy)', 'reserveandpay' ) . '</a> <a class="button" href="' . esc_url( self::action_url( 'voucher', 0, array( 'batch' => $batch ) ) ) . '">' . esc_html__( 'Všechny poukazy v jednom PDF', 'reserveandpay' ) . '</a> · <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Všechny kupóny', 'reserveandpay' ) . '</a></p>';
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Zatím nejsou žádné slevové kupóny.', 'reserveandpay' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped rap-coupon-list"><thead><tr><th>' . esc_html__( 'Kód', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Sleva', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Platnost', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Na co platí', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Použito', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			$uses = Resandpay_Discounts::uses( $c['id'] );
			echo '<tr><td><a href="' . esc_url( self::url( array( 'edit' => $c['id'] ) ) ) . '"><code>' . esc_html( $c['code'] ) . '</code></a>' . ( $c['note'] ? '<br><small>' . esc_html( $c['note'] ) . '</small>' : '' ) . ( $c['batch'] ? '<br><small><a href="' . esc_url( self::url( array( 'batch' => $c['batch'] ) ) ) . '">' . esc_html( $c['batch'] ) . '</a></small>' : '' ) . '</td>';
			echo '<td>' . esc_html( Resandpay_Discounts::value_label( $c['mode'], $c['value'] ) ) . '</td>';
			echo '<td>' . esc_html( trim( ( $c['valid_from'] ? wp_date( 'j. n. Y', strtotime( $c['valid_from'] . ' 12:00' ) ) : '' ) . ' – ' . ( $c['valid_to'] ? wp_date( 'j. n. Y', strtotime( $c['valid_to'] . ' 12:00' ) ) : '' ), ' –' ) ?: __( 'bez omezení', 'reserveandpay' ) ) . '</td>';
			echo '<td>' . esc_html( Resandpay_Discounts::scope_label( $c ) ) . '</td>';
			echo '<td>' . (int) $uses . ( $c['max_uses'] ? ' / ' . (int) $c['max_uses'] : '' ) . '</td>';
			echo '<td>' . ( $c['active'] ? '<span class="rap-ok">' . esc_html__( 'aktivní', 'reserveandpay' ) . '</span>' : '<span class="rap-err">' . esc_html__( 'vypnutý', 'reserveandpay' ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( self::action_url( 'export', 0, array( 'format' => 'csv' ) ) ) . '">' . esc_html__( 'Export všech kupónů (CSV)', 'reserveandpay' ) . '</a></p>';
	}

	private static function detail( array $c ) {
		$url = Resandpay_Discounts::coupon_url( $c );
		echo '<div class="rap-coupon-card"><div><img src="data:image/png;base64,' . esc_attr( base64_encode( self::qr_png( $c, 5 ) ) ) . '" width="160" height="160" alt="' . esc_attr__( 'QR kód kupónu', 'reserveandpay' ) . '"></div><div>';
		echo '<p class="rap-coupon-card__code"><code>' . esc_html( $c['code'] ) . '</code></p>';
		/* translators: 1: použito, 2: limit */
		echo '<p>' . esc_html( Resandpay_Discounts::value_label( $c['mode'], $c['value'] ) ) . ' · ' . esc_html( Resandpay_Discounts::scope_label( $c ) ) . ' · ' . esc_html( sprintf( __( 'použito %1$d%2$s', 'reserveandpay' ), Resandpay_Discounts::uses( $c['id'] ), $c['max_uses'] ? ' / ' . $c['max_uses'] : '' ) ) . '</p>';
		echo '<p><input type="text" readonly class="large-text code" value="' . esc_attr( $url ) . '" onclick="this.select()"></p>';
		echo '<p><a class="button" href="' . esc_url( self::action_url( 'qr', $c['id'] ) ) . '">' . esc_html__( 'Stáhnout QR (PNG)', 'reserveandpay' ) . '</a> <a class="button button-primary" href="' . esc_url( self::action_url( 'voucher', $c['id'] ) ) . '">' . esc_html__( 'Stáhnout poukaz (PDF)', 'reserveandpay' ) . '</a> <button type="button" class="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(\'' . esc_js( $url ) . '\');this.textContent=\'' . esc_js( __( 'Zkopírováno', 'reserveandpay' ) ) . '\'">' . esc_html__( 'Kopírovat odkaz', 'reserveandpay' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'QR kód kupónu je ve zlaté barvě – odlišený od platebních QR kódů. Po naskenování se kupón v rezervaci vyplní sám. GUID:', 'reserveandpay' ) . ' <code>' . esc_html( $c['guid'] ) . '</code></p>';
		echo '</div></div>';
	}

	private static function form( $c, $batch ) {
		$v      = function ( $k, $d = '' ) use ( $c ) {
			return $c ? $c[ $k ] : $d;
		};
		$events = get_posts( array( 'post_type' => Resandpay_Post_Types::EVENT, 'post_status' => array( 'publish', 'future', 'draft' ), 'posts_per_page' => 300, 'orderby' => 'meta_value', 'meta_key' => '_rap_start', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_rap_service', 'compare' => 'NOT EXISTS' ) ) ) );
		$svcs   = get_posts( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
		$cats   = get_terms( array( 'taxonomy' => Resandpay_Post_Types::CATEGORY, 'hide_empty' => false ) );
		$action = $batch ? 'batch' : 'save';
		$id     = $c ? (int) $c['id'] : 0;
		echo '<h2>' . esc_html( $batch ? __( 'Hromadné generování jednorázových kupónů', 'reserveandpay' ) : ( $c ? __( 'Upravit kupón', 'reserveandpay' ) : __( 'Nový kupón', 'reserveandpay' ) ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="resandpay_coupon_' . esc_attr( $action ) . '"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'resandpay_coupon_' . $action . ( $id ? '_' . $id : '' ) );
		echo '<table class="form-table"><tbody>';
		if ( $batch ) {
			echo '<tr><th><label for="rap_count">' . esc_html__( 'Počet kupónů', 'reserveandpay' ) . '</label></th><td><input type="number" id="rap_count" name="count" min="1" max="500" value="10" class="small-text"> <span class="description">' . esc_html__( 'Každý kupón dostane vlastní automatický kód a lze ho použít jednou (např. dárkové poukazy).', 'reserveandpay' ) . '</span></td></tr>';
		} else {
			echo '<tr><th><label for="rap_code">' . esc_html__( 'Kód', 'reserveandpay' ) . '</label></th><td><input type="text" id="rap_code" name="code" value="' . esc_attr( $v( 'code' ) ) . '" class="regular-text code" placeholder="' . esc_attr__( 'prázdné = vygenerovat automaticky (SLH-XXXX-XXXX-XX)', 'reserveandpay' ) . '"><p class="description">' . esc_html__( 'Nechte prázdné pro neuhodnutelný kód, nebo zadejte vlastní čitelný kód (např. JARO2027 – je uhodnutelný, doporučujeme limit použití). Velikost písmen a pomlčky se nerozlišují.', 'reserveandpay' ) . '</p></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Sleva', 'reserveandpay' ) . '</th><td><input type="number" name="value" min="0" step="any" value="' . esc_attr( $v( 'value' ) ? (string) $v( 'value' ) : '' ) . '" class="small-text" required> <select name="mode"><option value="percent" ' . selected( $v( 'mode', 'percent' ), 'percent', false ) . '>%</option><option value="fixed" ' . selected( $v( 'mode' ), 'fixed', false ) . '>Kč</option></select> <span class="description">' . esc_html__( '100 % = rezervace zdarma (potvrzena hned, bez platby). U platby ve dvou částech se sleva odečte z doplatku, nejvýš do jeho výše.', 'reserveandpay' ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Platnost', 'reserveandpay' ) . '</th><td>' . esc_html__( 'od', 'reserveandpay' ) . ' <input type="date" name="valid_from" value="' . esc_attr( (string) $v( 'valid_from' ) ) . '"> ' . esc_html__( 'do', 'reserveandpay' ) . ' <input type="date" name="valid_to" value="' . esc_attr( (string) $v( 'valid_to' ) ) . '"> <span class="description">' . esc_html__( 'Podle data rezervace; prázdné = bez omezení.', 'reserveandpay' ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Na co platí', 'reserveandpay' ) . '</th><td><p><label><input type="checkbox" name="scope_all" value="1" ' . checked( $c ? $c['scope_all'] : 1, 1, false ) . '> ' . esc_html__( 'Všechny akce a služby', 'reserveandpay' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Nebo odškrtněte a vyberte (lze kombinovat, výběr více položek s Ctrl / Cmd):', 'reserveandpay' ) . '</p><div class="rap-coupon-scope">';
		echo '<label>' . esc_html__( 'Akce', 'reserveandpay' ) . '<select name="scope_events[]" multiple size="8">';
		foreach ( $events as $e ) {
			$ev = Resandpay_Events::get( $e->ID );
			echo '<option value="' . (int) $e->ID . '" ' . selected( $c && in_array( $e->ID, $c['scope_events'], true ), true, false ) . '>' . esc_html( $e->post_title . ( $ev ? ' – ' . Resandpay_Events::format_date_full( $ev ) : '' ) ) . '</option>';
		}
		echo '</select></label><label>' . esc_html__( 'Služby (všechny termíny)', 'reserveandpay' ) . '<select name="scope_services[]" multiple size="8">';
		foreach ( $svcs as $sv ) {
			echo '<option value="' . (int) $sv->ID . '" ' . selected( $c && in_array( $sv->ID, $c['scope_services'], true ), true, false ) . '>' . esc_html( $sv->post_title ) . '</option>';
		}
		echo '</select></label><label>' . esc_html__( 'Kategorie', 'reserveandpay' ) . '<select name="scope_cats[]" multiple size="8">';
		foreach ( is_wp_error( $cats ) ? array() : $cats as $t ) {
			echo '<option value="' . (int) $t->term_id . '" ' . selected( $c && in_array( (int) $t->term_id, $c['scope_cats'], true ), true, false ) . '>' . esc_html( $t->name ) . '</option>';
		}
		echo '</select></label></div></td></tr>';
		if ( ! $batch ) {
			echo '<tr><th>' . esc_html__( 'Limity', 'reserveandpay' ) . '</th><td>' . esc_html__( 'celkem', 'reserveandpay' ) . ' <input type="number" name="max_uses" min="0" value="' . esc_attr( (string) $v( 'max_uses', 0 ) ) . '" class="small-text"> · ' . esc_html__( 'na jeden e-mail', 'reserveandpay' ) . ' <input type="number" name="max_per_email" min="0" value="' . esc_attr( (string) $v( 'max_per_email', 1 ) ) . '" class="small-text"> <span class="description">' . esc_html__( '0 = bez limitu. Vypršelé a zrušené rezervace se do použití nepočítají.', 'reserveandpay' ) . '</span></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th><td><label><input type="checkbox" name="active" value="1" ' . checked( $c ? $c['active'] : 1, 1, false ) . '> ' . esc_html__( 'Aktivní', 'reserveandpay' ) . '</label></td></tr>';
		echo '<tr><th><label for="rap_cnote">' . esc_html__( 'Poznámka', 'reserveandpay' ) . '</label></th><td><input type="text" id="rap_cnote" name="note" value="' . esc_attr( $v( 'note' ) ) . '" class="regular-text" placeholder="' . esc_attr__( 'interní, např. Instagram jaro 2027', 'reserveandpay' ) . '"></td></tr>';
		echo '</tbody></table>';
		submit_button( $batch ? __( 'Vygenerovat kupóny', 'reserveandpay' ) : __( 'Uložit kupón', 'reserveandpay' ) );
		echo '</form>';
		if ( $c ) {
			echo '<p><a class="submitdelete" href="' . esc_url( self::action_url( 'delete', $c['id'] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Smazat kupón? Použitý kupón nelze smazat, jen vypnout.', 'reserveandpay' ) ) . '\')">' . esc_html__( 'Smazat kupón', 'reserveandpay' ) . '</a> · <a href="' . esc_url( self::url() ) . '">' . esc_html__( '← Zpět na seznam', 'reserveandpay' ) . '</a></p>';
		}
	}
}
