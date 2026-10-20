<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stránka nastavení pluginu.
 */
class Resandpay_Admin_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'reserveandpay', __( 'Nastavení ReserveAndPay', 'reserveandpay' ), __( 'Nastavení', 'reserveandpay' ), resandpay_cap_settings(), 'reserveandpay-settings', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'resandpay_settings_group',
			Resandpay_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function fields() {
		$text = 'text';
		return array(
			__( 'Provozovatel', 'reserveandpay' )      => array(
				'company_name'    => array( __( 'Jméno / název provozovatele', 'reserveandpay' ), $text ),
				'company_ic'      => array( __( 'IČ', 'reserveandpay' ), $text ),
				'company_address' => array( __( 'Adresa', 'reserveandpay' ), $text ),
				'bank_account'    => array( __( 'Číslo účtu', 'reserveandpay' ), $text, __( 'Např. 123456789/2700', 'reserveandpay' ) ),
				'iban'            => array( __( 'IBAN', 'reserveandpay' ), $text ),
				'admin_email'     => array( __( 'E-mail pro upozornění na nové rezervace', 'reserveandpay' ), 'email' ),
				'notify_admin'    => array( __( 'Posílat upozornění na nové rezervace', 'reserveandpay' ), 'checkbox' ),
			),
			__( 'Rezervace a platby', 'reserveandpay' ) => array(
				'currency'          => array( __( 'Výchozí měna', 'reserveandpay' ), $text, __( 'Třípísmenný kód, např. CZK.', 'reserveandpay' ) ),
				'due_days'          => array( __( 'Výchozí splatnost (dní)', 'reserveandpay' ), 'number' ),
				'last_minute_hours' => array( __( 'Hranice pro platbu na místě (hodin před začátkem)', 'reserveandpay' ), 'number', __( 'Rezervace vytvořená později než tato hranice dostane i možnost zaplatit na místě a po splatnosti neexpiruje. Výchozí 24 h.', 'reserveandpay' ) ),
				'vs_prefix'         => array( __( 'Prefix variabilního symbolu', 'reserveandpay' ), $text, __( 'Číslice na začátku VS (např. rok 26). VS je vždy jedinečný a nikdy se nepoužije znovu.', 'reserveandpay' ) ),
				'gdpr_page'         => array( __( 'Stránka se zásadami zpracování osobních údajů', 'reserveandpay' ), 'page' ),
				'terms_page'        => array( __( 'Stránka s obchodními a stornopodmínkami', 'reserveandpay' ), 'page' ),
			),
			__( 'E-maily', 'reserveandpay' )           => array(
				'mail_from_name'        => array( __( 'Jméno odesílatele', 'reserveandpay' ), $text ),
				'mail_from_email'       => array( __( 'E-mail odesílatele', 'reserveandpay' ), 'email' ),
				'tpl_received_subject'  => array( __( 'Přijetí rezervace – předmět', 'reserveandpay' ), $text ),
				'tpl_received_body'     => array( __( 'Přijetí rezervace – text', 'reserveandpay' ), 'textarea' ),
				'tpl_lastminute_note'   => array( __( 'Doplněk pro rezervaci na poslední chvíli', 'reserveandpay' ), 'textarea', __( 'Vkládá se místo {last_minute_note}.', 'reserveandpay' ) ),
				'tpl_paid_subject'      => array( __( 'Potvrzení platby – předmět', 'reserveandpay' ), $text ),
				'tpl_paid_body'         => array( __( 'Potvrzení platby – text', 'reserveandpay' ), 'textarea' ),
				'tpl_cancelled_subject' => array( __( 'Zrušení – předmět', 'reserveandpay' ), $text ),
				'tpl_cancelled_body'    => array( __( 'Zrušení – text', 'reserveandpay' ), 'textarea' ),
				'tpl_admin_subject'     => array( __( 'Upozornění pro správce – předmět', 'reserveandpay' ), $text ),
				'tpl_admin_body'        => array( __( 'Upozornění pro správce – text', 'reserveandpay' ), 'textarea' ),
			),
			__( 'Testovací režim', 'reserveandpay' )   => array(
				'test_mode'  => array( __( 'Zapnout testovací režim', 'reserveandpay' ), 'checkbox', __( 'Všechny e-maily pluginu se pošlou jen na testovací adresu. Na vývojovém webu nechte zapnuté.', 'reserveandpay' ) ),
				'test_email' => array( __( 'Testovací e-mail', 'reserveandpay' ), 'email' ),
			),
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out       = Resandpay_Settings::all();
		$from_form = ! empty( $in['__form'] );
		foreach ( self::fields() as $section ) {
			foreach ( $section as $key => $def ) {
				$type = $def[1];
				if ( ! array_key_exists( $key, $in ) && ! ( $from_form && 'checkbox' === $type ) ) {
					continue; // Částečná aktualizace – ponechat stávající hodnotu.
				}
				$val = $in[ $key ] ?? null;
				switch ( $type ) {
					case 'checkbox':
						$out[ $key ] = empty( $val ) ? 0 : 1;
						break;
					case 'number':
						$out[ $key ] = max( 0, (float) str_replace( ',', '.', (string) $val ) );
						break;
					case 'page':
						$out[ $key ] = absint( $val );
						break;
					case 'email':
						$out[ $key ] = sanitize_email( (string) $val );
						break;
					case 'textarea':
						$out[ $key ] = sanitize_textarea_field( (string) $val );
						break;
					default:
						$out[ $key ] = sanitize_text_field( (string) $val );
				}
			}
		}
		$out['currency']  = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['currency'] ), 0, 3 ) ) ?: 'CZK';
		$out['vs_prefix'] = substr( preg_replace( '/\D/', '', $out['vs_prefix'] ), 0, 4 );
		$out['iban']      = strtoupper( preg_replace( '/\s+/', '', $out['iban'] ) );
		return $out;
	}

	public static function page() {
		$s = Resandpay_Settings::all();
		echo '<div class="wrap"><h1>' . esc_html__( 'Nastavení ReserveAndPay', 'reserveandpay' ) . '</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'resandpay_settings_group' );
		echo '<input type="hidden" name="' . esc_attr( Resandpay_Settings::OPTION ) . '[__form]" value="1">';
		foreach ( self::fields() as $title => $section ) {
			echo '<h2>' . esc_html( $title ) . '</h2>';
			if ( __( 'E-maily', 'reserveandpay' ) === $title ) {
				echo '<p class="description">' . esc_html__( 'Proměnné:', 'reserveandpay' ) . ' <code>{name} {email} {phone} {note} {event} {date} {location} {amount} {vs} {proforma} {due_date} {account} {iban} {company} {free} {reason} {last_minute_note}</code></p>';
			}
			echo '<table class="form-table">';
			foreach ( $section as $key => $def ) {
				$name = Resandpay_Settings::OPTION . '[' . $key . ']';
				$id   = 'rap_' . $key;
				$val  = $s[ $key ] ?? '';
				echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $def[0] ) . '</label></th><td>';
				switch ( $def[1] ) {
					case 'checkbox':
						echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $val ), true, false ) . '>';
						break;
					case 'textarea':
						echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="8" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
						break;
					case 'page':
						wp_dropdown_pages(
							array(
								'name'              => esc_attr( $name ),
								'id'                => esc_attr( $id ),
								'selected'          => (int) $val,
								'show_option_none'  => '— ' . __( 'nevybráno', 'reserveandpay' ) . ' —',
								'option_none_value' => 0,
							)
						);
						break;
					case 'number':
						echo '<input type="number" step="0.5" min="0" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="small-text">';
						break;
					default:
						echo '<input type="' . esc_attr( 'email' === $def[1] ? 'email' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text">';
				}
				if ( ! empty( $def[2] ) ) {
					echo '<p class="description">' . esc_html( $def[2] ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</table>';
		}
		submit_button();
		echo '</form></div>';
	}
}
