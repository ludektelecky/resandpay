<?php
/**
 * Časté dotazy (FAQ) u akcí a služeb.
 *
 * Otázky jsou vždy vidět, odpověď se rozbalí pod otázkou (nativní <details>, bez JavaScriptu).
 * Výchozí stav je vše sbalené, otevřít lze více odpovědí najednou.
 * Data: post meta `_rap_faq` = pole [ { q, a } ] u akce (CPT akce) i služby (CPT služba).
 * Individuální termín bez vlastních dotazů přebírá dotazy své služby.
 * Na stránce se jednou vypíší strukturovaná data schema.org FAQPage (JSON-LD).
 *
 * @package ReserveAndPay
 */

defined( 'ABSPATH' ) || exit;

class Resandpay_FAQ {

	const META = '_rap_faq';

	/** Nejvyšší počet otázek u jedné položky. */
	const MAX = 30;

	/** @var array Otázky vykreslené na stránce – pro JSON-LD v patičce. */
	private static $schema = array();

	/** @var array ID již vykreslených FAQ (ochrana proti dvojímu vypsání). */
	private static $rendered = array();

	public static function init() {
		add_shortcode( 'reserveandpay_faq', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_schema' ), 20 );
	}

	/**
	 * Otázky uložené u akce nebo služby.
	 *
	 * @param int $post_id ID akce nebo služby.
	 * @return array<int, array{q: string, a: string}>
	 */
	public static function get( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META, true );
		return is_array( $raw ) ? self::clean( $raw ) : array();
	}

	/**
	 * Otázky pro zobrazení u akce: vlastní, jinak (u individuálního termínu) otázky služby.
	 *
	 * @param int $event_id ID akce.
	 * @return array
	 */
	public static function for_event( $event_id ) {
		$own = self::get( $event_id );
		if ( $own ) {
			return $own;
		}
		$sid = (int) get_post_meta( (int) $event_id, '_rap_service', true );
		return $sid ? self::get( $sid ) : array();
	}

