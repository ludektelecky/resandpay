<?php
defined( 'ABSPATH' ) || exit;

/**
 * Odbavení na místě (SWD v2.0, kap. 4.6): vyhledání rezervace podle VS
 * (zadaného ručně nebo vloženého z QR kódu) a označení platby hotově/převodem.
 * Přehled rezervací dne s částkou k vybrání v hotovosti. Stránka je navržená
 * i pro telefon – Hanka ji otevře na lekci.
 */
class Resandpay_Admin_Checkin {

	const PAGE = 'reserveandpay-checkin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_resandpay_checkin', array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'reserveandpay-jsqr', RESANDPAY_URL . 'assets/js/jsQR.js', array(), '1.4.0', true );
		wp_enqueue_script( 'reserveandpay-scan', RESANDPAY_URL . 'assets/js/checkin-scan.js', array( 'reserveandpay-jsqr' ), RESANDPAY_VERSION, true );
		wp_add_inline_script(
			'reserveandpay-scan',
			'window.rapScanText = ' . wp_json_encode(
				array(
					'aim'        => __( 'Namiřte kameru na QR kód z e-mailu nebo platebního pokynu.', 'reserveandpay' ),
					'notPayment' => __( 'Tento QR kód neobsahuje variabilní symbol – zkuste QR platbu z e-mailu.', 'reserveandpay' ),
					'noCamera'   => __( 'Kamera není v tomto prohlížeči dostupná (je potřeba zabezpečené připojení HTTPS).', 'reserveandpay' ),
					'denied'     => __( 'Přístup ke kameře byl zamítnut. Povolte ho v prohlížeči, nebo zadejte VS ručně.', 'reserveandpay' ),
				)
			) . ';',
			'before'
		);
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Odbavení na místě', 'reserveandpay' ), __( 'Odbavení', 'reserveandpay' ), resandpay_cap_reservations(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/** Lze rezervaci na místě označit jako zaplacenou? */
	public static function payable( array $r ) {
		return in_array( $r['status'], array( Resandpay_Reservations::PENDING, Resandpay_Reservations::ONSITE, Resandpay_Reservations::OVERDUE, Resandpay_Reservations::EXPIRED ), true );
	}

	/**
	 * Označí platbu na místě.
	 *
	 * @param string $how cash|transfer.
	 */
	public static function mark_paid( $id, $how, $notify = false ) {
		$r = Resandpay_Reservations::get( $id );
		if ( ! $r ) {
			return new WP_Error( 'not_found', __( 'Rezervace nenalezena.', 'reserveandpay' ) );
		}
		if ( Resandpay_Reservations::PAID === $r['status'] ) {
			return new WP_Error( 'already', __( 'Rezervace už je zaplacená.', 'reserveandpay' ) );
		}
		if ( ! self::payable( $r ) ) {
			return new WP_Error( 'state', __( 'Zrušenou rezervaci nelze označit jako zaplacenou.', 'reserveandpay' ) );
		}
		return Resandpay_Reservations::set_status(
			$id,
			Resandpay_Reservations::PAID,
			array(
				'method'         => 'cash' === $how ? 'cash' : 'transfer_manual',
				'notify'         => (bool) $notify,
				'allow_overbook' => true, // Klientka je na místě – expirovanou rezervaci přijmeme i nad kapacitu.
			)
		);
	}

	public static function handle() {
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'resandpay_checkin_' . $id );
		$how = 'cash' === ( $_POST['how'] ?? '' ) ? 'cash' : 'transfer';
		$res = self::mark_paid( $id, $how, ! empty( $_POST['notify'] ) );
		if ( is_wp_error( $res ) ) {
			Resandpay_Admin::flash( $res->get_error_message(), 'error' );
		} else {
			/* translators: 1: jméno, 2: způsob */
			Resandpay_Admin::flash( sprintf( __( '%1$s – zaplaceno (%2$s).', 'reserveandpay' ), $res['customer_name'], Resandpay_Reservations::paid_method_labels()[ $res['paid_method'] ] ?? '' ) );
		}
		$back = array();
		if ( ! empty( $_POST['vs'] ) ) {
			$back['vs'] = sanitize_text_field( wp_unslash( $_POST['vs'] ) );
		}
		if ( ! empty( $_POST['day'] ) ) {
			$back['day'] = sanitize_text_field( wp_unslash( $_POST['day'] ) );
		}
		wp_safe_redirect( self::url( $back ) );
		exit;
	}

	/** Formulář tlačítka akce (POST). */
	private static function action_button( array $r, $how, $label, $primary, array $ctx ) {
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rap-inline-form" style="display:inline">';
		$out .= '<input type="hidden" name="action" value="resandpay_checkin"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="how" value="' . esc_attr( $how ) . '">';
		foreach ( $ctx as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$out .= wp_nonce_field( 'resandpay_checkin_' . (int) $r['id'], '_wpnonce', false, false );
		$out .= '<button type="submit" class="button ' . ( $primary ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		return $out;
	}

	public static function page() {
		$tz    = wp_timezone();
		$input = isset( $_GET['vs'] ) ? sanitize_text_field( wp_unslash( $_GET['vs'] ) ) : '';
		$dayp  = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
		$day   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dayp ) ? date_create_immutable( $dayp . ' 00:00:00', $tz ) : null;
		$day   = $day ?: new DateTimeImmutable( 'today', $tz );

		echo '<div class="wrap rap-checkin"><h1>' . esc_html__( 'Odbavení na místě', 'reserveandpay' ) . '</h1>';
		Resandpay_Admin::render_flash();
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="rap-checkin__form"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label class="screen-reader-text" for="rap-vs">' . esc_html__( 'Variabilní symbol', 'reserveandpay' ) . '</label>';
		echo '<input type="text" id="rap-vs" name="vs" value="' . esc_attr( $input ) . '" placeholder="' . esc_attr__( 'Variabilní symbol', 'reserveandpay' ) . '" inputmode="numeric" autocomplete="off" autofocus>';
		submit_button( __( 'Najít rezervaci', 'reserveandpay' ), 'primary', '', false );
		echo '</form>';
		echo '<p><button type="button" class="button button-hero" id="rap-scan-btn">📷 ' . esc_html__( 'Načíst QR kód kamerou', 'reserveandpay' ) . '</button></p>';
		echo '<div id="rap-scan" class="rap-scan" hidden><video muted playsinline></video><p class="rap-scan__msg"></p><p><button type="button" class="button rap-scan__stop">' . esc_html__( 'Zavřít kameru', 'reserveandpay' ) . '</button></p></div>';
		echo '<p class="description">' . esc_html__( 'Zadejte VS z potvrzovacího e-mailu (stačí i bez úvodních nul), nebo načtěte QR kód z e-mailu či platebního pokynu.', 'reserveandpay' ) . '</p>';

		if ( '' !== $input ) {
			$r = Resandpay_Reservations::find_by_vs_input( $input );
			if ( ! $r ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( /* translators: %s: VS */ __( 'Rezervace s VS %s nebyla nalezena.', 'reserveandpay' ), $input ) ) . '</p></div>';
			} else {
				self::render_card( $r, array( 'vs' => $input ) );
			}
		}
		self::render_day( $day );
		echo '</div>';
	}

	private static function render_card( array $r, array $ctx ) {
		$ev     = Resandpay_Events::get( $r['event_id'] );
		$labels = Resandpay_Reservations::status_labels();
		$paid   = Resandpay_Reservations::PAID === $r['status'];
		$cls    = $paid ? ' is-paid' : ( self::payable( $r ) ? '' : ' is-off' );
		echo '<div class="rap-checkin__card' . esc_attr( $cls ) . '">';
		echo '<h2>' . esc_html( $r['customer_name'] ) . '</h2>';
		echo '<p><strong>' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</strong>';
		if ( $paid && $r['paid_method'] ) {
			echo ' – ' . esc_html( Resandpay_Reservations::paid_method_labels()[ $r['paid_method'] ] ?? $r['paid_method'] );
		}
		echo '</p><dl>';
		echo '<dt>' . esc_html__( 'Akce', 'reserveandpay' ) . '</dt><dd>' . esc_html( $ev ? $ev['title'] . ' – ' . Resandpay_Events::format_date( $ev ) : '—' ) . ( $ev && $ev['code'] ? ' <code>' . esc_html( $ev['code'] ) . '</code>' : '' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Částka', 'reserveandpay' ) . '</dt><dd><strong>' . esc_html( Resandpay_Events::format_price( $r['amount'], $r['currency'] ) ) . '</strong></dd>';
		echo '<dt>' . esc_html__( 'VS', 'reserveandpay' ) . '</dt><dd><code>' . esc_html( $r['vs'] ) . '</code></dd>';
		echo '<dt>' . esc_html__( 'Zvolená platba', 'reserveandpay' ) . '</dt><dd>' . esc_html( Resandpay_Reservations::pay_choice_labels()[ $r['pay_choice'] ?? 'transfer' ] ?? '' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Kontakt', 'reserveandpay' ) . '</dt><dd>' . esc_html( trim( $r['email'] . ' · ' . $r['phone'], ' ·' ) ) . '</dd>';
		if ( $r['note'] ) {
			echo '<dt>' . esc_html__( 'Poznámka', 'reserveandpay' ) . '</dt><dd>' . esc_html( $r['note'] ) . '</dd>';
		}
		echo '</dl>';
		if ( self::payable( $r ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="resandpay_checkin"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			foreach ( $ctx as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			wp_nonce_field( 'resandpay_checkin_' . (int) $r['id'] );
			echo '<p><label><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'Poslat zákaznici potvrzení o platbě e-mailem', 'reserveandpay' ) . '</label></p>';
			echo '<p class="rap-checkin__actions"><button type="submit" name="how" value="cash" class="button button-primary">' . esc_html__( 'Zaplaceno hotově', 'reserveandpay' ) . '</button>';
			echo '<button type="submit" name="how" value="transfer" class="button">' . esc_html__( 'Zaplaceno převodem', 'reserveandpay' ) . '</button>';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-detail&id=' . (int) $r['id'] ) ) . '">' . esc_html__( 'Detail rezervace', 'reserveandpay' ) . '</a></p></form>';
		}
		echo '</div>';
	}

	/** Přehled rezervací vybraného dne s částkou k vybrání. */
	private static function render_day( DateTimeImmutable $day ) {
		$events = Resandpay_Views::events_between( $day, $day->modify( '+1 day' ), '', true );
		$wd     = Resandpay_Views::weekdays_short();
		$prev   = $day->modify( '-1 day' )->format( 'Y-m-d' );
		$next   = $day->modify( '+1 day' )->format( 'Y-m-d' );
		echo '<h2>' . esc_html( sprintf( /* translators: %s: den */ __( 'Rezervace – %s', 'reserveandpay' ), $wd[ (int) $day->format( 'w' ) ] . ' ' . $day->format( 'j. n. Y' ) ) ) . '</h2>';
		echo '<p><a class="button" href="' . esc_url( self::url( array( 'day' => $prev ) ) ) . '">← ' . esc_html__( 'Předchozí den', 'reserveandpay' ) . '</a> <a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Dnes', 'reserveandpay' ) . '</a> <a class="button" href="' . esc_url( self::url( array( 'day' => $next ) ) ) . '">' . esc_html__( 'Další den', 'reserveandpay' ) . ' →</a></p>';
		$any = false;
		foreach ( $events as $ev ) {
			$rows = Resandpay_Reservations::for_event( $ev['id'] );
			if ( ! $rows ) {
				continue;
			}
			$any     = true;
			$unpaid  = array_filter( $rows, fn( $r ) => Resandpay_Reservations::PAID !== $r['status'] );
			$sum     = array_sum( array_map( fn( $r ) => (float) $r['amount'], $unpaid ) );
			$cur     = $rows[0]['currency'];
			echo '<h3>' . esc_html( $ev['title'] . ' – ' . Resandpay_Events::format_date( $ev ) ) . '</h3>';
			/* translators: 1: počet nezaplacených, 2: částka */
			echo '<p>' . esc_html( sprintf( __( 'Nezaplaceno: %1$d · k vybrání na místě: %2$s', 'reserveandpay' ), count( $unpaid ), Resandpay_Events::format_price( $sum, $cur ) ) ) . '</p>';
			echo '<table class="widefat striped rap-checkin__day"><thead><tr><th>' . esc_html__( 'Jméno', 'reserveandpay' ) . '</th><th>VS</th><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Odbavit', 'reserveandpay' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$ctx    = array( 'day' => $day->format( 'Y-m-d' ) );
				$status = Resandpay_Reservations::status_labels()[ $r['status'] ] ?? $r['status'];
				echo '<tr><td>' . esc_html( $r['customer_name'] ) . ( 'cash' === ( $r['pay_choice'] ?? '' ) ? ' <span class="rap-tag--cash">' . esc_html__( 'hotově', 'reserveandpay' ) . '</span>' : '' ) . '</td>';
				echo '<td><a href="' . esc_url( self::url( array( 'vs' => $r['vs'] ) ) ) . '"><code>' . esc_html( $r['vs'] ) . '</code></a></td><td>' . esc_html( $status ) . '</td><td>';
				if ( self::payable( $r ) ) {
					echo self::action_button( $r, 'cash', __( 'Hotově', 'reserveandpay' ), true, $ctx ) . ' ' . self::action_button( $r, 'transfer', __( 'Převodem', 'reserveandpay' ), false, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno uvnitř.
				} else {
					echo '✓';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'V tento den nejsou žádné rezervace.', 'reserveandpay' ) . '</p>';
		}
	}
}
