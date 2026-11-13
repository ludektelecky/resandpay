<?php
defined( 'ABSPATH' ) || exit;

/**
 * Odpočet u akce (SWD kap. 15.1, R13; Kniha rozvoje R-007): do zahájení prodeje, do konce slevy
 * za včasnou rezervaci a do ukončení prodeje. Zapíná se u akce (služba ho nemá). Vždy se ukazuje
 * jen nejbližší cíl; dál než N dní (Nastavení) jen datum. Bez JavaScriptu statický údaj.
 */
class Resandpay_Countdown {

	const KINDS = array( 'open', 'discount', 'close' );

	public static function init() {
		add_shortcode( 'reserveandpay_countdown', array( __CLASS__, 'shortcode' ) );
	}

	/** Zapnuté cíle u akce (pole klíčů open / discount / close). */
	public static function enabled( $event_id ) {
		return array_values( array_intersect( self::KINDS, array_filter( explode( ',', (string) get_post_meta( $event_id, '_rap_countdown', true ) ) ) ) );
	}

	public static function save_meta( $post_id, array $in ) {
		if ( ! array_key_exists( 'rap_countdown_form', $in ) ) {
			return;
		}
		$v = array_values( array_intersect( self::KINDS, array_map( 'sanitize_key', (array) ( $in['rap_countdown'] ?? array() ) ) ) );
		update_post_meta( $post_id, '_rap_countdown', implode( ',', $v ) );
	}

	/** Pole „Odpočet“ do Parametrů akce. */
	public static function fields( $post_id ) {
		$on     = self::enabled( $post_id );
		$labels = array(
			'open'     => __( 'do zahájení prodeje (pole „Zahájení rezervací“)', 'reserveandpay' ),
			'discount' => __( 'do konce slevy za včasnou rezervaci (23:59 posledního dne)', 'reserveandpay' ),
			'close'    => __( 'do ukončení prodeje (uzávěrka rezervací, jinak začátek akce)', 'reserveandpay' ),
		);
		echo '<input type="hidden" name="rap_countdown_form" value="1">';
		foreach ( $labels as $k => $l ) {
			echo '<p><label class="rap-inline"><input type="checkbox" name="rap_countdown[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $on, true ), true, false ) . '> ' . esc_html( $l ) . '</label></p>';
		}
		$code = Resandpay_Codes::get( $post_id );
		/* translators: 1: počet dní, 2: shortcode */
		echo '<p class="rap-hint">' . esc_html( sprintf( __( 'Na webu se ukáže vždy jen nejbližší odpočet (dny · hodiny · minuty) v detailu akce a v rezervační kartě; dál než %1$d dní předem jen datum (Nastavení → Odpočet). Cíl bez data se nezobrazí. Kamkoli jinam: %2$s (id = kód akce; také v bočním panelu „Shortcody akce“).', 'reserveandpay' ), (int) Resandpay_Settings::get( 'countdown_days' ), '[reserveandpay_countdown id="' . ( $code ? $code : 'AK-…' ) . '"]' ) ) . '</p>';
	}

