<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administrace kontroly plateb (SWD kap. 6.3, 6.4): stav a ruční spuštění,
 * test připojení, nastavení CRONu, avíza k ruční kontrole s přiřazením, log běhů.
 */
class Resandpay_Admin_Payments {

	const PAGE = 'reserveandpay-payments';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
		foreach ( array( 'run', 'test', 'assign', 'resolve', 'upload', 'unassign' ) as $a ) {
			add_action( 'admin_post_resandpay_pay_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	public static function menu() {
		$review = Resandpay_Payments::counts()[ Resandpay_Payments::REVIEW ];
		$label  = __( 'Platby', 'reserveandpay' ) . ( $review ? ' <span class="awaiting-mod">' . (int) $review . '</span>' : '' );
		add_submenu_page( 'reserveandpay', __( 'Kontrola plateb', 'reserveandpay' ), $label, resandpay_cap_reservations(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( resandpay_cap_reservations() ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'reserveandpay' ) );
		}
		check_admin_referer( 'resandpay_pay_' . $action );
	}

	private static function back( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public static function handle_run() {
		self::guard( 'run' );
		$r = Resandpay_Payments::run( 'manual' );
		if ( $r['ok'] ) {
			/* translators: 1: načteno, 2: spárováno, 3: k ruční kontrole, 4: ignorováno */
			Resandpay_Admin::flash( sprintf( __( 'Kontrola plateb proběhla: načteno avíz %1$d, spárováno %2$d, k ruční kontrole %3$d, ignorováno %4$d.', 'reserveandpay' ), $r['fetched'], $r['matched'], $r['review'], $r['ignored'] ), $r['review'] ? 'warning' : 'success' );
		} else {
			/* translators: %s: chyba */
			Resandpay_Admin::flash( sprintf( __( 'Kontrola plateb selhala: %s', 'reserveandpay' ), $r['error'] ), 'error' );
		}
		self::back();
	}

	public static function handle_test() {
		self::guard( 'test' );
		$r = Resandpay_Payments::test_connection();
		if ( is_wp_error( $r ) ) {
			/* translators: %s: chyba */
			Resandpay_Admin::flash( sprintf( __( 'Připojení ke schránce se nezdařilo: %s', 'reserveandpay' ), $r->get_error_message() ), 'error' );
		} else {
			/* translators: 1: zpráv ve složce, 2: avíz od banky */
			Resandpay_Admin::flash( sprintf( __( 'Připojení ke schránce funguje. Ve složce je zpráv: %1$d, z toho avíz od banky ke zpracování: %2$d. Nic nebylo změněno.', 'reserveandpay' ), $r['total'], $r['bank'] ) );
		}
		self::back();
	}

	public static function handle_assign() {
		self::guard( 'assign' );
		$id  = absint( $_POST['id'] ?? 0 );
		$res = Resandpay_Payments::assign( $id, sanitize_text_field( wp_unslash( $_POST['vs'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), ! empty( $_POST['force'] ) );
		if ( is_wp_error( $res ) ) {
			Resandpay_Admin::flash( $res->get_error_message(), 'error' );
		} else {
			/* translators: 1: jméno, 2: VS */
			Resandpay_Admin::flash( sprintf( __( 'Platba přiřazena: %1$s (VS %2$s) – rezervace je zaplacená.', 'reserveandpay' ), $res['customer_name'], $res['vs'] ) );
		}
		self::back( array( 'status' => Resandpay_Payments::REVIEW ) );
	}

	public static function handle_unassign() {
		self::guard( 'unassign' );
		$res = Resandpay_Payments::unassign( absint( $_POST['id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ), array( 'notify' => ! empty( $_POST['notify'] ) ) );
		if ( is_wp_error( $res ) ) {
			Resandpay_Admin::flash( $res->get_error_message(), 'error' );
		} else {
			/* translators: 1: VS, 2: stav */
			$msg = sprintf( __( 'Avízo odpárováno od rezervace VS %1$s (nyní: %2$s) a vráceno k ruční kontrole – můžete ho přiřadit jiné rezervaci nebo vyřešit.', 'reserveandpay' ), $res['vs'], Resandpay_Reservations::status_labels()[ $res['status'] ] ?? $res['status'] );
			if ( ! empty( $res['due_passed'] ) ) {
				$msg .= ' ' . __( 'Pozor: splatnost rezervace už uplynula – při další kontrole rezervace bez platby vyprší, příp. přejde do stavu Po splatnosti.', 'reserveandpay' );
			}
			Resandpay_Admin::flash( $msg, empty( $res['due_passed'] ) ? 'success' : 'warning' );
		}
		self::back( array( 'status' => Resandpay_Payments::REVIEW ) );
	}

	public static function handle_resolve() {
		self::guard( 'resolve' );
		$res = Resandpay_Payments::resolve( absint( $_POST['id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		Resandpay_Admin::flash( is_wp_error( $res ) ? $res->get_error_message() : __( 'Avízo označeno jako vyřešené.', 'reserveandpay' ), is_wp_error( $res ) ? 'error' : 'success' );
		self::back( array( 'status' => Resandpay_Payments::REVIEW ) );
	}

	public static function handle_upload() {
		self::guard( 'upload' );
		$file = $_FILES['eml'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 2 * MB_IN_BYTES ) {
			Resandpay_Admin::flash( __( 'Vyberte soubor .eml s avízem (max. 2 MB).', 'reserveandpay' ), 'error' );
			self::back();
		}
		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$res = Resandpay_Payments::process_raw( (string) $raw, 'upload' );
		$map = array(
			Resandpay_Payments::MATCHED => array( __( 'Avízo zpracováno – platba spárována s rezervací.', 'reserveandpay' ), 'success' ),
			Resandpay_Payments::REVIEW  => array( __( 'Avízo zpracováno – je potřeba ruční kontrola:', 'reserveandpay' ) . ' ' . $res['reason'], 'warning' ),
			Resandpay_Payments::IGNORED => array( __( 'Avízo ignorováno:', 'reserveandpay' ) . ' ' . $res['reason'], 'warning' ),
		);
		$m = $map[ $res['status'] ] ?? array( $res['reason'], 'error' );
		Resandpay_Admin::flash( $m[0], $m[1] );
		self::back();
	}

	private static function form( $action, $inner, $class = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"' . ( 'upload' === $action ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="resandpay_pay_' . esc_attr( $action ) . '">' . wp_nonce_field( 'resandpay_pay_' . $action, '_wpnonce', true, false ) . $inner . '</form>';
	}

	private static function ago( $ts ) {
		return $ts ? wp_date( 'j. n. Y G:i', $ts ) . ' (' . human_time_diff( $ts ) . ')' : '—';
	}

	public static function page() {
		$s       = Resandpay_Settings::all();
		$counts  = Resandpay_Payments::counts();
		$status  = sanitize_key( $_GET['status'] ?? ( $counts[ Resandpay_Payments::REVIEW ] ? Resandpay_Payments::REVIEW : '' ) );
		$last    = (int) get_option( 'resandpay_last_run', 0 );
		$ok      = (int) get_option( 'resandpay_last_success', 0 );
		$cron    = (int) get_option( 'resandpay_last_cron', 0 );
		$has_pwd = '' !== Resandpay_Payments::imap_password();
		echo '<div class="wrap rap-payments"><h1>' . esc_html__( 'Kontrola plateb', 'reserveandpay' ) . '</h1>';
		Resandpay_Admin::render_flash();

		// Stav.
		echo '<div class="rap-paystate"><table class="widefat striped"><tbody>';
		$mode = 'auto' === $s['payment_mode']
			/* translators: %d: minuty */
			? sprintf( __( 'automaticky každých %d min', 'reserveandpay' ), (int) $s['payment_interval'] )
			: __( 'jen ručně (CRON kontrolu plateb nespouští)', 'reserveandpay' );
		$rows = array(
			__( 'Režim', 'reserveandpay' )              => esc_html( $mode ),
			__( 'Schránka', 'reserveandpay' )           => esc_html( ( $s['imap_user'] ?: '—' ) . ' @ ' . $s['imap_host'] . ':' . $s['imap_port'] ) . ( $has_pwd ? ' <small>· ' . esc_html( ( $pw = (int) get_option( 'resandpay_imap_pwd_saved', 0 ) ) ? sprintf( /* translators: %s: datum */ __( 'heslo uloženo %s', 'reserveandpay' ), wp_date( 'j. n. Y G:i', $pw ) ) : __( 'heslo uloženo', 'reserveandpay' ) ) . '</small>' : ' <span class="rap-tag--off">' . esc_html__( 'chybí heslo', 'reserveandpay' ) . '</span>' ),
			__( 'Poslední běh', 'reserveandpay' )       => esc_html( self::ago( $last ) ),
			__( 'Poslední úspěšný běh', 'reserveandpay' ) => esc_html( self::ago( $ok ) ),
			__( 'Poslední volání CRONu', 'reserveandpay' ) => esc_html( self::ago( $cron ) ) . ( 'auto' === $s['payment_mode'] && ( ! $cron || time() - $cron > HOUR_IN_SECONDS ) ? ' <span class="rap-tag--off">' . esc_html__( 'CRON neběží – nastavte ho ve WebAdminu (níže)', 'reserveandpay' ) . '</span>' : '' ),
		);
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno výše.
		}
		echo '</tbody></table>';
		echo '<p class="rap-paystate__actions">';
		echo self::form( 'run', get_submit_button( __( 'Zkontrolovat platby nyní', 'reserveandpay' ), 'primary', 'submit', false ), 'rap-inline-form' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::form( 'test', get_submit_button( __( 'Otestovat připojení ke schránce', 'reserveandpay' ), 'secondary', 'submit', false ), 'rap-inline-form' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( current_user_can( resandpay_cap_settings() ) ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-settings#rap-payments' ) ) . '">' . esc_html__( 'Nastavení kontroly plateb', 'reserveandpay' ) . '</a>';
		}
		echo '</p></div>';

		// Avíza.
		echo '<h2>' . esc_html__( 'Avíza z banky', 'reserveandpay' ) . '</h2><ul class="subsubsub">';
		$views = array( '' => __( 'Vše', 'reserveandpay' ) ) + Resandpay_Payments::status_labels();
		$i     = 0;
		foreach ( $views as $k => $l ) {
			$n = '' === $k ? array_sum( $counts ) : $counts[ $k ];
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( self::url( array( 'status' => $k ) ) ) . '" class="' . ( $status === $k ? 'current' : '' ) . '">' . esc_html( $l ) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
		}
		echo '</ul><br class="clear">';
		$items = Resandpay_Payments::query( $status, 200 );
		echo '<table class="widefat striped rap-paylist"><thead><tr><th>' . esc_html__( 'Připsáno', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Částka', 'reserveandpay' ) . '</th><th>VS</th><th>' . esc_html__( 'Plátce a zpráva', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Stav', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Rezervace / řešení', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		if ( ! $items ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Žádná avíza.', 'reserveandpay' ) . '</td></tr>';
		}
		foreach ( $items as $p ) {
			self::row( $p );
		}
		echo '</tbody></table>';

		// Ruční zpracování souboru.
		echo '<h2>' . esc_html__( 'Zpracovat avízo ze souboru', 'reserveandpay' ) . '</h2><p class="description">' . esc_html__( 'Pro avízo, které nedorazilo do schránky (např. přeposlané): uložte ho z pošty jako .eml a nahrajte. Platí stejné kontroly jako při automatickém zpracování.', 'reserveandpay' ) . '</p>';
		echo self::form( 'upload', '<input type="file" name="eml" accept=".eml,message/rfc822" required> ' . get_submit_button( __( 'Zpracovat avízo', 'reserveandpay' ), 'secondary', 'submit', false ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		// CRON.
		if ( current_user_can( resandpay_cap_settings() ) ) {
			echo '<h2 id="rap-cron">' . esc_html__( 'Nastavení CRONu na Active24', 'reserveandpay' ) . '</h2>';
			echo '<ol class="rap-steps"><li>' . esc_html__( 'WebAdmin → doména → CRON úlohy → Vytvořit CRON úlohu.', 'reserveandpay' ) . '</li>';
			echo '<li>' . esc_html__( 'Typ: Volání URL, adresa:', 'reserveandpay' ) . ' <input type="text" readonly class="large-text code" value="' . esc_attr( Resandpay_Payments::cron_url() ) . '" onclick="this.select()"></li>';
			echo '<li>' . esc_html__( 'Spouštění: */15 * * * * (každých 15 minut). Interval kontroly plateb se pak mění jen v nastavení pluginu.', 'reserveandpay' ) . '</li>';
			echo '<li>' . esc_html__( 'E-mailové upozornění: jen když je výstup – při úspěchu plugin nic nevypisuje, při chybě vypíše důvod.', 'reserveandpay' ) . '</li></ol>';
			echo '<p class="description">' . esc_html__( 'Adresu nikomu nesdílejte – obsahuje tajný klíč. Volání CRONu zpracovává i splatnosti rezervací.', 'reserveandpay' ) . '</p>';
		}

		// Log.
		echo '<h2>' . esc_html__( 'Poslední běhy', 'reserveandpay' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Čas', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Spuštění', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Výsledek', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Načteno / spárováno / k ruční kontrole / ignorováno', 'reserveandpay' ) . '</th></tr></thead><tbody>';
		$runs = Resandpay_Payments::runs( 20 );
		if ( ! $runs ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Zatím žádný běh.', 'reserveandpay' ) . '</td></tr>';
		}
		$trig = array(
			'cron'   => __( 'CRON', 'reserveandpay' ),
			'manual' => __( 'ručně', 'reserveandpay' ),
		);
		foreach ( $runs as $r ) {
			echo '<tr><td>' . esc_html( wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $r['started_at'] ) ) ) . '</td><td>' . esc_html( $trig[ $r['trigger_by'] ] ?? $r['trigger_by'] ) . '</td><td>' . ( $r['ok'] ? '✓' : '<span class="rap-tag--off">' . esc_html( $r['error'] ?: __( 'chyba', 'reserveandpay' ) ) . '</span>' ) . '</td><td>' . (int) $r['fetched'] . ' / ' . (int) $r['matched'] . ' / ' . (int) $r['review'] . ' / ' . (int) $r['ignored'] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	private static function row( array $p ) {
		$labels = Resandpay_Payments::status_labels();
		$r      = $p['reservation_id'] ? Resandpay_Reservations::get( $p['reservation_id'] ) : null;
		echo '<tr class="rap-pay--' . esc_attr( $p['status'] ) . '">';
		echo '<td>' . esc_html( $p['booked_at'] ? wp_date( 'j. n. Y G:i', Resandpay_Events::ts( $p['booked_at'] ) ) : '—' ) . '</td>';
		echo '<td><strong>' . esc_html( Resandpay_Events::format_price( $p['amount'], $p['currency'] ) ) . '</strong></td>';
		echo '<td><code>' . esc_html( $p['vs'] ?: '—' ) . '</code></td>';
		echo '<td>' . esc_html( $p['counter_name'] ?: '—' ) . '<br><small>' . esc_html( $p['counter_account'] ) . '</small>' . ( $p['message'] ? '<br><small>„' . esc_html( $p['message'] ) . '“</small>' : '' ) . '</td>';
		echo '<td>' . esc_html( $labels[ $p['status'] ] ?? $p['status'] ) . ( $p['reason'] ? '<br><small>' . esc_html( $p['reason'] ) . '</small>' : '' ) . ( $p['note'] ? '<br><small>' . esc_html__( 'Pozn.:', 'reserveandpay' ) . ' ' . esc_html( $p['note'] ) . '</small>' : '' ) . ( ! $p['auth_ok'] && $p['amount'] > 0 ? '<br><span class="rap-tag--off">' . esc_html__( 'pravost nepotvrzena', 'reserveandpay' ) . '</span>' : '' ) . '</td>';
		echo '<td>';
		if ( $r ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=reserveandpay-detail&id=' . (int) $r['id'] ) ) . '">' . esc_html( $r['customer_name'] ) . '</a><br><small>VS ' . esc_html( $r['vs'] ) . ' · ' . esc_html( Resandpay_Reservations::status_labels()[ $r['status'] ] ?? '' ) . '</small>';
		}
		if ( in_array( $p['status'], array( Resandpay_Payments::MATCHED, Resandpay_Payments::MANUAL ), true ) && $r ) {
			$in = '<input type="hidden" name="id" value="' . (int) $p['id'] . '">';
			echo '<details class="rap-assign"><summary>' . esc_html__( 'Odpárovat', 'reserveandpay' ) . '</summary>';
			echo self::form( 'unassign', $in . '<p><input type="text" name="reason" required placeholder="' . esc_attr__( 'důvod, např. platba patří jiné rezervaci', 'reserveandpay' ) . '"></p><p><label><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'poslat zákazníkovi aktuální platební údaje', 'reserveandpay' ) . '</label></p>' . get_submit_button( __( 'Odpárovat od rezervace', 'reserveandpay' ), 'secondary small', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Odpárovat platbu? Částka se u rezervace odečte a avízo se vrátí k ruční kontrole.', 'reserveandpay' ) ) . "')" ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</details>';
		}
		if ( Resandpay_Payments::REVIEW === $p['status'] ) {
			$sug = Resandpay_Payments::suggest( $p );
			$vs  = $r ? $r['vs'] : ( $sug ? $sug['vs'] : '' );
			$in  = '<input type="hidden" name="id" value="' . (int) $p['id'] . '">';
			echo '<details class="rap-assign"' . ( $sug ? ' open' : '' ) . '><summary>' . esc_html__( 'Přiřadit k rezervaci', 'reserveandpay' ) . '</summary>';
			if ( $sug ) {
				/* translators: 1: jméno, 2: VS */
				echo '<p><small>' . esc_html( sprintf( __( 'Návrh podle zprávy pro příjemce: %1$s (VS %2$s)', 'reserveandpay' ), $sug['customer_name'], $sug['vs'] ) ) . '</small></p>';
			}
			echo self::form( 'assign', $in . '<p><input type="text" name="vs" value="' . esc_attr( $vs ) . '" placeholder="' . esc_attr__( 'VS rezervace', 'reserveandpay' ) . '" class="regular-text" style="width:10em" required> <input type="text" name="note" placeholder="' . esc_attr__( 'poznámka', 'reserveandpay' ) . '"></p><p><label><input type="checkbox" name="force" value="1"> ' . esc_html__( 'přiřadit i při rozdílu částky nebo měny', 'reserveandpay' ) . '</label></p>' . get_submit_button( __( 'Přiřadit a označit zaplaceno', 'reserveandpay' ), 'primary small', 'submit', false ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</details><details class="rap-assign"><summary>' . esc_html__( 'Vyřešit bez přiřazení', 'reserveandpay' ) . '</summary>';
			echo self::form( 'resolve', $in . '<p><input type="text" name="note" placeholder="' . esc_attr__( 'např. vráceno plátci', 'reserveandpay' ) . '" required></p>' . get_submit_button( __( 'Označit jako vyřešené', 'reserveandpay' ), 'secondary small', 'submit', false ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</details>';
		}
		echo '</td></tr>';
	}
}