	/**
	 * Vyčištění pole otázek: prázdné řádky pryč, otázka jako prostý text, odpověď přes wp_kses_post.
	 *
	 * @param array $rows Pole [ { q, a } ].
	 * @return array
	 */
	public static function clean( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
			$a = trim( wp_kses_post( (string) ( $row['a'] ?? '' ) ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out[] = array(
				'q' => $q,
				'a' => $a,
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Uložení z formuláře administrace (pole rap_faq_q[] a rap_faq_a[]).
	 *
	 * @param int   $post_id ID akce nebo služby.
	 * @param array $in      Odeslaná data (bez lomítek).
	 */
	public static function save( $post_id, array $in ) {
		if ( empty( $in['rap_faq_present'] ) ) {
			return; // Formulář boxu nebyl odeslán (např. generátor termínů) – ponechat.
		}
		$qs   = isset( $in['rap_faq_q'] ) && is_array( $in['rap_faq_q'] ) ? array_values( $in['rap_faq_q'] ) : array();
		$as   = isset( $in['rap_faq_a'] ) && is_array( $in['rap_faq_a'] ) ? array_values( $in['rap_faq_a'] ) : array();
		$rows = array();
		foreach ( $qs as $i => $q ) {
			$rows[] = array(
				'q' => $q,
				'a' => $as[ $i ] ?? '',
			);
		}
		$rows = self::clean( $rows );
		if ( $rows ) {
			update_post_meta( $post_id, self::META, $rows );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/**
	 * HTML sekce FAQ. Prázdné pole = prázdný řetězec (sekce se nevykreslí).
	 *
	 * @param array  $items Otázky [ { q, a } ].
	 * @param string $key   Klíč pro ochranu proti dvojímu vypsání a pro JSON-LD (např. ID).
	 * @return string
	 */
	public static function render( array $items, $key = '' ) {
		$items = self::clean( $items );
		if ( ! $items ) {
			return '';
		}
		$key = (string) $key;
		if ( '' !== $key ) {
			if ( isset( self::$rendered[ $key ] ) ) {
				return '';
			}
			self::$rendered[ $key ] = true;
		}
		/**
		 * Nadpis sekce FAQ (eyebrow).
		 *
		 * @param string $title Výchozí „Časté dotazy“.
		 */
		$title = apply_filters( 'resandpay_faq_title', __( 'Časté dotazy', 'reserveandpay' ) );
		$out   = '<section class="rap-faq">';
		if ( '' !== (string) $title ) {
			$out .= '<p class="rap-eyebrow rap-faq__title">' . esc_html( $title ) . '</p>';
		}
		foreach ( $items as $it ) {
			$out .= '<details class="rap-faq__item"><summary class="rap-faq__q"><span>' . esc_html( $it['q'] ) . '</span><span class="rap-faq__icon" aria-hidden="true"></span></summary>';
			$out .= '<div class="rap-faq__a">' . wp_kses_post( wpautop( $it['a'] ) ) . '</div></details>';
			self::$schema[] = $it;
		}
		$out .= '</section>';
		return $out;
	}

	/** Vykreslení FAQ akce (vlastní, nebo zděděné ze služby). */
	public static function render_event( $event_id ) {
		return self::render( self::for_event( $event_id ), 'p' . (int) $event_id );
	}

	/** Vykreslení FAQ služby (na stránce s volnými termíny). */
	public static function render_service( $service_id ) {
		return self::render( self::get( $service_id ), 'p' . (int) $service_id );
	}

	/**
	 * [reserveandpay_faq id=""] – FAQ akce nebo služby podle kódu (AK-…, SL-…) nebo ID; bez id aktuální akce.
	 *
	 * @param array $atts Atributy.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$a   = shortcode_atts( array( 'id' => '' ), $atts, 'reserveandpay_faq' );
		$ref = trim( (string) $a['id'] );
		$id  = Resandpay_Codes::looks_like_code( $ref ) ? Resandpay_Codes::find( $ref ) : absint( $ref );
		if ( '' === $ref ) {
			$id = (int) get_the_ID();
		}
		if ( ! $id || ! in_array( get_post_type( $id ), array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::SERVICE ), true ) ) {
			return '';
		}
		Resandpay_Style::enqueue();
		return Resandpay_Post_Types::SERVICE === get_post_type( $id ) ? self::render_service( $id ) : self::render_event( $id );
	}

	/**
	 * Data pro JSON-LD schema.org FAQPage ze všech otázek vypsaných na stránce.
	 *
	 * @return array|null
	 */
	public static function schema_data() {
		if ( ! self::$schema ) {
			return null;
		}
		$seen = array();
		$main = array();
		foreach ( self::$schema as $it ) {
			$k = mb_strtolower( $it['q'] );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$main[]     = array(
				'@type'          => 'Question',
				'name'           => $it['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => trim( wp_kses( wpautop( $it['a'] ), array( 'p' => array(), 'br' => array(), 'a' => array( 'href' => array() ), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'ul' => array(), 'ol' => array(), 'li' => array() ) ) ),
				),
			);
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main,
		);
	}

	/** JSON-LD FAQPage do patičky – jednou za stránku. */
	public static function print_schema() {
		/**
		 * Vypnutí strukturovaných dat FAQPage (např. když je generuje SEO plugin).
		 *
		 * @param bool $on Výchozí true.
		 */
		if ( ! apply_filters( 'resandpay_faq_schema', true ) ) {
			return;
		}
		$data = self::schema_data();
		if ( ! $data ) {
			return;
		}
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON z wp_json_encode s JSON_HEX_TAG.
	}

	/**
	 * Meta box „Časté dotazy“ v editaci akce i služby: opakovač otázka + odpověď.
	 *
	 * @param WP_Post $post Akce nebo služba.
	 */
	public static function box( $post ) {
		$items = self::get( $post->ID );
		$is_ev = Resandpay_Post_Types::EVENT === $post->post_type;
		echo '<input type="hidden" name="rap_faq_present" value="1">';
		echo '<div class="rap-faq-admin" id="rap_faq_list">';
		foreach ( $items as $it ) {
			self::box_row( $it['q'], $it['a'] );
		}
		echo '</div>';
		echo '<template id="rap_faq_tpl">';
		self::box_row( '', '' );
		echo '</template>';
		echo '<p><button type="button" class="button" id="rap_faq_add">' . esc_html__( '+ Přidat otázku', 'reserveandpay' ) . '</button></p>';
		$desc = $is_ev
			? __( 'Zobrazí se pod popisem akce: otázka je vidět vždy, odpověď se rozbalí kliknutím. Řádek bez otázky nebo odpovědi se neuloží. U individuálního termínu bez vlastních dotazů se použijí dotazy jeho služby.', 'reserveandpay' )
			: __( 'Zobrazí se pod volnými termíny služby a u jejích termínů, které nemají vlastní dotazy. Řádek bez otázky nebo odpovědi se neuloží.', 'reserveandpay' );
		echo '<p class="description">' . esc_html( $desc ) . ' ' . esc_html__( 'V odpovědi lze použít odkaz, tučné písmo a odrážky (HTML). Pro Google se vygenerují strukturovaná data FAQPage.', 'reserveandpay' ) . '</p>';
		?>
		<style>
		.rap-faq-admin .rap-faq-row{border:1px solid #dcdcde;background:#fff;padding:8px 10px;margin:0 0 8px}
		.rap-faq-admin .rap-faq-row__head{display:flex;gap:6px;align-items:center}
		.rap-faq-admin .rap-faq-row__head input{flex:1}
		.rap-faq-admin textarea{width:100%;margin-top:6px}
		.rap-faq-admin .rap-faq-row:first-child [data-rap-faq=up],.rap-faq-admin .rap-faq-row:last-child [data-rap-faq=down]{visibility:hidden}
		</style>
		<script>
		( function () {
			var list = document.getElementById( 'rap_faq_list' ), tpl = document.getElementById( 'rap_faq_tpl' ), add = document.getElementById( 'rap_faq_add' );
			if ( ! list || ! tpl || ! add ) { return; }
			add.addEventListener( 'click', function () {
				list.appendChild( tpl.content.cloneNode( true ) );
				var rows = list.querySelectorAll( '.rap-faq-row' );
				rows[ rows.length - 1 ].querySelector( 'input' ).focus();
			} );
			list.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '[data-rap-faq]' );
				if ( ! b ) { return; }
				var row = b.closest( '.rap-faq-row' ), act = b.getAttribute( 'data-rap-faq' );
				if ( 'del' === act ) { row.remove(); }
				if ( 'up' === act && row.previousElementSibling ) { list.insertBefore( row, row.previousElementSibling ); }
				if ( 'down' === act && row.nextElementSibling ) { list.insertBefore( row.nextElementSibling, row ); }
			} );
		} )();
		</script>
		<?php
	}

	/** Jeden řádek opakovače v administraci. */
	private static function box_row( $q, $a ) {
		echo '<div class="rap-faq-row"><div class="rap-faq-row__head">';
		echo '<input type="text" name="rap_faq_q[]" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Otázka, např. Co si mám vzít s sebou?', 'reserveandpay' ) . '" aria-label="' . esc_attr__( 'Otázka', 'reserveandpay' ) . '">';
		echo '<button type="button" class="button-link" data-rap-faq="up" aria-label="' . esc_attr__( 'Posunout nahoru', 'reserveandpay' ) . '">↑</button>';
		echo '<button type="button" class="button-link" data-rap-faq="down" aria-label="' . esc_attr__( 'Posunout dolů', 'reserveandpay' ) . '">↓</button>';
		echo '<button type="button" class="button-link button-link-delete" data-rap-faq="del">' . esc_html__( 'Odebrat', 'reserveandpay' ) . '</button>';
		echo '</div><textarea name="rap_faq_a[]" rows="3" placeholder="' . esc_attr__( 'Odpověď', 'reserveandpay' ) . '" aria-label="' . esc_attr__( 'Odpověď', 'reserveandpay' ) . '">' . esc_textarea( $a ) . '</textarea></div>';
	}
}