	/**
	 * Cíle odpočtu, které mají datum, seřazené podle času.
	 *
	 * @return array[] kind, ts (Unix), label (běží), label_date (jen datum), date (text).
	 */
	public static function steps( array $ev ) {
		if ( $ev['cancelled'] || $ev['service_id'] || ( ! $ev['external'] && Resandpay_Events::free_places( $ev['id'] ) < 1 ) ) {
			return array(); // Zrušená, termín služby nebo obsazená akce – odpočet nemá smysl.
		}
		$on = self::enabled( $ev['id'] );
		if ( ! $on ) {
			return array();
		}
		$s   = Resandpay_Settings::all();
		$out = array();
		if ( in_array( 'open', $on, true ) && '' !== ( $ev['access']['open_at'] ?? '' ) ) {
			$out[] = array( 'open', Resandpay_Events::ts( $ev['access']['open_at'] ), $s['countdown_open_text'], $s['countdown_open_date'] );
		}
		if ( in_array( 'discount', $on, true ) ) {
			$cfg = Resandpay_Discounts::early_config( $ev );
			if ( $cfg ) {
				$out[] = array( 'discount', Resandpay_Events::ts( $cfg['until'] . ' 23:59:59' ), $s['countdown_discount_text'], $s['countdown_discount_date'] );
			}
		}
		if ( in_array( 'close', $on, true ) && $ev['start'] ) {
			$out[] = array( 'close', Resandpay_Events::ts( $ev['start'] ) - (int) round( (float) $ev['close_hours'] * HOUR_IN_SECONDS ), $s['countdown_close_text'], $s['countdown_close_date'] );
		}
		$steps = array();
		foreach ( $out as $o ) {
			$steps[] = array(
				'kind'       => $o[0],
				'ts'         => (int) $o[1],
				'label'      => (string) $o[2],
				'label_date' => (string) $o[3],
				'date'       => wp_date( 'j. n. Y G:i', $o[1] ),
				'date_far'   => wp_date( 'discount' === $o[0] ? 'j. n. Y' : 'j. n. Y G:i', $o[1] ), // Sleva platí do konce dne – čas netřeba.
			);
		}
		usort( $steps, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
		return $steps;
	}

	/** Nejbližší cíl v budoucnosti (null = nic). */
	public static function current( array $steps, $now = null ) {
		$now = $now ?? time();
		foreach ( $steps as $st ) {
			if ( $st['ts'] > $now ) {
				return $st;
			}
		}
		return null;
	}

	/** Statický text (bez JavaScriptu): „Sleva končí za 3 dny 4 hodiny 12 minut“ nebo „Sleva do 31. 1. 2027“. */
	public static function static_text( array $st, $now = null ) {
		$now  = $now ?? time();
		$left = $st['ts'] - $now;
		$days = (int) Resandpay_Settings::get( 'countdown_days' );
		if ( $left > $days * DAY_IN_SECONDS ) {
			return sprintf( $st['label_date'], $st['date_far'] );
		}
		$p = self::parts( $left );
		return $st['label'] . ' ' . trim( ( $p[0] ? $p[0] . ' ' . self::plural( $p[0], 'den', 'dny', 'dní' ) . ' ' : '' ) . $p[1] . ' ' . self::plural( $p[1], 'hodina', 'hodiny', 'hodin' ) . ' ' . $p[2] . ' ' . self::plural( $p[2], 'minuta', 'minuty', 'minut' ) );
	}

	/** Dny, hodiny, minuty (minuty zaokrouhlené nahoru). */
	public static function parts( $seconds ) {
		$m = max( 0, (int) ceil( $seconds / 60 ) );
		return array( intdiv( $m, 1440 ), intdiv( $m % 1440, 60 ), $m % 60 );
	}

	public static function plural( $n, $one, $few, $many ) {
		return 1 === $n ? $one : ( $n >= 2 && $n <= 4 ? $few : $many );
	}

	/**
	 * HTML odpočtu akce (prázdné, když není co zobrazit).
	 *
	 * @param string $ctx header | card | shortcode.
	 */
	public static function html( array $ev, $ctx = 'header', $now = null ) {
		$steps = self::steps( $ev );
		$st    = self::current( $steps, $now );
		if ( ! $st ) {
			return '';
		}
		self::enqueue();
		$now   = $now ?? time();
		$days  = (int) Resandpay_Settings::get( 'countdown_days' );
		$far   = $st['ts'] - $now > $days * DAY_IN_SECONDS;
		$p     = self::parts( $st['ts'] - $now );
		$data  = array_map(
			fn( $x ) => array(
				'kind'  => $x['kind'],
				'ts'    => $x['ts'],
				'label' => $x['label'],
				'ldate' => sprintf( $x['label_date'], $x['date_far'] ),
				'date'  => $x['date'],
			),
			array_values( array_filter( $steps, fn( $x ) => $x['ts'] > $now ) )
		);
		$out  = '<div class="rap-countdown rap-countdown--' . esc_attr( $ctx ) . ( $far ? ' is-far' : '' ) . '" data-days="' . (int) $days . '" data-steps="' . esc_attr( wp_json_encode( $data ) ) . '" data-event="' . (int) $ev['id'] . '">';
		$out .= '<p class="rap-countdown__label" aria-hidden="true">' . esc_html( $far ? sprintf( $st['label_date'], $st['date_far'] ) : $st['label'] ) . '</p>';
		$out .= '<div class="rap-countdown__units"' . ( $far ? ' hidden' : '' ) . ' aria-hidden="true">';
		$units = array( array( $p[0], 'den', 'dny', 'dní', 'd' ), array( $p[1], 'hodina', 'hodiny', 'hodin', 'h' ), array( $p[2], 'minuta', 'minuty', 'minut', 'm' ) );
		foreach ( $units as $u ) {
			$out .= '<span class="rap-countdown__unit" data-u="' . esc_attr( $u[4] ) . '"><strong>' . (int) $u[0] . '</strong><small>' . esc_html( self::plural( (int) $u[0], $u[1], $u[2], $u[3] ) ) . '</small></span>';
		}
		$out .= '</div>';
		$out .= '<p class="rap-countdown__date"' . ( $far ? ' hidden' : '' ) . '>' . esc_html( sprintf( __( 'do %s', 'reserveandpay' ), $st['date'] ) ) . '</p>';
		$out .= '<p class="rap-countdown__sr">' . esc_html( self::static_text( $st, $now ) ) . '</p>';
		return $out . '</div>';
	}

	private static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$js = "(function(){function pl(n,a,b,c){return n===1?a:(n>=2&&n<=4?b:c);}function upd(el){var steps=JSON.parse(el.getAttribute('data-steps')||'[]');var days=parseInt(el.getAttribute('data-days'),10)||30;var now=Date.now()/1000;var st=null;for(var i=0;i<steps.length;i++){if(steps[i].ts>now){st=steps[i];break;}}"
			. "if(steps.length&&steps[0].kind==='open'&&steps[0].ts<=now&&!el._r){el._r=1;try{var k='rapcd'+el.getAttribute('data-event')+steps[0].ts;if(!sessionStorage.getItem(k)){sessionStorage.setItem(k,'1');location.reload();return;}}catch(e){}}"
			. "if(!st){el.hidden=true;return false;}var left=st.ts-now;var lab=el.querySelector('.rap-countdown__label'),u=el.querySelector('.rap-countdown__units'),d=el.querySelector('.rap-countdown__date');if(left>days*86400){lab.textContent=st.ldate;u.hidden=true;d.hidden=true;el.classList.add('is-far');var s2=el.querySelector('.rap-countdown__sr');if(s2){s2.textContent=st.ldate;}return true;}el.classList.remove('is-far');lab.textContent=st.label;u.hidden=false;d.hidden=false;d.textContent='do '+st.date;var m=Math.ceil(left/60),v={d:Math.floor(m/1440),h:Math.floor((m%1440)/60),m:m%60},w={d:['den','dny','dní'],h:['hodina','hodiny','hodin'],m:['minuta','minuty','minut']};"
			. "u.querySelectorAll('.rap-countdown__unit').forEach(function(x){var k=x.getAttribute('data-u');x.querySelector('strong').textContent=v[k];x.querySelector('small').textContent=pl(v[k],w[k][0],w[k][1],w[k][2]);});var sr=el.querySelector('.rap-countdown__sr');if(sr){sr.textContent=st.label+' '+(v.d?v.d+' '+pl(v.d,'den','dny','dní')+' ':'')+v.h+' '+pl(v.h,'hodina','hodiny','hodin')+' '+v.m+' '+pl(v.m,'minuta','minuty','minut');}return true;}"
			. "function tick(){document.querySelectorAll('.rap-countdown').forEach(upd);}tick();setInterval(tick,15000);})();";
		wp_register_script( 'resandpay-countdown', '', array(), RESANDPAY_VERSION, true );
		wp_enqueue_script( 'resandpay-countdown' );
		wp_add_inline_script( 'resandpay-countdown', $js );
	}

	/** [reserveandpay_countdown id="AK-2027-0001"] – id = kód akce (nebo ID); bez id aktuální akce. */
	public static function shortcode( $atts ) {
		$a   = shortcode_atts( array( 'id' => '' ), $atts, 'reserveandpay_countdown' );
		$ref = trim( (string) $a['id'] );
		$id  = '' === $ref ? (int) get_the_ID() : ( Resandpay_Codes::looks_like_code( $ref ) ? Resandpay_Codes::find( $ref ) : absint( $ref ) );
		$ev  = $id && Resandpay_Post_Types::EVENT === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? Resandpay_Events::get( $id ) : null;
		if ( ! $ev ) {
			return '';
		}
		wp_enqueue_style( 'reserveandpay' );
		return self::html( $ev, 'shortcode' );
	}
}
