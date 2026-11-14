<?php
defined( 'ABSPATH' ) || exit;

/**
 * ResAndPay → Zákazníci (Issue #19): přehled všech, kdo kdy rezervovali,
 * s prokliknutím na rezervace zákazníka a exportem CSV.
 */
class Resandpay_Admin_Customers {

	const PAGE = 'reserveandpay-customers';

	public static function init() {
		add_action( 'admin_post_resandpay_customers_export', array( __CLASS__, 'handle_export' ) );
	}

	/** Odkaz na seznam rezervací zákazníka. */
	public static function reservations_url( $email ) {
		return add_query_arg( 'email', rawurlencode( Resandpay_Customers::key( $email ) ), admin_url( 'admin.php?page=reserveandpay' ) );
	}

	/** Odkaz na přehled zákazníků. */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/** Odkaz na export CSV s aktuálním filtrem. */
	public static function export_url( array $args ) {
		$q = array( 'action' => 'resandpay_customers_export' );
		if ( '' !== $args['search'] ) {
			$q['s'] = $args['search'];
		}
		if ( $args['newsletter'] ) {
			$q['newsletter'] = 1;
		}
		return wp_nonce_url( add_query_arg( $q, admin_url( 'admin-post.php' ) ), 'resandpay_customers_export' );
	}

	public static function page() {
		$table = new Resandpay_Customers_Table();
		$table->prepare_items();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Zákazníci', 'reserveandpay' ) . '</h1><hr class="wp-header-end">';
		echo '<p class="description">' . esc_html__( 'Všichni, kdo kdy rezervovali (akce, termíny služeb i materiály), jeden řádek na e-mail. Počítají se i zrušené a expirované rezervace. Jméno, telefon a souhlas s newsletterem jsou z poslední rezervace. Kliknutím na zákazníka zobrazíte jeho rezervace.', 'reserveandpay' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		$table->search_box( __( 'Hledat (jméno, e-mail, telefon)', 'reserveandpay' ), 'rap-customer-search' );
		$table->display();
		echo '</form></div>';
	}

	/** Export zákazníků do CSV (středník, UTF-8 s BOM – pro český Excel). */
	public static function handle_export() {
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_customers_export' );
		$args = Resandpay_Customers_Table::request_args();
		$name = sanitize_file_name( 'zakaznici-' . ( $args['newsletter'] ? 'newsletter-' : '' ) . wp_date( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		foreach ( Resandpay_Customers::csv_rows( $args ) as $line ) {
			fputcsv( $out, $line, ';', '"', '\\' );
		}
		fclose( $out );
		exit;
	}
}
