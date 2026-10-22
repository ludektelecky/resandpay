<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace rezervací: seznam, detail, ruční rezervace, akce, export.
 */
class Resandpay_Admin_Reservations {

	public static function init() {
		add_action( 'admin_post_resandpay_res_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_resandpay_new', array( __CLASS__, 'handle_new' ) );
		add_action( 'admin_post_resandpay_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_bulk' ) );
	}

	/** Akce dostupné pro rezervaci podle stavu. */
	public static function row_actions_for( array $r ) {
		$a = array();
		switch ( $r['status'] ) {
			case Resandpay_Reservations::PENDING:
				$a['paid']        = __( 'Zaplaceno (převod)', 'reserveandpay' );
				$a['onsite']      = __( 'Platba na místě', 'reserveandpay' );
				$a['resend']      = __( 'Znovu poslat platební údaje', 'reserveandpay' );
				$a['cancel']      = __( 'Zrušit', 'reserveandpay' );
				break;
			case Resandpay_Reservations::ONSITE:
				$a['paid_onsite'] = __( 'Zaplaceno hotově', 'reserveandpay' );
				$a['paid']        = __( 'Zaplaceno (převod)', 'reserveandpay' );
				$a['resend']      = __( 'Znovu poslat potvrzení', 'reserveandpay' );
				$a['cancel']      = __( 'Zrušit', 'reserveandpay' );
				break;
			case Resandpay_Reservations::PARTIAL:
				$a['paid']        = __( 'Doplaceno (převod)', 'reserveandpay' );
				$a['paid_onsite'] = __( 'Doplaceno hotově', 'reserveandpay' );
				$a['resend']      = __( 'Znovu poslat platební údaje', 'reserveandpay' );
				$a['cancel']      = __( 'Zrušit', 'reserveandpay' );
				break;
			case Resandpay_Reservations::OVERDUE:
				$a['paid']        = __( 'Zaplaceno (převod)', 'reserveandpay' );
				$a['paid_onsite'] = __( 'Zaplaceno hotově', 'reserveandpay' );
				$a['resend']      = __( 'Znovu poslat platební údaje', 'reserveandpay' );
				$a['cancel']      = __( 'Zrušit', 'reserveandpay' );
				break;
			case Resandpay_Reservations::EXPIRED:
				$a['paid']        = __( 'Zaplaceno (převod)', 'reserveandpay' );
				$a['cancel']      = __( 'Zrušit', 'reserveandpay' );
				break;
			case Resandpay_Reservations::PAID:
				$a['cancel']      = __( 'Zrušit', 'reserveandpay' );
				break;
		}
		return $a;
	}

	public static function action_url( $do, $id, $extra = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'action' => 'resandpay_res_action',
					'do'     => $do,
					'id'     => (int) $id,
				),
				$extra
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'resandpay_res_' . $do . '_' . (int) $id );
	}

	private static function back( $fallback = '' ) {
		$ref = wp_get_referer();
		return $ref ? remove_query_arg( array( 'rap_msg' ), $ref ) : ( $fallback ?: admin_url( 'admin.php?page=reserveandpay' ) );
	}

	/** Provede jednu akci nad rezervací. */
	public static function do_action( $do, $id, $reason = '' ) {
		switch ( $do ) {
			case 'paid':
				return Resandpay_Reservations::set_status( $id, Resandpay_Reservations::PAID, array( 'method' => 'transfer_manual', 'notify' => true ) );
			case 'paid_onsite':
				return Resandpay_Reservations::set_status( $id, Resandpay_Reservations::PAID, array( 'method' => 'cash', 'notify' => true ) );
			case 'onsite':
				return Resandpay_Reservations::set_status( $id, Resandpay_Reservations::ONSITE );
			case 'cancel':
				return Resandpay_Reservations::set_status( $id, Resandpay_Reservations::CANCELLED, array( 'notify' => true, 'reason' => $reason ) );
			case 'cancel_silent':
				return Resandpay_Reservations::set_status( $id, Resandpay_Reservations::CANCELLED, array( 'notify' => false, 'reason' => $reason ) );
			case 'add_payment':
				$amount = round( (float) str_replace( ',', '.', (string) ( $_REQUEST['amount'] ?? 0 ) ), 2 ); // phpcs:ignore WordPress.Security.NonceVerification -- ověřeno v handle_action.
				$method = 'cash' === ( $_REQUEST['method'] ?? '' ) ? 'cash' : 'transfer_manual'; // phpcs:ignore WordPress.Security.NonceVerification
				return Resandpay_Reservations::add_payment( $id, $amount, $method, array( 'allow_overbook' => true ) );
			case 'resend':
				$r = Resandpay_Reservations::get( $id );
				if ( ! $r ) {
					return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
				}
				$tpl = (float) $r['amount_paid'] > 0 ? 'reminder' : Resandpay_Mailer::received_key( $r );
				if ( ! Resandpay_Mailer::send_template( $r['email'], $tpl, $r ) ) {
					/* translators: %s: chybová zpráva */
					return new WP_Error( 'mail', sprintf( __( 'E-mail se nepodařilo odeslat: %s', 'reserveandpay' ), Resandpay_Mailer::$last_error ?: '—' ) );
				}
				return $r;
		}
		return new WP_Error( 'unknown', __( 'Neznámá akce.', 'reserveandpay' ) );
	}

	public static function handle_action() {
		$do = sanitize_key( $_REQUEST['do'] ?? '' );
		$id = absint( $_REQUEST['id'] ?? 0 );
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_res_' . $do . '_' . $id );
		$reason = sanitize_text_field( wp_unslash( $_REQUEST['reason'] ?? '' ) );
		$res    = self::do_action( $do, $id, $reason );
		if ( is_wp_error( $res ) ) {
			Resandpay_Admin::flash( $res->get_error_message(), 'error' );
		} else {
			Resandpay_Admin::flash( 'resend' === $do ? __( 'Platební údaje byly znovu odeslány.', 'reserveandpay' ) : __( 'Rezervace byla aktualizována.', 'reserveandpay' ) );
		}
		wp_safe_redirect( self::back() );
		exit;
	}

	public static function handle_bulk() {
		if ( ( $_REQUEST['page'] ?? '' ) !== 'reserveandpay' || empty( $_REQUEST['ids'] ) ) {
			return;
		}
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		if ( '-1' === $action || '' === $action ) {
			$action = sanitize_key( $_REQUEST['action2'] ?? '' );
		}
		if ( ! in_array( $action, array( 'cancel', 'cancel_silent' ), true ) ) {
			return;
		}
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'bulk-reservations' );
		$ok = 0;
		foreach ( array_map( 'absint', (array) $_REQUEST['ids'] ) as $id ) {
			if ( ! is_wp_error( self::do_action( $action, $id ) ) ) {
				++$ok;
			}
		}
		/* translators: %d: počet */
		Resandpay_Admin::flash( sprintf( __( 'Zrušeno rezervací: %d', 'reserveandpay' ), $ok ) );
		wp_safe_redirect( remove_query_arg( array( 'ids', 'action', 'action2', '_wpnonce', '_wp_http_referer' ), wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
		exit;
	}

	public static function page_list() {
		$table = new Resandpay_Reservations_Table();
		$table->prepare_items();
		$event = absint( $_GET['event_id'] ?? 0 );
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Rezervace', 'reserveandpay' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-new' . ( $event ? '&event_id=' . $event : '' ) ) ) . '">' . esc_html__( 'Přidat ručně', 'reserveandpay' ) . '</a><hr class="wp-header-end">';
		Resandpay_Admin::render_flash();
		if ( $event ) {
			$ev = Resandpay_Events::get( $event );
			if ( $ev ) {
				echo '<p class="rap-event-head"><strong>' . esc_html( $ev['title'] ) . '</strong> – ' . esc_html( Resandpay_Events::format_date( $ev ) ) . ' · ';
				/* translators: 1: obsazeno, 2: kapacita */
				echo esc_html( sprintf( __( 'obsazeno %1$d / %2$d', 'reserveandpay' ), Resandpay_Reservations::count_active( $event ), $ev['capacity'] ) );
				echo ' · <a href="' . esc_url( get_edit_post_link( $event ) ) . '">' . esc_html__( 'upravit akci', 'reserveandpay' ) . '</a></p>';
			}
		}
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="reserveandpay">';
		if ( ! empty( $_GET['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( $_GET['status'] ) ) . '">';
		}
		$table->search_box( __( 'Hledat', 'reserveandpay' ), 'rap-search' );
		$table->display();
		echo '</form></div>';
	}

	public static function page_detail() {
		$r = Resandpay_Reservations::get( absint( $_GET['id'] ?? 0 ) );
		echo '<div class="wrap">';
		if ( ! $r ) {
			echo '<h1>' . esc_html__( 'Rezervace nenalezena', 'reserveandpay' ) . '</h1></div>';
			return;
		}
		$ev     = Resandpay_Events::get( $r['event_id'] );
		$labels = Resandpay_Reservations::status_labels();
		echo '<h1>' . esc_html( sprintf( /* translators: %s: VS */ __( 'Rezervace VS %s', 'reserveandpay' ), $r['vs'] ) ) . '</h1>';
		Resandpay_Admin::render_flash();
		$fmt  = function ( $d ) {
			return $d ? wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $d ) ) : '—';
		};
		$rows = array(
			__( 'Stav', 'reserveandpay' )              => '<span class="rap-status rap-status--' . esc_attr( $r['status'] ) . '">' . esc_html( $labels[ $r['status'] ] ) . '</span>',
			__( 'Akce', 'reserveandpay' )              => $ev ? '<a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay&event_id=' . $ev['id'] ) ) . '">' . esc_html( $ev['title'] ) . '</a> – ' . esc_html( Resandpay_Events::format_date( $ev ) ) . ( $ev['code'] ? ' · <code>' . esc_html( $ev['code'] ) . '</code>' : '' ) : '—',
			__( 'Jméno', 'reserveandpay' )             => esc_html( $r['customer_name'] ),
			__( 'E-mail', 'reserveandpay' )            => '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>',
			__( 'Telefon', 'reserveandpay' )           => esc_html( $r['phone'] ),
			__( 'Firma', 'reserveandpay' )             => esc_html( trim( $r['company'] . ( $r['company_ic'] ? ', IČ ' . $r['company_ic'] : '' ) . ( $r['company_dic'] ? ', DIČ ' . $r['company_dic'] : '' ), ', ' ) ?: '—' ),
			__( 'Poznámka', 'reserveandpay' )          => nl2br( esc_html( $r['note'] ?: '—' ) ),
			__( 'Částka', 'reserveandpay' )            => esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) . ( Resandpay_Reservations::is_split( $r ) ? ' · ' . esc_html( sprintf( /* translators: 1: 1. část, 2: datum, 3: doplatek, 4: datum */ __( '1. část %1$s do %2$s · doplatek %3$s do %4$s', 'reserveandpay' ), Resandpay_Events::format_price( $r['part1_amount'], $r['currency'] ), $fmt( $r['part1_due_at'] ), Resandpay_Events::format_price( (float) $r['amount'] - (float) $r['part1_amount'], $r['currency'] ), $fmt( $r['due_at'] ) ) ) : '' ),
			__( 'Uhrazeno', 'reserveandpay' )          => esc_html( sprintf( /* translators: 1: zaplaceno, 2: zbývá */ __( '%1$s · zbývá %2$s', 'reserveandpay' ), Resandpay_Events::format_price( $r['amount_paid'], $r['currency'] ), Resandpay_Events::format_price( Resandpay_Reservations::remaining( $r ), $r['currency'] ) ) ),
			__( 'Variabilní symbol', 'reserveandpay' ) => '<code>' . esc_html( $r['vs'] ) . '</code>',
			__( 'Číslo pokynu', 'reserveandpay' )      => esc_html( $r['proforma_no'] ) . ' · <a href="' . esc_url( Resandpay_PDF::url( $r ) ) . '" target="_blank">' . esc_html__( 'Platební pokyn (PDF)', 'reserveandpay' ) . '</a>',
			__( 'Zvolená platba', 'reserveandpay' )    => esc_html( Resandpay_Reservations::pay_choice_labels()[ $r['pay_choice'] ?? 'transfer' ] ?? '' ),
			__( 'Splatnost', 'reserveandpay' )         => esc_html( $fmt( $r['due_at'] ) ) . ( $r['last_minute'] ? ' (' . esc_html__( 'rezervace na poslední chvíli – lze platit na místě', 'reserveandpay' ) . ')' : '' ),
			__( 'Zaplaceno', 'reserveandpay' )         => $r['paid_at'] ? esc_html( $fmt( $r['paid_at'] ) . ' – ' . ( Resandpay_Reservations::paid_method_labels()[ $r['paid_method'] ] ?? $r['paid_method'] ) ) : '—',
			__( 'Zrušeno', 'reserveandpay' )           => $r['cancelled_at'] ? esc_html( $fmt( $r['cancelled_at'] ) . ( $r['cancel_reason'] ? ' – ' . $r['cancel_reason'] : '' ) ) : '—',
			__( 'Souhlasy', 'reserveandpay' )          => esc_html( sprintf( 'GDPR: %s · %s: %s · Newsletter: %s', $fmt( $r['consent_gdpr_at'] ), __( 'Podmínky', 'reserveandpay' ), $fmt( $r['consent_terms_at'] ), $r['newsletter'] ? __( 'ano', 'reserveandpay' ) : __( 'ne', 'reserveandpay' ) ) ),
			__( 'Vytvořeno', 'reserveandpay' )         => esc_html( $fmt( $r['created_at'] ) . ' (' . ( 'admin' === $r['source'] ? __( 'ručně v administraci', 'reserveandpay' ) : __( 'web', 'reserveandpay' ) ) . ')' ),
		);
		echo '<table class="widefat striped rap-detail"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- hodnoty escapovány výše.
		}
		echo '</tbody></table>';

		$actions = self::row_actions_for( $r );
		if ( $actions ) {
			echo '<h2>' . esc_html__( 'Akce', 'reserveandpay' ) . '</h2><p>';
			foreach ( $actions as $k => $l ) {
				if ( 'cancel' === $k ) {
					continue;
				}
				echo '<a class="button" href="' . esc_url( self::action_url( $k, $r['id'] ) ) . '">' . esc_html( $l ) . '</a> ';
			}
			echo '</p>';
			if ( Resandpay_Reservations::CANCELLED !== $r['status'] && Resandpay_Reservations::PAID !== $r['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rap-addpay-form">';
				echo '<input type="hidden" name="action" value="resandpay_res_action"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="do" value="add_payment">';
				wp_nonce_field( 'resandpay_res_add_payment_' . (int) $r['id'] );
				echo '<label>' . esc_html__( 'Připsat platbu:', 'reserveandpay' ) . ' <input type="number" name="amount" min="1" step="1" class="small-text" value="' . esc_attr( (string) round( Resandpay_Reservations::amount_now( $r ) ) ) . '"> ' . esc_html( $r['currency'] ) . '</label> ';
				echo '<select name="method"><option value="cash">' . esc_html__( 'hotově', 'reserveandpay' ) . '</option><option value="transfer_manual">' . esc_html__( 'převodem', 'reserveandpay' ) . '</option></select> ';
				echo '<button class="button">' . esc_html__( 'Připsat a poslat e-mail', 'reserveandpay' ) . '</button>';
				echo '<p class="description">' . esc_html__( 'Pro částečné platby (např. 1. část hotově). Při doplacení celé částky se rezervace označí jako zaplacená.', 'reserveandpay' ) . '</p>';
				echo '</form>';
			}
			if ( isset( $actions['cancel'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rap-cancel-form">';
				echo '<input type="hidden" name="action" value="resandpay_res_action"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
				wp_nonce_field( 'resandpay_res_cancel_' . (int) $r['id'] );
				echo '<input type="hidden" name="do" value="cancel">';
				echo '<label>' . esc_html__( 'Důvod zrušení (zobrazí se v e-mailu):', 'reserveandpay' ) . ' <input type="text" name="reason" class="regular-text"></label> ';
				echo '<button class="button button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Opravdu zrušit rezervaci a uvolnit místo?', 'reserveandpay' ) ) . '\')">' . esc_html__( 'Zrušit rezervaci a poslat e-mail', 'reserveandpay' ) . '</button>';
				echo '</form>';
			}
		}
		echo '<h2>' . esc_html__( 'Odeslané e-maily', 'reserveandpay' ) . '</h2>';
		$mails = Resandpay_Mailer::log_rows( array( 'reservation_id' => (int) $r['id'], 'limit' => 100 ) );
		if ( $mails['rows'] ) {
			Resandpay_Mailer::render_log_table( $mails['rows'], false );
		} else {
			echo '<p>' . esc_html__( 'K této rezervaci zatím není zaznamenaný žádný e-mail (seznam se vede od verze 0.7.2).', 'reserveandpay' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay' ) ) . '">&larr; ' . esc_html__( 'Zpět na seznam', 'reserveandpay' ) . '</a></p></div>';
	}

	public static function page_new() {
		$events = Resandpay_Events::upcoming(
			array(
				'limit'         => 300,
				'include_slots' => true,
			)
		);
		$pre    = absint( $_GET['event_id'] ?? 0 );
		echo '<div class="wrap"><h1>' . esc_html__( 'Nová rezervace (ručně)', 'reserveandpay' ) . '</h1>';
		Resandpay_Admin::render_flash();
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'Nejsou žádné nadcházející publikované akce.', 'reserveandpay' ) . '</p></div>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="resandpay_new">
			<?php wp_nonce_field( 'resandpay_new' ); ?>
			<table class="form-table">
				<tr><th><label for="event_id"><?php esc_html_e( 'Akce', 'reserveandpay' ); ?></label></th><td><select name="event_id" id="event_id" required>
					<?php foreach ( $events as $ev ) : ?>
						<option value="<?php echo (int) $ev['id']; ?>" <?php selected( $pre, $ev['id'] ); ?>><?php echo esc_html( $ev['title'] . ' – ' . Resandpay_Events::format_date( $ev ) . ' (' . Resandpay_Events::free_places( $ev['id'] ) . ' ' . __( 'volných', 'reserveandpay' ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
				<tr><th><label for="name"><?php esc_html_e( 'Jméno a příjmení', 'reserveandpay' ); ?> *</label></th><td><input type="text" name="name" id="name" class="regular-text" required></td></tr>
				<tr><th><label for="email"><?php esc_html_e( 'E-mail', 'reserveandpay' ); ?> *</label></th><td><input type="email" name="email" id="email" class="regular-text" required></td></tr>
				<tr><th><label for="phone"><?php esc_html_e( 'Telefon', 'reserveandpay' ); ?></label></th><td><input type="text" name="phone" id="phone" class="regular-text"></td></tr>
				<tr><th><label for="note"><?php esc_html_e( 'Poznámka', 'reserveandpay' ); ?></label></th><td><textarea name="note" id="note" rows="3" class="large-text"></textarea></td></tr>
				<tr><th><label for="pay_choice"><?php esc_html_e( 'Způsob platby', 'reserveandpay' ); ?></label></th><td><select name="pay_choice" id="pay_choice">
					<?php foreach ( Resandpay_Reservations::pay_choice_labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
				<tr><th><?php esc_html_e( 'Možnosti', 'reserveandpay' ); ?></th><td>
					<label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Poslat zákaznici e-mail s platebními údaji', 'reserveandpay' ); ?></label><br>
					<label><input type="checkbox" name="paid_onsite" value="1"> <?php esc_html_e( 'Rovnou označit jako zaplaceno hotově', 'reserveandpay' ); ?></label>
				</td></tr>
			</table>
			<?php submit_button( __( 'Vytvořit rezervaci', 'reserveandpay' ) ); ?>
		</form></div>
		<?php
	}

	public static function handle_new() {
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_new' );
		$event = absint( $_POST['event_id'] ?? 0 );
		$res   = Resandpay_Reservations::create(
			$event,
			array(
				'customer_name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'email'         => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
				'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				'note'          => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
				'pay_choice'    => 'cash' === ( $_POST['pay_choice'] ?? '' ) ? 'cash' : 'transfer',
				'user_id'       => 0,
			),
			array(
				'source'       => 'admin',
				'ignore_close' => true,
				'notify'       => ! empty( $_POST['notify'] ),
			)
		);
		if ( is_wp_error( $res ) ) {
			Resandpay_Admin::flash( $res->get_error_message(), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-new&event_id=' . $event ) );
			exit;
		}
		if ( ! empty( $_POST['paid_onsite'] ) ) {
			Resandpay_Reservations::set_status( $res['id'], Resandpay_Reservations::PAID, array( 'method' => 'cash' ) );
		}
		/* translators: %s: VS */
		Resandpay_Admin::flash( sprintf( __( 'Rezervace vytvořena (VS %s).', 'reserveandpay' ), $res['vs'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=reserveandpay-detail&id=' . $res['id'] ) );
		exit;
	}

	/** Export účastnic akce do CSV (středník, UTF-8 s BOM – pro český Excel). */
	public static function handle_export() {
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_export' );
		$event = absint( $_GET['event_id'] ?? 0 );
		$ev    = Resandpay_Events::get( $event );
		$rows  = Resandpay_Reservations::query(
			array(
				'event_id' => $event,
				'per_page' => 10000,
				'orderby'  => 'created_at',
				'order'    => 'ASC',
			)
		)['items'];
		$name  = sanitize_file_name( 'ucastnice-' . ( $ev ? $ev['title'] : 'vse' ) . '-' . wp_date( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		$labels = Resandpay_Reservations::status_labels();
		fputcsv( $out, array( 'Kód akce', 'Jméno', 'E-mail', 'Telefon', 'Firma', 'IČ', 'DIČ', 'Stav', 'VS', 'Částka', 'Uhrazeno', 'Zbývá', 'Měna', 'Zvolená platba', 'Splatnost', 'Zaplaceno', 'Způsob úhrady', 'Poznámka', 'Newsletter', 'Vytvořeno' ), ';' );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					Resandpay_Codes::get( $r['event_id'] ),
					$r['customer_name'],
					$r['email'],
					$r['phone'],
					$r['company'],
					$r['company_ic'],
					$r['company_dic'],
					$labels[ $r['status'] ] ?? $r['status'],
					$r['vs'],
					number_format( (float) $r['amount'], 2, ',', '' ),
					number_format( (float) $r['amount_paid'], 2, ',', '' ),
					number_format( Resandpay_Reservations::remaining( $r ), 2, ',', '' ),
					$r['currency'],
					Resandpay_Reservations::pay_choice_labels()[ $r['pay_choice'] ?? 'transfer' ] ?? '',
					$r['due_at'],
					$r['paid_at'],
					$r['paid_method'] ? ( Resandpay_Reservations::paid_method_labels()[ $r['paid_method'] ] ?? $r['paid_method'] ) : '',
					$r['note'],
					$r['newsletter'] ? 'ano' : 'ne',
					$r['created_at'],
				),
				';'
			);
		}
		fclose( $out );
		exit;
	}
}
