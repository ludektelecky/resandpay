<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vzhled pluginu podle webu (SWD kap. 15.1, R15; Kniha rozvoje R-009).
 *
 * Jedna paleta a jedna dvojice písem pro všechny výstupy – web (CSS proměnné --rap-*), e-maily,
 * PDF (platební pokyn, slevový poukaz) a štítek verze v administraci.
 *
 * Nastavení → Vzhled → „Barvy a písma“:
 * - theme  = ze šablony webu: blokové šablony z theme.json (globální styly), GeneratePress z globálních
 *            barev a písem, ostatní šablony z palety editoru; co šablona neurčí, se dopočítá,
 * - custom = vlastní barvy a písma z nastavení (slehkouhlavou.cz: paleta grafického manuálu).
 */
class Resandpay_Style {

	/** Role barev (klíč nastavení color_*). */
	const ROLES = array( 'bg', 'bg_alt', 'ink', 'ink_soft', 'accent', 'accent_hover', 'accent_2', 'gold', 'line', 'error' );

	/** Neutrální paleta, když šablona nic neurčí. */
	const NEUTRAL = array(
		'bg'     => '#ffffff',
		'ink'    => '#1e1e1e',
		'accent' => '#2c5f8a',
		'error'  => '#b32d2e',
	);

	/** Sady písem v PDF: logický název → soubor TCPDF. */
	const PDF_FONTS = array(
		'brand'  => array(
			'heading'        => 'fraunces_sb',
			'heading_md'     => 'fraunces_md',
			'heading_italic' => 'fraunces_i',
			'body'           => 'worksans',
			'body_bold'      => 'worksansb',
		),
		'dejavu' => array(
			'heading'        => 'dejavuserifb',
			'heading_md'     => 'dejavuserif',
			'heading_italic' => 'dejavuserifi',
			'body'           => 'dejavusans',
			'body_bold'      => 'dejavusansb',
		),
	);

	/** @var array|null Mezipaměť pro jeden požadavek. */
	private static $cache = null;

	public static function reset() {
		self::$cache = null;
	}

	/** Režim: theme | custom. */
	public static function mode() {
		return 'custom' === Resandpay_Settings::get( 'style_mode' ) ? 'custom' : 'theme';
	}

	/**
	 * Výsledná paleta (hex #rrggbb pro všechny role) a písma.
	 *
	 * @return array{colors: array, font_body: string, font_heading: string, source: string}
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		if ( 'custom' === self::mode() ) {
			$s      = Resandpay_Settings::all();
			$colors = array();
			foreach ( self::ROLES as $r ) {
				$colors[ $r ] = self::hex( (string) ( $s[ 'color_' . $r ] ?? '' ) );
			}
			$font_body    = trim( (string) $s['font_body'] );
			$font_heading = trim( (string) $s['font_heading'] );
			$source       = 'custom';
		} else {
			$t            = self::from_theme();
			$colors       = $t['colors'];
			$font_body    = $t['font_body'];
			$font_heading = $t['font_heading'];
			$source       = $t['source'];
		}
		$colors = self::complete( $colors );
		$out    = apply_filters(
			'resandpay_style',
			array(
				'colors'       => $colors,
				'font_body'    => $font_body,
				'font_heading' => $font_heading,
				'source'       => $source,
			)
		);
		return self::$cache = $out;
	}

	/** Barva role jako #rrggbb. */
	public static function color( $role ) {
		$c = self::get()['colors'];
		return $c[ $role ] ?? '#000000';
	}

	/** Barva role jako [r, g, b] (PDF). */
	public static function rgb( $role ) {
		return self::to_rgb( self::color( $role ) );
	}

	/** Písmo PDF podle logického názvu (heading, heading_md, heading_italic, body, body_bold). */
	public static function pdf_font( $logical ) {
		$set = self::PDF_FONTS[ Resandpay_Settings::get( 'pdf_font' ) ] ?? self::PDF_FONTS['dejavu'];
		return $set[ $logical ] ?? $set['body'];
	}

