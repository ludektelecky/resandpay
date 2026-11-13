<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nápověda ke shortcodům v administraci (SWD kap. 15.1, R9): vždy celý shortcode s kódem
 * služby / akce (ne slug) a seznam parametrů; tlačítko „Kopírovat“.
 */
class Resandpay_Admin_Shortcodes {

	/**
	 * Vykreslí ukázku shortcodu s parametry.
	 *
	 * @param string $shortcode Celý shortcode (např. [reserveandpay_slots service="SL-003"]).
	 * @param array  $params    název parametru => popis.
	 * @param string $intro     Úvodní věta (nepovinné).
	 */
	public static function example( $shortcode, array $params = array(), $intro = '' ) {
		$id  = 'rap-sc-' . wp_generate_password( 6, false, false );
		$out = '<div class="rap-sc">';
		if ( '' !== $intro ) {
			$out .= '<p class="rap-sc__intro">' . esc_html( $intro ) . '</p>';
		}
		$out .= '<p class="rap-sc__line"><code id="' . esc_attr( $id ) . '">' . esc_html( $shortcode ) . '</code> <button type="button" class="button button-small rap-sc__copy" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Kopírovat', 'reserveandpay' ) . '</button></p>';
		if ( $params ) {
			$out .= '<table class="rap-sc__params"><thead><tr><th>' . esc_html__( 'Parametr', 'reserveandpay' ) . '</th><th>' . esc_html__( 'Význam', 'reserveandpay' ) . '</th></tr></thead><tbody>';
			foreach ( $params as $k => $v ) {
				$out .= '<tr><td><code>' . esc_html( $k ) . '</code></td><td>' . esc_html( $v ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		return $out . '</div>';
	}

	/** Kód služby / akce pro ukázku, nebo zástupný text u ještě neuložené položky. */
	public static function code_or_placeholder( $post_id, $placeholder ) {
		$code = Resandpay_Codes::get( $post_id );
		return $code ? $code : $placeholder;
	}

	/** Skript tlačítka „Kopírovat“ (jednou na stránku). */
	public static function script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return "<script>document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.rap-sc__copy');if(!b)return;var c=document.getElementById(b.getAttribute('data-target'));if(!c)return;var t=c.textContent;var ok=function(){var o=b.textContent;b.textContent='" . esc_js( __( 'Zkopírováno ✓', 'reserveandpay' ) ) . "';setTimeout(function(){b.textContent=o;},1500);};if(navigator.clipboard){navigator.clipboard.writeText(t).then(ok);}else{var r=document.createRange();r.selectNodeContents(c);var s=window.getSelection();s.removeAllRanges();s.addRange(r);document.execCommand('copy');ok();}});</script>";
	}

	/** Shortcody služby: výběr termínů a časté dotazy. */
	public static function service_box( $post_id ) {
		$code = self::code_or_placeholder( $post_id, 'SL-…' );
		$out  = self::example(
			'[reserveandpay_slots service="' . $code . '" days="21" description="toggle" faq="yes"]',
			array(
				'service'     => __( 'kód služby (povinné), např. ', 'reserveandpay' ) . $code,
				'days'        => __( 'kolik dní se zobrazí najednou, 7–90 (výchozí 21)', 'reserveandpay' ),
				'description' => __( 'popis služby: toggle = rozbalovací, yes = vždy zobrazit, no = nezobrazovat; bez parametru platí volba „Popis na webu“', 'reserveandpay' ),
				'faq'         => __( 'časté dotazy služby pod termíny: yes (výchozí) / no', 'reserveandpay' ),
			),
			__( 'Výběr termínů služby – vložte na stránku vybranou výše:', 'reserveandpay' )
		);
		$out .= self::example(
			'[reserveandpay_faq id="' . $code . '"]',
			array( 'id' => __( 'kód služby nebo akce (povinné mimo detail akce)', 'reserveandpay' ) ),
			__( 'Časté dotazy služby samostatně (kamkoli na web):', 'reserveandpay' )
		);
		if ( 'SL-…' === $code ) {
			$out .= '<p class="description">' . esc_html__( 'Kód služby se přidělí při prvním uložení.', 'reserveandpay' ) . '</p>';
		}
		return $out . self::script();
	}

	/** Shortcody akce: rezervace a časté dotazy. */
	public static function event_box( $post_id ) {
		$code = self::code_or_placeholder( $post_id, 'AK-…' );
		$out  = self::example(
			'[reserveandpay_booking id="' . $code . '"]',
			array( 'id' => __( 'kód akce; bez parametru aktuální stránka akce', 'reserveandpay' ) ),
			__( 'Termín, cena a rezervační formulář akce (kamkoli na web):', 'reserveandpay' )
		);
		$out .= self::example(
			'[reserveandpay_faq id="' . $code . '"]',
			array( 'id' => __( 'kód akce nebo služby; bez parametru aktuální akce', 'reserveandpay' ) ),
			__( 'Časté dotazy akce samostatně:', 'reserveandpay' )
		);
		$out .= self::example(
			'[reserveandpay_countdown id="' . $code . '"]',
			array( 'id' => __( 'kód akce; bez parametru aktuální stránka akce', 'reserveandpay' ) ),
			__( 'Odpočet akce (zapíná se v Parametrech akce → Odpočet) – např. na úvodní stránku nebo do banneru:', 'reserveandpay' )
		);
		if ( 'AK-…' === $code ) {
			$out .= '<p class="description">' . esc_html__( 'Kód akce se přidělí při prvním uložení.', 'reserveandpay' ) . '</p>';
		}
		return $out . self::script();
	}
}
