<?php
defined( 'ABSPATH' ) || exit;

/**
 * Slevy (SWD kap. 3.10): slevový kupón a sleva za včasnou rezervaci.
 *
 * OP01 – sleva je v textu e-mailu / PDF, QR kódy obsahují částky po slevě; u platby ve dvou
 * částech se 1. část počítá z plné ceny a sleva se odečte až z doplatku (nejvýš do jeho výše).
 * Na jednu rezervaci jen jedna sleva – kupón nelze uplatnit, platí-li sleva za včasnou rezervaci.
 */
class Resandpay_Discounts {

	const COUPON = 'coupon';
	const EARLY  = 'early';

	/** Znaky automatického kódu – bez zaměnitelných 0/O, 1/I/L. */
	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_coupons';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'resandpay_res_log';
	}

	/* ---------------------------------------------------------------------
	 * Kupóny
	 * ------------------------------------------------------------------- */

	/** Normalizace kódu pro porovnání: velká písmena, bez mezer a pomlček. */
	public static function normalize( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
	}

	/** Automatický kód SLH-XXXX-XXXX-XX (10 náhodných znaků z 31, ~50 bitů – neuhodnutelný). */
	public static function generate_code() {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) Resandpay_Settings::get( 'coupon_prefix' ) ) );
		do {
			$c = '';
			for ( $i = 0; $i < 10; $i++ ) {
				$c .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}
			$code = ( $prefix ? $prefix . '-' : '' ) . substr( $c, 0, 4 ) . '-' . substr( $c, 4, 4 ) . '-' . substr( $c, 8, 2 );
		} while ( self::get_by_code( $code ) );
		return $code;
	}

	public static function guid() {
		return wp_generate_uuid4();
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_code( $code ) {
		global $wpdb;
		$n = self::normalize( $code );
		if ( '' === $n ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code_norm = %s', $n ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $c ) {
		foreach ( array( 'scope_events', 'scope_services', 'scope_cats' ) as $k ) {
			$c[ $k ] = array_values( array_filter( array_map( 'intval', explode( ',', (string) $c[ $k ] ) ) ) );
		}
		$c['value']         = (float) $c['value'];
		$c['max_uses']      = (int) $c['max_uses'];
		$c['max_per_email'] = (int) $c['max_per_email'];
		$c['active']        = (int) $c['active'];
		$c['scope_all']     = (int) $c['scope_all'];
		return $c;
	}

	/** @return array Kupóny pro administraci (nejnovější první). */
	public static function all( array $args = array() ) {
		global $wpdb;
		$where = '1=1';
		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where = $wpdb->prepare( '(code LIKE %s OR note LIKE %s OR batch LIKE %s)', $like, $like, $like );
		}
		if ( ! empty( $args['batch'] ) ) {
			$where .= $wpdb->prepare( ' AND batch = %s', $args['batch'] );
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Uložení kupónu (nový / úprava).
	 *
	 * @param array $in code (prázdné = vygenerovat), mode, value, valid_from, valid_to, scope_all,
	 *                  scope_events[], scope_services[], scope_cats[], max_uses, max_per_email, active, note, batch.
	 * @return array|WP_Error Kupón.
	 */
	public static function save( array $in, $id = 0 ) {
		global $wpdb;
		$old  = $id ? self::get( $id ) : null;
		$code = trim( (string) ( $in['code'] ?? '' ) );
		if ( '' === $code ) {
			$code = $old ? $old['code'] : self::generate_code();
		}
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $code ) );
		$norm = self::normalize( $code );
		if ( strlen( $norm ) < 4 || strlen( $norm ) > 40 ) {
			return new WP_Error( 'code', __( 'Kód kupónu musí mít 4–40 písmen nebo číslic.', 'reserveandpay' ) );
		}
		$dup = self::get_by_code( $code );
		if ( $dup && (int) $dup['id'] !== (int) $id ) {
			/* translators: %s: kód */
			return new WP_Error( 'dup', sprintf( __( 'Kupón s kódem %s už existuje.', 'reserveandpay' ), $code ) );
		}
		$mode  = 'fixed' === ( $in['mode'] ?? '' ) ? 'fixed' : 'percent';
		$value = max( 0, (float) str_replace( ',', '.', (string) ( $in['value'] ?? 0 ) ) );
		if ( 'percent' === $mode ) {
			$value = min( 100, $value );
		}
		if ( $value <= 0 ) {
			return new WP_Error( 'value', __( 'Zadejte výši slevy.', 'reserveandpay' ) );
		}
		$date = function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
		};
		$ids = function ( $v ) {
			return implode( ',', array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
		};
		$row = array(
			'code'           => $code,
			'code_norm'      => $norm,
			'mode'           => $mode,
			'value'          => round( $value, 2 ),
			'valid_from'     => $date( $in['valid_from'] ?? '' ),
			'valid_to'       => $date( $in['valid_to'] ?? '' ),
			'scope_all'      => empty( $in['scope_all'] ) ? 0 : 1,
			'scope_events'   => $ids( $in['scope_events'] ?? array() ),
			'scope_services' => $ids( $in['scope_services'] ?? array() ),
			'scope_cats'     => $ids( $in['scope_cats'] ?? array() ),
			'max_uses'       => max( 0, (int) ( $in['max_uses'] ?? 0 ) ),
			'max_per_email'  => max( 0, (int) ( $in['max_per_email'] ?? 0 ) ),
			'active'         => empty( $in['active'] ) ? 0 : 1,
			'note'           => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 255 ),
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( ! $row['scope_all'] && '' === $row['scope_events'] && '' === $row['scope_services'] && '' === $row['scope_cats'] ) {
			$row['scope_all'] = 1;
		}
		if ( $old ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		} else {
			$row['guid']       = self::guid();
			$row['batch']      = mb_substr( sanitize_text_field( (string) ( $in['batch'] ?? '' ) ), 0, 40 );
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		}
		return self::get( $id );
	}

	/** Hromadné vytvoření N jednorázových kupónů se stejnými parametry. @return array Kupóny. */
	public static function generate_batch( array $in, $count ) {
		$count = max( 1, min( 500, (int) $count ) );
		$batch = 'D-' . wp_date( 'Ymd-His' );
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$c = self::save( array_merge( $in, array( 'code' => '', 'batch' => $batch, 'max_uses' => 1 ) ) );
			if ( ! is_wp_error( $c ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	public static function delete( $id ) {
		global $wpdb;
		if ( self::uses( $id ) || (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Resandpay_Reservations::table() . ' WHERE coupon_id = %d', $id ) ) ) {
			return new WP_Error( 'used', __( 'Kupón už byl použit – nelze ho smazat, jen vypnout.', 'reserveandpay' ) );
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		return true;
	}

	/** Počet platných použití (aktivní a zaplacené rezervace; vypršelé a zrušené se nepočítají). */
	public static function uses( $coupon_id, $email = '' ) {
		global $wpdb;
		$in  = "'" . implode( "','", Resandpay_Reservations::active_statuses() ) . "'";
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Resandpay_Reservations::table() . " WHERE coupon_id = %d AND status IN ({$in})", $coupon_id ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' !== $email ) {
			$sql .= $wpdb->prepare( ' AND email = %s', $email );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Platí kupón pro akci / termín (rozsah)? */
	public static function in_scope( array $c, array $ev ) {
		if ( $c['scope_all'] ) {
			return true;
		}
		if ( in_array( (int) $ev['id'], $c['scope_events'], true ) ) {
			return true;
		}
		$service = (int) get_post_meta( $ev['id'], '_rap_service', true );
		if ( $service && in_array( $service, $c['scope_services'], true ) ) {
			return true;
		}
		if ( $c['scope_cats'] ) {
			$terms = wp_get_object_terms( $service ?: $ev['id'], Resandpay_Post_Types::CATEGORY, array( 'fields' => 'ids' ) );
			if ( $service && ! $terms ) {
				$terms = wp_get_object_terms( $ev['id'], Resandpay_Post_Types::CATEGORY, array( 'fields' => 'ids' ) );
			}
			if ( ! is_wp_error( $terms ) && array_intersect( array_map( 'intval', $terms ), $c['scope_cats'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ověření kupónu pro rezervaci.
	 *
	 * @return array|WP_Error Kupón.
	 */
	public static function validate_coupon( $code, array $ev, $email = '', $now = null ) {
		$now = $now ?? time();
		$c   = self::get_by_code( $code );
		if ( ! $c || ! $c['active'] ) {
			return new WP_Error( 'coupon_invalid', __( 'Slevový kupón neexistuje nebo není platný.', 'reserveandpay' ) );
		}
		$day = wp_date( 'Y-m-d', $now );
		if ( ( $c['valid_from'] && $day < $c['valid_from'] ) || ( $c['valid_to'] && $day > $c['valid_to'] ) ) {
			return new WP_Error( 'coupon_expired', __( 'Platnost slevového kupónu vypršela nebo ještě nezačala.', 'reserveandpay' ) );
		}
		if ( ! self::in_scope( $c, $ev ) ) {
			return new WP_Error( 'coupon_scope', __( 'Kupón pro tuto akci neplatí.', 'reserveandpay' ) );
		}
		if ( $c['max_uses'] && self::uses( $c['id'] ) >= $c['max_uses'] ) {
			return new WP_Error( 'coupon_used', __( 'Slevový kupón už byl vyčerpán.', 'reserveandpay' ) );
		}
		if ( $c['max_per_email'] && is_email( $email ) && self::uses( $c['id'], $email ) >= $c['max_per_email'] ) {
			return new WP_Error( 'coupon_used_email', __( 'Tento slevový kupón jste už využili.', 'reserveandpay' ) );
		}
		return $c;
	}

	/** Odkaz s kupónem: na akci (platí-li jen pro jednu), jinak na přehled akcí. */
	public static function coupon_url( array $c ) {
		$url = '';
		if ( ! $c['scope_all'] && 1 === count( $c['scope_events'] ) && ! $c['scope_services'] && ! $c['scope_cats'] ) {
			$url = get_permalink( $c['scope_events'][0] );
		} elseif ( ! $c['scope_all'] && 1 === count( $c['scope_services'] ) && ! $c['scope_events'] && ! $c['scope_cats'] ) {
			$page = (int) get_post_meta( $c['scope_services'][0], '_rap_page', true );
			$url  = $page ? get_permalink( $page ) : '';
		}
		if ( ! $url ) {
			$page = (int) Resandpay_Settings::get( 'events_page' );
			$url  = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : home_url( '/' );
		}
		return add_query_arg( 'kupon', rawurlencode( $c['code'] ), $url );
	}

	/** Popis výše slevy: „20 %“ / „500 Kč“. */
	public static function value_label( $mode, $value, $currency = 'CZK' ) {
		return 'percent' === $mode ? rtrim( rtrim( number_format( (float) $value, 2, ',', '' ), '0' ), ',' ) . ' %' : Resandpay_Events::format_price( $value, $currency );
	}

	/** Popis rozsahu platnosti pro administraci a poukaz. */
	public static function scope_label( array $c ) {
		if ( $c['scope_all'] ) {
			return __( 'všechny akce a služby', 'reserveandpay' );
		}
		$parts = array();
		foreach ( $c['scope_events'] as $id ) {
			$parts[] = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
		}
		foreach ( $c['scope_services'] as $id ) {
			$parts[] = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
		}
		foreach ( $c['scope_cats'] as $id ) {
			$t = get_term( $id );
			if ( $t && ! is_wp_error( $t ) ) {
				/* translators: %s: kategorie */
				$parts[] = sprintf( __( 'kategorie %s', 'reserveandpay' ), $t->name );
			}
		}
		return implode( ', ', array_filter( $parts ) );
	}

	/* ---------------------------------------------------------------------
	 * Sleva za včasnou rezervaci
	 * ------------------------------------------------------------------- */

	/** Nastavená sleva za včasnou rezervaci akce (u termínu služby ze služby). */
	public static function early_config( array $ev ) {
		$m   = function ( $id, $k ) {
			return (string) get_post_meta( $id, '_rap_early_' . $k, true );
		};
		$src = (int) $ev['id'];
		if ( ! in_array( $m( $src, 'mode' ), array( 'fixed', 'percent' ), true ) ) {
			$svc = (int) get_post_meta( $ev['id'], '_rap_service', true );
			if ( ! $svc || ! in_array( $m( $svc, 'mode' ), array( 'fixed', 'percent' ), true ) ) {
				return null;
			}
			$src = $svc;
		}
		$until = $m( $src, 'until' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			return null;
		}
		return array(
			'mode'  => $m( $src, 'mode' ),
			'value' => (float) $m( $src, 'value' ),
			'until' => $until,
			'label' => $m( $src, 'label' ) ?: __( 'Sleva za včasnou rezervaci', 'reserveandpay' ),
		);
	}

	/** Pole „Sleva za včasnou rezervaci“ pro editaci akce a služby. */
	public static function early_fields( $post_id ) {
		$g    = function ( $k ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, '_rap_early_' . $k, true );
		};
		$mode = $g( 'mode' );
		echo '<fieldset class="rap-early-box"><p><label for="rap_early_mode">' . esc_html__( 'Sleva za včasnou rezervaci', 'reserveandpay' ) . '</label> <select id="rap_early_mode" name="rap_early_mode">';
		foreach ( array( '' => __( 'Ne', 'reserveandpay' ), 'fixed' => __( 'Pevná částka (Kč)', 'reserveandpay' ), 'percent' => __( 'Procento z ceny', 'reserveandpay' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $mode, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <input type="number" min="0" step="any" name="rap_early_value" value="' . esc_attr( $g( 'value' ) ) . '" class="small-text" aria-label="' . esc_attr__( 'Výše slevy', 'reserveandpay' ) . '"> ' . esc_html__( 'při rezervaci do', 'reserveandpay' ) . ' <input type="date" name="rap_early_until" value="' . esc_attr( $g( 'until' ) ) . '"></p>';
		echo '<p><label for="rap_early_label">' . esc_html__( 'Popis slevy', 'reserveandpay' ) . '</label> <input type="text" id="rap_early_label" name="rap_early_label" value="' . esc_attr( $g( 'label' ) ) . '" class="regular-text" placeholder="' . esc_attr__( 'Sleva za včasnou rezervaci', 'reserveandpay' ) . '">';
		echo '<span class="rap-hint">' . esc_html__( 'Jen pro platbu převodem. Sleva zanikne, pokud platba nebo některá její část přijde po splatnosti (rozhoduje datum připsání na účet). U platby ve dvou částech se odečte z doplatku, nejvýš do jeho výše. Slevový kupón s ní nelze kombinovat.', 'reserveandpay' ) . '</span></p>';
		$ev = Resandpay_Events::get( $post_id );
		if ( ! $ev && 'resandpay_service' === get_post_type( $post_id ) && class_exists( 'Resandpay_Services' ) ) {
			$ev = Resandpay_Services::get( $post_id );
		}
		if ( $ev && in_array( $mode, array( 'fixed', 'percent' ), true ) ) {
			$p1 = Resandpay_Events::part1_amount( $ev );
			$v  = (float) $g( 'value' );
			$d  = 'percent' === $mode ? round( (float) $ev['price'] * min( 100, $v ) / 100 ) : $v;
			if ( $p1 > 0 && $d > (float) $ev['price'] - $p1 ) {
				/* translators: %s: doplatek */
				echo '<p class="rap-warn">' . esc_html( sprintf( __( 'Pozor: sleva je vyšší než doplatek – uplatní se jen do výše doplatku (%s).', 'reserveandpay' ), Resandpay_Events::format_price( (float) $ev['price'] - $p1, $ev['currency'] ) ) ) . '</p>';
			}
		}
		echo '</fieldset>';
	}

	public static function save_early_meta( $post_id, array $in ) {
		if ( ! array_key_exists( 'rap_early_mode', $in ) ) {
			return;
		}
		$mode = in_array( $in['rap_early_mode'], array( 'fixed', 'percent' ), true ) ? $in['rap_early_mode'] : '';
		$val  = max( 0, (float) str_replace( ',', '.', (string) ( $in['rap_early_value'] ?? 0 ) ) );
		update_post_meta( $post_id, '_rap_early_mode', $mode );
		update_post_meta( $post_id, '_rap_early_value', 'percent' === $mode ? min( 100, $val ) : $val );
		$until = trim( (string) ( $in['rap_early_until'] ?? '' ) );
		update_post_meta( $post_id, '_rap_early_until', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ? $until : '' );
		update_post_meta( $post_id, '_rap_early_label', mb_substr( sanitize_text_field( (string) ( $in['rap_early_label'] ?? '' ) ), 0, 120 ) );
	}

	/** Platí v daném okamžiku sleva za včasnou rezervaci (podle data rezervace)? */
	public static function early_open( array $ev, $now = null ) {
		$cfg = self::early_config( $ev );
		if ( ! $cfg || $cfg['value'] <= 0 ) {
			return null;
		}
		return wp_date( 'Y-m-d', $now ?? time() ) <= $cfg['until'] ? $cfg : null;
	}

	/** Sleva v Kč z výše slevy; max. cena a u dvou plateb max. doplatek (OP01). */
	public static function amount( $mode, $value, $price, $part1 = 0.0 ) {
		$d = 'percent' === $mode ? round( $price * min( 100, $value ) / 100 ) : round( (float) $value, 2 );
		$d = min( $d, $price );
		if ( $part1 > 0 && $d < $price ) {
			$d = min( $d, $price - $part1 );
		}
		return max( 0, round( $d, 2 ) );
	}

	/**
	 * Výpočet ceny rezervace.
	 *
	 * @param array $args now, cash, last_minute, part1 (Kč, z plné ceny), coupon (kód), email.
	 * @return array price_full, discount, type, label, coupon_id, coupon_code, free, error (WP_Error|null).
	 */
	public static function compute( array $ev, array $args = array() ) {
		$price = (float) $ev['price'];
		$out   = array(
			'price_full'  => $price,
			'discount'    => 0.0,
			'type'        => '',
			'label'       => '',
			'coupon_id'   => 0,
			'coupon_code' => '',
			'free'        => false,
			'error'       => null,
		);
		$now   = (int) ( $args['now'] ?? time() );
		$part1 = (float) ( $args['part1'] ?? 0 );
		$code  = trim( (string) ( $args['coupon'] ?? '' ) );
		$early = empty( $args['cash'] ) && empty( $args['last_minute'] ) ? self::early_open( $ev, $now ) : null;
		if ( $early ) {
			$out['discount'] = self::amount( $early['mode'], $early['value'], $price, $part1 );
			$out['type']     = self::EARLY;
			$out['label']    = $early['label'];
			if ( '' !== $code ) {
				$out['error'] = new WP_Error( 'coupon_combine', __( 'Slevový kupón nelze kombinovat se slevou za včasnou rezervaci.', 'reserveandpay' ) );
			}
		} elseif ( '' !== $code ) {
			$c = self::validate_coupon( $code, $ev, (string) ( $args['email'] ?? '' ), $now );
			if ( is_wp_error( $c ) ) {
				$out['error'] = $c;
			} else {
				$full            = 'percent' === $c['mode'] && $c['value'] >= 100 || ( 'fixed' === $c['mode'] && $c['value'] >= $price );
				$out['discount'] = $full ? $price : self::amount( $c['mode'], $c['value'], $price, $part1 );
				$out['type']     = self::COUPON;
				/* translators: %s: kód */
				$out['label']       = sprintf( __( 'Slevový kupón %s', 'reserveandpay' ), $c['code'] );
				$out['coupon_id']   = (int) $c['id'];
				$out['coupon_code'] = $c['code'];
			}
		}
		$out['free'] = $price > 0 && $out['discount'] + 0.009 >= $price;
		return $out;
	}

	/** Text u ceny na webu: „4 900 Kč · při rezervaci do 31. 12. a platbě ve splatnosti 4 400 Kč“. */
	public static function price_note( array $ev, $now = null ) {
		$o = self::early_offer( $ev, $now );
		return $o ? $o['condition'] . ' ' . $o['price_text'] : '';
	}

	/**
	 * Nabídka slevy za včasnou rezervaci pro zobrazení u ceny: cena po slevě (zlatě, stejně
	 * velkým písmem jako základní cena) a podmínka („při rezervaci do … a platbě ve splatnosti“).
	 *
	 * @return array|null price, price_text, condition, label.
	 */
	public static function early_offer( array $ev, $now = null ) {
		$cfg = self::early_open( $ev, $now );
		if ( ! $cfg || (float) $ev['price'] <= 0 ) {
			return null;
		}
		$part1 = Resandpay_Events::part1_amount( $ev );
		$d     = self::amount( $cfg['mode'], $cfg['value'], (float) $ev['price'], $part1 );
		if ( $d <= 0 ) {
			return null;
		}
		$until = date_create_immutable( $cfg['until'] . ' 12:00:00', wp_timezone() );
		$price = (float) $ev['price'] - $d;
		return array(
			'price'      => $price,
			'price_text' => Resandpay_Events::format_price( $price, $ev['currency'] ),
			/* translators: %s: datum */
			'condition'  => sprintf( __( 'při rezervaci do %s a platbě ve splatnosti', 'reserveandpay' ), $until ? $until->format( 'j. n.' ) . ( $until->format( 'Y' ) !== wp_date( 'Y' ) ? ' ' . $until->format( 'Y' ) : '' ) : $cfg['until'] ),
			'label'      => $cfg['label'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Historie u rezervace
	 * ------------------------------------------------------------------- */

	public static function log( $reservation_id, $type, $message ) {
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'reservation_id' => (int) $reservation_id,
				'created_at'     => current_time( 'mysql' ),
				'user_id'        => get_current_user_id(),
				'type'           => sanitize_key( $type ),
				'message'        => mb_substr( (string) $message, 0, 255 ),
			)
		);
	}

	public static function log_rows( $reservation_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::log_table() . ' WHERE reservation_id = %d ORDER BY id', $reservation_id ), ARRAY_A );
	}
}