	/** Písmo do e-mailu (inline style) s bezpečnou náhradou. */
	public static function email_font( $kind = 'body' ) {
		$st  = self::get();
		$fam = 'heading' === $kind ? ( $st['font_heading'] ?: $st['font_body'] ) : $st['font_body'];
		$fam = str_replace( '"', "'", self::clean_family( $fam ) ); // Uvnitř atributu style="…".
		$fb  = 'heading' === $kind ? "Georgia,'Times New Roman',serif" : 'Arial,Helvetica,sans-serif';
		return '' === $fam ? $fb : $fam . ',' . $fb;
	}

	/** CSS proměnné pro web (inline za frontend.css). */
	public static function css_vars() {
		$st = self::get();
		$c  = $st['colors'];
		$v  = array(
			'--rap-bg'           => $c['bg'],
			'--rap-bg-alt'       => $c['bg_alt'],
			'--rap-ink'          => $c['ink'],
			'--rap-ink-soft'     => $c['ink_soft'],
			'--rap-accent'       => $c['accent'],
			'--rap-accent-hover' => $c['accent_hover'],
			'--rap-accent-text'  => self::text_on( $c['accent'], $c ),
			'--rap-accent-2'     => $c['accent_2'],
			'--rap-line'         => $c['line'],
			'--rap-error'        => $c['error'],
			'--rap-gold'         => $c['gold'],
			'--rap-font'         => self::css_family( $st['font_body'], 'inherit' ),
			'--rap-font-heading' => self::css_family( $st['font_heading'], 'inherit' ),
		);
		if ( 'custom' !== $st['source'] ) {
			// Odvozené odstíny jen při převzetí ze šablony (vlastní paleta ponechá hodnoty z frontend.css).
			$v += array(
				'--rap-error-bg'    => self::mix( $c['error'], $c['bg'], 0.1 ),
				'--rap-gold-light'  => self::mix( $c['gold'], $c['bg'], 0.6 ),
				'--rap-on-dark'     => $c['bg'],
				'--rap-card-top'    => self::mix( $c['accent_2'], $c['ink'], 0.55 ),
				'--rap-card-bottom' => self::mix( $c['ink'], '#000000', 0.8 ),
			);
		}
		$out = '';
		foreach ( $v as $k => $val ) {
			$out .= $k . ':' . $val . ';';
		}
		return ':root{' . $out . '}';
	}

	/** Vlastní CSS z nastavení (bez značek). */
	public static function custom_css() {
		return trim( wp_strip_all_tags( (string) Resandpay_Settings::get( 'custom_css' ) ) );
	}

