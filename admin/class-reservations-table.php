<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabulka rezervací v administraci.
 */
class Resandpay_Reservations_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'reservation',
				'plural'   => 'reservations',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox">',
			'customer_name' => __( 'Jméno', 'reserveandpay' ),
			'contact'       => __( 'Kontakt', 'reserveandpay' ),
			'event'         => __( 'Akce', 'reserveandpay' ),
			'vs'            => __( 'VS', 'reserveandpay' ),
			'amount'        => __( 'Částka', 'reserveandpay' ),
			'status'        => __( 'Stav', 'reserveandpay' ),
			'due_at'        => __( 'Splatnost', 'reserveandpay' ),
			'created_at'    => __( 'Vytvořeno', 'reserveandpay' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'customer_name' => array( 'customer_name', false ),
			'vs'            => array( 'vs', false ),
			'amount'        => array( 'amount', false ),
			'status'        => array( 'status', false ),
			'due_at'        => array( 'due_at', false ),
			'created_at'    => array( 'created_at', true ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'cancel'        => __( 'Zrušit (s e-mailem zákaznici)', 'reserveandpay' ),
			'cancel_silent' => __( 'Zrušit (bez e-mailu)', 'reserveandpay' ),
			'delete'        => __( 'Smazat trvale (jen zrušené)', 'reserveandpay' ),
		);
	}

	public function prepare_items() {
		$per_page = 50;
		$result   = Resandpay_Reservations::query(
			array(
				'event_id' => absint( $_GET['event_id'] ?? 0 ),
				'status'   => sanitize_key( $_GET['status'] ?? '' ),
				'search'   => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
				'orderby'  => sanitize_key( $_GET['orderby'] ?? 'created_at' ),
				'order'    => sanitize_key( $_GET['order'] ?? 'desc' ),
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		$this->items = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'customer_name' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	protected function get_views() {
		$counts = Resandpay_Reservations::counts_by_status( absint( $_GET['event_id'] ?? 0 ) );
		$base   = remove_query_arg( array( 'status', 'paged', 'rap_msg' ) );
		$cur    = sanitize_key( $_GET['status'] ?? '' );
		$views  = array(
			'all' => sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( $base ), '' === $cur ? 'current' : '', esc_html__( 'Vše', 'reserveandpay' ), array_sum( $counts ) ),
		);
		foreach ( Resandpay_Reservations::status_labels() as $k => $l ) {
			$views[ $k ] = sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', $k, $base ) ), $cur === $k ? 'current' : '', esc_html( $l ), $counts[ $k ] );
		}
		return $views;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$events = get_posts(
			array(
				'post_type'      => Resandpay_Post_Types::EVENT,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => 300,
				'meta_key'       => '_rap_start',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		$cur    = absint( $_GET['event_id'] ?? 0 );
		echo '<div class="alignleft actions"><select name="event_id"><option value="0">' . esc_html__( 'Všechny akce', 'reserveandpay' ) . '</option>';
		foreach ( $events as $e ) {
			$ev = Resandpay_Events::get( $e->ID );
			printf( '<option value="%d" %s>%s – %s</option>', (int) $e->ID, selected( $cur, $e->ID, false ), esc_html( $e->post_title ), esc_html( Resandpay_Events::format_date_full( $ev ) ) );
		}
		$mats = get_posts( array( 'post_type' => Resandpay_Post_Types::MATERIAL, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( $mats ) {
			echo '<optgroup label="' . esc_attr__( 'Materiály (objednávky)', 'reserveandpay' ) . '">';
			foreach ( $mats as $m ) {
				printf( '<option value="%d" %s>%s</option>', (int) $m->ID, selected( $cur, $m->ID, false ), esc_html( $m->post_title . ' – ' . Resandpay_Codes::get( $m->ID ) ) );
			}
			echo '</optgroup>';
		}
		echo '</select>';
		submit_button( __( 'Filtrovat', 'reserveandpay' ), '', 'filter_action', false );
		if ( $cur ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=resandpay_export&event_id=' . $cur ), 'resandpay_export' ) ) . '">' . esc_html__( 'Export CSV', 'reserveandpay' ) . '</a>';
		}
		echo '</div>';
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '">';
	}

	protected function column_customer_name( $item ) {
		$detail = admin_url( 'admin.php?page=reserveandpay-detail&id=' . (int) $item['id'] );
		$out    = '<strong><a href="' . esc_url( $detail ) . '">' . esc_html( $item['customer_name'] ) . '</a></strong>';
		if ( $item['company'] ) {
			$out .= '<br><small>' . esc_html( $item['company'] . ( $item['company_ic'] ? ' · IČ ' . $item['company_ic'] : '' ) ) . '</small>';
		}
		$actions = array( 'detail' => '<a href="' . esc_url( $detail ) . '">' . esc_html__( 'Detail', 'reserveandpay' ) . '</a>' );
		foreach ( Resandpay_Admin_Reservations::row_actions_for( $item ) as $key => $label ) {
			$confirm         = 'cancel' === $key ? ' onclick="return confirm(\'' . esc_js( __( 'Zrušit rezervaci, uvolnit místo a poslat zákaznici e-mail?', 'reserveandpay' ) ) . '\')"' : '';
			if ( 'delete' === $key ) {
				$confirm = ' class="submitdelete" onclick="return confirm(\'' . esc_js( __( 'Trvale smazat rezervaci? Tuto akci nelze vrátit.', 'reserveandpay' ) ) . '\')"';
			}
			$actions[ $key ] = '<a href="' . esc_url( Resandpay_Admin_Reservations::action_url( $key, $item['id'] ) ) . '"' . $confirm . '>' . esc_html( $label ) . '</a>';
		}
		return $out . $this->row_actions( $actions );
	}

	protected function column_contact( $item ) {
		return '<a href="mailto:' . esc_attr( $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a><br>' . esc_html( $item['phone'] );
	}

	protected function column_event( $item ) {
		$ev = Resandpay_Events::get( $item['event_id'] );
		if ( ! $ev ) {
			return '—';
		}
		return '<a href="' . esc_url( add_query_arg( 'event_id', $ev['id'], remove_query_arg( array( 'paged', 'rap_msg' ) ) ) ) . '">' . esc_html( $ev['title'] ) . '</a><br><small>' . esc_html( trim( $ev['code'] . ' · ' . Resandpay_Events::format_date_full( $ev ), ' ·' ) ) . '</small>';
	}

	protected function column_status( $item ) {
		$labels = Resandpay_Reservations::status_labels();
		$out    = '<span class="rap-status rap-status--' . esc_attr( $item['status'] ) . '">' . esc_html( $labels[ $item['status'] ] ?? $item['status'] ) . '</span>';
		if ( $item['last_minute'] && Resandpay_Reservations::PENDING === $item['status'] ) {
			$out .= '<br><small>' . esc_html__( 'na poslední chvíli', 'reserveandpay' ) . '</small>';
		}
		if ( 'cash' === ( $item['pay_choice'] ?? '' ) && Resandpay_Reservations::PAID !== $item['status'] ) {
			$out .= '<br><span class="rap-tag--cash">' . esc_html__( 'zvolila hotovost', 'reserveandpay' ) . '</span>';
		}
		if ( Resandpay_Reservations::PAID === $item['status'] && $item['paid_method'] ) {
			$out .= '<br><small>' . esc_html( Resandpay_Reservations::paid_method_labels()[ $item['paid_method'] ] ?? $item['paid_method'] ) . '</small>';
		}
		return $out;
	}

	protected function column_default( $item, $col ) {
		switch ( $col ) {
			case 'vs':
				return '<code>' . esc_html( $item['vs'] ) . '</code>';
			case 'amount':
				$out = esc_html( Resandpay_Events::format_price( $item['amount'], $item['currency'] ) );
				if ( Resandpay_Reservations::PAID !== $item['status'] && (float) $item['amount_paid'] > 0 ) {
					/* translators: %s: zaplaceno */
					$out .= '<br><small>' . esc_html( sprintf( __( 'zaplaceno %s', 'reserveandpay' ), Resandpay_Events::format_price( $item['amount_paid'], $item['currency'] ) ) ) . '</small>';
				}
				if ( Resandpay_Reservations::discount_now( $item ) > 0 ) {
					/* translators: %s: sleva */
					$out .= '<br><small class="rap-tag--discount">' . esc_html( sprintf( __( 'sleva −%s', 'reserveandpay' ), Resandpay_Events::format_price( $item['discount_amount'], $item['currency'] ) ) ) . '</small>';
				} elseif ( ! empty( $item['discount_lost_at'] ) ) {
					$out .= '<br><small>' . esc_html__( 'sleva zanikla', 'reserveandpay' ) . '</small>';
				}
				if ( Resandpay_Reservations::is_split( $item ) && Resandpay_Reservations::PAID !== $item['status'] && ! ( (float) $item['amount_paid'] > 0 ) ) {
					$out .= '<br><small>' . esc_html__( 've 2 částech', 'reserveandpay' ) . '</small>';
				}
				return $out;
			case 'due_at':
			case 'created_at':
				return $item[ $col ] ? esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $item[ $col ] ) ) ) : '—';
		}
		return esc_html( $item[ $col ] ?? '' );
	}

	public function no_items() {
		esc_html_e( 'Zatím žádné rezervace.', 'reserveandpay' );
	}
}
