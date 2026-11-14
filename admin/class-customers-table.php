<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabulka zákazníků v administraci (ResAndPay → Zákazníci).
 */
class Resandpay_Customers_Table extends WP_List_Table {

	const PER_PAGE = 50;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'customer',
				'plural'   => 'customers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		$cols = array(
			'name'       => __( 'Jméno', 'reserveandpay' ),
			'email'      => __( 'Kontakt', 'reserveandpay' ),
			'total'      => __( 'Rezervace', 'reserveandpay' ),
			'first_at'   => __( 'První rezervace', 'reserveandpay' ),
			'last_at'    => __( 'Poslední rezervace', 'reserveandpay' ),
			'paid'       => __( 'Zaplaceno', 'reserveandpay' ),
			'newsletter' => __( 'Newsletter', 'reserveandpay' ),
		);
		if ( Resandpay_MailerLite::enabled() ) {
			$cols['ml'] = __( 'MailerLite', 'reserveandpay' );
		}
		return $cols;
	}

	protected function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'email'    => array( 'email', false ),
			'total'    => array( 'total', true ),
			'first_at' => array( 'first_at', true ),
			'last_at'  => array( 'last_at', true ),
			'paid'     => array( 'paid', true ),
		);
	}

	/** Parametry výpisu z URL (sdílené s exportem CSV). */
	public static function request_args() {
		return array(
			'search'     => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'newsletter' => ! empty( $_GET['newsletter'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'ml'         => sanitize_key( $_GET['ml'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification
			'orderby'    => sanitize_key( $_GET['orderby'] ?? 'last_at' ), // phpcs:ignore WordPress.Security.NonceVerification
			'order'      => sanitize_key( $_GET['order'] ?? 'desc' ), // phpcs:ignore WordPress.Security.NonceVerification
		);
	}

	public function prepare_items() {
		$result = Resandpay_Customers::query(
			array_merge(
				self::request_args(),
				array(
					'per_page' => self::PER_PAGE,
					'page'     => $this->get_pagenum(),
				)
			)
		);
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$args = self::request_args();
		echo '<div class="alignleft actions"><label><input type="checkbox" name="newsletter" value="1"' . checked( $args['newsletter'], true, false ) . '> ' . esc_html__( 'jen se souhlasem s newsletterem', 'reserveandpay' ) . '</label> ';
		if ( Resandpay_MailerLite::enabled() ) {
			echo '<select name="ml"><option value="">' . esc_html__( 'MailerLite: vše', 'reserveandpay' ) . '</option>';
			foreach ( Resandpay_MailerLite::customer_status_labels() as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $args['ml'], $k, false ) . '>' . esc_html( 'MailerLite: ' . $l ) . '</option>';
			}
			echo '</select> ';
		}
		submit_button( __( 'Filtrovat', 'reserveandpay' ), '', 'filter_action', false );
		echo ' <a class="button" href="' . esc_url( Resandpay_Admin_Customers::export_url( $args ) ) . '">' . esc_html__( 'Export CSV', 'reserveandpay' ) . '</a></div>';
	}

	protected function column_name( $item ) {
		$url = Resandpay_Admin_Customers::reservations_url( $item['email'] );
		$out = '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $item['customer_name'] ?: $item['email'] ) . '</a></strong>';
		return $out . $this->row_actions( array( 'reservations' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Rezervace zákazníka', 'reserveandpay' ) . '</a>' ) );
	}

	protected function column_email( $item ) {
		return '<a href="mailto:' . esc_attr( $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a>' . ( $item['phone'] ? '<br>' . esc_html( $item['phone'] ) : '' );
	}

	protected function column_total( $item ) {
		$out = '<a href="' . esc_url( Resandpay_Admin_Customers::reservations_url( $item['email'] ) ) . '"><strong>' . (int) $item['total'] . '</strong></a>';
		/* translators: 1: aktivní rezervace, 2: zrušené a expirované */
		return $out . '<br><small>' . esc_html( sprintf( __( 'aktivní %1$d · zrušené / expirované %2$d', 'reserveandpay' ), $item['active'], $item['closed'] ) ) . '</small>';
	}

	protected function column_default( $item, $col ) {
		switch ( $col ) {
			case 'first_at':
			case 'last_at':
				return $item[ $col ] ? esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $item[ $col ] ) ) ) : '—';
			case 'paid':
				return esc_html( Resandpay_Customers::format_paid( $item['paid_by_cur'] ) );
			case 'newsletter':
				return $item['newsletter'] ? esc_html__( 'ano', 'reserveandpay' ) : esc_html__( 'ne', 'reserveandpay' );
			case 'ml':
				return esc_html( Resandpay_MailerLite::customer_status_labels()[ $item['ml'] ] ?? '' );
		}
		return esc_html( $item[ $col ] ?? '' );
	}

	public function no_items() {
		esc_html_e( 'Zatím žádní zákazníci.', 'reserveandpay' );
	}
}