	/**
	 * Načte styly pluginu na webu: frontend.css (lze vypnout filtrem resandpay_enqueue_styles),
	 * proměnné palety a vlastní CSS z nastavení.
	 */
	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$file = (bool) apply_filters( 'resandpay_enqueue_styles', true );
		if ( ! wp_style_is( 'reserveandpay', 'registered' ) ) {
			wp_register_style( 'reserveandpay', RESANDPAY_URL . 'assets/css/frontend.css', array(), RESANDPAY_VERSION );
		}
		if ( $file ) {
			wp_enqueue_style( 'reserveandpay' );
		}
		wp_register_style( 'reserveandpay-style', false, $file ? array( 'reserveandpay' ) : array(), RESANDPAY_VERSION );
		wp_enqueue_style( 'reserveandpay-style' );
		wp_add_inline_style( 'reserveandpay-style', self::css_vars() . ( self::custom_css() ? "\n/* ReserveAndPay – vlastní CSS (Nastavení → Vzhled) */\n" . self::custom_css() : '' ) );
	}

	// ------------------------------------------------------------------
	// Převzetí ze šablony.
	// ------------------------------------------------------------------

	/** Paleta a písma ze šablony webu. */
	public static function from_theme() {
		if ( function_exists( 'generate_get_defaults' ) || 'generatepress' === get_template() ) {
			$gp = self::from_generatepress();
			if ( $gp ) {
				return $gp;
			}
		}
		if ( function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			return self::from_theme_json();
		}
		return self::from_classic();
	}

	/** Blokové šablony a šablony s theme.json – globální styly webu. */
	private static function from_theme_json() {
		$palette = self::palette_map( (array) wp_get_global_settings( array( 'color', 'palette' ) ) );
		$fonts   = array();
		foreach ( (array) wp_get_global_settings( array( 'typography', 'fontFamilies' ) ) as $origin ) {
			foreach ( (array) $origin as $f ) {
				if ( ! empty( $f['slug'] ) && ! empty( $f['fontFamily'] ) ) {
					$fonts[ $f['slug'] ] = $f['fontFamily'];
				}
			}
		}
		$styles = (array) wp_get_global_styles();
		$get    = function ( array $path ) use ( $styles ) {
			$v = $styles;
			foreach ( $path as $p ) {
				if ( ! is_array( $v ) || ! isset( $v[ $p ] ) ) {
					return '';
				}
				$v = $v[ $p ];
			}
			return is_string( $v ) ? $v : '';
		};
		$col    = fn( $raw ) => self::resolve_color( $raw, $palette );
		$colors = array(
			'bg'           => $col( $get( array( 'color', 'background' ) ) ) ?: self::first( $palette, array( 'base', 'background', 'white' ) ),
			'ink'          => $col( $get( array( 'color', 'text' ) ) ) ?: self::first( $palette, array( 'contrast', 'foreground', 'black' ) ),
			'accent'       => $col( $get( array( 'elements', 'button', 'color', 'background' ) ) ) ?: $col( $get( array( 'elements', 'link', 'color', 'text' ) ) ) ?: self::first( $palette, array( 'primary', 'accent', 'accent-1', 'vivid-cyan-blue' ) ),
			'accent_2'     => $col( $get( array( 'elements', 'link', 'color', 'text' ) ) ) ?: self::first( $palette, array( 'secondary', 'accent-3', 'accent-2' ) ),
			'bg_alt'       => self::first( $palette, array( 'base-2', 'tertiary', 'accent-5', 'subtle-background', 'secondary-background' ) ),
			'gold'         => self::first( $palette, array( 'secondary', 'accent-2', 'accent-3' ) ),
			'accent_hover' => '',
			'ink_soft'     => '',
			'line'         => '',
			'error'        => '',
		);
		// Barva tlačítka může být stejná jako text (např. černé tlačítko) – zlatá pak převezme akcent.
		if ( ! $colors['gold'] || self::contrast( $colors['gold'], $colors['bg'] ?: '#ffffff' ) < 2 ) {
			$colors['gold'] = $colors['accent'];
		}
		if ( $colors['bg_alt'] && $colors['bg'] && self::contrast( $colors['bg_alt'], $colors['bg'] ) > 1.6 ) {
			$colors['bg_alt'] = ''; // Příliš výrazná plocha – dopočítat jemný odstín.
		}
		$body    = self::resolve_font( $get( array( 'typography', 'fontFamily' ) ), $fonts );
		$heading = self::resolve_font( $get( array( 'elements', 'heading', 'typography', 'fontFamily' ) ), $fonts ) ?: self::resolve_font( $get( array( 'elements', 'h1', 'typography', 'fontFamily' ) ), $fonts );
		return array(
			'colors'       => $colors,
			'font_body'    => $body,
			'font_heading' => $heading ?: $body,
			'source'       => 'theme.json',
		);
	}

	/** GeneratePress – globální barvy a písma (Přizpůsobit → Barvy / Typografie). */
	private static function from_generatepress() {
		$opt = get_option( 'generate_settings', array() );
		$def = function_exists( 'generate_get_defaults' ) ? (array) generate_get_defaults() : array();
		$gc  = ! empty( $opt['global_colors'] ) ? $opt['global_colors'] : ( $def['global_colors'] ?? array() );
		if ( ! $gc ) {
			return null;
		}
		$p = array();
		foreach ( (array) $gc as $c ) {
			if ( ! empty( $c['slug'] ) && ! empty( $c['color'] ) ) {
				$p[ $c['slug'] ] = self::hex( (string) $c['color'] );
			}
		}
		$p      = array_filter( $p );
		$colors = array(
			'bg'           => $p['base-3'] ?? '',
			'bg_alt'       => $p['base-2'] ?? ( $p['base'] ?? '' ),
			'ink'          => $p['contrast'] ?? '',
			'ink_soft'     => $p['contrast-2'] ?? '',
			'accent'       => $p['accent'] ?? '',
			'accent_hover' => $p['accent-hover'] ?? '',
			'accent_2'     => $p['accent-2'] ?? ( $p['contrast-2'] ?? '' ),
			'gold'         => $p['accent-3'] ?? ( $p['accent-2'] ?? ( $p['accent'] ?? '' ) ),
			'line'         => '',
			'error'        => '',
		);
		$body = $heading = '';
		foreach ( (array) ( $opt['typography'] ?? array() ) as $t ) {
			$fam = trim( (string) ( $t['fontFamily'] ?? '' ) );
			$sel = (string) ( $t['selector'] ?? '' );
			if ( '' === $fam ) {
				continue;
			}
			if ( 'body' === $sel && '' === $body ) {
				$body = $fam;
			} elseif ( in_array( $sel, array( 'all-headings', 'h1' ), true ) && '' === $heading ) {
				$heading = $fam;
			}
		}
		return array(
			'colors'       => $colors,
			'font_body'    => self::quote_family( $body ),
			'font_heading' => self::quote_family( $heading ?: $body ),
			'source'       => 'generatepress',
		);
	}

	/** Klasické šablony – paleta editoru a barva pozadí webu. */
	private static function from_classic() {
		$p = array();
		foreach ( (array) get_theme_support( 'editor-color-palette' ) as $set ) {
			foreach ( (array) $set as $c ) {
				if ( ! empty( $c['slug'] ) && ! empty( $c['color'] ) ) {
					$p[ $c['slug'] ] = self::hex( (string) $c['color'] );
				}
			}
		}
		$p  = array_filter( $p );
		$bg = get_background_color() ? self::hex( '#' . get_background_color() ) : '';
		return array(
			'colors'       => array(
				'bg'     => $bg ?: self::first( $p, array( 'white', 'background', 'base' ) ),
				'ink'    => self::first( $p, array( 'black', 'dark-gray', 'foreground', 'contrast' ) ),
				'accent' => self::first( $p, array( 'primary', 'accent', 'blue', 'vivid-cyan-blue' ) ),
			),
			'font_body'    => '',
			'font_heading' => '',
			'source'       => 'classic',
		);
	}

	// ------------------------------------------------------------------
	// Pomocné funkce.
	// ------------------------------------------------------------------

	/** Doplní chybějící role (dopočet ze základních barev). */
	public static function complete( array $c ) {
		$c = array_map( fn( $x ) => self::hex( (string) $x ), $c + array_fill_keys( self::ROLES, '' ) );
		$c['bg']     = $c['bg'] ?: self::NEUTRAL['bg'];
		$c['ink']    = $c['ink'] ?: self::NEUTRAL['ink'];
		$c['accent'] = $c['accent'] ?: self::NEUTRAL['accent'];
		$c['error']  = $c['error'] ?: self::NEUTRAL['error'];
		$c['bg_alt']       = $c['bg_alt'] ?: self::mix( $c['ink'], $c['bg'], 0.05 );
		$c['ink_soft']     = $c['ink_soft'] ?: self::mix( $c['ink'], $c['bg'], 0.72 );
		$c['line']         = $c['line'] ?: self::mix( $c['ink'], $c['bg'], 0.2 );
		$c['accent_hover'] = $c['accent_hover'] ?: ( self::luminance( $c['accent'] ) < 0.06 ? self::mix( $c['accent'], $c['bg'], 0.78 ) : self::mix( $c['accent'], '#000000', 0.78 ) );
		$c['accent_2']     = $c['accent_2'] ?: $c['accent'];
		$c['gold']         = $c['gold'] ?: $c['accent'];
		return array_merge( array_flip( self::ROLES ), array_intersect_key( $c, array_flip( self::ROLES ) ) ); // Pořadí rolí dle ROLES.
	}

	/** Normalizace barvy na #rrggbb ('' = neplatná). */
	public static function hex( $c ) {
		$c = strtolower( trim( $c ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $c, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/', $c ) ) {
			return $c;
		}
		if ( preg_match( '/^#([0-9a-f]{6})[0-9a-f]{2}$/', $c, $m ) ) {
			return '#' . $m[1];
		}
		if ( preg_match( '/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/', $c, $m ) ) {
			return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
		}
		$named = array( 'white' => '#ffffff', 'black' => '#000000' );
		return $named[ $c ] ?? '';
	}

	public static function to_rgb( $hex ) {
		$h = ltrim( self::hex( $hex ) ?: '#000000', '#' );
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
	}

	/** Směs barev: $w = podíl barvy $a (0–1). */
	public static function mix( $a, $b, $w ) {
		$x = self::to_rgb( $a );
		$y = self::to_rgb( $b );
		return sprintf( '#%02x%02x%02x', round( $x[0] * $w + $y[0] * ( 1 - $w ) ), round( $x[1] * $w + $y[1] * ( 1 - $w ) ), round( $x[2] * $w + $y[2] * ( 1 - $w ) ) );
	}

	public static function luminance( $hex ) {
		$l = array_map(
			function ( $v ) {
				$v /= 255;
				return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			self::to_rgb( $hex )
		);
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	}

	/** Kontrastní poměr WCAG. */
	public static function contrast( $a, $b ) {
		$x = self::luminance( $a );
		$y = self::luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/** Čitelný text na barvě (bílá, nebo barva textu / podkladu). */
	public static function text_on( $hex, array $c ) {
		$light = self::luminance( $c['bg'] ) > self::luminance( $c['ink'] ) ? $c['bg'] : $c['ink'];
		$dark  = $light === $c['bg'] ? $c['ink'] : $c['bg'];
		return self::contrast( $hex, '#ffffff' ) >= 3 ? '#ffffff' : ( self::contrast( $hex, $light ) >= self::contrast( $hex, $dark ) ? $light : $dark );
	}

	/** Mapa slug → hex z palety theme.json (theme, pak custom; default jen jako poslední). */
	private static function palette_map( array $palette ) {
		$out = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $c ) {
				if ( ! empty( $c['slug'] ) && ! empty( $c['color'] ) ) {
					$h = self::hex( (string) $c['color'] );
					if ( $h ) {
						$out[ $c['slug'] ] = $h;
					}
				}
			}
		}
		return $out;
	}

	/** Hodnota z globálních stylů: #hex, var:preset|color|slug nebo var(--wp--preset--color--slug). */
	private static function resolve_color( $raw, array $palette ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/i', $raw, $m ) || preg_match( '/var\(--wp--preset--color--([a-z0-9-]+)\)/i', $raw, $m ) ) {
			return $palette[ $m[1] ] ?? '';
		}
		return self::hex( $raw );
	}

	private static function resolve_font( $raw, array $fonts ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/^var:preset\|font-family\|([a-z0-9-]+)$/i', $raw, $m ) || preg_match( '/var\(--wp--preset--font-family--([a-z0-9-]+)\)/i', $raw, $m ) ) {
			return $fonts[ $m[1] ] ?? '';
		}
		return 0 === strpos( $raw, 'var' ) ? '' : $raw;
	}

	private static function first( array $p, array $slugs ) {
		foreach ( $slugs as $s ) {
			if ( ! empty( $p[ $s ] ) ) {
				return $p[ $s ];
			}
		}
		return '';
	}

	/** Název písma s mezerou do uvozovek („Work Sans“ → "Work Sans"). */
	private static function quote_family( $fam ) {
		$fam = trim( (string) $fam );
		if ( '' === $fam || false !== strpos( $fam, ',' ) || false !== strpos( $fam, '"' ) || false !== strpos( $fam, "'" ) ) {
			return $fam;
		}
		return false !== strpos( $fam, ' ' ) ? '"' . $fam . '"' : $fam;
	}

	/** Bezpečný font-family pro CSS ('' = $fallback). */
	public static function css_family( $fam, $fallback ) {
		$fam = self::clean_family( $fam );
		return '' === $fam ? $fallback : $fam;
	}

	/** Povolené znaky v názvu písma (žádné ; { } < >). */
	public static function clean_family( $fam ) {
		$fam = trim( preg_replace( '/[^\p{L}\p{N}\s,"\'\-_.]/u', '', (string) $fam ) );
		return in_array( strtolower( $fam ), array( 'inherit', 'initial', 'unset' ), true ) ? '' : $fam;
	}
}
