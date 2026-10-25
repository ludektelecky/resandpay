<?php
/**
 * Integrační testy ReserveAndPay. Spuštění: wp eval-file tests/run-tests.php
 * Pracuje s daty v aktuální instalaci – spouštět jen na testovacím WordPressu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['rap_pass']  = 0;
$GLOBALS['rap_fail']  = 0;
$GLOBALS['rap_mails'] = array();

function rap_t( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['rap_pass'];
		echo "  PASS  {$label}\n";
	} else {
		++$GLOBALS['rap_fail'];
		echo "  FAIL  {$label}\n";
	}
}

function rap_event( $title, $start_ts, $end_ts, $cap, $extra = array() ) {
	$id = wp_insert_post(
		array(
			'post_type'   => Resandpay_Post_Types::EVENT,
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	Resandpay_Admin_Events::save_event_meta(
		$id,
		array_merge(
			array(
				'rap_type'     => 'single',
				'rap_start'    => wp_date( 'Y-m-d H:i', $start_ts ),
				'rap_end'      => wp_date( 'Y-m-d H:i', $end_ts ),
				'rap_capacity' => $cap,
				'rap_price'    => '500',
				'rap_currency' => 'CZK',
			),
			$extra
		)
	);
	return $id;
}

function rap_person( $n ) {
	return array(
		'customer_name' => "Test Osoba {$n}",
		'email'         => "osoba{$n}@example.com",
		'phone'         => '777000' . $n,
		'consent_gdpr'  => 1,
		'consent_terms' => 1,
	);
}

require_once ABSPATH . 'wp-admin/includes/template.php';
foreach ( array( 'class-admin.php', 'class-admin-events.php', 'class-admin-settings.php', 'class-admin-services.php', 'class-admin-checkin.php', 'class-admin-payments.php' ) as $f ) {
	require_once RESANDPAY_DIR . 'admin/' . $f;
}

// Zachycení e-mailů (neodesílat).
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['rap_mails'][] = $atts;
		return true;
	},
	10,
	2
);

echo "== Příprava\n";
Resandpay_Reservations::delete_all();
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'resandpay\\_code\\_seq\\_%'" );
wp_cache_flush();
foreach ( get_posts( array( 'post_type' => array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::LOCATION, Resandpay_Post_Types::SERVICE ), 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $pid ) {
	wp_delete_post( $pid, true );
}
Resandpay_Settings::update(
	array(
		'test_mode'         => 1,
		'test_email'        => 'tester@example.com',
		'company_name'      => 'Testovací provozovatel',
		'bank_account'      => '123456789/2700',
		'iban'              => 'CZ6527000000000123456789',
		'due_mode'          => 'booking',
		'due_days'          => 7,
		'due_offset'        => -1,
		'cash_enabled'      => 0,
		'last_minute_hours' => 24,
		'vs_prefix'         => '26',
		'notify_admin'      => 1,
		'admin_email'       => 'admin@example.com',
	)
);
Resandpay_Settings::reset_templates();
Resandpay_Settings::update( array( 'company_name' => 'INSYSTEMS CZ s.r.o.', 'brand_name' => 'S lehkou hlavou…', 'company_ic' => '01919555', 'company_address' => 'Pod višňovkou 1661/31, 140 00 Praha 4', 'contact_web' => 'slehkouhlavou.cz', 'contact_email' => 'hana@slehkouhlavou.cz', 'qr_recipient_name' => '', 'pdf_footer' => 'Nejedná se o daňový doklad' ) );
$loc = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::LOCATION, 'post_status' => 'publish', 'post_title' => 'Studio Brno' ) );
update_post_meta( $loc, '_rap_address', 'Hlavní 1, Brno' );

$now = time();
$day = DAY_IN_SECONDS;

echo "== Kapacita a vytvoření rezervací\n";
$a  = rap_event( 'Sound healing', $now + 10 * $day, $now + 10 * $day + 2 * HOUR_IN_SECONDS, 2, array( 'rap_type' => 'short', 'rap_location' => $loc ) );
$r1 = Resandpay_Reservations::create( $a, rap_person( 1 ) );
$r2 = Resandpay_Reservations::create( $a, rap_person( 2 ) );
$r3 = Resandpay_Reservations::create( $a, rap_person( 3 ) );
rap_t( is_array( $r1 ) && is_array( $r2 ), 'dvě rezervace do kapacity 2 projdou' );
rap_t( is_wp_error( $r3 ) && 'full' === $r3->get_error_code(), 'třetí rezervace je odmítnuta (obsazeno)' );
rap_t( 0 === Resandpay_Events::free_places( $a ), 'volná místa = 0' );
rap_t( 'pending' === $r1['status'], 'nová rezervace má stav Čeká na platbu' );

echo "== Variabilní symbol a číslo pokynu\n";
rap_t( $r1['vs'] !== $r2['vs'], 'každé místo má vlastní VS' );
rap_t( (bool) preg_match( '/^\d{1,10}$/', $r1['vs'] ), 'VS je číselný, max. 10 číslic (' . $r1['vs'] . ')' );
rap_t( 0 === strpos( $r1['vs'], '26' ), 'VS začíná nastaveným prefixem' );
rap_t( (bool) preg_match( '/^PF-\d{4}-\d{6}$/', $r1['proforma_no'] ), 'formát čísla pokynu ' . $r1['proforma_no'] );
rap_t( strlen( $r1['token'] ) >= 40, 'token pro odkazy má min. 40 znaků' );

echo "== Splatnost\n";
$due_exp = ( new DateTimeImmutable( '@' . strtotime( $r1['created_at'] . ' UTC' ) ) );
rap_t( Resandpay_Events::ts( $r1['due_at'] ) <= Resandpay_Events::ts( Resandpay_Events::get( $a )['start'] ), 'splatnost nepřesáhne začátek akce' );
rap_t( '23:59:59' === substr( $r1['due_at'], 11 ), 'splatnost je konec dne' );
rap_t( 0 === (int) $r1['last_minute'], 'rezervace s předstihem není „na poslední chvíli“' );

echo "== Zrušení uvolní místo\n";
$c = Resandpay_Reservations::set_status( $r1['id'], Resandpay_Reservations::CANCELLED, array( 'reason' => 'test', 'notify' => true ) );
rap_t( is_array( $c ) && 'cancelled' === $c['status'], 'rezervace zrušena' );
rap_t( 1 === Resandpay_Events::free_places( $a ), 'po zrušení je 1 volné místo' );
$r4 = Resandpay_Reservations::create( $a, rap_person( 4 ) );
rap_t( is_array( $r4 ), 'na uvolněné místo lze rezervovat' );
rap_t( ! in_array( $r4['vs'], array( $r1['vs'], $r2['vs'] ), true ), 'VS zrušené rezervace se znovu nepoužije' );

echo "== Stavové přechody\n";
$p = Resandpay_Reservations::set_status( $r2['id'], Resandpay_Reservations::PAID, array( 'method' => 'transfer_manual', 'notify' => true ) );
rap_t( is_array( $p ) && 'paid' === $p['status'] && 'transfer_manual' === $p['paid_method'] && $p['paid_at'], 'čeká → zaplaceno (ručně)' );
rap_t( is_wp_error( Resandpay_Reservations::set_status( $r2['id'], Resandpay_Reservations::PENDING ) ), 'zaplaceno → čeká není povoleno' );
rap_t( is_wp_error( Resandpay_Reservations::set_status( $r1['id'], Resandpay_Reservations::PAID ) ), 'zrušeno → zaplaceno není povoleno' );
$e = Resandpay_Reservations::set_status( $r4['id'], Resandpay_Reservations::EXPIRED );
rap_t( is_array( $e ) && 1 === Resandpay_Events::free_places( $a ), 'expirace uvolní místo' );
$r5 = Resandpay_Reservations::create( $a, rap_person( 5 ) );
$re = Resandpay_Reservations::set_status( $r4['id'], Resandpay_Reservations::PAID );
rap_t( is_wp_error( $re ) && 'full' === $re->get_error_code(), 'pozdní platba expirované rezervace při plné akci je odmítnuta (k ručnímu řešení)' );
Resandpay_Reservations::set_status( $r5['id'], Resandpay_Reservations::CANCELLED );
$re = Resandpay_Reservations::set_status( $r4['id'], Resandpay_Reservations::PAID );
rap_t( is_array( $re ) && 'paid' === $re['status'], 'pozdní platba expirované rezervace při volném místě projde' );

echo "== Rezervace na poslední chvíli\n";
$b  = rap_event( 'Masáž zítra', $now + 5 * HOUR_IN_SECONDS, $now + 6 * HOUR_IN_SECONDS, 1, array( 'rap_type' => 'slot' ) );
$lm = Resandpay_Reservations::create( $b, rap_person( 6 ) );
rap_t( is_array( $lm ) && 1 === (int) $lm['last_minute'], 'rezervace 5 h před začátkem je „na poslední chvíli“' );
rap_t( is_array( $lm ) && Resandpay_Events::ts( $lm['due_at'] ) === Resandpay_Events::ts( Resandpay_Events::get( $b )['start'] ), 'splatnost = začátek termínu' );
$o = Resandpay_Reservations::set_status( $lm['id'], Resandpay_Reservations::ONSITE );
rap_t( is_array( $o ) && 0 === Resandpay_Events::free_places( $b ), 'stav Platba na místě blokuje místo' );
$o = Resandpay_Reservations::set_status( $lm['id'], Resandpay_Reservations::PAID, array( 'method' => 'onsite' ) );
rap_t( is_array( $o ) && 'onsite' === $o['paid_method'], 'platba na místě → zaplaceno (na místě)' );

echo "== Rezervovatelnost\n";
$past = rap_event( 'Proběhlá akce', $now - $day, $now - $day + HOUR_IN_SECONDS, 5 );
rap_t( is_wp_error( Resandpay_Reservations::create( $past, rap_person( 7 ) ) ), 'na proběhlou akci nelze rezervovat' );
$cl = rap_event( 'S uzávěrkou', $now + 10 * HOUR_IN_SECONDS, $now + 12 * HOUR_IN_SECONDS, 5, array( 'rap_close_hours' => '24' ) );
$x  = Resandpay_Reservations::create( $cl, rap_person( 8 ) );
rap_t( is_wp_error( $x ) && 'closed' === $x->get_error_code(), 'po uzávěrce nelze rezervovat z webu' );
rap_t( is_array( Resandpay_Reservations::create( $cl, rap_person( 9 ), array( 'ignore_close' => true, 'source' => 'admin' ) ) ), 'ručně v administraci lze rezervovat i po uzávěrce' );
$cn = rap_event( 'Zrušená akce', $now + 3 * $day, $now + 3 * $day + HOUR_IN_SECONDS, 5, array( 'rap_cancelled' => 1 ) );
rap_t( is_wp_error( Resandpay_Reservations::create( $cn, rap_person( 10 ) ) ), 'na zrušenou akci nelze rezervovat' );
rap_t( is_wp_error( Resandpay_Reservations::create( $a, array( 'customer_name' => 'X', 'email' => 'neplatny' ) ) ), 'neplatný e-mail je odmítnut' );

echo "== Typy akcí\n";
$md = rap_event( 'Jógový pobyt Estepona', Resandpay_Events::ts( '2027-05-10 15:00' ), Resandpay_Events::ts( '2027-05-15 11:00' ), 12, array( 'rap_type' => 'multiday' ) );
rap_t( '10. 5. 2027 15:00 – 15. 5. 2027 11:00' === Resandpay_Events::format_date( Resandpay_Events::get( $md ) ), 'vícedenní akce: ' . Resandpay_Events::format_date( Resandpay_Events::get( $md ) ) );
$sh = rap_event( 'Večer 20. 1.', Resandpay_Events::ts( '2027-01-20 16:00' ), Resandpay_Events::ts( '2027-01-20 18:00' ), 10, array( 'rap_type' => 'short' ) );
rap_t( '20. 1. 2027, 16:00–18:00' === Resandpay_Events::format_date( Resandpay_Events::get( $sh ) ), 'krátká akce: ' . Resandpay_Events::format_date( Resandpay_Events::get( $sh ) ) );
$sl = rap_event( 'Termín masáže', $now + 4 * $day, $now + 4 * $day + HOUR_IN_SECONDS, 0, array( 'rap_type' => 'slot' ) );
rap_t( 1 === Resandpay_Events::get( $sl )['capacity'], 'individuální termín bez kapacity dostane kapacitu 1' );

echo "== Překryvy (jen upozornění)\n";
$ov = rap_event( 'Překrývající workshop', $now + 10 * $day + HOUR_IN_SECONDS, $now + 10 * $day + 3 * HOUR_IN_SECONDS, 5 );
$ol = wp_list_pluck( Resandpay_Events::overlaps( $ov ), 'id' );
rap_t( in_array( $a, $ol, true ), 'překryv s akcí Sound healing je detekován' );
rap_t( is_array( Resandpay_Reservations::create( $ov, rap_person( 11 ) ) ), 'překryv nebrání rezervaci' );
rap_t( ! in_array( $md, $ol, true ), 'nepřekrývající se akce není hlášena' );

echo "== E-maily a testovací režim\n";
$received = array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Rezervace přijata' ) );
$first    = reset( $received );
rap_t( count( $received ) >= 3, 'e-mail o přijetí rezervace se odesílá' );
rap_t( $first && 'tester@example.com' === $first['to'], 'v testovacím režimu jde e-mail jen na testovací adresu' );
rap_t( $first && false !== strpos( $first['subject'], '[RAP TEST → osoba' ), 'předmět obsahuje zkratku RAP a původního příjemce' );
rap_t( $first && (bool) preg_match( '/Variabilní symbol<\/td><td[^>]*>26\d+/', $first['message'] ), 'e-mail obsahuje variabilní symbol v platebních údajích' );
rap_t( $first && false !== strpos( $first['message'], '123456789/2700' ), 'text obsahuje číslo účtu z nastavení' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Nová rezervace' ) ), 'správce dostává upozornění na novou rezervaci' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Platba přijata' ) ), 'e-mail o zaplacení se odesílá' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Rezervace zrušena' ) && false !== strpos( $m['message'], 'Důvod: test' ) ), 'e-mail o zrušení obsahuje důvod' );
$lm_mail = array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Masáž zítra' ) && false !== strpos( $m['message'], 'zaplatit na místě' ) );
rap_t( (bool) $lm_mail, 'e-mail u rezervace na poslední chvíli nabízí platbu na místě' );
Resandpay_Settings::update( array( 'test_mode' => 0 ) );
$GLOBALS['rap_mails'] = array();
Resandpay_Reservations::create( $md, rap_person( 12 ) );
rap_t( isset( $GLOBALS['rap_mails'][0] ) && 'osoba12@example.com' === $GLOBALS['rap_mails'][0]['to'], 'mimo testovací režim jde e-mail skutečnému příjemci' );
Resandpay_Settings::update( array( 'test_mode' => 1 ) );

echo "== Nastavení – částečná aktualizace\n";
$before = Resandpay_Settings::get( 'iban' );
update_option( Resandpay_Settings::OPTION, Resandpay_Admin_Settings::sanitize( array( 'due_days' => '5' ) ) );
rap_t( $before === Resandpay_Settings::get( 'iban' ) && 5.0 === (float) Resandpay_Settings::get( 'due_days' ), 'uložení části nastavení nesmaže ostatní hodnoty' );
Resandpay_Settings::update( array( 'due_days' => 7 ) );

echo "== E2: výpis, kalendář, duplikace\n";
$term = wp_insert_term( 'Jóga eventy', Resandpay_Post_Types::CATEGORY );
$tid  = is_wp_error( $term ) ? (int) $term->get_error_data() : (int) $term['term_id'];
wp_set_object_terms( $md, array( $tid ), Resandpay_Post_Types::CATEGORY );
$list = Resandpay_Views::render_list( array( 'limit' => 50, 'category' => '' ) );
rap_t( false !== strpos( $list, 'Jógový pobyt Estepona' ) && false !== strpos( $list, 'Rezervovat termín →' ), 'seznam akcí obsahuje akci a tlačítko „Rezervovat termín →“' );
rap_t( false === strpos( $list, 'Termín masáže' ), 'seznam neobsahuje individuální termíny' );
rap_t( false === strpos( $list, 'Proběhlá akce' ), 'seznam neobsahuje proběhlé akce' );
rap_t( false !== strpos( $list, 'Obsazeno' ), 'obsazená akce je označena „Obsazeno“' );
$flt = Resandpay_Views::render_list( array( 'limit' => 50, 'category' => 'joga-eventy' ) );
rap_t( false !== strpos( $flt, 'Jógový pobyt Estepona' ) && false === strpos( $flt, 'Sound healing' ), 'filtr kategorie funguje' );
rap_t( false !== strpos( Resandpay_Views::render_filter( '', home_url( '/' ) ), 'Jóga eventy' ), 'filtr nabízí kategorie' );
$_GET['rap_m'] = '2027-05';
$cal = Resandpay_Views::render_calendar( array( 'category' => '' ) );
unset( $_GET['rap_m'] );
rap_t( false !== strpos( $cal, 'květen' ) && false !== strpos( $cal, '2027' ), 'kalendář zobrazuje zvolený měsíc' );
rap_t( substr_count( $cal, 'Jógový pobyt Estepona' ) >= 6, 'vícedenní akce je v kalendáři u každého dne' );
rap_t( false !== strpos( $cal, '15:00 Jógový pobyt' ), 'první den vícedenní akce má čas začátku' );
$_GET['rap_view'] = 'calendar';
rap_t( false !== strpos( Resandpay_Views::render_program( array( 'limit' => 50, 'category' => '', 'filter' => 'yes', 'view' => 'list', 'include_slots' => '' ) ), 'rap-calendar__grid' ), 'přepínač zobrazí kalendář' );
unset( $_GET['rap_view'] );
rap_t( 'so 20. 1. 2027 · 16:00–18:00' === Resandpay_Views::when_line( Resandpay_Events::get( $sh ) ) || 'st 20. 1. 2027 · 16:00–18:00' === Resandpay_Views::when_line( Resandpay_Events::get( $sh ) ), 'řádek termínu krátké akce: ' . Resandpay_Views::when_line( Resandpay_Events::get( $sh ) ) );
$dup = Resandpay_Admin_Events::duplicate( $md );
$dev = Resandpay_Events::get( $dup );
rap_t( ! is_wp_error( $dup ) && 'draft' === $dev['status'] && $dev['start'] === Resandpay_Events::get( $md )['start'] && 12 === $dev['capacity'], 'duplikace vytvoří koncept se stejnými údaji' );
rap_t( 0 === Resandpay_Reservations::count_active( $dup ), 'duplikát nemá žádné rezervace' );
rap_t( in_array( $tid, wp_get_object_terms( $dup, Resandpay_Post_Types::CATEGORY, array( 'fields' => 'ids' ) ), true ), 'duplikát převezme kategorii' );
rap_t( array( 'začátek', 'konec', 'kapacita' ) === Resandpay_Admin_Events::missing( array( 'start' => '', 'end' => '', 'capacity' => 0 ) ), 'kontrola chybějících údajů akce' );

echo "== E3: služby a generátor termínů\n";
$svc_id = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Bukální masáž', 'post_content' => 'Uvolnění obličejových svalů zevnitř i zvenku.' ) );
Resandpay_Admin_Services::save_service_meta( $svc_id, array( 'rap_duration' => 60, 'rap_pause' => 15, 'rap_price' => '1200', 'rap_currency' => 'CZK', 'rap_location' => $loc ) );
wp_set_object_terms( $svc_id, array( $tid ), Resandpay_Post_Types::CATEGORY );
$gen = array( 'weekdays' => array( 2, 4 ), 'time_from' => '09:00', 'time_to' => '12:00', 'date_from' => '2027-03-01', 'date_to' => '2027-03-14' );
$pv  = Resandpay_Services::preview( $svc_id, $gen );
rap_t( is_array( $pv ) && 8 === count( $pv ), 'náhled: út+čt 9–12 h, 60+15 min, 2 týdny = 8 termínů (' . ( is_array( $pv ) ? count( $pv ) : 'chyba' ) . ')' );
rap_t( is_array( $pv ) && '2027-03-02 09:00:00' === $pv[0]['start'] && '2027-03-02 10:15:00' === $pv[1]['start'], 'termíny respektují délku a pauzu (9:00, 10:15)' );
$blocker = rap_event( 'Workshop v úterý', Resandpay_Events::ts( '2027-03-02 09:30:00' ), Resandpay_Events::ts( '2027-03-02 10:00:00' ), 5 );
$pv  = Resandpay_Services::preview( $svc_id, $gen );
rap_t( ! empty( $pv[0]['overlaps'] ) && empty( $pv[1]['overlaps'] ), 'náhled označí termín překrývající se s jinou akcí' );
$cr  = Resandpay_Services::create_slots( $svc_id, wp_list_pluck( $pv, 'start' ) );
rap_t( 8 === $cr['created'] && 0 === $cr['skipped'], 'vytvořeno 8 termínů i s překryvem (překryv nebrání)' );
$cr2 = Resandpay_Services::create_slots( $svc_id, wp_list_pluck( $pv, 'start' ) );
rap_t( 0 === $cr2['created'] && 8 === $cr2['skipped'], 'opakované generování duplicitní termíny přeskočí' );
$pv2 = Resandpay_Services::preview( $svc_id, $gen );
rap_t( 8 === count( array_filter( $pv2, fn( $x ) => $x['exists'] ) ), 'náhled ukazuje existující termíny' );
$tzz   = wp_timezone();
$slots = Resandpay_Services::slots( $svc_id, date_create_immutable( '2027-03-01 00:00:00', $tzz ), date_create_immutable( '2027-03-15 00:00:00', $tzz ) );
$s0    = reset( $slots );
rap_t( 8 === count( $slots ) && 'slot' === $s0['type'] && 1 === $s0['capacity'] && 1200.0 === $s0['price'] && $loc === $s0['location_id'], 'termín: typ slot, kapacita 1, cena a místo ze služby' );
rap_t( '2027-03-02 10:00:00' === $s0['end'], 'konec termínu = začátek + 60 min' );
rap_t( in_array( $tid, wp_get_object_terms( $s0['id'], Resandpay_Post_Types::CATEGORY, array( 'fields' => 'ids' ) ), true ), 'termín převezme kategorii služby' );
Resandpay_Admin_Services::save_service_meta( $svc_id, array( 'rap_duration' => 90, 'rap_pause' => 15, 'rap_price' => '1500', 'rap_currency' => 'CZK', 'rap_location' => $loc ) );
rap_t( '2027-03-02 10:00:00' === Resandpay_Events::get( $s0['id'] )['end'] && 1200.0 === Resandpay_Events::get( $s0['id'] )['price'], 'změna délky a ceny služby nemění existující termíny' );
$pv3 = Resandpay_Services::preview( $svc_id, array_merge( $gen, array( 'date_from' => '2027-03-15', 'date_to' => '2027-03-21' ) ) );
rap_t( 2 === count( $pv3 ) && '2027-03-16 10:30:00' === $pv3[0]['end'], 'nové termíny už mají délku 90 min (1 denně v okně 9–12)' );
$free = Resandpay_Services::free_slots( $svc_id, date_create_immutable( '2027-03-01 00:00:00', $tzz ), date_create_immutable( '2027-03-15 00:00:00', $tzz ) );
rap_t( 8 === count( $free ), 'volných termínů 8' );
$bk = Resandpay_Reservations::create( $s0['id'], rap_person( 20 ) );
rap_t( is_array( $bk ) && 0 === Resandpay_Events::free_places( $s0['id'] ), 'rezervace termínu ho obsadí' );
rap_t( is_wp_error( Resandpay_Reservations::create( $s0['id'], rap_person( 21 ) ) ), 'obsazený termín nelze rezervovat podruhé' );
$_GET['rap_from'] = '2027-03-01';
$html = Resandpay_Views::render_slots( array( 'service' => $svc_id, 'days' => 14 ) );
unset( $_GET['rap_from'] );
rap_t( false !== strpos( $html, '10:15' ) && false !== strpos( $html, 'út 2. 3.' ), 'stránka volných termínů ukazuje dny a časy' );
rap_t( 7 === substr_count( $html, 'rap-chip rap-slot' ), 'obsazený termín se mezi volnými nezobrazuje (7 z 8)' );
rap_t( false !== strpos( Resandpay_Views::render_slots( array( 'service' => 'bukalni-masaz', 'days' => 21 ) ), 'Bukální masáž' ), 'shortcode najde službu i podle slugu' );
$svc_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Masáž – stránka', 'post_content' => '[reserveandpay_slots service="bukalni-masaz"]' ) );
update_post_meta( $svc_id, '_rap_page', $svc_page );
$list = Resandpay_Views::render_list( array( 'limit' => 100, 'category' => '' ) );
rap_t( 1 === substr_count( $list, 'rap-item--service' ), 'služba je v přehledu akcí jako jedna souhrnná karta' );
rap_t( false !== strpos( $list, 'Nejbližší volný termín: út 2. 3. 2027 10:15' ), 'karta služby ukazuje nejbližší volný termín (obsazený 9:00 přeskočí)' );
rap_t( false !== strpos( $list, 'Volných termínů: 7' ) && false !== strpos( $list, 'Vybrat termín →' ), 'karta ukazuje počet volných termínů a tlačítko' );
rap_t( false !== strpos( $list, get_permalink( $svc_page ) . '#rap-slots' ), 'karta odkazuje na stránku s volnými termíny' );
rap_t( strpos( $list, 'Bukální masáž' ) > strpos( $list, 'Večer 20. 1.' ) && strpos( $list, 'Bukální masáž' ) < strpos( $list, 'Jógový pobyt Estepona' ), 'karta služby je zařazena podle data nejbližšího termínu' );
$cat_list = Resandpay_Views::render_list( array( 'limit' => 100, 'category' => 'joga-eventy' ) );
rap_t( false !== strpos( $cat_list, 'rap-item--service' ), 'filtr kategorie služby zobrazí kartu služby' );
rap_t( false === strpos( Resandpay_Views::render_list( array( 'limit' => 100, 'category' => '', 'services' => 'no' ) ), 'rap-item--service' ), 'parametr services="no" karty služeb vypne' );
$_GET['rap_m'] = '2027-03';
$cal_cat = Resandpay_Views::render_calendar( array( 'category' => 'joga-eventy' ) );
$cal_all = Resandpay_Views::render_calendar( array( 'category' => '' ) );
unset( $_GET['rap_m'] );
rap_t( false !== strpos( $cal_cat, 'rap-item--service' ) && false === strpos( $cal_all, 'rap-item--service' ), 'kalendář ukáže kartu služby jen při filtru kategorie' );
rap_t( false === strpos( $cal_all, '10:15 Bukální' ), 'jednotlivé termíny služby nejsou v kalendáři' );
$GLOBALS['rap_mails'] = array();
$dry = Resandpay_Services::bulk_cancel( $svc_id, date_create_immutable( '2027-03-01 00:00:00', $tzz ), date_create_immutable( '2027-03-05 23:59:59', $tzz ), true, 'nemoc', true );
rap_t( 3 === count( $dry['free'] ) && 1 === count( $dry['booked'] ), 'hromadné rušení – náhled: 3 volné, 1 obsazený' );
rap_t( 8 === count( Resandpay_Services::slots( $svc_id, date_create_immutable( '2027-03-01 00:00:00', $tzz ), date_create_immutable( '2027-03-15 00:00:00', $tzz ) ) ), 'náhled nic nezmění' );
Resandpay_Services::bulk_cancel( $svc_id, date_create_immutable( '2027-03-01 00:00:00', $tzz ), date_create_immutable( '2027-03-05 23:59:59', $tzz ), true, 'nemoc' );
rap_t( 5 === count( Resandpay_Services::slots( $svc_id, date_create_immutable( '2027-03-01 00:00:00', $tzz ), date_create_immutable( '2027-03-15 00:00:00', $tzz ) ) ), 'volné termíny v období odstraněny (zbývá 5)' );
rap_t( Resandpay_Events::get( $s0['id'] )['cancelled'] && 'cancelled' === Resandpay_Reservations::get( $bk['id'] )['status'], 'obsazený termín zrušen včetně rezervace' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Rezervace zrušena' ) && false !== strpos( $m['message'], 'nemoc' ) ), 'zákaznice dostane e-mail o zrušení s důvodem' );

echo "== 0.3.2: počet míst na termín služby (skupinové lekce)\n";
rap_t( 1 === Resandpay_Services::get( $svc_id )['capacity'], 'služba bez nastavení má 1 místo (masáž)' );
$grp = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Sound Healing', 'post_content' => 'Zvuková lázeň.' ) );
Resandpay_Admin_Services::save_service_meta( $grp, array( 'rap_duration' => 90, 'rap_pause' => 30, 'rap_capacity' => 15, 'rap_price' => '450', 'rap_currency' => 'CZK', 'rap_location' => $loc ) );
rap_t( 15 === Resandpay_Services::get( $grp )['capacity'], 'služba Sound Healing má 15 míst' );
$gpv = Resandpay_Services::preview( $grp, array( 'weekdays' => array( 3 ), 'time_from' => '18:00', 'time_to' => '19:30', 'date_from' => '2027-04-01', 'date_to' => '2027-04-30' ) );
$gcr = Resandpay_Services::create_slots( $grp, wp_list_pluck( $gpv, 'start' ) );
$gsl = Resandpay_Services::slots( $grp, date_create_immutable( '2027-04-01 00:00:00', $tzz ), date_create_immutable( '2027-05-01 00:00:00', $tzz ) );
$g0  = reset( $gsl );
rap_t( 4 === $gcr['created'] && 15 === $g0['capacity'], 'generátor: 4 středy v dubnu, každý termín s 15 místy' );
$GLOBALS['rap_mails'] = array();
$gb1 = Resandpay_Reservations::create( $g0['id'], rap_person( 30 ) );
$gb2 = Resandpay_Reservations::create( $g0['id'], rap_person( 31 ) );
rap_t( is_array( $gb1 ) && is_array( $gb2 ) && $gb1['vs'] !== $gb2['vs'] && 13 === Resandpay_Events::free_places( $g0['id'] ), 'dvě rezervace na stejný termín, každá s vlastním VS, zbývá 13 míst' );
rap_t( 4 === count( Resandpay_Services::free_slots( $grp, date_create_immutable( '2027-04-01 00:00:00', $tzz ), date_create_immutable( '2027-05-01 00:00:00', $tzz ) ) ), 'částečně obsazený termín zůstává mezi volnými' );
$_GET['rap_from'] = '2027-04-01';
$ghtml = Resandpay_Views::render_slots( array( 'service' => $grp, 'days' => 30 ) );
unset( $_GET['rap_from'] );
rap_t( false !== strpos( $ghtml, '13 volných míst' ) && false !== strpos( $ghtml, '15 volných míst' ) && false !== strpos( $ghtml, 'max. 15 osob' ), 'stránka termínů ukazuje volná místa a kapacitu' );
rap_t( false === strpos( Resandpay_Views::render_slots( array( 'service' => $svc_id, 'days' => 30 ) ), 'volných míst' ), 'u masáže (1 místo) se počet míst neukazuje' );
rap_t( '1 volné místo' === Resandpay_Views::places_label( 1 ) && '3 volná místa' === Resandpay_Views::places_label( 3 ) && '5 volných míst' === Resandpay_Views::places_label( 5 ), 'české skloňování počtu míst' );
$glist = Resandpay_Views::render_list( array( 'limit' => 100, 'category' => '' ) );
rap_t( false !== strpos( $glist, 'Nejbližší: volno 13 z 15' ), 'karta skupinové služby ukazuje volná místa nejbližšího termínu' );
$_GET['rap_done'] = '';
$det = Resandpay_Frontend::render_details( $g0['id'] );
rap_t( false !== strpos( $det, '13 z 15' ), 'detail termínu ukazuje „Volná místa 13 z 15“' );
for ( $i = 0; $i < 13; $i++ ) {
	Resandpay_Reservations::create( $g0['id'], rap_person( 40 + $i ) );
}
rap_t( is_wp_error( Resandpay_Reservations::create( $g0['id'], rap_person( 60 ) ) ) && 3 === count( Resandpay_Services::free_slots( $grp, date_create_immutable( '2027-04-01 00:00:00', $tzz ), date_create_immutable( '2027-05-01 00:00:00', $tzz ) ) ), 'po zaplnění 15 míst termín zmizí z volných a další rezervace je odmítnuta' );
Resandpay_Admin_Services::save_service_meta( $grp, array( 'rap_duration' => 90, 'rap_pause' => 30, 'rap_capacity' => 12, 'rap_price' => '450', 'rap_currency' => 'CZK', 'rap_location' => $loc ) );
rap_t( 15 === Resandpay_Events::get( $g0['id'] )['capacity'], 'změna kapacity služby bez volby nemění existující termíny' );
$ap = Resandpay_Services::apply_capacity( $grp, 12 );
rap_t( 3 === $ap['updated'] && 1 === count( $ap['skipped'] ) && 15 === Resandpay_Events::get( $g0['id'] )['capacity'] && 12 === Resandpay_Events::get( $gsl[ array_keys( $gsl )[1] ]['id'] )['capacity'], 'promítnutí kapacity: 3 termíny upraveny, plně obsazený (15 > 12) ponechán' );
Resandpay_Admin_Services::save_service_meta( $grp, array( 'rap_capacity' => 0 ) );
$c0 = Resandpay_Services::get( $grp )['capacity'];
Resandpay_Admin_Services::save_service_meta( $grp, array( 'rap_capacity' => 9999 ) );
rap_t( 1 === $c0 && 500 === Resandpay_Services::get( $grp )['capacity'], 'kapacita služby je omezena na 1–500' );

echo "== 0.3.3: pravidla splatnosti\n";
$ts  = fn( $s ) => Resandpay_Events::ts( $s );
$dE  = rap_event( 'Lekce 10. 6.', $ts( '2027-06-10 18:00:00' ), $ts( '2027-06-10 19:30:00' ), 20 );
$n0  = $ts( '2027-06-01 10:00:00' );
$due = function ( $over ) use ( $dE, $n0 ) {
	$ev = array_merge( Resandpay_Events::get( $dE ), $over );
	return wp_date( 'Y-m-d H:i:s', Resandpay_Events::compute_due( $ev, $n0 )['due_ts'] );
};
rap_t( '2027-06-08 23:59:59' === $due( array( 'due_mode' => 'booking', 'due_days' => 7 ) ), 'od rezervace: 1. 6. + 7 dní = 8. 6. do konce dne' );
rap_t( '2027-06-10 18:00:00' === $due( array( 'due_mode' => 'booking', 'due_days' => 30 ) ), 'od rezervace: nejpozději začátek termínu' );
rap_t( '2027-06-09 23:59:59' === $due( array( 'due_mode' => 'event', 'due_offset' => -1 ) ), 'vůči termínu −1: den před termínem' );
rap_t( '2027-06-10 23:59:59' === $due( array( 'due_mode' => 'event', 'due_offset' => 0 ) ), 'vůči termínu 0: v den termínu' );
rap_t( '2027-06-11 23:59:59' === $due( array( 'due_mode' => 'event', 'due_offset' => 1 ) ), 'vůči termínu +1: den po termínu' );
rap_t( '2027-06-07 23:59:59' === $due( array( 'due_mode' => 'combined', 'due_days' => 7, 'due_offset' => -3 ) ), 'kombinace: dřívější z (8. 6., 7. 6.)' );
$lmE = Resandpay_Events::compute_due( array_merge( Resandpay_Events::get( $dE ), array( 'due_mode' => 'event', 'due_offset' => -1 ) ), $ts( '2027-06-10 10:00:00' ) );
rap_t( $lmE['last_minute'] && $ts( '2027-06-10 18:00:00' ) === $lmE['due_ts'], 'vůči termínu −1, rezervace v den termínu → poslední chvíle, splatnost = začátek' );
$lmP = Resandpay_Events::compute_due( array_merge( Resandpay_Events::get( $dE ), array( 'due_mode' => 'event', 'due_offset' => 1 ) ), $ts( '2027-06-10 16:00:00' ) );
rap_t( ! $lmP['last_minute'] && '2027-06-11 23:59:59' === wp_date( 'Y-m-d H:i:s', $lmP['due_ts'] ), 'platba po termínu (+1): krátce před začátkem není „poslední chvíle“' );
$lt  = function ( $now_s, $over = array() ) use ( $dE, $ts ) {
	return Resandpay_Events::compute_due( array_merge( Resandpay_Events::get( $dE ), array( 'due_mode' => 'event', 'due_offset' => -30 ), $over ), $ts( $now_s ) );
};
$l1 = $lt( '2027-05-27 10:00:00' );
rap_t( $l1['late'] && ! $l1['last_minute'] && '2027-06-03 23:59:59' === wp_date( 'Y-m-d H:i:s', $l1['due_ts'] ), 'pozdní rezervace (−30, rezervace 14 dní předem) → celá částka do 7 dní (0.6.1)' );
$l2 = $lt( '2027-06-06 10:00:00' );
rap_t( $l2['late'] && '2027-06-09 23:59:59' === wp_date( 'Y-m-d H:i:s', $l2['due_ts'] ), 'pozdní rezervace: nejpozději den před akcí' );
$l3 = $lt( '2027-05-27 10:00:00', array( 'part1_days' => 3 ) );
rap_t( '2027-05-30 23:59:59' === wp_date( 'Y-m-d H:i:s', $l3['due_ts'] ), 'pozdní rezervace: lhůta podle „1. část splatná do“' );
$l4 = $lt( '2027-06-10 10:00:00' );
rap_t( $l4['last_minute'] && ! $l4['late'], 'méně než 24 h před začátkem zůstává „poslední chvíle“' );
$l5 = $lt( '2027-06-09 12:00:00' );
rap_t( ! $l5['last_minute'] && $l5['late'] && '2027-06-09 23:59:59' === wp_date( 'Y-m-d H:i:s', $l5['due_ts'] ), '30 h před začátkem: splatnost do konce dne před akcí' );
Resandpay_Settings::update( array( 'due_mode' => 'event', 'due_offset' => -2 ) );
rap_t( 'event' === Resandpay_Events::get( $dE )['due_mode'] && -2 === Resandpay_Events::get( $dE )['due_offset'], 'akce bez vlastního pravidla přebírá výchozí z nastavení' );
$rD = Resandpay_Reservations::create( $dE, rap_person( 70 ), array( 'now' => $n0 ) );
rap_t( '2027-06-08 23:59:59' === $rD['due_at'], 'rezervace dostane splatnost podle nastavení (termín −2 dny)' );
Resandpay_Admin_Events::save_event_meta( $dE, array( 'rap_type' => 'single', 'rap_start' => '2027-06-10 18:00', 'rap_end' => '2027-06-10 19:30', 'rap_capacity' => 20, 'rap_price' => 500, 'rap_currency' => 'CZK', 'rap_due_mode' => 'event', 'rap_due_offset' => '1' ) );
$rD2 = Resandpay_Reservations::create( $dE, rap_person( 71 ), array( 'now' => $n0 ) );
rap_t( '2027-06-11 23:59:59' === $rD2['due_at'], 'pravidlo uložené u akce přepíše nastavení (+1 den po termínu)' );
Resandpay_Settings::update( array( 'due_mode' => 'booking', 'due_offset' => -1 ) );
rap_t( 'termín +1 den' === Resandpay_Events::due_rule_text( 'event', 7, 1 ) && 'v den termínu' === Resandpay_Events::due_rule_text( 'event', 7, 0 ), 'čitelný popis pravidla' );

echo "== 0.3.3: stav Po splatnosti\n";
$past = rap_event( 'Proběhlá lekce', $now - 3 * $day, $now - 3 * $day + HOUR_IN_SECONDS, 10, array( 'rap_due_mode' => 'event', 'rap_due_offset' => '1' ) );
$rO   = Resandpay_Reservations::create( $past, rap_person( 72 ), array( 'now' => $now - 6 * $day, 'notify' => false ) );
$pastB = rap_event( 'Proběhlá lekce B', $now - 3 * $day, $now - 3 * $day + HOUR_IN_SECONDS, 10, array( 'rap_due_mode' => 'booking', 'rap_due_days' => '1' ) );
$rB   = Resandpay_Reservations::create( $pastB, rap_person( 73 ), array( 'now' => $now - 6 * $day, 'notify' => false ) );
$rL   = Resandpay_Reservations::create( $pastB, rap_person( 74 ), array( 'now' => $now - 3 * $day - HOUR_IN_SECONDS, 'notify' => false ) );
$GLOBALS['rap_mails'] = array();
$cnt  = Resandpay_Reservations::process_due();
$rO   = Resandpay_Reservations::get( $rO['id'] );
rap_t( $cnt >= 1 && 'overdue' === $rO['status'] && $rO['reminded_at'], 'nezaplacená rezervace se splatností po termínu → Po splatnosti' );
rap_t( 1 === count( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Připomínka platby' ) && false !== strpos( $m['message'], $rO['vs'] ) ) ), 'zákaznice dostane jednu připomínku s VS' );
rap_t( 'expired' === Resandpay_Reservations::get( $rB['id'] )['status'], 'splatnost před termínem → po splatnosti expiruje (0.6.0)' );
rap_t( 1 === (int) $rL['last_minute'] && 'onsite' === Resandpay_Reservations::get( $rL['id'] )['status'], 'rezervace na poslední chvíli bez platby → Platba na místě' );
$GLOBALS['rap_mails'] = array();
Resandpay_Reservations::process_due();
rap_t( ! $GLOBALS['rap_mails'], 'opakovaný běh připomínku znovu neposílá' );
rap_t( 1 === Resandpay_Reservations::count_active( $pastB ) && 1 === Resandpay_Reservations::count_active( $past ), 'rezervace Po splatnosti dál blokuje místo, expirovaná ne' );
rap_t( 'paid' === Resandpay_Reservations::set_status( $rO['id'], Resandpay_Reservations::PAID )['status'], 'po splatnosti → zaplaceno je povoleno' );

echo "== 0.3.3: platba v hotovosti\n";
$cE = rap_event( 'Sound Healing večer', $now + 12 * $day, $now + 12 * $day + 2 * HOUR_IN_SECONDS, 15 );
$GLOBALS['rap_mails'] = array();
$rT = Resandpay_Reservations::create( $cE, array_merge( rap_person( 80 ), array( 'pay_choice' => 'cash' ) ) );
rap_t( 'pending' === $rT['status'] && 'transfer' === $rT['pay_choice'], 'hotovost vypnutá: volba hotovosti z webu se ignoruje (převod)' );
rap_t( false === strpos( Resandpay_Frontend::render_booking( $cE ), 'name="rap_pay"' ), 'hotovost vypnutá: formulář volbu platby neukazuje' );
Resandpay_Settings::update( array( 'cash_enabled' => 1 ) );
$form = Resandpay_Frontend::render_booking( $cE );
rap_t( (bool) preg_match( '/value="transfer"\s+checked/', $form ) && ! preg_match( '/value="cash"\s+checked/', $form ) && strpos( $form, 'value="transfer"' ) < strpos( $form, 'value="cash"' ), 'hotovost zapnutá: převod je první a předvybraný, hotovost doplňková' );
$GLOBALS['rap_mails'] = array();
$rC = Resandpay_Reservations::create( $cE, array_merge( rap_person( 81 ), array( 'pay_choice' => 'cash' ) ) );
rap_t( 'onsite' === $rC['status'] && 'cash' === $rC['pay_choice'] && 13 === Resandpay_Events::free_places( $cE ), 'volba hotovosti → stav Platba na místě, místo blokováno' );
$mc = $GLOBALS['rap_mails'][0] ?? array( 'message' => '', 'subject' => '' );
rap_t( false !== strpos( $mc['message'], 'hotovosti na místě' ) && false !== strpos( $mc['message'], '123456789/2700' ) && false !== strpos( $mc['message'], $rC['vs'] ), 'e-mail u hotovosti: potvrzení + údaje pro převod předem (QR v E4)' );
update_post_meta( $cE, '_rap_cash', '0' );
rap_t( 'transfer' === Resandpay_Reservations::create( $cE, array_merge( rap_person( 82 ), array( 'pay_choice' => 'cash' ) ), array( 'notify' => false ) )['pay_choice'], 'u akce lze hotovost vypnout i při zapnutém nastavení' );
Resandpay_Settings::update( array( 'cash_enabled' => 0 ) );
update_post_meta( $cE, '_rap_cash', '1' );
rap_t( Resandpay_Events::get( $cE )['cash'], 'u akce lze hotovost zapnout i při vypnutém nastavení' );
$svc2 = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Masáž s hotovostí' ) );
Resandpay_Admin_Services::save_service_meta( $svc2, array( 'rap_duration' => 60, 'rap_pause' => 15, 'rap_capacity' => 1, 'rap_price' => 1300, 'rap_due_mode' => 'event', 'rap_due_offset' => '0', 'rap_cash' => '1' ) );
$pv5 = Resandpay_Services::preview( $svc2, array( 'weekdays' => array( 1 ), 'time_from' => '09:00', 'time_to' => '10:00', 'date_from' => '2027-07-05', 'date_to' => '2027-07-05' ) );
Resandpay_Services::create_slots( $svc2, wp_list_pluck( $pv5, 'start' ) );
$sl5 = array_values( Resandpay_Services::slots( $svc2, date_create_immutable( '2027-07-05 00:00:00', $tzz ), date_create_immutable( '2027-07-06 00:00:00', $tzz ) ) );
rap_t( $sl5 && $sl5[0]['cash'] && 'event' === $sl5[0]['due_mode'] && 0 === $sl5[0]['due_offset'], 'termín služby převezme pravidlo splatnosti a hotovost ze služby' );

echo "== 0.3.3: odbavení na místě\n";
rap_t( (int) $rC['id'] === (int) Resandpay_Reservations::find_by_vs_input( $rC['vs'] )['id'], 'vyhledání podle VS' );
rap_t( (int) $rC['id'] === (int) Resandpay_Reservations::find_by_vs_input( 'SPD*1.0*ACC:CZ6527000000000123456789*AM:500.00*CC:CZK*X-VS:' . $rC['vs'] . '*MSG:test' )['id'], 'vyhledání z textu QR platby (X-VS)' );
rap_t( (int) $rC['id'] === (int) Resandpay_Reservations::find_by_vs_input( ' ' . chunk_split( $rC['vs'], 4, ' ' ) )['id'], 'VS s mezerami' );
rap_t( null === Resandpay_Reservations::find_by_vs_input( '999' ) && null === Resandpay_Reservations::find_by_vs_input( 'abc' ), 'neexistující VS nic nenajde' );
$_GET = array( 'page' => 'reserveandpay-checkin', 'day' => wp_date( 'Y-m-d', $now + 12 * $day ), 'vs' => $rC['vs'] );
ob_start();
Resandpay_Admin_Checkin::page();
$ck = ob_get_clean();
$_GET = array();
rap_t( false !== strpos( $ck, 'Test Osoba 81' ) && false !== strpos( $ck, 'Zaplaceno hotově' ) && false !== strpos( $ck, 'Hotově na místě' ), 'stránka odbavení ukáže kartu rezervace s tlačítky' );
rap_t( false !== strpos( $ck, 'Sound Healing večer' ) && false !== strpos( $ck, 'k vybrání na místě: 1' ), 'přehled dne: rezervace akce a částka k vybrání' );
$pd = Resandpay_Admin_Checkin::mark_paid( $rC['id'], 'cash' );
rap_t( 'paid' === $pd['status'] && 'cash' === $pd['paid_method'], 'odbavení: zaplaceno hotově' );
rap_t( is_wp_error( Resandpay_Admin_Checkin::mark_paid( $rC['id'], 'cash' ) ), 'opakované odbavení zaplacené rezervace je odmítnuto' );
$pt = Resandpay_Admin_Checkin::mark_paid( $rT['id'], 'transfer' );
rap_t( 'paid' === $pt['status'] && 'transfer_manual' === $pt['paid_method'], 'odbavení: zaplaceno převodem (ověřeno na místě)' );
rap_t( is_wp_error( Resandpay_Admin_Checkin::mark_paid( $r1['id'], 'cash' ) ), 'zrušenou rezervaci nelze odbavit' );

echo "== 0.3.4: kódy akcí, služeb a termínů\n";
$yr = wp_date( 'Y' );
$ca = Resandpay_Events::get( $a )['code'];
rap_t( (bool) preg_match( '/^AK-' . $yr . '-\d{4}$/', $ca ), 'akce má kód AK-RRRR-NNNN (' . $ca . ')' );
rap_t( 'AK-' . $yr . '-0001' === $ca, 'první akce v roce má číslo 0001' );
$cs = Resandpay_Services::get( $svc_id )['code'];
rap_t( (bool) preg_match( '/^SL-\d{3}$/', $cs ), 'služba má kód SL-NNN (' . $cs . ')' );
$ct = $s0['id'] ? Resandpay_Events::get( $s0['id'] )['code'] : '';
rap_t( (bool) preg_match( '/^TE-' . $yr . '-\d{4}$/', $ct ), 'termín služby má kód TE-RRRR-NNNN (' . $ct . ')' );
$all_codes = array();
foreach ( get_posts( array( 'post_type' => array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::SERVICE ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $pid ) {
	$all_codes[] = Resandpay_Codes::get( $pid );
}
rap_t( ! in_array( '', $all_codes, true ) && count( $all_codes ) === count( array_unique( $all_codes ) ), 'všechny akce, služby a termíny mají kód a žádný se neopakuje (' . count( $all_codes ) . ')' );
$before = Resandpay_Events::get( $a )['code'];
Resandpay_Admin_Events::save_event_meta( $a, array( 'rap_type' => 'short', 'rap_start' => wp_date( 'Y-m-d H:i', $now + 15 * $day ), 'rap_end' => wp_date( 'Y-m-d H:i', $now + 15 * $day + 7200 ), 'rap_capacity' => 3, 'rap_price' => 600, 'rap_currency' => 'CZK' ) );
wp_update_post( array( 'ID' => $a, 'post_title' => 'Sound healing – přejmenováno' ) );
rap_t( $before === Resandpay_Events::get( $a )['code'], 'změna termínu, ceny a názvu kód nemění' );
$dupc = Resandpay_Admin_Events::duplicate( $a );
rap_t( Resandpay_Codes::get( $dupc ) && Resandpay_Codes::get( $dupc ) !== $before, 'duplikát akce dostane nový kód (' . Resandpay_Codes::get( $dupc ) . ')' );
$last = Resandpay_Codes::get( $dupc );
wp_delete_post( $dupc, true );
$nx = rap_event( 'Po smazání', $now + 40 * $day, $now + 40 * $day + 3600, 5 );
rap_t( (int) substr( Resandpay_Codes::get( $nx ), -4 ) === (int) substr( $last, -4 ) + 1, 'kód smazané akce se znovu nepoužije (' . $last . ' → ' . Resandpay_Codes::get( $nx ) . ')' );
rap_t( $a === Resandpay_Codes::find( strtolower( $before ) ) && $svc_id === Resandpay_Codes::find( ' ' . $cs ) && 0 === Resandpay_Codes::find( 'AK-1999-9999' ), 'vyhledání podle kódu (i malými písmeny, s mezerou)' );
rap_t( 'Bukální masáž' === Resandpay_Services::find( $cs )['title'], 'služba se najde i podle kódu (shortcode service="SL-…")' );
rap_t( false !== strpos( Resandpay_Frontend::shortcode_booking( array( 'id' => $before ) ), 'rap-booking' ), 'shortcode [reserveandpay_booking id="AK-…"] přijme kód' );
$rq = Resandpay_Reservations::query( array( 'search' => $cs, 'per_page' => 100 ) );
rap_t( $rq['total'] > 0 && ! array_filter( $rq['items'], fn( $r ) => 'TE' !== substr( Resandpay_Codes::get( $r['event_id'] ), 0, 2 ) ), 'hledání rezervací podle kódu služby najde rezervace jejích termínů' );
$rq2 = Resandpay_Reservations::query( array( 'search' => $before, 'per_page' => 100 ) );
rap_t( $rq2['total'] > 0 && ! array_filter( $rq2['items'], fn( $r ) => (int) $r['event_id'] !== $a ), 'hledání rezervací podle kódu akce' );
rap_t( false !== strpos( Resandpay_Mailer::render( 'Akce {event_code}', $rq2['items'][0] ), $before ), 'proměnná {event_code} v e-mailech' );
delete_post_meta( $nx, Resandpay_Codes::META );
delete_post_meta( $svc2, Resandpay_Codes::META );
rap_t( 2 === Resandpay_Codes::backfill() && Resandpay_Codes::get( $nx ) && 'SL-' === substr( Resandpay_Codes::get( $svc2 ), 0, 3 ), 'doplnění kódů existujícím položkám (migrace)' );
Resandpay_Settings::update( array( 'code_prefix_event' => 'EV', 'code_digits_event' => 5 ) );
$ev5 = rap_event( 'Jiná předpona', $now + 41 * $day, $now + 41 * $day + 3600, 5 );
rap_t( (bool) preg_match( '/^EV-' . $yr . '-\d{5}$/', Resandpay_Codes::get( $ev5 ) ) && $before === Resandpay_Events::get( $a )['code'], 'předpona a počet číslic z nastavení; stávající kódy beze změny' );
Resandpay_Settings::update( array( 'code_prefix_event' => 'AK', 'code_digits_event' => 4 ) );
rap_t( 'TE-2027-0001' === Resandpay_Codes::format( 'slot', 1, 2027 ) && 'SL-012' === Resandpay_Codes::format( 'service', 12, 2027 ), 'formát kódu podle druhu' );

echo "== E4: QR platba (SPD)\n";
$qe  = rap_event( 'Jóga & dech', $now + 20 * $day, $now + 20 * $day + 7200, 10, array( 'rap_location' => $loc ) );
$qr  = Resandpay_Reservations::create( $qe, array_merge( rap_person( 90 ), array( 'customer_name' => 'Žofie Řeháková-*Dlouhé příjmení které je opravdu velmi dlouhé' ) ), array( 'notify' => false ) );
$spd = Resandpay_QR::spd( $qr );
$fld = array();
foreach ( array_slice( explode( '*', $spd ), 2 ) as $kv ) {
	list( $k, $v ) = explode( ':', $kv, 2 );
	$fld[ $k ]     = $v;
}
rap_t( 0 === strpos( $spd, 'SPD*1.0*ACC:CZ6527000000000123456789*' ), 'SPD začíná hlavičkou a IBAN z nastavení' );
rap_t( '500.00' === $fld['AM'] && 'CZK' === $fld['CC'] && $qr['vs'] === $fld['X-VS'], 'částka, měna a VS' );
rap_t( wp_date( 'Ymd', Resandpay_Events::ts( $qr['due_at'] ) ) === $fld['DT'], 'datum splatnosti DT = splatnost rezervace' );
rap_t( 0 === strpos( $fld['MSG'], Resandpay_Codes::get( $qe ) . ' Zofie Rehakova' ) && mb_strlen( $fld['MSG'] ) <= 60 && false === strpos( $fld['MSG'], '*' ), 'MSG: kód akce + jméno bez diakritiky, bez „*“, max. 60 znaků (' . $fld['MSG'] . ')' );
rap_t( 'INSYSTEMS CZ s.r.o.' === $fld['RN'], 'RN: jméno příjemce = provozovatel' );
Resandpay_Settings::update( array( 'qr_recipient_name' => 'S lehkou hlavou – Hana Vildomcová' ) );
rap_t( false !== strpos( Resandpay_QR::spd( $qr ), '*RN:S lehkou hlavou - Hana Vildomcova*' ), 'RN z nastavení, jen ASCII (bez diakritiky a pomlček „–“)' );
Resandpay_Settings::update( array( 'qr_recipient_name' => '' ) );
$png = Resandpay_QR::png( $spd, 4 );
$dim = unpack( 'Nw/Nh', substr( $png, 16, 8 ) );
rap_t( "\x89PNG\r\n\x1a\n" === substr( $png, 0, 8 ) && $dim['w'] === $dim['h'] && $dim['w'] >= 4 * ( 21 + 8 ), 'PNG obrázek QR kódu bez GD (' . $dim['w'] . ' px)' );
file_put_contents( sys_get_temp_dir() . '/rap-qr-test.png', $png );
file_put_contents( sys_get_temp_dir() . '/rap-qr-test.txt', $spd );
rap_t( Resandpay_QR::applicable( $qr ) && ! Resandpay_QR::applicable( array_merge( $qr, array( 'status' => 'paid' ) ) ), 'QR jen u nezaplacené rezervace' );
rap_t( '' === Resandpay_QR::spd( array_merge( $qr, array( 'currency' => 'EUR' ) ) ), 'jiná měna než CZK → bez SPD (EUR ve fázi 2)' );
$iban = Resandpay_Settings::get( 'iban' );
Resandpay_Settings::update( array( 'iban' => '' ) );
rap_t( '' === Resandpay_QR::spd( $qr ), 'bez IBAN v nastavení se QR negeneruje' );
Resandpay_Settings::update( array( 'iban' => $iban ) );

echo "== E4: platební pokyn (PDF)\n";
$pdf = Resandpay_PDF::render( $qr );
rap_t( 0 === strpos( $pdf, '%PDF-' ) && strlen( $pdf ) > 10000, 'PDF platebního pokynu se vytvoří (' . round( strlen( $pdf ) / 1024 ) . ' kB)' );
file_put_contents( sys_get_temp_dir() . '/rap-pokyn-test.pdf', $pdf );
rap_t( false !== strpos( Resandpay_PDF::url( $qr ), 'rap_pdf=' . $qr['token'] ) && 'platebni-pokyn-' . $qr['proforma_no'] . '.pdf' === Resandpay_PDF::filename( $qr ), 'odkaz ke stažení s tokenem a název souboru' );
$pp = Resandpay_Reservations::set_status( $qr['id'], Resandpay_Reservations::PAID );
rap_t( 0 === strpos( Resandpay_PDF::render( $pp ), '%PDF-' ), 'PDF i pro zaplacenou rezervaci (bez QR, „Uhrazeno“)' );
rap_t( (bool) array_filter( Resandpay_PDF::notes( $rC, Resandpay_Events::get( $cE ), Resandpay_Settings::all() ), fn( $n ) => false !== strpos( $n, 'hotově na místě' ) ), 'PDF u hotovosti: text o platbě na místě a možnosti platit předem' );

echo "== E4: HTML e-maily\n";
$qr2 = Resandpay_Reservations::create( $qe, rap_person( 91 ), array( 'notify' => false ) );
$bp  = Resandpay_Mailer::build( 'received', $qr2 );
rap_t( false !== strpos( $bp['html'], '<!doctype html>' ) && false !== strpos( $bp['html'], '#8C6423' ) && false !== strpos( $bp['html'], '>hlavou</span>' ), 'HTML e-mail v barvách webu se značkou' );
rap_t( false !== strpos( $bp['html'], 'src="cid:rap-qr"' ) && "\x89PNG" === substr( $bp['qr_png'], 0, 4 ), 'QR kód vložený do e-mailu (cid:rap-qr)' );
rap_t( 0 === strpos( $bp['pdf'], '%PDF-' ) && 'platebni-pokyn-' . $qr2['proforma_no'] . '.pdf' === $bp['pdf_name'], 'PDF platebního pokynu jako příloha' );
rap_t( false !== strpos( $bp['html'], esc_url( Resandpay_PDF::url( $qr2 ) ) ) && false !== strpos( $bp['text'], Resandpay_PDF::url( $qr2 ) ), 'odkaz ke stažení PDF v HTML i textové verzi' );
rap_t( false !== strpos( $bp['text'], 'Variabilní symbol: ' . $qr2['vs'] ) && false !== strpos( $bp['text'], 'IBAN: CZ6527' ) && false === strpos( $bp['text'], '<' ), 'textová verze obsahuje platební údaje' );
rap_t( 1 === substr_count( $bp['html'], '123456789/2700' ), 'výchozí text neopakuje platební údaje (jen v rámečku)' );
$bc = Resandpay_Mailer::build( 'received_cash', array_merge( $qr2, array( 'pay_choice' => 'cash', 'status' => 'onsite' ) ) );
rap_t( false !== strpos( $bc['html'], 'Platba předem převodem' ) && false !== strpos( $bc['html'], 'cid:rap-qr' ) && false === strpos( $bc['text'], 'Splatnost:' ), 'u hotovosti: QR pro platbu předem, bez splatnosti' );
$bpaid = Resandpay_Mailer::build( 'paid', $pp );
rap_t( '' === $bpaid['pdf'] && false === strpos( $bpaid['html'], 'cid:rap-qr' ), 'potvrzení platby bez QR a přílohy' );
$GLOBALS['rap_mails'] = array();
Resandpay_Mailer::send_template( $qr2['email'], 'received', $qr2 );
$m0 = $GLOBALS['rap_mails'][0];
rap_t( 'tester@example.com' === $m0['to'] && false !== strpos( $m0['message'], 'TESTOVACÍ REŽIM' ) && in_array( 'Content-Type: text/html; charset=UTF-8', (array) $m0['headers'], true ), 'testovací režim: HTML e-mail s pruhem o původním příjemci' );

echo "== E4: web a odbavení\n";
$_GET['rap_done'] = $qr2['token'];
$conf = Resandpay_Frontend::render_booking( $qe );
unset( $_GET['rap_done'] );
rap_t( false !== strpos( $conf, 'src="data:image/png;base64,' ) && false !== strpos( $conf, 'Stáhnout platební pokyn (PDF)' ), 'potvrzovací stránka: QR kód a odkaz na PDF' );
$_GET = array( 'page' => 'reserveandpay-checkin' );
ob_start();
Resandpay_Admin_Checkin::page();
$ckp = ob_get_clean();
$_GET = array();
rap_t( false !== strpos( $ckp, 'id="rap-scan-btn"' ) && false !== strpos( $ckp, '<video' ), 'odbavení: tlačítko „Načíst QR kód kamerou“' );
rap_t( (int) $qr2['id'] === (int) Resandpay_Reservations::find_by_vs_input( Resandpay_QR::spd( $qr2 ) )['id'], 'odbavení: obsah QR platby najde rezervaci' );
Resandpay_Settings::update( array( 'tpl_received_body' => 'upraveno' ) );
Resandpay_Settings::reset_templates();
rap_t( 0 === strpos( Resandpay_Settings::get( 'tpl_received_body' ), 'Dobrý den' ), 'obnovení výchozích textů e-mailů' );

echo "== E5: čtení avíza Raiffeisenbank\n";
$eml = file_get_contents( RESANDPAY_DIR . 'tests/fixtures/rb-prichozi-okamzita-platba.eml' );
/** Varianta vzorového avíza: VS, částka, Message-ID a další úpravy. */
function rap_avizo( $base, $vs, $amount, $id, array $repl = array() ) {
	$e = str_replace( array( '<1983301034.29395.1792613962452@rb.cz>', '>01919555<', '>+5.000,00 CZK<' ), array( '<' . $id . '@rb.cz>', '>' . $vs . '<', '>' . $amount . '<' ), $base );
	return strtr( $e, $repl );
}
$mime = Resandpay_Mime::parse( $eml );
$pa   = Resandpay_Bank_RB::parse( $mime );
rap_t( 'Pohyb na účtě' === Resandpay_Mime::decode_header( Resandpay_Mime::first( $mime['headers'], 'subject' ) ), 'předmět „Pohyb na účtě“ (UTF-8)' );
rap_t( 5000.0 === $pa['amount'] && 'CZK' === $pa['currency'] && 'in' === $pa['direction'], 'částka +5.000,00 CZK, příchozí' );
rap_t( '01919555' === $pa['vs'] && '0308' === $pa['ks'] && '' === $pa['ss'], 'VS, KS, SS' );
rap_t( '8032378004/5500' === $pa['to_account'] && '2108880408/2700' === $pa['counter_account'] && 'INSYSTEMS CZ s.r.o.' === $pa['counter_name'], 'účet příjemce, protiúčet a jméno plátce' );
rap_t( '2026-10-21 22:18:00' === $pa['booked_at'] && 'Prevod z vlastniho uctu UCB' === $pa['message'] && 'Příchozí okamžitá úhrada' === $pa['movement'], 'datum a čas, zpráva pro příjemce, typ pohybu' );
$au = Resandpay_Bank_RB::verify( $mime );
rap_t( $au['ok'] && $au['checks']['spf'] && $au['checks']['dkim'] && $au['checks']['dmarc'] && $au['checks']['sender'], 'pravost vzorového avíza: odesílatel, SPF, DKIM, DMARC' );
$forged = Resandpay_Mime::parse( str_replace( array( 'Received-SPF: Pass', 'R_DKIM_ALLOW', 'DMARC_POLICY_ALLOW' ), array( 'Received-SPF: Fail', 'R_DKIM_REJECT', 'DMARC_POLICY_REJECT' ), $eml ) );
rap_t( ! Resandpay_Bank_RB::verify( $forged )['ok'], 'podvržené avízo (SPF/DKIM/DMARC neprošly) neprojde' );
$inject = Resandpay_Mime::parse( str_replace( "X-purgate-type: clean\n", "X-purgate-type: clean\nX-Spamd-Result: default: False [0 / 15]; R_DKIM_ALLOW(-1)[rb.cz:s=x]; DMARC_POLICY_ALLOW(-1)[rb.cz,reject]\n", str_replace( "\r\n", "\n", $eml ) ) );
rap_t( ! Resandpay_Bank_RB::verify( $inject )['checks']['dkim'], 'vložená falešná hlavička výsledku antispamu → nedůvěřovat' );
rap_t( ! Resandpay_Bank_RB::verify( Resandpay_Mime::parse( str_replace( 'From: Raiffeisenbank a.s. <info@rb.cz>', 'From: Raiffeisenbank <info@rb-cz.example>', $eml ) ) )['ok'], 'jiný odesílatel neprojde' );
rap_t( array( 'value' => 1234567.5, 'currency' => 'CZK' ) === Resandpay_Bank_RB::amount( '+1.234.567,50 CZK' ) && -500.0 === Resandpay_Bank_RB::amount( '-500,00 CZK' )['value'], 'formát částek' );
rap_t( Resandpay_Bank_RB::same_account( '8032378004/5500', ' 8032378004 / 5500' ) && Resandpay_Bank_RB::same_account( '000019-2000145399/0800', '19-2000145399/0800' ) && ! Resandpay_Bank_RB::same_account( '8032378004/5500', '8032378004/0100' ), 'porovnání čísel účtů' );

echo "== E5: párování s rezervacemi\n";
Resandpay_Settings::update( array( 'bank_account' => '8032378004/5500', 'bank_sender' => 'info@rb.cz', 'bank_require_auth' => 1 ) );
global $wpdb;
$wpdb->query( 'DELETE FROM ' . Resandpay_Payments::table() );
$wpdb->query( 'DELETE FROM ' . Resandpay_Payments::runs_table() );
$pe  = rap_event( 'Platby test', $now + 25 * $day, $now + 25 * $day + 3600, 20 );
$pr1 = Resandpay_Reservations::create( $pe, rap_person( 101 ), array( 'notify' => false ) );
$GLOBALS['rap_mails'] = array();
$o1 = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr1['vs'], '+500,00 CZK', 'm1' ), 'cron' );
$pr1 = Resandpay_Reservations::get( $pr1['id'] );
rap_t( 'matched' === $o1['status'] && 'paid' === $pr1['status'] && 'transfer_auto' === $pr1['paid_method'], 'správný VS a částka → rezervace Zaplaceno (převod – automaticky)' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Platba přijata' ) ), 'zákaznice dostane potvrzení platby' );
rap_t( 'duplicate' === Resandpay_Payments::process_raw( rap_avizo( $eml, $pr1['vs'], '+500,00 CZK', 'm1' ) )['status'], 'stejné avízo (Message-ID) se nezpracuje dvakrát' );
$o1b = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr1['vs'], '+500,00 CZK', 'm1-kopie' ) );
rap_t( 'review' === $o1b['status'] && false !== strpos( $o1b['reason'], 'duplicitní' ), 'stejná platba z jiného e-mailu → k ruční kontrole jako možná duplicita (nic se neztratí)' );
$prP = Resandpay_Reservations::create( $pe, rap_person( 109 ), array( 'notify' => false ) );
$GLOBALS['rap_mails'] = array();
$oP  = Resandpay_Payments::process_raw( rap_avizo( $eml, $prP['vs'], '+400,00 CZK', 'm2p' ) );
$prP = Resandpay_Reservations::get( $prP['id'] );
rap_t( 'matched' === $oP['status'] && false !== strpos( $oP['reason'], 'Částečná' ) && 'partial' === $prP['status'] && 400.0 === (float) $prP['amount_paid'], 'menší částka → zapíše se automaticky, rezervace Částečně zaplaceno (0.6.0)' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'část platby' ) && false !== strpos( $m['message'], '100' ) ), 'zákazník je upozorněn, že nezaplatil vše (zbývá 100 Kč)' );
$pr2 = Resandpay_Reservations::create( $pe, rap_person( 102 ), array( 'notify' => false ) );
$o2  = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr2['vs'], '+600,00 CZK', 'm2' ) );
rap_t( 'review' === $o2['status'] && false !== strpos( $o2['reason'], 'Přeplatek' ) && 'pending' === Resandpay_Reservations::get( $pr2['id'] )['status'], 'přeplatek → k ruční kontrole, rezervace dál čeká' );
$o3 = Resandpay_Payments::process_raw( rap_avizo( $eml, '99999999', '+500,00 CZK', 'm3' ) );
rap_t( 'review' === $o3['status'] && false !== strpos( $o3['reason'], 'neexistuje' ), 'neznámý VS → k ruční kontrole' );
$o4 = Resandpay_Payments::process_raw( rap_avizo( $eml, '', '+500,00 CZK', 'm4', array( 'Prevod z vlastniho uctu UCB' => 'Joga VS ' . $pr2['vs'] ) ) );
rap_t( 'review' === $o4['status'] && false !== strpos( $o4['reason'], 'bez variabilního' ), 'platba bez VS → k ruční kontrole' );
$p4 = Resandpay_Payments::get( $o4['payment_id'] );
rap_t( ( $sg4 = Resandpay_Payments::suggest( $p4 ) ) && (int) $sg4['id'] === (int) $pr2['id'], 'VS ve zprávě pro příjemce → návrh rezervace k ručnímu přiřazení' );
$pr3 = Resandpay_Reservations::create( $pe, rap_person( 103 ), array( 'notify' => false ) );
$o5  = Resandpay_Payments::process_raw( rap_avizo( $eml, '', '+300,00 CZK', 'm5', array( 'Prevod z vlastniho uctu UCB' => 'Platba ' . $pr3['vs'], '22:18<' => '22:19<' ) ) );
rap_t( 'review' === $o5['status'] && null === Resandpay_Payments::suggest( Resandpay_Payments::get( $o5['payment_id'] ) ), 'návrh se nenabídne, když nesedí částka (300 vs 500)' );
$o6 = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr3['vs'], '+500,00 CZK', 'm6', array( 'Received-SPF: Pass' => 'Received-SPF: Fail', 'R_DKIM_ALLOW' => 'R_DKIM_REJECT' ) ) );
rap_t( 'review' === $o6['status'] && false !== strpos( $o6['reason'], 'pravost' ) && 'pending' === Resandpay_Reservations::get( $pr3['id'] )['status'], 'podvržené avízo se správným VS se nespáruje (ruční kontrola)' );
$o7 = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr3['vs'], '+500,00 CZK', 'm7', array( '8032378004/5500' => '1234567890/0100' ) ) );
rap_t( 'ignored' === $o7['status'] && false !== strpos( $o7['reason'], 'jiný účet' ), 'avízo na jiný účet → ignorováno' );
$o8 = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr3['vs'], '-500,00 CZK', 'm8', array( 'P=C5=99=C3=ADchoz=C3=AD' => 'Odchoz=C3=AD', 'p=C5=99=C3=ADchoz=C3=AD platba' => 'odchoz=C3=AD platba' ) ) );
rap_t( 'ignored' === $o8['status'] && false !== strpos( $o8['reason'], 'Odchozí' ), 'odchozí platba → ignorováno' );
$o9 = Resandpay_Payments::process_raw( str_replace( array( '<tr', '</tr>' ), array( '<div', '</div>' ), rap_avizo( $eml, $pr3['vs'], '+500,00 CZK', 'm9' ) ) );
rap_t( 'review' === $o9['status'] && false !== strpos( $o9['reason'], 'formát' ), 'změněná šablona banky → k ruční kontrole (nic se neztratí)' );
rap_t( 'not_bank' === Resandpay_Payments::process_raw( "From: Jana <jana@example.com>\nSubject: dotaz\n\nAhoj" )['status'], 'jiný e-mail než od banky se ignoruje' );
$o10 = Resandpay_Payments::process_raw( rap_avizo( $eml, $pr1['vs'], '+500,00 CZK', 'm10', array( '22:18<' => '23:45<' ) ) );
rap_t( 'review' === $o10['status'] && false !== strpos( $o10['reason'], 'už je zaplacená' ), 'druhá platba za zaplacenou rezervaci → k ruční kontrole (vrátit?)' );

echo "== E5: ruční přiřazení\n";
rap_t( is_wp_error( Resandpay_Payments::assign( $o2['payment_id'], $pr2['vs'] ) ), 'ruční přiřazení při rozdílu částky bez potvrzení odmítnuto' );
$as = Resandpay_Payments::assign( $o2['payment_id'], $pr2['vs'], 'doplatí 100 Kč na místě', true );
rap_t( is_array( $as ) && 'paid' === $as['status'] && 'transfer_manual' === $as['paid_method'] && 'manual' === Resandpay_Payments::get( $o2['payment_id'] )['status'], 's potvrzením „i při rozdílu částky“ přiřazeno, rezervace zaplacena' );
rap_t( is_wp_error( Resandpay_Payments::assign( $o2['payment_id'], $pr2['vs'] ) ), 'vyřešené avízo nelze přiřadit znovu' );
rap_t( true === Resandpay_Payments::resolve( $o3['payment_id'], 'vráceno plátci' ) && 'resolved' === Resandpay_Payments::get( $o3['payment_id'] )['status'], 'vyřešení bez přiřazení s poznámkou' );
$cn = Resandpay_Payments::counts();
rap_t( 2 === $cn['matched'] && 1 === $cn['manual'] && 1 === $cn['resolved'] && 2 === $cn['ignored'] && 6 === $cn['review'], 'přehled počtů podle stavu (' . wp_json_encode( $cn ) . ')' );

echo "== E5: heslo, CRON, interval\n";
$enc = Resandpay_Payments::encrypt( 'tajne-heslo' );
rap_t( 0 === strpos( $enc, 'v1:' ) && false === strpos( $enc, 'tajne' ) && 'tajne-heslo' === Resandpay_Payments::decrypt( $enc ), 'heslo se ukládá šifrovaně a jde dešifrovat' );
update_option( Resandpay_Settings::OPTION, Resandpay_Admin_Settings::sanitize( array_merge( Resandpay_Settings::all(), array( '__form' => 1, 'imap_password' => 'tajne-heslo' ) ) ) );
$first = Resandpay_Settings::get( 'imap_password' );
update_option( Resandpay_Settings::OPTION, Resandpay_Admin_Settings::sanitize( array( '__form' => 1, 'imap_password' => '' ) ) );
rap_t( 'tajne-heslo' === Resandpay_Payments::imap_password() && $first === Resandpay_Settings::get( 'imap_password' ), 'prázdné pole hesla v nastavení ponechá uložené heslo' );
Resandpay_Settings::update( array( 'test_mode' => 1, 'notify_admin' => 1, 'bank_require_auth' => 1 ) ); // Odeslaný formulář bez zaškrtnutí vypíná checkboxy.
// Jako v administraci: kontrola formuláře je navěšená na každý zápis volby.
add_filter( 'sanitize_option_' . Resandpay_Settings::OPTION, array( 'Resandpay_Admin_Settings', 'sanitize' ) );
Resandpay_Settings::reset_templates();
Resandpay_Settings::update( array( 'cron_key' => '' ) );
$ck1 = Resandpay_Payments::cron_key();
rap_t( $ck1 === Resandpay_Settings::get( 'cron_key' ) && $ck1 === Resandpay_Payments::cron_key(), 'klíč CRONu se v administraci uloží a dál se nemění' );
rap_t( 'tajne-heslo' === Resandpay_Payments::imap_password() && $first === Resandpay_Settings::get( 'imap_password' ), 'interní zápisy nastavení (obnovení textů, klíč CRONu) heslo znovu nešifrují' );
update_option( Resandpay_Settings::OPTION, array_merge( Resandpay_Settings::all(), array( '__form' => 1, 'imap_password' => 'nove-heslo' ) ) );
rap_t( 'nove-heslo' === Resandpay_Payments::imap_password(), 'uložení nového hesla formulářem' );
update_option( Resandpay_Settings::OPTION, array_merge( Resandpay_Settings::all(), array( '__form' => 1 ) ) );
rap_t( 'nove-heslo' === Resandpay_Payments::imap_password(), 'odeslání formuláře s již zašifrovanou hodnotou heslo nezmění' );
remove_filter( 'sanitize_option_' . Resandpay_Settings::OPTION, array( 'Resandpay_Admin_Settings', 'sanitize' ) );
Resandpay_Settings::update( array( 'test_mode' => 1, 'notify_admin' => 1, 'bank_require_auth' => 1 ) );
rap_t( 'tajne-heslo' === Resandpay_Payments::decrypt( Resandpay_Payments::encrypt( Resandpay_Payments::encrypt( 'tajne-heslo' ) ) ), 'oprava: dvakrát zašifrované heslo (verze 0.5.0–0.5.2) se přečte správně' );
$multi = 'tajne-heslo';
for ( $i = 0; $i < 25; $i++ ) {
	$multi = Resandpay_Payments::encrypt( $multi );
}
$keep = Resandpay_Settings::get( 'imap_password' );
Resandpay_Settings::update( array( 'imap_password' => $multi ) );
rap_t( 'tajne-heslo' === Resandpay_Payments::imap_password() && 'tajne-heslo' === Resandpay_Payments::decrypt( $multi ), 'oprava: heslo zašifrované 25× se přečte správně' );
rap_t( strlen( Resandpay_Settings::get( 'imap_password' ) ) < 80, 'a uloží se znovu jako jedna vrstva' );
Resandpay_Settings::update( array( 'imap_password' => $keep ) );
Resandpay_Settings::update( array( 'payment_mode' => 'auto', 'payment_interval' => 60, 'cron_key' => '' ) );
$ck = Resandpay_Payments::cron_key();
rap_t( strlen( $ck ) >= 32 && $ck === Resandpay_Payments::cron_key() && false !== strpos( Resandpay_Payments::cron_url(), 'rap_cron=' . $ck ), 'tajný klíč a adresa pro CRON' );
update_option( 'resandpay_last_run', time() - 30 * MINUTE_IN_SECONDS );
rap_t( ! Resandpay_Payments::due_for_run(), 'interval 60 min: po 30 min se kontrola nespustí' );
update_option( 'resandpay_last_run', time() - 59 * MINUTE_IN_SECONDS );
rap_t( Resandpay_Payments::due_for_run(), 'po 59 min ano (tolerance kroku CRONu)' );
Resandpay_Settings::update( array( 'payment_mode' => 'manual' ) );
rap_t( ! Resandpay_Payments::due_for_run(), 'režim „jen ručně“: CRON kontrolu nespouští' );
Resandpay_Settings::update( array( 'payment_mode' => 'auto' ) );
update_option( 'resandpay_last_success', time() - 4 * HOUR_IN_SECONDS );
delete_option( 'resandpay_outage_alert' );
$GLOBALS['rap_mails'] = array();
$al1 = Resandpay_Payments::check_outage();
$al2 = Resandpay_Payments::check_outage();
rap_t( $al1 && ! $al2 && 1 === count( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'neprobíhá' ) ) ), 'výpadek > 3× interval → upozornění správkyni, nejvýš 1× denně' );

echo "== E5: běh nad schránkou IMAP (lokální Dovecot)\n";
$maildir = '/var/tmp/rapdove/mail/bankovni@test';
$sock    = @fsockopen( '127.0.0.1', 1143, $en, $es, 1 );
if ( ! $sock || ! is_dir( $maildir ) ) {
	echo "  SKIP  testovací IMAP server neběží\n";
} else {
	fclose( $sock );
	foreach ( array( 'new', 'cur', 'tmp' ) as $d ) {
		array_map( 'unlink', glob( "$maildir/$d/*" ) ?: array() );
	}
	exec( 'rm -rf ' . escapeshellarg( "$maildir/.Zpracov&AOE-no" ) );
	$pr4 = Resandpay_Reservations::create( $pe, rap_person( 104 ), array( 'notify' => false ) );
	file_put_contents( "$maildir/new/1.eml", rap_avizo( $eml, $pr4['vs'], '+500,00 CZK', 'imap1' ) );
	file_put_contents( "$maildir/new/2.eml", rap_avizo( $eml, '88888888', '+500,00 CZK', 'imap2' ) );
	file_put_contents( "$maildir/new/3.eml", "From: Jana <jana@example.com>\r\nTo: bankovni@test\r\nSubject: Dotaz\r\nMessage-ID: <x1@example.com>\r\n\r\nDobry den" );
	Resandpay_Settings::update( array( 'imap_host' => '127.0.0.1', 'imap_port' => 1143, 'imap_security' => 'none', 'imap_user' => 'bankovni@test', 'imap_password' => Resandpay_Payments::encrypt( 'tajne-heslo' ), 'imap_folder' => 'INBOX', 'imap_folder_done' => 'Zpracováno' ) );
	$tc = Resandpay_Payments::test_connection();
	rap_t( is_array( $tc ) && 3 === $tc['total'] && 2 === $tc['bank'], 'test připojení: 3 zprávy, z toho 2 od banky' );
	$run = Resandpay_Payments::run( 'manual' );
	rap_t( $run['ok'] && 2 === $run['fetched'] && 1 === $run['matched'] && 1 === $run['review'], 'běh: načteno 2, spárováno 1, k ruční kontrole 1 (' . ( $run['error'] ?: 'ok' ) . ')' );
	rap_t( 'paid' === Resandpay_Reservations::get( $pr4['id'] )['status'] && 'transfer_run' === Resandpay_Reservations::get( $pr4['id'] )['paid_method'], 'rezervace zaplacena (převod – ruční běh kontroly)' );
	$im = new Resandpay_Imap();
	$im->connect( '127.0.0.1', 1143, 'none' );
	$im->login( 'bankovni@test', 'tajne-heslo' );
	$inbox = $im->select( 'INBOX' );
	$doneN = $im->select( 'Zpracováno' );
	$im->logout();
	rap_t( 1 === $inbox && 2 === $doneN, 'zpracovaná avíza přesunuta do složky „Zpracováno“, jiný e-mail zůstal' );
	rap_t( 'Zpracov&AOE-no' === Resandpay_Imap::utf7_encode( 'Zpracováno' ), 'název složky v IMAP kódování (modified UTF-7)' );
	$iu = new Resandpay_Imap();
	$iu->connect( '127.0.0.1', 1143, 'none' );
	rap_t( $iu->login( 'unicode@test', 'Žluťoučký"kůň\\x%25' ), 'heslo s diakritikou, uvozovkami a zpětným lomítkem (AUTHENTICATE PLAIN)' );
	$iu->logout();
	$iu = new Resandpay_Imap();
	$iu->connect( '127.0.0.1', 1143, 'none' );
	$iu->caps = array_values( array_diff( $iu->caps, array( 'SASL-IR' ) ) );
	rap_t( $iu->login( 'unicode@test', 'Žluťoučký"kůň\\x%25' ), 'AUTHENTICATE PLAIN i bez SASL-IR (pokračovací odpověď serveru)' );
	$iu->logout();
	$iu = new Resandpay_Imap();
	$iu->connect( '127.0.0.1', 1143, 'none' );
	rap_t( ! $iu->login( 'bankovni@test', 'spatne' ) && false !== strpos( $iu->error, 'Server odpověděl' ) && false !== strpos( $iu->error, 'bankovni@test' ), 'chyba přihlášení obsahuje uživatele a odpověď serveru (' . $iu->error . ')' );
	$iu->logout();
	$run2 = Resandpay_Payments::run( 'manual' );
	rap_t( $run2['ok'] && 0 === $run2['fetched'], 'další běh: nic nového' );
	Resandpay_Settings::update( array( 'imap_password' => Resandpay_Payments::encrypt( 'spatne' ) ) );
	$run3 = Resandpay_Payments::run( 'manual' );
	rap_t( ! $run3['ok'] && false !== strpos( $run3['error'], 'Přihlášení' ), 'špatné heslo → srozumitelná chyba v logu' );
	$runs = Resandpay_Payments::runs( 3 );
	rap_t( 3 === count( $runs ) && 0 === (int) $runs[0]['ok'] && 1 === (int) $runs[1]['ok'], 'log běhů' );
	Resandpay_Settings::update( array( 'payment_mode' => 'auto', 'payment_interval' => 60, 'imap_password' => Resandpay_Payments::encrypt( 'tajne-heslo' ) ) );
	update_option( 'resandpay_last_run', time() - 2 * HOUR_IN_SECONDS );
	rap_t( '' === Resandpay_Payments::cron_tick(), 'tik CRONu při úspěchu nic nevypíše' );
	Resandpay_Settings::update( array( 'imap_password' => Resandpay_Payments::encrypt( 'spatne' ) ) );
	update_option( 'resandpay_last_run', time() - 2 * HOUR_IN_SECONDS );
	rap_t( 0 === strpos( Resandpay_Payments::cron_tick(), 'ReserveAndPay – kontrola plateb selhala' ), 'tik CRONu při chybě vypíše důvod (Active24 pošle e-mail)' );
}
$_GET = array( 'page' => 'reserveandpay-payments', 'status' => 'review' );
wp_set_current_user( 1 );
ob_start();
Resandpay_Admin_Payments::page();
$pg = ob_get_clean();
$_GET = array();
wp_set_current_user( 0 );
rap_t( false !== strpos( $pg, 'Zkontrolovat platby nyní' ) && false !== strpos( $pg, 'Přiřadit k rezervaci' ) && false !== strpos( $pg, 'rap_cron=' ), 'stránka Platby: stav, ruční kontrola, přiřazení, CRON' );
Resandpay_Settings::update( array( 'bank_account' => '123456789/2700' ) );

echo "== 0.5.1: název v menu\n";
global $menu;
$menu = array();
wp_set_current_user( 1 );
Resandpay_Admin::menu();
$top = array_values( array_filter( (array) $menu, fn( $m ) => 'reserveandpay' === ( $m[2] ?? '' ) ) );
wp_set_current_user( 0 );
rap_t( $top && 'ResAndPay' === $top[0][0] && 'ReserveAndPay' === $top[0][3], 'menu: „ResAndPay“, titulek stránky „ReserveAndPay“' );

echo "== 0.6.0: platba ve dvou částech (1. část a doplatek)\n";
require_once RESANDPAY_DIR . 'admin/class-admin-reservations.php';
Resandpay_Settings::update( array( 'reminder_days' => 0, 'bank_account' => '8032378004/5500' ) );
$sp = rap_event( 'Jóga víkend', $now + 30 * $day, $now + 31 * $day, 10, array( 'rap_due_mode' => 'event', 'rap_due_offset' => '-7', 'rap_part1_mode' => 'fixed', 'rap_part1_value' => '200', 'rap_part1_days' => '7' ) );
rap_t( 200.0 === Resandpay_Events::part1_amount( Resandpay_Events::get( $sp ) ), '1. část jako pevná částka (200 z 500)' );
update_post_meta( $sp, '_rap_part1_mode', 'percent' );
update_post_meta( $sp, '_rap_part1_value', '30' );
rap_t( 150.0 === Resandpay_Events::part1_amount( Resandpay_Events::get( $sp ) ), '1. část jako procento (30 % z 500 = 150)' );
update_post_meta( $sp, '_rap_part1_value', '600' );
update_post_meta( $sp, '_rap_part1_mode', 'fixed' );
rap_t( 0.0 === Resandpay_Events::part1_amount( Resandpay_Events::get( $sp ) ), '1. část ≥ cena → platba najednou' );
Resandpay_Events::save_part1_meta( $sp, array( 'rap_part1_mode' => 'percent', 'rap_part1_value' => '150', 'rap_part1_days' => '3' ) );
rap_t( '99' === (string) get_post_meta( $sp, '_rap_part1_value', true ), 'procento se omezí na 99 %' );
Resandpay_Events::save_part1_meta( $sp, array( 'rap_part1_mode' => 'fixed', 'rap_part1_value' => '200', 'rap_part1_days' => '7' ) );
$GLOBALS['rap_mails'] = array();
$s1 = Resandpay_Reservations::create( $sp, rap_person( 120 ) );
$p1due = wp_date( 'Y-m-d', $now + 7 * $day );
rap_t( Resandpay_Reservations::is_split( $s1 ) && 200.0 === (float) $s1['part1_amount'] && 0 === strpos( $s1['part1_due_at'], $p1due ) && 0.0 === (float) $s1['amount_paid'], 'rezervace: 1. část 200 Kč se splatností za 7 dní' );
rap_t( 200.0 === Resandpay_Reservations::amount_now( $s1 ) && $s1['part1_due_at'] === Resandpay_Reservations::due_now( $s1 ) && 0 === strpos( $s1['due_at'], wp_date( 'Y-m-d', $now + 23 * $day ) ), 'teď k úhradě 1. část, doplatek do splatnosti akce' );
rap_t( false !== strpos( Resandpay_QR::spd( $s1 ), '*AM:200.00*' ) && false !== strpos( Resandpay_QR::spd_full( $s1 ), '*AM:500.00*' ), 'QR na 1. část (200) a druhý QR „vše najednou“ (500)' );
$m1 = $GLOBALS['rap_mails'][0] ?? array( 'message' => '' );
rap_t( false !== strpos( $m1['message'], 'Nebo zaplaťte vše najednou' ) && false !== strpos( $m1['message'], '1. část platby' ) && false !== strpos( $m1['message'], 'Platba je rozdělená' ), 'e-mail: rozpis, QR na 1. část i „vše najednou“' );
rap_t( false !== strpos( Resandpay_QR::spd_full( $s1 ), '*DT:' . wp_date( 'Ymd', $now + 7 * $day ) ) && false !== strpos( $m1['message'], '500 Kč do ' . wp_date( 'j. n. Y', $now + 7 * $day ) ), 'QR „vše najednou“: splatnost 1. části (0.6.2)' );
$legacy = Resandpay_Mailer::render( 'Částka: {amount} · Splatnost: {due_date}', $s1, 'received' );
rap_t( false !== strpos( $legacy, 'Částka: 200 Kč' ) && false !== strpos( $legacy, wp_date( 'j. n. Y', $now + 7 * $day ) . ' 23:59' ), 'starší text e-mailu: {amount} a {due_date} = 1. část' );
rap_t( false !== strpos( Resandpay_Mailer::render( '{amount}', $s1, 'admin' ), '500 Kč' ), 'e-mail správci: {amount} = celá cena' );
$pdf1 = Resandpay_PDF::render( $s1 );
rap_t( 0 === strpos( $pdf1, '%PDF' ) && strlen( $pdf1 ) > 5000, 'platební pokyn PDF s rozpisem a dvěma QR' );
$conf = new ReflectionMethod( 'Resandpay_Frontend', 'render_confirmation' );
$conf->setAccessible( true );
ob_start();
$conf->invoke( null, $s1, Resandpay_Events::get( $sp ) );
$ch = ob_get_clean();
rap_t( false !== strpos( $ch, '1. část platby' ) && false !== strpos( $ch, 'Doplatek' ) && false !== strpos( $ch, 'rap-qr-full' ), 'potvrzovací stránka: 1. část, doplatek, oba QR' );
$GLOBALS['rap_mails'] = array();
$s1 = Resandpay_Reservations::add_payment( $s1['id'], 100, 'transfer_auto' );
rap_t( 'partial' === $s1['status'] && 100.0 === Resandpay_Reservations::amount_now( $s1 ) && Resandpay_Reservations::in_part1( $s1 ), 'platba 100 Kč: Částečně zaplaceno, z 1. části zbývá 100' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Přijali jsme část platby' ) ), 'zákazník upozorněn na neúplnou platbu' );
$GLOBALS['rap_mails'] = array();
$s1 = Resandpay_Reservations::add_payment( $s1['id'], 100, 'transfer_auto' );
rap_t( 'partial' === $s1['status'] && ! Resandpay_Reservations::in_part1( $s1 ) && 300.0 === Resandpay_Reservations::amount_now( $s1 ) && $s1['due_at'] === Resandpay_Reservations::due_now( $s1 ), '1. část doplacena: zbývá doplatek 300 do splatnosti' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], '1. část platby přijata' ) && false !== strpos( $m['message'], '300' ) ), 'e-mail „1. část přijata – místo potvrzeno“ s QR na doplatek' );
rap_t( '' === Resandpay_QR::spd_full( $s1 ) && false !== strpos( Resandpay_QR::spd( $s1 ), '*AM:300.00*' ), 'po 1. části už jen QR na doplatek' );
$GLOBALS['rap_mails'] = array();
$oS = Resandpay_Payments::process_raw( rap_avizo( $eml, $s1['vs'], '+300,00 CZK', 'split-final' ), 'cron' );
$s1 = Resandpay_Reservations::get( $s1['id'] );
rap_t( 'matched' === $oS['status'] && 'paid' === $s1['status'] && 500.0 === (float) $s1['amount_paid'], 'doplatek z avíza → Zaplaceno (500 z 500)' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Platba přijata' ) ), 'potvrzení platby po doplatku' );
$s2 = Resandpay_Reservations::create( $sp, rap_person( 121 ), array( 'notify' => false ) );
$s2 = Resandpay_Reservations::add_payment( $s2['id'], 500, 'transfer_auto', array( 'notify' => false ) );
rap_t( 'paid' === $s2['status'], 'zaplaceno vše najednou → rovnou Zaplaceno' );
$s3 = Resandpay_Reservations::create( $sp, rap_person( 122 ), array( 'notify' => false ) );
rap_t( 'overpay' === Resandpay_Reservations::add_payment( $s3['id'], 700 )->get_error_code(), 'přeplatek se automaticky nezapíše' );
$s4 = Resandpay_Reservations::create( $sp, array_merge( rap_person( 123 ), array( 'pay_choice' => 'cash' ) ), array( 'notify' => false, 'source' => 'admin' ) );
rap_t( ! Resandpay_Reservations::is_split( $s4 ), 'hotovost na místě → bez rozdělení' );
$s5 = Resandpay_Reservations::create( $sp, rap_person( 124 ), array( 'notify' => false ) );
$s5 = Resandpay_Admin_Reservations::do_action( 'paid_onsite', $s5['id'] );
rap_t( 'paid' === $s5['status'] && 500.0 === (float) $s5['amount_paid'], 'ruční „zaplaceno hotově“ = uhrazena celá částka' );

$lp = rap_event( 'Pobyt pozdní', $now + 14 * $day, $now + 18 * $day, 10, array( 'rap_due_mode' => 'event', 'rap_due_offset' => '-30', 'rap_cash' => '0', 'rap_part1_mode' => 'fixed', 'rap_part1_value' => '200', 'rap_part1_days' => '7' ) );
$lr = Resandpay_Reservations::create( $lp, rap_person( 125 ), array( 'notify' => false ) );
rap_t( 'pending' === $lr['status'] && ! $lr['last_minute'] && ! Resandpay_Reservations::is_split( $lr ) && 0 === strpos( $lr['due_at'], wp_date( 'Y-m-d', $now + 7 * $day ) ), 'rezervace 14 dní před pobytem se splatností −30: vše najednou do 7 dní, neplatí se na místě' );
Resandpay_Reservations::process_due( $now + 8 * $day );
rap_t( 'expired' === Resandpay_Reservations::get( $lr['id'] )['status'], '… a po splatnosti normálně expiruje' );

$locD = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::LOCATION, 'post_status' => 'publish', 'post_title' => 'Mirošov - chalupa Navrátilka' ) );
update_post_meta( $lp, '_rap_location', $locD );
rap_t( 'Mirošov – chalupa Navrátilka' === Resandpay_Events::get( $lp )['location'], 'název lokace s pomlčkou bez HTML entity (PDF, e-mail)' );

echo "== 0.6.0: expirace, po splatnosti, připomínky\n";
global $wpdb;
$ex = rap_event( 'Workshop expirace', $now + 5 * $day, $now + 5 * $day + 3 * HOUR_IN_SECONDS, 1, array( 'rap_due_mode' => 'booking', 'rap_due_days' => '1' ) );
$e1 = Resandpay_Reservations::create( $ex, rap_person( 130 ), array( 'now' => $now - 10 * $day, 'notify' => false ) );
rap_t( 0 === Resandpay_Events::free_places( $ex ), 'kapacita 1 obsazena' );
$GLOBALS['rap_mails'] = array();
Resandpay_Reservations::process_due();
$e1 = Resandpay_Reservations::get( $e1['id'] );
rap_t( 'expired' === $e1['status'] && 1 === Resandpay_Events::free_places( $ex ), 'nezaplacená rezervace se splatností před termínem expiruje, místo se uvolní' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Rezervace vypršela' ) && false !== strpos( $m['message'], $e1['vs'] ) ), 'zákazník dostane e-mail o vypršení' );
$oL = Resandpay_Payments::process_raw( rap_avizo( $eml, $e1['vs'], '+500,00 CZK', 'late-1' ), 'cron' );
rap_t( 'matched' === $oL['status'] && 'paid' === Resandpay_Reservations::get( $e1['id'] )['status'], 'pozdní platba, místo volné → rezervace obnovena jako zaplacená' );
$ex2 = rap_event( 'Workshop plno', $now + 5 * $day, $now + 5 * $day + 3 * HOUR_IN_SECONDS, 1, array( 'rap_due_mode' => 'booking', 'rap_due_days' => '1' ) );
$f1 = Resandpay_Reservations::create( $ex2, rap_person( 131 ), array( 'now' => $now - 10 * $day, 'notify' => false ) );
Resandpay_Reservations::process_due();
$f2 = Resandpay_Reservations::create( $ex2, rap_person( 132 ), array( 'notify' => false ) );
$oF = Resandpay_Payments::process_raw( rap_avizo( $eml, $f1['vs'], '+500,00 CZK', 'late-2' ), 'cron' );
rap_t( 'review' === $oF['status'] && false !== strpos( $oF['reason'], 'obsazena' ) && 'expired' === Resandpay_Reservations::get( $f1['id'] )['status'], 'pozdní platba, místo obsazené → k ruční kontrole' );
$dp = rap_event( 'Víkend doplatek', $now + 2 * $day, $now + 3 * $day, 5, array( 'rap_due_mode' => 'event', 'rap_due_offset' => '-1', 'rap_part1_mode' => 'fixed', 'rap_part1_value' => '200', 'rap_part1_days' => '0' ) );
$d1 = Resandpay_Reservations::create( $dp, rap_person( 133 ), array( 'now' => $now - 10 * $day, 'notify' => false ) );
$d1 = Resandpay_Reservations::add_payment( $d1['id'], 200, 'transfer_auto', array( 'notify' => false ) );
rap_t( 'partial' === $d1['status'], '1. část zaplacena před splatností doplatku → Částečně zaplaceno' );
$wpdb->update( $wpdb->prefix . 'resandpay_reservations', array( 'due_at' => wp_date( 'Y-m-d 23:59:59', $now - $day ) ), array( 'id' => (int) $d1['id'] ) ); // Doplatek mezitím po splatnosti.
$GLOBALS['rap_mails'] = array();
Resandpay_Reservations::process_due();
$d1 = Resandpay_Reservations::get( $d1['id'] );
rap_t( 'overdue' === $d1['status'] && 1 === Resandpay_Reservations::count_active( $dp ), 'nezaplacený doplatek po splatnosti → Po splatnosti, místo zůstává (rozhodne správkyně)' );
rap_t( 1 === count( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Připomínka platby' ) && false !== strpos( $m['message'], '300' ) ) ), 'připomínka doplatku 300 Kč' );
$d2 = Resandpay_Reservations::create( $dp, rap_person( 134 ), array( 'now' => $now - 10 * $day, 'notify' => false ) );
Resandpay_Reservations::add_payment( $d2['id'], 100, 'transfer_auto', array( 'notify' => false ) );
Resandpay_Reservations::process_due();
rap_t( 'overdue' === Resandpay_Reservations::get( $d2['id'] )['status'], 'část 1. části zaplacena → po splatnosti neexpiruje, ale Po splatnosti' );
rap_t( 'paid' === Resandpay_Reservations::add_payment( $d2['id'], 400 )['status'], 'po splatnosti lze doplatit → Zaplaceno' );
$rm = rap_event( 'Připomínka', $now + 20 * $day, $now + 20 * $day + 3600, 5, array( 'rap_due_mode' => 'booking', 'rap_due_days' => '11' ) );
$m1 = Resandpay_Reservations::create( $rm, rap_person( 135 ), array( 'now' => $now - 10 * $day, 'notify' => false ) );
$m2 = Resandpay_Reservations::create( $rm, rap_person( 136 ), array( 'notify' => false, 'now' => $now - HOUR_IN_SECONDS ) );
$GLOBALS['rap_mails'] = array();
rap_t( 0 === Resandpay_Reservations::remind_before_due() && ! $GLOBALS['rap_mails'], 'připomínka vypnutá (0 dní) → nic' );
Resandpay_Settings::update( array( 'reminder_days' => 2 ) );
$GLOBALS['rap_mails'] = array();
rap_t( 1 === Resandpay_Reservations::remind_before_due() && false !== strpos( $GLOBALS['rap_mails'][0]['subject'], 'Připomínka splatnosti' ) && false !== strpos( $GLOBALS['rap_mails'][0]['message'], $m1['vs'] ), 'připomínka 2 dny před splatností (nastavitelné)' );
rap_t( 0 === Resandpay_Reservations::remind_before_due(), 'stejná splatnost se připomene jen jednou' );
rap_t( '' === (string) Resandpay_Reservations::get( $m2['id'] )['reminded_due_at'], 'rezervace s delší splatností zatím bez připomínky' );
Resandpay_Settings::update( array( 'reminder_days' => 1, 'bank_account' => '123456789/2700' ) );
$_GET = array( 'page' => 'reserveandpay-detail', 'id' => $d1['id'] );
wp_set_current_user( 1 );
ob_start();
Resandpay_Admin_Reservations::page_detail();
$dd = ob_get_clean();
wp_set_current_user( 0 );
$_GET = array();
rap_t( false !== strpos( $dd, 'Uhrazeno' ) && false !== strpos( $dd, 'zbývá' ) && false !== strpos( $dd, 'Připsat platbu' ), 'detail rezervace: uhrazeno, zbývá, připsání platby' );

echo "== 0.6.0: migrace\n";
global $wpdb;
$wpdb->update( $wpdb->prefix . 'resandpay_reservations', array( 'amount_paid' => 0 ), array( 'id' => (int) $s2['id'] ) );
update_option( 'resandpay_db_version', '4' );
Resandpay_Install::maybe_upgrade();
rap_t( 500.0 === (float) Resandpay_Reservations::get( $s2['id'] )['amount_paid'] && RESANDPAY_DB_VERSION === get_option( 'resandpay_db_version' ), 'upgrade DB 4 → 5: zaplaceným rezervacím doplněna zaplacená částka' );

echo "== 0.6.2: krátký přehled nadcházejících (úvodní stránka)\n";
$ucat = wp_insert_term( 'Úvodní test', Resandpay_Post_Types::CATEGORY, array( 'slug' => 'uvod-test' ) );
$ucat = is_wp_error( $ucat ) ? (int) $ucat->get_error_data() : (int) $ucat['term_id'];
$utag = function ( $id ) use ( $ucat ) {
	wp_set_object_terms( $id, array( $ucat ), Resandpay_Post_Types::CATEGORY );
	return $id;
};
$ufeed = fn( $extra = array() ) => Resandpay_Views::upcoming_feed( array_merge( array( 'category' => 'uvod-test' ), $extra ) );
rap_t( array() === $ufeed() && false !== strpos( Resandpay_Views::render_upcoming( array( 'limit' => 3, 'category' => 'uvod-test', 'first_service' => 'yes', 'more' => 'no' ) ), 'rap-empty' ), 'bez akcí a služeb → prázdný přehled s hláškou' );
$ue1 = $utag( rap_event( 'Uvod A', $now + 3 * $day, $now + 3 * $day + 3600, 10 ) );
$ue2 = $utag( rap_event( 'Uvod B', $now + 5 * $day, $now + 5 * $day + 3600, 10 ) );
$ue3 = $utag( rap_event( 'Uvod C', $now + 7 * $day, $now + 7 * $day + 3600, 10 ) );
$ue4 = $utag( rap_event( 'Uvod D', $now + 9 * $day, $now + 9 * $day + 3600, 10 ) );
$uex = $utag( rap_event( 'Uvod zrušená', $now + 2 * $day, $now + 2 * $day + 3600, 10, array( 'rap_cancelled' => 1 ) ) );
$uer = $utag( rap_event( 'Uvod probíhá', $now - 3600, $now + 2 * $day, 10, array( 'rap_type' => 'multiday' ) ) );
update_post_meta( $uex, '_rap_cancelled', 1 );
$uf = $ufeed();
rap_t( 3 === count( $uf ) && array( $ue1, $ue2, $ue3 ) === array_map( fn( $i ) => $i['event']['id'], $uf ), 'bez služby: 3 nejbližší akce podle data (max. 3)' );
rap_t( ! in_array( $uex, array_map( fn( $i ) => $i['event']['id'], $uf ), true ) && ! in_array( $uer, array_map( fn( $i ) => $i['event']['id'], $uf ), true ), 'zrušená a již probíhající akce se vynechají' );
$usv = $utag( wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Uvod služba' ) ) );
$ud  = wp_date( 'Y-m-d', $now + 6 * $day );
$upv = Resandpay_Services::preview( $usv, array( 'weekdays' => array( (int) wp_date( 'N', $now + 6 * $day ) ), 'time_from' => '10:00', 'time_to' => '11:00', 'date_from' => $ud, 'date_to' => $ud ) );
Resandpay_Services::create_slots( $usv, wp_list_pluck( $upv, 'start' ) );
$uf = $ufeed();
rap_t( 3 === count( $uf ) && 'service' === $uf[0]['kind'] && $usv === (int) $uf[0]['service']['service']['id'], 'služba je vždy první, i když je její termín později než akce' );
rap_t( array( $ue1, $ue2 ) === array( $uf[1]['event']['id'], $uf[2]['event']['id'] ), 'za službou nejbližší akce podle data, celkem max. 3' );
rap_t( 5 === count( $ufeed( array( 'limit' => 5 ) ) ), 'limit lze změnit' );
rap_t( 'event' === $ufeed( array( 'first_service' => false ) )[0]['kind'], 'first_service=no – jen akce' );
$usv2 = $utag( wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Uvod služba dřív' ) ) );
$ud2  = wp_date( 'Y-m-d', $now + 4 * $day );
Resandpay_Services::create_slots( $usv2, wp_list_pluck( Resandpay_Services::preview( $usv2, array( 'weekdays' => array( (int) wp_date( 'N', $now + 4 * $day ) ), 'time_from' => '10:00', 'time_to' => '11:00', 'date_from' => $ud2, 'date_to' => $ud2 ) ), 'start' ) );
$uf = $ufeed();
rap_t( $usv2 === (int) $uf[0]['service']['service']['id'] && 1 === count( array_filter( $uf, fn( $i ) => 'service' === $i['kind'] ) ), 'ze služeb jen jedna – ta s nejbližším volným termínem' );
$uh = Resandpay_Views::render_upcoming( array( 'limit' => 3, 'category' => 'uvod-test', 'first_service' => 'yes', 'more' => 'yes' ) );
rap_t( 1 === substr_count( $uh, 'rap-item--service' ) && 3 === substr_count( $uh, '<article' ) && strpos( $uh, 'Uvod služba dřív' ) < strpos( $uh, 'Uvod A' ), 'shortcode vykreslí kartu služby a akce v pořadí' );
$uevp = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Termíny' ) );
$uold = Resandpay_Settings::get( 'events_page' );
Resandpay_Settings::update( array( 'events_page' => $uevp ) );
rap_t( false !== strpos( Resandpay_Views::render_upcoming( array( 'limit' => 3, 'category' => 'uvod-test', 'first_service' => 'yes', 'more' => 'yes' ) ), get_permalink( $uevp ) ), 'odkaz „Zobrazit všechny termíny“ na stránku s přehledem akcí' );
rap_t( false === strpos( Resandpay_Views::render_upcoming( array( 'limit' => 3, 'category' => 'uvod-test', 'first_service' => 'yes', 'more' => 'no' ) ), 'rap-upcoming__more' ), 'more="no" odkaz vypne' );
Resandpay_Settings::update( array( 'events_page' => $uold ) );
rap_t( false !== strpos( do_shortcode( '[reserveandpay_upcoming category="uvod-test"]' ), 'rap-upcoming' ), 'shortcode [reserveandpay_upcoming] je registrovaný' );

echo "== 0.7.0: detail akce s galerií\n";
require_once ABSPATH . 'wp-admin/includes/image.php';
$rap_img = function ( $name ) {
	$up  = wp_upload_dir();
	$f   = trailingslashit( $up['path'] ) . $name . '-' . wp_generate_password( 6, false ) . '.png';
	$im  = imagecreatetruecolor( 400, 300 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 122, 132, 102 ) );
	imagepng( $im, $f );
	imagedestroy( $im );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ), $f );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $f ) );
	return $id;
};
$din = array( 'rap_type' => 'single', 'rap_start' => wp_date( 'Y-m-d H:i', $now + 15 * $day ), 'rap_end' => wp_date( 'Y-m-d H:i', $now + 15 * $day + 90 * MINUTE_IN_SECONDS ), 'rap_capacity' => 16, 'rap_price' => '500', 'rap_currency' => 'CZK', 'rap_close_hours' => 1 );
$dE  = rap_event( 'Večer s mísami', $now + 15 * $day, $now + 15 * $day + 90 * MINUTE_IN_SECONDS, 16, array( 'rap_close_hours' => 1 ) );
$im1 = $rap_img( 'misy' );
$im2 = $rap_img( 'ruce' );
$im3 = $rap_img( 'sal' );
$doc = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'pdf', 'post_status' => 'inherit' ), 'neni.pdf' );
Resandpay_Events::save_meta( $dE, array_merge( $din, array( 'rap_gallery' => "$im2, $im3,$im2,abc,$doc" ) ) );
rap_t( array( $im2, $im3 ) === Resandpay_Events::get( $dE )['gallery'], 'galerie: jen obrázky, bez duplicit, v zadaném pořadí' );
Resandpay_Events::save_meta( $dE, $din );
rap_t( array( $im2, $im3 ) === Resandpay_Events::get( $dE )['gallery'], 'uložení bez pole galerie (generátor termínů) galerii ponechá' );
set_post_thumbnail( $dE, $im1 );
rap_t( array( $im1, $im2, $im3 ) === Resandpay_Events::photos( $dE ), 'fotky detailu: náhledový obrázek první, za ním galerie' );
wp_update_post( array( 'ID' => $dE, 'post_excerpt' => 'Hodina a půl vleže na podložce.' ) );
wp_set_object_terms( $dE, 'Sound Healing', Resandpay_Post_Types::CATEGORY );
for ( $i = 0; $i < 11; $i++ ) {
	Resandpay_Reservations::create( $dE, rap_person( 200 + $i ) );
}
$pg = Resandpay_Frontend::render_event_page( $dE, '<p>Popis akce z editoru.</p>', true );
rap_t( false !== strpos( $pg, '<h1 class="rap-event__title">Večer s mísami</h1>' ) && false !== strpos( $pg, 'Hodina a půl vleže na podložce.' ) && false !== strpos( $pg, '<p class="rap-eyebrow">Sound Healing</p>' ), 'záhlaví: nadpis, perex z úryvku a kategorie' );
rap_t( 3 === substr_count( $pg, 'class="rap-gallery__thumb"' ) && false !== strpos( $pg, 'data-rap-main' ), 'galerie: velká fotka a 3 náhledy' );
rap_t( strpos( $pg, 'rap-event__hero' ) < strpos( $pg, 'Popis akce z editoru.' ) && strpos( $pg, 'Popis akce z editoru.' ) < strpos( $pg, 'id="rap-booking"' ), 'pořadí: záhlaví, popis, rezervační karta' );
rap_t( false !== strpos( $pg, 'rap-booking--card' ) && false !== strpos( $pg, '<strong>5 volných míst</strong> z 16' ) && 11 === substr_count( $pg, '<i class="is-taken"></i>' ) && false !== strpos( $pg, 'Uzávěrka ' ), 'karta: 5 volných míst z 16, 11 obsazených teček, uzávěrka' );
rap_t( false !== strpos( $pg, 'name="rap_name"' ) && false !== strpos( $pg, 'Rezervovat místo →' ) && false !== strpos( $pg, '500 Kč' ), 'karta obsahuje formulář, tlačítko v záhlaví a cenu' );
rap_t( false === strpos( Resandpay_Frontend::render_event_page( $dE, '', false ), '<h1' ), 'bez vlastního nadpisu se H1 nevykreslí' );
$dF = rap_event( 'Bez fotek', $now + 16 * $day, $now + 16 * $day + HOUR_IN_SECONDS, 2 );
Resandpay_Reservations::create( $dF, rap_person( 230 ) );
Resandpay_Reservations::create( $dF, rap_person( 231 ) );
$pf = Resandpay_Frontend::render_event_page( $dF, '', true );
rap_t( false !== strpos( $pf, 'rap-gallery--empty' ) && false === strpos( $pf, 'name="rap_name"' ) && false !== strpos( $pf, 'rap-event__full' ) && false === strpos( $pf, 'href="#rap-booking"' ), 'bez fotek náhradní plocha; obsazená akce bez formuláře a tlačítka' );
rap_t( 'full' === Resandpay_Settings::get( 'detail_layout' ) && Resandpay_Frontend::full_layout(), 'výchozí vzhled detailu: s galerií' );
Resandpay_Settings::update( array( 'detail_layout' => 'basic' ) );
rap_t( ! Resandpay_Frontend::full_layout(), 'nastavení „Jednoduchý“ vrátí dosavadní vzhled' );
Resandpay_Settings::update( array( 'detail_layout' => 'full' ) );
$dup = Resandpay_Admin_Events::duplicate( $dE );
rap_t( array( $im2, $im3 ) === Resandpay_Events::get( $dup )['gallery'] && $im1 === (int) get_post_thumbnail_id( $dup ), 'duplikace akce převezme galerii i náhledový obrázek' );
$_GET['rap_done'] = Resandpay_Reservations::create( $dE, rap_person( 240 ) )['token'];
$pc = Resandpay_Frontend::render_event_page( $dE, '', true );
unset( $_GET['rap_done'] );
rap_t( false !== strpos( $pc, 'rap-success' ) && false === strpos( $pc, 'name="rap_name"' ), 'potvrzení rezervace se zobrazí v kartě místo formuláře' );

echo "== 0.7.2: seznam odeslaných e-mailů\n";
require_once RESANDPAY_DIR . 'admin/class-admin-tools.php';
global $wpdb;
$wpdb->query( 'DELETE FROM ' . Resandpay_Mailer::log_table() );
$ml = rap_event( 'Log e-mailů', $now + 40 * $day, $now + 40 * $day + 3600, 5 );
Resandpay_Settings::update( array( 'test_mode' => 0, 'notify_admin' => 1, 'admin_email' => 'spravce@example.com' ) );
$mr = Resandpay_Reservations::create( $ml, rap_person( 140 ) );
$rows = Resandpay_Mailer::log_rows( array( 'reservation_id' => $mr['id'] ) )['rows'];
$keys = wp_list_pluck( $rows, 'mail_key' );
rap_t( 2 === count( $rows ) && in_array( 'received', $keys, true ) && in_array( 'admin', $keys, true ), 'po rezervaci zapsány 2 e-maily (zákazník, správce)' );
$rc = array_values( array_filter( $rows, fn( $x ) => 'received' === $x['mail_key'] ) )[0];
rap_t( 'osoba140@example.com' === $rc['recipient'] && 'osoba140@example.com' === $rc['sent_to'] && 1 === (int) $rc['ok'] && false !== strpos( $rc['subject'], 'Rezervace přijata' ) && $mr['vs'] === $rc['vs'], 'záznam: příjemce, předmět, výsledek, VS' );
Resandpay_Reservations::add_payment( $mr['id'], 500, 'cash' );
rap_t( 'paid' === Resandpay_Mailer::log_rows( array( 'reservation_id' => $mr['id'], 'limit' => 1 ) )['rows'][0]['mail_key'], 'potvrzení platby zapsáno' );
Resandpay_Settings::update( array( 'test_mode' => 1, 'test_email' => 'test@example.com' ) );
Resandpay_Mailer::send_template( 'zakaznik@example.com', 'reminder', $mr );
$tl = Resandpay_Mailer::log_rows( array( 'reservation_id' => $mr['id'], 'limit' => 1 ) )['rows'][0];
rap_t( 'zakaznik@example.com' === $tl['recipient'] && 'test@example.com' === $tl['sent_to'], 'testovací režim: původní příjemce i skutečná testovací adresa' );
add_filter( 'pre_wp_mail', '__return_false', 99 );
Resandpay_Mailer::send_template( 'chyba@example.com', 'paid', $mr );
remove_filter( 'pre_wp_mail', '__return_false', 99 );
rap_t( 0 === (int) Resandpay_Mailer::log_rows( array( 'only_failed' => true ) )['rows'][0]['ok'] && 1 === Resandpay_Mailer::log_rows( array( 'only_failed' => true ) )['total'], 'neúspěšné odeslání se zapíše jako chyba (filtr „jen chyby“)' );
rap_t( 1 <= Resandpay_Mailer::log_rows( array( 'search' => $mr['vs'] ) )['total'] && 0 === Resandpay_Mailer::log_rows( array( 'search' => 'nikdo@nikde.cz' ) )['total'], 'hledání podle VS a e-mailu' );
$wpdb->update( Resandpay_Mailer::log_table(), array( 'created_at' => '2020-01-01 00:00:00' ), array( 'mail_key' => 'admin', 'reservation_id' => $mr['id'] ) );
Resandpay_Mailer::prune_log();
rap_t( 0 === Resandpay_Mailer::log_rows( array( 'search' => 'spravce@example.com' ) )['total'], 'staré záznamy se automaticky mažou' );
wp_set_current_user( 1 );
$_GET = array( 'page' => 'reserveandpay-tools' );
ob_start();
Resandpay_Admin_Tools::page();
$tp = ob_get_clean();
$_GET = array( 'page' => 'reserveandpay-detail', 'id' => $mr['id'] );
ob_start();
Resandpay_Admin_Reservations::page_detail();
$dp2 = ob_get_clean();
$_GET = array();
wp_set_current_user( 0 );
rap_t( false !== strpos( $tp, 'Odeslané e-maily' ) && false !== strpos( $tp, 'Rezervace přijata' ) && false !== strpos( $tp, 'testovací režim → test@example.com' ), 'Nástroje: seznam odeslaných e-mailů' );
rap_t( false !== strpos( $dp2, 'Odeslané e-maily' ) && false !== strpos( $dp2, 'Platba přijata' ) && false !== strpos( $dp2, '✗' ), 'detail rezervace: odeslané e-maily vč. chyby' );
Resandpay_Settings::update( array( 'test_mode' => 1 ) );

echo "== 0.7.3: ochrana formuláře proti robotům\n";
global $wpdb;
delete_option( Resandpay_Antibot::STATS );
delete_transient( 'resandpay_antibot_notified' );
Resandpay_Settings::update( array( 'antibot_min_seconds' => 3, 'rate_ip_window_minutes' => 10, 'rate_ip_max_window' => 3, 'rate_ip_max_day' => 10, 'rate_email_max_event' => 3, 'rate_notify' => 1, 'admin_email' => 'spravce@example.com', 'turnstile_enabled' => 0 ) );
$ab   = rap_event( 'Ochrana', $now + 45 * $day, $now + 45 * $day + 3600, 30 );
$ok_p = array( 'rap_ts' => Resandpay_Antibot::sign_time( $now - 20 ) );
$h1   = Resandpay_Antibot::ip_hash( '203.0.113.7' );
rap_t( 40 === strlen( $h1 ) && $h1 === Resandpay_Antibot::ip_hash( '203.0.113.7' ) && $h1 !== Resandpay_Antibot::ip_hash( '203.0.113.8' ) && false === strpos( $h1, '203' ), 'IP adresa jen jako nevratný otisk' );
rap_t( true === Resandpay_Antibot::check( $ok_p, $ab, 'clovek@example.com', array( 'now' => $now, 'ip_hash' => $h1 ) ), 'běžné vyplnění projde' );
rap_t( 'antibot_honeypot' === Resandpay_Antibot::check( $ok_p + array( 'rap_website' => 'http://spam' ), $ab, 'a@example.com', array( 'now' => $now, 'ip_hash' => $h1 ) )->get_error_code(), 'vyplněné skryté pole → zablokováno' );
rap_t( 'antibot_too_fast' === Resandpay_Antibot::check( array( 'rap_ts' => Resandpay_Antibot::sign_time( $now - 1 ) ), $ab, 'a@example.com', array( 'now' => $now, 'ip_hash' => $h1 ) )->get_error_code(), 'odeslání do 3 s → zablokováno' );
rap_t( 'antibot_too_fast' === Resandpay_Antibot::check( array( 'rap_ts' => ( $now - 60 ) . '.0123456789abcdef0123' ), $ab, 'a@example.com', array( 'now' => $now, 'ip_hash' => $h1 ) )->get_error_code() && 'antibot_too_fast' === Resandpay_Antibot::check( array(), $ab, 'a@example.com', array( 'now' => $now, 'ip_hash' => $h1 ) )->get_error_code(), 'podvržený nebo chybějící čas → zablokováno' );
Resandpay_Settings::update( array( 'antibot_min_seconds' => 0 ) );
rap_t( true === Resandpay_Antibot::check( array(), $ab, 'a@example.com', array( 'now' => $now, 'ip_hash' => $h1 ) ), 'minimální doba 0 = nekontroluje se' );
Resandpay_Settings::update( array( 'antibot_min_seconds' => 3 ) );
$form = Resandpay_Frontend::render_booking( $ab );
rap_t( false !== strpos( $form, 'name="rap_ts"' ) && false !== strpos( $form, 'name="rap_website"' ) && false === strpos( $form, 'cf-turnstile' ), 'formulář: skryté pole a podepsaný čas, Turnstile vypnutý' );
for ( $i = 0; $i < 3; $i++ ) {
	Resandpay_Reservations::create( $ab, array_merge( rap_person( 150 + $i ), array( 'ip_hash' => $h1 ) ), array( 'notify' => false ) );
}
$GLOBALS['rap_mails'] = array();
$rb = Resandpay_Antibot::check( $ok_p, $ab, 'dalsi@example.com', array( 'now' => time(), 'ip_hash' => $h1 ) );
rap_t( is_wp_error( $rb ) && 'antibot_rate_ip' === $rb->get_error_code() && false !== strpos( $rb->get_error_message(), 'později' ), '4. rezervace z jedné IP za 10 min → zablokováno se zdvořilou hláškou' );
rap_t( 1 === count( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'zablokované rezervace' ) ) ), 'správce dostane upozornění' );
$GLOBALS['rap_mails'] = array();
Resandpay_Antibot::check( $ok_p, $ab, 'dalsi@example.com', array( 'now' => time(), 'ip_hash' => $h1 ) );
rap_t( ! $GLOBALS['rap_mails'], 'další upozornění nejdříve za hodinu' );
rap_t( true === Resandpay_Antibot::check( $ok_p, $ab, 'dalsi@example.com', array( 'now' => time() + 11 * MINUTE_IN_SECONDS, 'ip_hash' => $h1 ) ), 'po uplynutí okna (10 min) opět povoleno' );
Resandpay_Settings::update( array( 'rate_ip_max_window' => 0, 'rate_ip_max_day' => 3 ) );
rap_t( 'antibot_rate_ip_day' === Resandpay_Antibot::check( $ok_p, $ab, 'dalsi@example.com', array( 'now' => time() + HOUR_IN_SECONDS, 'ip_hash' => $h1 ) )->get_error_code(), 'denní limit z jedné IP (parametr)' );
Resandpay_Settings::update( array( 'rate_ip_max_day' => 0 ) );
rap_t( true === Resandpay_Antibot::check( $ok_p, $ab, 'dalsi@example.com', array( 'now' => time(), 'ip_hash' => $h1 ) ), 'limity 0 = bez limitu' );
$h2 = Resandpay_Antibot::ip_hash( '198.51.100.1' );
for ( $i = 0; $i < 3; $i++ ) {
	Resandpay_Reservations::create( $ab, array_merge( rap_person( 160 ), array( 'ip_hash' => Resandpay_Antibot::ip_hash( '198.51.100.' . ( 10 + $i ) ) ) ), array( 'notify' => false ) );
}
$re = Resandpay_Antibot::check( $ok_p, $ab, 'osoba160@example.com', array( 'now' => time(), 'ip_hash' => $h2 ) );
rap_t( is_wp_error( $re ) && 'antibot_rate_email' === $re->get_error_code() && false !== strpos( $re->get_error_message(), '3 rezervace' ), 'limit aktivních rezervací jednoho e-mailu u akce (3)' );
Resandpay_Settings::update( array( 'rate_email_max_event' => 5 ) );
rap_t( true === Resandpay_Antibot::check( $ok_p, $ab, 'osoba160@example.com', array( 'now' => time(), 'ip_hash' => $h2 ) ), 'limit e-mailu je parametr (5)' );
$adm = Resandpay_Reservations::create( $ab, array_merge( rap_person( 161 ), array( 'ip_hash' => $h1 ) ), array( 'notify' => false, 'source' => 'admin' ) );
rap_t( '' === $adm['ip_hash'], 'ruční rezervace v administraci se neomezuje a neukládá otisk' );
$wpdb->update( Resandpay_Reservations::table(), array( 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'customer_name' => 'Test Osoba 150', 'event_id' => $ab ) );
Resandpay_Antibot::prune();
rap_t( '' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ip_hash FROM ' . Resandpay_Reservations::table() . ' WHERE event_id = %d AND customer_name = %s', $ab, 'Test Osoba 150' ) ), 'otisky IP starší než 30 dní se mažou' );
Resandpay_Settings::update( array( 'turnstile_enabled' => 1, 'turnstile_site_key' => '1x00000000000000000000AA', 'turnstile_secret' => 'tajne' ) );
$tsr = null;
$tsf = function ( $pre, $args, $url ) use ( &$tsr ) {
	if ( false === strpos( $url, 'challenges.cloudflare.com' ) ) {
		return $pre;
	}
	return array( 'body' => wp_json_encode( array( 'success' => 'ok-token' === ( $args['body']['response'] ?? '' ) ) ), 'response' => array( 'code' => 200 ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $tsf, 10, 3 );
rap_t( false !== strpos( Resandpay_Frontend::render_booking( $ab ), 'cf-turnstile' ), 'Turnstile zapnutý: widget ve formuláři' );
rap_t( 'antibot_turnstile' === Resandpay_Antibot::check( $ok_p, $ab, 'x@example.com', array( 'now' => time(), 'ip_hash' => $h2 ) )->get_error_code(), 'Turnstile: bez ověření → zablokováno' );
rap_t( true === Resandpay_Antibot::check( $ok_p + array( 'cf-turnstile-response' => 'ok-token' ), $ab, 'x@example.com', array( 'now' => time(), 'ip_hash' => $h2 ) ), 'Turnstile: ověřeno → projde' );
remove_filter( 'pre_http_request', $tsf, 10 );
Resandpay_Settings::update( array( 'turnstile_enabled' => 0, 'rate_ip_max_window' => 3, 'rate_ip_max_day' => 10, 'rate_email_max_event' => 3 ) );
wp_set_current_user( 1 );
ob_start();
Resandpay_Admin_Tools::page();
$tp3 = ob_get_clean();
wp_set_current_user( 0 );
rap_t( false !== strpos( $tp3, 'Ochrana formuláře proti robotům' ) && false !== strpos( $tp3, 'limit rezervací z jedné IP adresy za okno' ), 'Nástroje: přehled ochrany a zablokovaných pokusů' );

echo "== 0.7.4: evidence plateb, odpárování, smazání\n";
global $wpdb;
require_once RESANDPAY_DIR . 'admin/class-reservations-table.php';
Resandpay_Settings::update( array( 'bank_account' => '8032378004/5500', 'test_mode' => 1 ) );
$up  = rap_event( 'Odpárování', $now + 60 * $day, $now + 60 * $day + 3600, 10 );
$u1  = Resandpay_Reservations::create( $up, rap_person( 170 ), array( 'notify' => false ) );
$oU  = Resandpay_Payments::process_raw( rap_avizo( $eml, $u1['vs'], '+500,00 CZK', 'unpair-1' ), 'cron' );
$lg  = Resandpay_Reservations::ledger( $u1['id'] );
rap_t( 'matched' === $oU['status'] && 1 === count( $lg ) && (int) $oU['payment_id'] === (int) $lg[0]['payment_id'] && 500.0 === (float) $lg[0]['amount'], 'platba z avíza zapsána do evidence plateb s vazbou na avízo' );
rap_t( 'reason' === Resandpay_Payments::unassign( $oU['payment_id'], '' )->get_error_code(), 'odpárování bez důvodu odmítnuto' );
$GLOBALS['rap_mails'] = array();
$v1 = Resandpay_Payments::unassign( $oU['payment_id'], 'patří jiné rezervaci' );
$pU = Resandpay_Payments::get( $oU['payment_id'] );
rap_t( 'pending' === $v1['status'] && 0.0 === (float) $v1['amount_paid'] && empty( $v1['paid_at'] ) && empty( $v1['due_passed'] ), 'odpárováno: rezervace zpět Čeká na platbu, zaplaceno 0, místo zůstává' );
rap_t( 'review' === $pU['status'] && 0 === (int) $pU['reservation_id'] && false !== strpos( $pU['reason'], 'patří jiné rezervaci' ), 'avízo vráceno k ruční kontrole s důvodem' );
rap_t( null !== Resandpay_Reservations::ledger( $u1['id'] )[0]['voided_at'] && ! $GLOBALS['rap_mails'], 'platba v evidenci označena jako odpárovaná, zákazníkovi nic nepřišlo' );
$u2 = Resandpay_Reservations::create( $up, rap_person( 171 ), array( 'notify' => false ) );
$as = Resandpay_Payments::assign( $oU['payment_id'], $u2['vs'] );
rap_t( 'paid' === $as['status'] && (int) $oU['payment_id'] === (int) Resandpay_Reservations::ledger( $u2['id'] )[0]['payment_id'], 'odpárované avízo lze přiřadit jiné rezervaci' );
$u3 = Resandpay_Reservations::create( $up, rap_person( 172 ), array( 'notify' => false ) );
Resandpay_Reservations::add_payment( $u3['id'], 200, 'cash', array( 'notify' => false ) );
$o3 = Resandpay_Payments::process_raw( rap_avizo( $eml, $u3['vs'], '+300,00 CZK', 'unpair-3' ), 'cron' );
rap_t( 'paid' === Resandpay_Reservations::get( $u3['id'] )['status'] && 2 === count( Resandpay_Reservations::ledger( $u3['id'] ) ), 'dvě platby (200 hotově + 300 převodem) v evidenci' );
$GLOBALS['rap_mails'] = array();
$v3 = Resandpay_Payments::unassign( $o3['payment_id'], 'chybné avízo', array( 'notify' => true ) );
rap_t( 'partial' === $v3['status'] && 200.0 === (float) $v3['amount_paid'] && 300.0 === Resandpay_Reservations::remaining( $v3 ), 'odpárování jedné platby: Částečně zaplaceno, zbývá 300' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Připomínka splatnosti' ) ), 'volitelně zákazníkovi aktuální platební údaje' );
$u4 = Resandpay_Reservations::create( $up, rap_person( 173 ), array( 'notify' => false ) );
Resandpay_Admin_Reservations::do_action( 'paid_onsite', $u4['id'] );
$l4 = Resandpay_Reservations::ledger( $u4['id'] );
rap_t( 1 === count( $l4 ) && 'cash' === $l4[0]['method'] && 500.0 === (float) $l4[0]['amount'], 'ruční „zaplaceno hotově“ zapsáno do evidence' );
$_REQUEST = array( 'ledger' => $l4[0]['id'] );
$v4 = Resandpay_Admin_Reservations::do_action( 'void_payment', $u4['id'], 'omylem označeno' );
$_REQUEST = array();
rap_t( 'pending' === $v4['status'] && 0.0 === (float) $v4['amount_paid'], 'odpárování ruční platby v detailu rezervace' );
rap_t( is_wp_error( Resandpay_Reservations::void_payment( $l4[0]['id'], 'znovu' ) ), 'odpárovanou platbu nelze odpárovat znovu' );
$past = rap_event( 'Odpárování po splatnosti', $now + 20 * $day, $now + 20 * $day + 3600, 5, array( 'rap_due_mode' => 'booking', 'rap_due_days' => '1' ) );
$u5 = Resandpay_Reservations::create( $past, rap_person( 174 ), array( 'notify' => false, 'now' => $now - 5 * $day ) );
Resandpay_Reservations::add_payment( $u5['id'], 500, 'transfer_manual', array( 'notify' => false ) );
$v5 = Resandpay_Reservations::void_payment( Resandpay_Reservations::ledger( $u5['id'] )[0]['id'], 'test' );
rap_t( ! empty( $v5['due_passed'] ), 'po splatnosti: odpárování upozorní, že rezervace při další kontrole vyprší' );
Resandpay_Reservations::process_due();
rap_t( 'expired' === Resandpay_Reservations::get( $u5['id'] )['status'], '… a běžná logika splatnosti ji zpracuje' );
rap_t( 'state' === Resandpay_Reservations::delete( $u3['id'] )->get_error_code(), 'smazat nelze nezrušenou rezervaci' );
Resandpay_Reservations::set_status( $u3['id'], Resandpay_Reservations::CANCELLED );
rap_t( 'paid' === Resandpay_Reservations::delete( $u3['id'] )->get_error_code() && ! isset( Resandpay_Admin_Reservations::row_actions_for( Resandpay_Reservations::get( $u3['id'] ) )['delete'] ), 'zrušenou rezervaci s platbou nelze smazat (nejdřív odpárovat)' );
Resandpay_Reservations::void_payment( Resandpay_Reservations::ledger( $u3['id'] )[0]['id'], 'vráceno zákazníkovi' );
Resandpay_Mailer::send_template( 'osoba172@example.com', 'cancelled', Resandpay_Reservations::get( $u3['id'] ) );
rap_t( isset( Resandpay_Admin_Reservations::row_actions_for( Resandpay_Reservations::get( $u3['id'] ) )['delete'] ), 'po odpárování nabídne „Smazat trvale“' );
$o3p = Resandpay_Payments::get( $o3['payment_id'] );
rap_t( true === Resandpay_Reservations::delete( $u3['id'] ) && null === Resandpay_Reservations::get( $u3['id'] ) && ! Resandpay_Reservations::ledger( $u3['id'] ) && 0 === Resandpay_Mailer::log_rows( array( 'reservation_id' => $u3['id'] ) )['total'], 'smazání: rezervace, evidence plateb i e-maily pryč' );
$u6 = Resandpay_Reservations::create( $up, rap_person( 175 ), array( 'notify' => false ) );
$u7 = Resandpay_Reservations::create( $up, rap_person( 176 ), array( 'notify' => false ) );
Resandpay_Reservations::set_status( $u6['id'], Resandpay_Reservations::CANCELLED );
$_REQUEST = $_GET = array( 'page' => 'reserveandpay', 'ids' => array( $u6['id'], $u7['id'] ), 'action' => 'delete' );
$ok6 = ! is_wp_error( Resandpay_Admin_Reservations::do_action( 'delete', $u6['id'] ) ) && is_wp_error( Resandpay_Admin_Reservations::do_action( 'delete', $u7['id'] ) );
$_REQUEST = $_GET = array();
rap_t( $ok6 && null === Resandpay_Reservations::get( $u6['id'] ) && null !== Resandpay_Reservations::get( $u7['id'] ), 'hromadné smazání: zrušená smazána, aktivní přeskočena' );
$seq_before = (int) get_option( 'resandpay_sequence' );
$u8 = Resandpay_Reservations::create( $up, rap_person( 177 ), array( 'notify' => false ) );
rap_t( $u8['vs'] !== $u6['vs'] && (int) get_option( 'resandpay_sequence' ) > $seq_before, 'VS smazané rezervace se znovu nepoužije' );
$wpdb->insert( Resandpay_Reservations::table(), array_merge( Resandpay_Reservations::get( $u8['id'] ), array( 'id' => null, 'vs' => '99990001', 'token' => wp_generate_password( 40, false, false ), 'status' => 'paid', 'amount_paid' => 500, 'paid_method' => 'cash', 'paid_at' => current_time( 'mysql' ) ) ) );
$mid = (int) $wpdb->insert_id;
update_option( 'resandpay_db_version', '7' );
Resandpay_Install::maybe_upgrade();
$lm = Resandpay_Reservations::ledger( $mid );
rap_t( 1 === count( $lm ) && 500.0 === (float) $lm[0]['amount'] && 'cash' === $lm[0]['method'] && RESANDPAY_DB_VERSION === get_option( 'resandpay_db_version' ), 'upgrade DB 7 → 8: dřívější platby převedeny do evidence' );
wp_set_current_user( 1 );
$_GET = array( 'page' => 'reserveandpay-detail', 'id' => $u2['id'] );
ob_start();
Resandpay_Admin_Reservations::page_detail();
$dd4 = ob_get_clean();
$_GET = array( 'page' => 'reserveandpay-payments', 'status' => 'all' );
ob_start();
Resandpay_Admin_Payments::page();
$pp4 = ob_get_clean();
$_GET = array();
wp_set_current_user( 0 );
rap_t( false !== strpos( $dd4, 'Přijaté platby' ) && false !== strpos( $dd4, 'Odpárovat platbu' ), 'detail rezervace: přijaté platby s odpárováním' );
rap_t( false !== strpos( $pp4, 'Odpárovat od rezervace' ), 'stránka Platby: odpárování spárovaného avíza' );
Resandpay_Settings::update( array( 'bank_account' => '123456789/2700' ) );

echo "== 0.7.5: dynamický prefix VS\n";
$t26 = Resandpay_Events::ts( '2026-10-22 12:00:00' );
$t27 = Resandpay_Events::ts( '2027-01-05 12:00:00' );
rap_t( '26' === Resandpay_Reservations::vs_prefix( '%YY%', $t26 ) && '27' === Resandpay_Reservations::vs_prefix( '%yy%', $t27 ) && '2027' === Resandpay_Reservations::vs_prefix( '%YYYY%', $t27 ) && '2601' === Resandpay_Reservations::vs_prefix( '%YY%01', $t26 ) && '2701' === Resandpay_Reservations::vs_prefix( '%YY%%MM%', $t27 ), 'proměnné %YY%, %YYYY%, %MM% a číslice' );
Resandpay_Settings::update( array( 'vs_prefix' => '%YY%' ) );
rap_t( '26000123' === Resandpay_Reservations::format_vs( 123, $t26 ) && '27000124' === Resandpay_Reservations::format_vs( 124, $t27 ), '%YY%: VS 26000123 letos, 27000124 příští rok' );
Resandpay_Settings::update( array( 'vs_prefix' => '%YYYY%' ) );
rap_t( '2027000124' === Resandpay_Reservations::format_vs( 124, $t27 ), '%YYYY%: VS 2027000124 (10 číslic)' );
$vsE = rap_event( 'VS prefix', $now + 70 * $day, $now + 70 * $day + 3600, 5 );
$vr  = Resandpay_Reservations::create( $vsE, rap_person( 180 ), array( 'notify' => false ) );
rap_t( 0 === strpos( $vr['vs'], wp_date( 'Y' ) ) && 10 === strlen( $vr['vs'] ) && (int) $vr['id'] === (int) Resandpay_Reservations::find_by_vs_input( $vr['vs'] )['id'], 'rezervace dostane VS s aktuálním rokem a lze ji podle něj najít' );
rap_t( false !== strpos( Resandpay_QR::spd( $vr ), '*X-VS:' . $vr['vs'] ), 'QR platba nese VS s prefixem' );
$san = Resandpay_Admin_Settings::sanitize( array_merge( Resandpay_Settings::all(), array( '__form' => 1, 'vs_prefix' => ' %yy% ' ) ) );
rap_t( '%YY%' === $san['vs_prefix'], 'nastavení: proměnná se uloží (velká písmena, bez mezer)' );
$san = Resandpay_Admin_Settings::sanitize( array_merge( Resandpay_Settings::all(), array( '__form' => 1, 'vs_prefix' => '%YYYY%9' ) ) );
rap_t( '%YYYY%' === $san['vs_prefix'], 'nastavení: víc než 4 číslice po dosazení → ponechán původní prefix' );
$san = Resandpay_Admin_Settings::sanitize( array_merge( Resandpay_Settings::all(), array( '__form' => 1, 'vs_prefix' => 'AB%DD%' ) ) );
rap_t( '%YYYY%' === $san['vs_prefix'], 'nastavení: neznámá proměnná nebo písmena → ponechán původní prefix' );
Resandpay_Settings::update( array( 'vs_prefix' => '26' ) );

echo "== 0.7.6: zobrazení času akcí a termínů\n";
Resandpay_Settings::update( array( 'time_display_default' => 'show' ) );
$tdE = rap_event( 'Jóga víkend bez času', Resandpay_Events::ts( '2027-03-12 14:00:00' ), Resandpay_Events::ts( '2027-03-14 14:00:00' ), 10 );
rap_t( Resandpay_Events::get( $tdE )['show_time'] && '12. 3. 2027 14:00 – 14. 3. 2027 14:00' === Resandpay_Events::format_date( Resandpay_Events::get( $tdE ) ), 'výchozí: čas se zobrazuje' );
Resandpay_Events::save_time_display_meta( $tdE, array( 'rap_time_display' => 'date' ) );
$tde = Resandpay_Events::get( $tdE );
rap_t( ! $tde['show_time'] && '12.–14. 3. 2027' === Resandpay_Events::format_date( $tde ), 'akce „jen datum“: 12.–14. 3. 2027' );
rap_t( '12. 3. 2027 14:00 – 14. 3. 2027 14:00' === Resandpay_Events::format_date_full( $tde ), 'administrace: čas vždy' );
rap_t( 'pá 12. – ne 14. 3. 2027' === Resandpay_Views::when_line( $tde ), 'web: řádek Kdy bez času' );
rap_t( '28. 2. – 2. 3. 2027' === Resandpay_Events::format_date_only( Resandpay_Events::ts( '2027-02-28 10:00:00' ), Resandpay_Events::ts( '2027-03-02 12:00:00' ) ) && '30. 12. 2026 – 2. 1. 2027' === Resandpay_Events::format_date_only( Resandpay_Events::ts( '2026-12-30 10:00:00' ), Resandpay_Events::ts( '2027-01-02 12:00:00' ) ) && '5. 6. 2027' === Resandpay_Events::format_date_only( Resandpay_Events::ts( '2027-06-05 10:00:00' ), Resandpay_Events::ts( '2027-06-05 12:00:00' ) ), 'formáty jen data (přes měsíc, přes rok, jeden den)' );
$tdr = Resandpay_Reservations::create( $tdE, rap_person( 190 ), array( 'notify' => false, 'now' => Resandpay_Events::ts( '2026-12-01 10:00:00' ) ) );
$tdb = Resandpay_Mailer::build( 'received', $tdr );
rap_t( false !== strpos( $tdb['html'], '12.–14. 3. 2027' ) && false === strpos( $tdb['html'], '14:00' ), 'e-mail zákazníkovi bez času' );
rap_t( false !== strpos( Resandpay_Mailer::render( '{date}', $tdr, 'admin' ), '14:00' ), 'e-mail správci s časem' );
Resandpay_Events::save_time_display_meta( $tdE, array( 'rap_time_display' => 'show' ) );
Resandpay_Settings::update( array( 'time_display_default' => 'date' ) );
rap_t( Resandpay_Events::get( $tdE )['show_time'], 'volba u akce má přednost před výchozí' );
Resandpay_Events::save_time_display_meta( $tdE, array() );
rap_t( Resandpay_Events::get( $tdE )['show_time'], 'bez pole ve formuláři se volba nemění' );
Resandpay_Events::save_time_display_meta( $tdE, array( 'rap_time_display' => '' ) );
rap_t( ! Resandpay_Events::get( $tdE )['show_time'], 'prázdná volba = výchozí z nastavení (jen datum)' );
Resandpay_Settings::update( array( 'time_display_default' => 'show' ) );
$tdS = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Celodenní kurz' ) );
Resandpay_Admin_Services::save_service_meta( $tdS, array( 'rap_duration' => 480, 'rap_pause' => 0, 'rap_capacity' => 8, 'rap_price' => 2500, 'rap_time_display' => 'date' ) );
$pvT = Resandpay_Services::preview( $tdS, array( 'weekdays' => array( 6 ), 'time_from' => '09:00', 'time_to' => '17:00', 'date_from' => '2027-08-07', 'date_to' => '2027-08-07' ) );
Resandpay_Services::create_slots( $tdS, wp_list_pluck( $pvT, 'start' ) );
$slT = array_values( Resandpay_Services::slots( $tdS, date_create_immutable( '2027-08-07 00:00:00', $tzz ), date_create_immutable( '2027-08-08 00:00:00', $tzz ) ) );
rap_t( $slT && ! $slT[0]['show_time'] && '7. 8. 2027' === Resandpay_Events::format_date( $slT[0] ), 'termín služby přebírá „jen datum“ ze služby' );
Resandpay_Admin_Services::save_service_meta( $tdS, array( 'rap_duration' => 480, 'rap_pause' => 0, 'rap_capacity' => 8, 'rap_price' => 2500, 'rap_time_display' => 'show' ) );
rap_t( Resandpay_Events::get( $slT[0]['id'] )['show_time'], 'změna u služby platí i pro již vypsané termíny' );
Resandpay_Admin_Services::save_service_meta( $tdS, array( 'rap_duration' => 480, 'rap_pause' => 0, 'rap_capacity' => 8, 'rap_price' => 2500, 'rap_time_display' => 'date' ) );
$_GET = array( 'rap_from' => '2027-08-07' );
$slh = Resandpay_Views::render_slots( array( 'service' => $tdS, 'days' => 7 ) );
$_GET = array();
rap_t( is_string( $slh ) && false !== strpos( $slh, '>Rezervovat<' ) && false === strpos( $slh, '>9:00<' ), 'výběr volných termínů: tlačítko „Rezervovat“ místo času' );
$lsT = Resandpay_Views::render_list( array( 'limit' => 50, 'category' => '' ) );
rap_t( is_string( $lsT ) && (bool) preg_match( '/Nejbližší volný termín: \S+ \d{1,2}\. \d{1,2}\. \d{4}/u', $lsT ), '0.8.4: nejbližší volný termín služby s rokem' );

echo "== 0.8.5: výpis termínů služby začíná nejbližším volným termínem\n";
$fy  = (int) wp_date( 'Y' ) + 3;
$fjS = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Vzdálená služba' ) );
Resandpay_Admin_Services::save_service_meta( $fjS, array( 'rap_duration' => 60, 'rap_pause' => 0, 'rap_capacity' => 1, 'rap_price' => 1000, 'rap_time_display' => 'show' ) );
$pvF = Resandpay_Services::preview( $fjS, array( 'weekdays' => array( 1, 2, 3, 4, 5, 6, 0 ), 'time_from' => '10:00', 'time_to' => '11:00', 'date_from' => $fy . '-03-02', 'date_to' => $fy . '-03-03' ) );
Resandpay_Services::create_slots( $fjS, wp_list_pluck( $pvF, 'start' ) );
$fFirst = Resandpay_Services::first_free( $fjS, new DateTimeImmutable( 'now', wp_timezone() ) );
rap_t( $fFirst && 0 === strpos( $fFirst['start'], $fy . '-03-02 10:00' ), 'první volný termín se najde i víc než rok dopředu' );
$_GET = array();
$fh = Resandpay_Views::render_slots( array( 'service' => $fjS, 'days' => 14 ) );
rap_t( false !== strpos( $fh, '2. 3. ' . $fy . ' 10:00' ) && false !== strpos( $fh, 'rap-slots__first' ) && false === strpos( $fh, 'rap-empty' ) && false !== strpos( $fh, '← Předchozí termíny' ), 'bez data v odkazu začne výpis dnem nejbližšího volného termínu, s větou nahoře' );
rap_t( false === strpos( $fh, 'dřívější termíny jsou obsazené' ), 'bez dřívějších termínů se neříká „obsazené“' );
Resandpay_Reservations::create( $fFirst['id'], rap_person( 500 ), array( 'notify' => false ) );
$fh2 = Resandpay_Views::render_slots( array( 'service' => $fjS, 'days' => 14 ) );
rap_t( false !== strpos( $fh2, 'Nejbližší volný termín je' ) && false !== strpos( $fh2, '3. 3. ' . $fy ) && false !== strpos( $fh2, 'dřívější termíny jsou obsazené' ), 'po obsazení začne výpis dalším volným termínem, věta „dřívější termíny jsou obsazené“' );
$_GET = array( 'rap_from' => wp_date( 'Y-m-d' ) );
$fh3 = Resandpay_Views::render_slots( array( 'service' => $fjS, 'days' => 14 ) );
$_GET = array();
rap_t( false !== strpos( $fh3, 'rap-empty' ) && false === strpos( $fh3, 'rap-slots__first' ) && false !== strpos( $fh3, 'Další termíny →' ), 'odkaz s datem (rap_from) začne tím datem, šipka „Další termíny“ najde vzdálený termín' );
$fsum = wp_list_filter( Resandpay_Views::service_summaries( '' ), array() );
$fsum = array_values( array_filter( $fsum, fn( $x ) => (int) $x['service']['id'] === (int) $fjS ) );
rap_t( $fsum && 0 === strpos( $fsum[0]['next']['start'], $fy . '-03-03' ), 'karta služby v přehledu najde nejbližší volný termín bez omezení dopředu' );
Resandpay_Services::bulk_cancel( $fjS, new DateTimeImmutable( $fy . '-01-01', wp_timezone() ), new DateTimeImmutable( $fy . '-12-31', wp_timezone() ), false );
$fh4 = Resandpay_Views::render_slots( array( 'service' => $fjS, 'days' => 14 ) );
rap_t( false !== strpos( $fh4, 'rap-empty' ) && false === strpos( $fh4, 'rap-slots__first' ), 'bez volného termínu výpis začne dneškem s hláškou' );

echo "== 0.9.0: externí rezervační systém (SWD kap. 3.11)\n";
$xE  = rap_event( 'Mirošov externě', $now + 40 * $day, $now + 42 * $day, 0, array( 'rap_price' => '6900', 'rap_booking' => 'external', 'rap_ext_url' => 'https://rezervace.example.com/mirosov?d=%DATE%&c=%CODE%', 'rap_ext_label' => 'Rezervovat na Example', 'rap_ext_new_tab' => '1', 'rap_ext_note' => 'Rezervace a platba probíhá v systému Example.' ) );
$xEv = Resandpay_Events::get( $xE );
rap_t( $xEv['external'] && 0 === strpos( $xEv['external']['url'], 'https://rezervace.example.com/mirosov?d=' . wp_date( 'Y-m-d', $now + 40 * $day ) ) && false !== strpos( $xEv['external']['url'], 'c=' . rawurlencode( $xEv['code'] ) ), 'externí akce: adresa s doplněným datem a kódem' );
rap_t( Resandpay_Events::is_open( $xE ) && true === Resandpay_Events::check_bookable( $xE ), 'externí akce bez kapacity je otevřená' );
$xi = Resandpay_Views::render_item( $xEv );
rap_t( false !== strpos( $xi, 'href="https://rezervace.example.com/mirosov' ) && false !== strpos( $xi, 'target="_blank"' ) && false !== strpos( $xi, 'rel="noopener nofollow"' ) && false !== strpos( $xi, 'Rezervovat na Example →' ) && false === strpos( $xi, 'Volná místa' ) && false === strpos( $xi, 'Obsazeno' ), 'výpis: tlačítko do externího systému, bez volných míst' );
$xb = Resandpay_Frontend::render_booking( $xE, true );
rap_t( false !== strpos( $xb, 'rap-external__cta' ) && false !== strpos( $xb, 'probíhá v systému Example' ) && false === strpos( $xb, '<form' ) && false === strpos( $xb, 'volných míst' ), 'detail: karta s poznámkou a tlačítkem ven, bez formuláře a obsazenosti' );
$xp = Resandpay_Frontend::render_event_page( $xE, '<p>Popis</p>' );
rap_t( false !== strpos( $xp, 'rap-event__cta' ) && false !== strpos( $xp, 'Rezervovat na Example →' ) && false === strpos( $xp, '>Obsazeno<' ), 'detail akce: tlačítko v záhlaví vede ven' );
rap_t( false !== strpos( Resandpay_Frontend::price_html( $xEv ), '6 900 Kč' ), 'cena se zobrazuje beze změny' );
$xr = Resandpay_Reservations::create( $xE, rap_person( 600 ), array( 'notify' => false ) );
rap_t( is_wp_error( $xr ) && 'external' === $xr->get_error_code(), 'rezervaci v ReserveAndPay u externí akce nelze vytvořit (ani ručně)' );
$xm = Resandpay_Admin_Events::missing( $xEv );
rap_t( ! $xm, 'externí akce nevyžaduje kapacitu' );
Resandpay_Events::save_booking_meta( $xE, array( 'rap_booking' => 'external', 'rap_ext_url' => 'http://nebezpecne.example.com/' ) );
$xEv2 = Resandpay_Events::get( $xE );
rap_t( '' === $xEv2['external']['url'] && ! Resandpay_Events::is_open( $xE ) && in_array( 'adresa externí rezervace', Resandpay_Admin_Events::missing( $xEv2 ), true ), 'adresa bez https se neuloží, termín bez adresy se nenabízí' );
Resandpay_Events::save_booking_meta( $xE, array( 'rap_booking' => 'external', 'rap_ext_url' => 'https://rezervace.example.com/mirosov', 'rap_ext_label' => '', 'rap_ext_note' => '' ) );
$xEv3 = Resandpay_Events::get( $xE );
rap_t( 'Rezervovat' === $xEv3['external']['label'] && ! $xEv3['external']['new_tab'], 'výchozí text tlačítka „Rezervovat“, nové okno vypnuto' );
update_post_meta( $xE, '_rap_close_hours', 24 * 60 );
$xi2 = Resandpay_Views::render_item( Resandpay_Events::get( $xE ) );
rap_t( false === strpos( $xi2, 'rezervace.example.com' ) && false !== strpos( $xi2, 'Zobrazit detail' ), 'po uzávěrce rezervací tlačítko ven zmizí' );
update_post_meta( $xE, '_rap_close_hours', 0 );
Resandpay_Events::save_booking_meta( $xE, array( 'rap_booking' => '' ) );
rap_t( null === Resandpay_Events::get( $xE )['external'], 'přepnutí zpět na rezervaci v ReserveAndPay' );
// Služba v externím systému.
$xS = wp_insert_post( array( 'post_type' => Resandpay_Post_Types::SERVICE, 'post_status' => 'publish', 'post_title' => 'Masáž externě' ) );
Resandpay_Admin_Services::save_service_meta( $xS, array( 'rap_duration' => 60, 'rap_pause' => 0, 'rap_capacity' => 3, 'rap_price' => 1300, 'rap_time_display' => 'show', 'rap_booking' => 'external', 'rap_ext_url' => 'https://book.example.com/?date=%DATE%&time=%TIME%', 'rap_ext_new_tab' => '1' ) );
$xfy = (int) wp_date( 'Y' ) + 2;
$pvX = Resandpay_Services::preview( $xS, array( 'weekdays' => array( 1, 2, 3, 4, 5, 6, 0 ), 'time_from' => '09:00', 'time_to' => '11:00', 'date_from' => $xfy . '-05-04', 'date_to' => $xfy . '-05-04' ) );
Resandpay_Services::create_slots( $xS, wp_list_pluck( $pvX, 'start' ) );
$xSl = array_values( Resandpay_Services::slots( $xS, date_create_immutable( $xfy . '-05-04 00:00:00', wp_timezone() ), date_create_immutable( $xfy . '-05-05 00:00:00', wp_timezone() ) ) );
rap_t( 2 === count( $xSl ) && $xSl[0]['external'] && 'https://book.example.com/?date=' . $xfy . '-05-04&time=09:00' === $xSl[0]['external']['url'], 'termín služby přebírá externí rezervaci ze služby, proměnné %DATE% a %TIME%' );
Resandpay_Events::save_booking_meta( $xSl[1]['id'], array( 'rap_ext_url' => 'https://book.example.com/special' ), true );
rap_t( 'https://book.example.com/special' === Resandpay_Events::get( $xSl[1]['id'] )['external']['url'], 'vlastní adresa jednoho termínu' );
$_GET = array();
$xh = Resandpay_Views::render_slots( array( 'service' => $xS, 'days' => 14 ) );
rap_t( false !== strpos( $xh, 'href="https://book.example.com/?date=' . $xfy . '-05-04&#038;time=09:00"' ) && false !== strpos( $xh, 'href="https://book.example.com/special"' ) && false !== strpos( $xh, 'target="_blank"' ) && false === strpos( $xh, 'volná' ) && false === strpos( $xh, 'max. 3 osob' ), 'výběr termínů: každý čas vede ven, bez volných míst' );
rap_t( false !== strpos( $xh, 'Nejbližší termín je' ) && false === strpos( $xh, 'obsazené' ), 'věta „Nejbližší termín je …“ bez „obsazené“' );
$xsum = array_values( array_filter( Resandpay_Views::service_summaries( '' ), fn( $x ) => (int) $x['service']['id'] === (int) $xS ) );
$xcard = $xsum ? Resandpay_Views::render_service_item( $xsum[0] ) : '';
rap_t( false !== strpos( $xcard, 'Nejbližší termín:' ) && false !== strpos( $xcard, 'href="https://book.example.com/?date=' ) && false === strpos( $xcard, 'Volných termínů' ) && false === strpos( $xcard, 'volno' ), 'karta služby bez stránky termínů vede rovnou ven, bez počtu volných' );

echo "== 0.8.0: slevy – sleva za včasnou rezervaci\n";
global $wpdb;
require_once RESANDPAY_DIR . 'admin/class-admin-coupons.php';
Resandpay_Settings::update( array( 'bank_account' => '8032378004/5500', 'test_mode' => 1, 'reminder_days' => 0, 'cash_enabled' => 1 ) );
$dE = rap_event( 'Víkend se slevou', $now + 60 * $day, $now + 62 * $day, 20, array( 'rap_price' => '4900', 'rap_due_mode' => 'booking', 'rap_due_days' => '20', 'rap_part1_mode' => 'fixed', 'rap_part1_value' => '2000', 'rap_part1_days' => '7', 'rap_early_mode' => 'fixed', 'rap_early_value' => '500', 'rap_early_until' => wp_date( 'Y-m-d', $now + 10 * $day ), 'rap_early_label' => '' ) );
$dEv = Resandpay_Events::get( $dE );
rap_t( false !== strpos( Resandpay_Discounts::price_note( $dEv ), 'při rezervaci do' ) && false !== strpos( Resandpay_Frontend::price_html( $dEv ), '4 400 Kč' ), 'web: u ceny „při rezervaci do … a platbě ve splatnosti 4 400 Kč“' );
rap_t( false !== strpos( Resandpay_Frontend::render_booking( $dE ), 'automaticky při platbě' ) && false === strpos( Resandpay_Frontend::render_booking( $dE ), 'name="rap_coupon"' ), 'formulář: sleva se uplatní automaticky, pole kupónu se nenabízí' );
$GLOBALS['rap_mails'] = array();
$d1 = Resandpay_Reservations::create( $dE, rap_person( 200 ) );
rap_t( 4900.0 === (float) $d1['price_full'] && 500.0 === (float) $d1['discount_amount'] && 'early' === $d1['discount_type'] && 4400.0 === (float) $d1['amount'] && 2000.0 === (float) $d1['part1_amount'], 'rezervace: plná cena 4 900, sleva 500, k úhradě 4 400, 1. část 2 000 z plné ceny' );
rap_t( false !== strpos( Resandpay_QR::spd( $d1 ), '*AM:2000.00*' ) && false !== strpos( Resandpay_QR::spd_full( $d1 ), '*AM:4400.00*' ), 'OP01: QR 1. část 2 000, QR vše najednou 4 400 (po slevě)' );
$m1 = $GLOBALS['rap_mails'][0]['message'] ?? '';
rap_t( false !== strpos( $m1, 'Sleva za včasnou rezervaci' ) && false !== strpos( $m1, '−500 Kč' ) && false !== strpos( $m1, 'doplatek 2 400 Kč' ) && false !== strpos( $m1, 've splatnosti' ), 'e-mail: rozpis se slevou a podmínkou, doplatek 2 400' );
rap_t( 0 === strpos( Resandpay_PDF::render( $d1 ), '%PDF' ), 'platební pokyn PDF se slevou' );
rap_t( false !== strpos( Resandpay_Frontend::price_html( $dEv ), '<span class="rap-early-price">4 400 Kč</span>' ) && false !== strpos( Resandpay_Frontend::price_html( $dEv ), 'class="rap-early"' ), '0.8.3: cena po slevě samostatně (zlatě, velikost základní ceny), podmínka zvlášť' );
$pr = Resandpay_Mailer::payment_rows( $d1 );
rap_t( '4 400 Kč' === ( $pr['Cena po slevě'] ?? '' ) && in_array( 'Cena po slevě', Resandpay_Mailer::$discount_keys, true ) && in_array( 'Sleva za včasnou rezervaci', Resandpay_Mailer::$discount_keys, true ), '0.8.3: e-mail a PDF – řádek „Cena po slevě“, slevové řádky zlatě' );
rap_t( false !== strpos( $m1, 'Cena po slevě' ), '0.8.3: e-mail obsahuje cenu po slevě' );
rap_t( 'coupon_combine' === Resandpay_Reservations::create( $dE, array_merge( rap_person( 201 ), array( 'coupon' => 'NECO' ) ), array( 'notify' => false ) )->get_error_code(), 'kupón nelze kombinovat se slevou za včasnou rezervaci' );
$dc = Resandpay_Reservations::create( $dE, array_merge( rap_person( 202 ), array( 'pay_choice' => 'cash' ) ), array( 'notify' => false ) );
rap_t( 0.0 === (float) $dc['discount_amount'] && 4900.0 === (float) $dc['amount'], 'hotovost na místě: bez slevy za včasnou rezervaci' );
$dl = Resandpay_Reservations::create( $dE, rap_person( 203 ), array( 'notify' => false, 'now' => $now + 11 * $day ) );
rap_t( 0.0 === (float) $dl['discount_amount'], 'rezervace po datu: bez slevy' );
Resandpay_Reservations::add_payment( $d1['id'], 2000, 'transfer_auto', array( 'notify' => false, 'booked_at' => $now ) );
$d1 = Resandpay_Reservations::get( $d1['id'] );
rap_t( 'partial' === $d1['status'] && Resandpay_Reservations::early_active( $d1 ) && 2400.0 === Resandpay_Reservations::remaining( $d1 ), '1. část ve splatnosti: sleva trvá, zbývá 2 400' );
$GLOBALS['rap_mails'] = array();
$d1 = Resandpay_Reservations::add_payment( $d1['id'], 2400, 'transfer_auto', array( 'booked_at' => Resandpay_Events::ts( $d1['due_at'] ) + DAY_IN_SECONDS ) );
rap_t( ! Resandpay_Reservations::early_active( $d1 ) && $d1['discount_lost_at'] && 4900.0 === (float) $d1['amount'] && 500.0 === Resandpay_Reservations::remaining( $d1 ) && 'paid' !== $d1['status'], 'doplatek po splatnosti: sleva zanikla, zbývá doplatit 500' );
$ml = array_values( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'sleva zanikla' ) ) );
rap_t( 1 === count( $ml ) && false !== strpos( $ml[0]['message'], '500 Kč' ) && false !== strpos( $ml[0]['message'], 'zanikla' ), 'e-mail „Změna platebního předpisu – sleva zanikla“ s QR na zbytek' );
rap_t( false !== strpos( implode( ' ', wp_list_pluck( Resandpay_Discounts::log_rows( $d1['id'] ), 'message' ) ), 'zanikla – platba připsána po splatnosti' ), 'historie: zánik slevy s důvodem' );
rap_t( 'reason' === Resandpay_Reservations::keep_discount( $d1['id'], '' )->get_error_code(), 'ponechat slevu jen s důvodem' );
$GLOBALS['rap_mails'] = array();
$d1 = Resandpay_Reservations::keep_discount( $d1['id'], 'banka platbu zdržela' );
rap_t( 'paid' === $d1['status'] && 4400.0 === (float) $d1['amount'] && Resandpay_Reservations::early_active( $d1 ) && (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Platba přijata' ) ), 'Ponechat slevu: částka 4 400, zaplaceno → Zaplaceno' );
$d2 = Resandpay_Reservations::create( $dE, rap_person( 204 ), array( 'notify' => false ) );
Resandpay_Reservations::add_payment( $d2['id'], 4000, 'transfer_auto', array( 'notify' => false, 'booked_at' => $now ) );
$wpdb->update( Resandpay_Reservations::table(), array( 'due_at' => wp_date( 'Y-m-d 23:59:59', $now - $day ) ), array( 'id' => (int) $d2['id'] ) );
$GLOBALS['rap_mails'] = array();
Resandpay_Reservations::process_due();
$d2 = Resandpay_Reservations::get( $d2['id'] );
rap_t( 'overdue' === $d2['status'] && $d2['discount_lost_at'] && 900.0 === Resandpay_Reservations::remaining( $d2 ), 'nedoplatek ve splatnosti (4 000 z 4 400): sleva zanikla, doplácí 900, Po splatnosti' );
rap_t( 1 === count( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'sleva zanikla' ) ) ) && ! array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Připomínka platby' ) ), 'e-mail o změně předpisu nahrazuje připomínku po splatnosti' );
$d3 = Resandpay_Reservations::create( $dE, rap_person( 205 ), array( 'notify' => false, 'now' => $now - 10 * $day ) );
Resandpay_Reservations::process_due();
$d3 = Resandpay_Reservations::get( $d3['id'] );
rap_t( 'expired' === $d3['status'] && $d3['discount_lost_at'] && 4900.0 === (float) $d3['amount'], 'nic nezaplaceno do splatnosti 1. části: vypršela, sleva zanikla (obnovení už bez slevy)' );
$dE2 = rap_event( 'Víkend pozdní platba', $now + 90 * $day, $now + 92 * $day, 20, array( 'rap_price' => '4900', 'rap_due_mode' => 'booking', 'rap_due_days' => '5', 'rap_early_mode' => 'percent', 'rap_early_value' => '10', 'rap_early_until' => '2026-10-05' ) );
$d4 = Resandpay_Reservations::create( $dE2, rap_person( 206 ), array( 'notify' => false, 'now' => Resandpay_Events::ts( '2026-10-01 10:00:00' ) ) );
rap_t( 490.0 === (float) $d4['discount_amount'] && 4410.0 === (float) $d4['amount'], 'sleva v procentech (10 % z 4 900 = 490)' );
$o4 = Resandpay_Payments::process_raw( rap_avizo( $eml, $d4['vs'], '+4.900,00 CZK', 'early-late' ), 'cron' );
$d4 = Resandpay_Reservations::get( $d4['id'] );
rap_t( 'matched' === $o4['status'] && 'paid' === $d4['status'] && $d4['discount_lost_at'], 'avízo po splatnosti na plnou cenu: sleva zanikla, nejde o přeplatek → Zaplaceno (rozhoduje datum připsání)' );

echo "== 0.8.0: slevy – slevový kupón\n";
$cE = rap_event( 'Kupónová akce', $now + 70 * $day, $now + 72 * $day, 20, array( 'rap_price' => '4900', 'rap_due_mode' => 'booking', 'rap_due_days' => '20', 'rap_part1_mode' => 'fixed', 'rap_part1_value' => '2000', 'rap_part1_days' => '7' ) );
$cE2 = rap_event( 'Jiná akce', $now + 70 * $day, $now + 70 * $day + 3600, 20, array( 'rap_price' => '500' ) );
$cp = Resandpay_Discounts::save( array( 'mode' => 'percent', 'value' => 20, 'scope_all' => 0, 'scope_events' => array( $cE ), 'max_per_email' => 1, 'active' => 1 ) );
rap_t( (bool) preg_match( '/^SLH-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/', $cp['code'] ) && 36 === strlen( $cp['guid'] ), 'automatický kód SLH-XXXX-XXXX-XX (bez 0/O/1/I/L) a GUID' );
rap_t( (int) $cp['id'] === (int) Resandpay_Discounts::get_by_code( strtolower( str_replace( '-', ' ', $cp['code'] ) ) )['id'], 'kód bez ohledu na velikost písmen, mezery a pomlčky' );
rap_t( 'coupon_scope' === Resandpay_Reservations::create( $cE2, array_merge( rap_person( 210 ), array( 'coupon' => $cp['code'] ) ), array( 'notify' => false ) )->get_error_code(), 'kupón pro jinou akci: „Kupón pro tuto akci neplatí“' );
rap_t( 'coupon_invalid' === Resandpay_Reservations::create( $cE, array_merge( rap_person( 210 ), array( 'coupon' => 'NEEXISTUJE' ) ), array( 'notify' => false ) )->get_error_code(), 'neexistující kupón odmítnut' );
$q = Resandpay_Frontend::quote( $cE, $cp['code'] );
rap_t( ! $q['error'] && 980.0 === $q['discount'] && false !== strpos( Resandpay_Frontend::quote_text( $q ), '3 920 Kč' ), '„Použít“: cena po slevě 3 920 Kč (−980)' );
$k1 = Resandpay_Reservations::create( $cE, array_merge( rap_person( 211 ), array( 'coupon' => $cp['code'] ) ) );
rap_t( 'coupon' === $k1['discount_type'] && (int) $cp['id'] === (int) $k1['coupon_id'] && 3920.0 === (float) $k1['amount'] && 2000.0 === (float) $k1['part1_amount'] && false !== strpos( Resandpay_QR::spd_full( $k1 ), '*AM:3920.00*' ), 'rezervace s kupónem: 3 920, 1. část 2 000, doplatek 1 920' );
rap_t( 1 === Resandpay_Discounts::uses( $cp['id'] ) && 'coupon_used_email' === Resandpay_Reservations::create( $cE, array_merge( rap_person( 211 ), array( 'coupon' => $cp['code'] ) ), array( 'notify' => false ) )->get_error_code(), 'limit na e-mail (1)' );
Resandpay_Reservations::set_status( $k1['id'], Resandpay_Reservations::CANCELLED );
rap_t( 0 === Resandpay_Discounts::uses( $cp['id'] ) && ! is_wp_error( Resandpay_Reservations::create( $cE, array_merge( rap_person( 211 ), array( 'coupon' => $cp['code'] ) ), array( 'notify' => false ) ) ), 'po zrušení se použití kupónu vrátí' );
$k2 = Resandpay_Reservations::create( $cE, array_merge( rap_person( 212 ), array( 'coupon' => $cp['code'] ) ), array( 'notify' => false, 'now' => $now - 30 * $day ) );
Resandpay_Reservations::process_due();
rap_t( 'expired' === Resandpay_Reservations::get( $k2['id'] )['status'] && 3920.0 === (float) Resandpay_Reservations::get( $k2['id'] )['amount'], 'sleva z kupónu pozdní platbou / vypršením nezaniká' );
$jr  = 'Jaro' . wp_rand( 10000, 99999 );
$cpx = Resandpay_Discounts::save( array( 'code' => $jr, 'mode' => 'fixed', 'value' => 3500, 'active' => 1 ) );
rap_t( strtoupper( $jr ) === $cpx['code'] && 'dup' === Resandpay_Discounts::save( array( 'code' => strtolower( substr( $jr, 0, 4 ) . '-' . substr( $jr, 4 ) ), 'mode' => 'fixed', 'value' => 1 ) )->get_error_code(), 'vlastní kód (např. JARO2027), duplicita odmítnuta' );
$k3 = Resandpay_Reservations::create( $cE, array_merge( rap_person( 213 ), array( 'coupon' => strtolower( $jr ) ) ), array( 'notify' => false ) );
rap_t( 2900.0 === (float) $k3['discount_amount'] && 2000.0 === (float) $k3['amount'], 'pevná sleva vyšší než doplatek se omezí na doplatek (2 900)' );
$cpf = Resandpay_Discounts::save( array( 'mode' => 'percent', 'value' => 100, 'active' => 1 ) );
$GLOBALS['rap_mails'] = array();
$k4 = Resandpay_Reservations::create( $cE, array_merge( rap_person( 214 ), array( 'coupon' => $cpf['code'] ) ) );
rap_t( 'paid' === $k4['status'] && 0.0 === (float) $k4['amount'] && 'free' === $k4['paid_method'] && 0.0 === (float) $k4['part1_amount'], 'kupón 100 %: rezervace zdarma, rovnou potvrzená' );
rap_t( (bool) array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Rezervace potvrzena' ) && false === strpos( $m['message'], 'Variabilní symbol</td>' ) ), 'e-mail „Rezervace potvrzena“ bez platebních údajů' );
$conf = new ReflectionMethod( 'Resandpay_Frontend', 'render_confirmation' );
$conf->setAccessible( true );
ob_start();
$conf->invoke( null, $k4, Resandpay_Events::get( $cE ) );
rap_t( false !== strpos( ob_get_clean(), 'zdarma' ), 'potvrzovací stránka: zdarma' );
Resandpay_Discounts::save( array( 'mode' => 'percent', 'value' => 100, 'active' => 0 ), $cpf['id'] );
rap_t( 'coupon_invalid' === Resandpay_Discounts::validate_coupon( $cpf['code'], Resandpay_Events::get( $cE ) )->get_error_code(), 'vypnutý kupón neplatí' );
$cpd = Resandpay_Discounts::save( array( 'mode' => 'fixed', 'value' => 100, 'valid_to' => wp_date( 'Y-m-d', $now - $day ), 'active' => 1 ) );
rap_t( 'coupon_expired' === Resandpay_Discounts::validate_coupon( $cpd['code'], Resandpay_Events::get( $cE ) )->get_error_code(), 'kupón po platnosti neplatí' );
rap_t( false !== strpos( Resandpay_Discounts::coupon_url( $cp ), '?kupon=' . rawurlencode( $cp['code'] ) ) && 0 === strpos( Resandpay_Discounts::coupon_url( $cp ), get_permalink( $cE ) ), 'odkaz kupónu pro jednu akci vede na její stránku s ?kupon=' );
$_GET = array( 'kupon' => $cpx['code'] );
$fk = Resandpay_Frontend::render_booking( $cE2 );
$_GET = array();
rap_t( false !== strpos( $fk, 'name="rap_coupon" value="' . $cpx['code'] . '"' ), 'odkaz / QR: kupón se ve formuláři vyplní sám' );
$bt = Resandpay_Discounts::generate_batch( array( 'mode' => 'fixed', 'value' => 300, 'active' => 1, 'scope_all' => 1 ), 5 );
rap_t( 5 === count( $bt ) && 5 === count( array_unique( wp_list_pluck( $bt, 'code' ) ) ) && 1 === $bt[0]['max_uses'] && $bt[0]['batch'] === $bt[4]['batch'], 'hromadné generování: 5 jednorázových kupónů v jedné dávce' );
$csv = Resandpay_Admin_Coupons::csv( $bt );
rap_t( false !== strpos( $csv, $bt[2]['code'] ) && false !== strpos( $csv, '?kupon=' ), 'CSV dávky s kódy a odkazy' );
$png = Resandpay_Admin_Coupons::qr_png( $bt[0] );
rap_t( 0 === strpos( $png, "\x89PNG" ) && 2 === ord( $png[25] ), 'zlatý QR kód kupónu (barevný PNG)' );
$vp = Resandpay_PDF::voucher( $bt );
rap_t( 0 === strpos( $vp, '%PDF' ) && strlen( $vp ) > 5000, 'PDF poukazy (dávka v jednom PDF)' );
rap_t( 'used' === Resandpay_Discounts::delete( $cp['id'] )->get_error_code() && true === Resandpay_Discounts::delete( $bt[4]['id'] ), 'použitý kupón nelze smazat, nepoužitý ano' );
wp_set_current_user( 1 );
$_GET = array( 'page' => 'reserveandpay-coupons' );
ob_start();
Resandpay_Admin_Coupons::page();
$cpl = ob_get_clean();
$_GET = array( 'page' => 'reserveandpay-coupons', 'edit' => $cp['id'] );
ob_start();
Resandpay_Admin_Coupons::page();
$cpe = ob_get_clean();
$wpdb->update( Resandpay_Reservations::table(), array( 'amount' => 4900, 'discount_lost_at' => current_time( 'mysql' ), 'status' => 'pending', 'amount_paid' => 0 ), array( 'id' => (int) $d2['id'] ) );
$_GET = array( 'page' => 'reserveandpay-detail', 'id' => $d2['id'] );
ob_start();
Resandpay_Admin_Reservations::page_detail();
$dpd = ob_get_clean();
$_GET = array();
wp_set_current_user( 0 );
rap_t( false !== strpos( $cpl, $cp['code'] ) && false !== strpos( $cpl, 'Hromadně vygenerovat' ), 'administrace: seznam kupónů' );
rap_t( false !== strpos( $cpe, 'Stáhnout poukaz (PDF)' ) && false !== strpos( $cpe, 'Stáhnout QR (PNG)' ) && false !== strpos( $cpe, 'data:image/png;base64' ), 'administrace: detail kupónu s QR a poukazem' );
rap_t( false !== strpos( $dpd, 'Ponechat slevu' ) && false !== strpos( $dpd, 'zanikla' ), 'detail rezervace: historie slevy a Ponechat slevu' );
$wpdb->update( Resandpay_Reservations::table(), array( 'price_full' => 0 ), array( 'id' => (int) $dc['id'] ) );
update_option( 'resandpay_db_version', '8' );
Resandpay_Install::maybe_upgrade();
rap_t( 4900.0 === (float) Resandpay_Reservations::get( $dc['id'] )['price_full'], 'upgrade DB 8 → 9: plná cena doplněna' );
Resandpay_Settings::update( array( 'bank_account' => '123456789/2700', 'cash_enabled' => 0, 'reminder_days' => 1 ) );

echo "== 0.8.1: vzhled poukazu\n";
require_once RESANDPAY_DIR . 'admin/class-admin-settings.php';
$sv = Resandpay_Admin_Settings::sanitize_svg( '<svg viewBox="0 0 10 10" onload="x()"><script>a</script><path d="M0 0 L5 5" stroke="#8C6423" onclick="y"/><image href="x"/></svg>' );
rap_t( false !== strpos( $sv, 'viewBox="0 0 10 10"' ) && false === strpos( $sv, 'onload' ) && false === strpos( $sv, '<script' ) && false === strpos( $sv, '<image' ) && false !== strpos( $sv, '<path' ), 'symbol loga: jen bezpečné SVG prvky, viewBox zachován' );
$dflt = Resandpay_Settings::defaults();
rap_t( 'nádech — výdech — prostor' === $dflt['brand_motto'] && 's lehkou' === $dflt['logo_line1'] && 'hlavou' === $dflt['logo_line2'] && false !== strpos( $dflt['brand_symbol_svg'], '#8C6423' ), 'výchozí logo (lotos z vlny) a motto dle grafického manuálu' );
$vp1 = Resandpay_PDF::voucher( array( Resandpay_Discounts::all()[0] ) );
rap_t( 0 === strpos( $vp1, '%PDF' ) && strlen( $vp1 ) > 8000, 'poukaz s logem, kružnicemi, dechovou linkou, mottem a webem' );

echo "== 0.8.2: jednotné logo ve výstupech\n";
Resandpay_Settings::update( array( 'logo_source' => 'site' ) );
remove_theme_mod( 'custom_logo' );
rap_t( 'mark' === Resandpay_Brand::logo()['type'] && false !== strpos( Resandpay_Brand::email_header( array( 'ink' => '#2E2A22', 'accent' => '#8C6423' ) ), 'font-style:italic' ), 'bez loga webu: logo z nastavení pluginu (PDF symbol + text, e-mail textová sestava)' );
$up   = wp_upload_dir();
$png  = trailingslashit( $up['path'] ) . 'rap-test-logo.png';
$gd   = imagecreatetruecolor( 300, 80 );
imagepng( $gd, $png );
$aid  = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'rap-test-logo', 'post_status' => 'inherit' ), $png );
set_theme_mod( 'custom_logo', $aid );
$lg = Resandpay_Brand::logo();
rap_t( 'raster' === $lg['type'] && false !== strpos( Resandpay_Brand::email_header( array( 'ink' => '#000', 'accent' => '#000' ) ), 'cid:rap-logo' ), 'logo webu (PNG): použije se v PDF i v e-mailu' );
$rr = Resandpay_Reservations::create( rap_event( 'Logo test', $now + 80 * $day, $now + 80 * $day + 3600, 5 ), rap_person( 220 ), array( 'notify' => false ) );
rap_t( 0 === strpos( Resandpay_PDF::render( $rr ), '%PDF' ) && 0 === strpos( Resandpay_PDF::voucher( array( Resandpay_Discounts::all()[0] ) ), '%PDF' ), 'platební pokyn i poukaz s logem webu' );
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
$pm       = new PHPMailer\PHPMailer\PHPMailer();
$pm->Body = Resandpay_Mailer::build( 'received', $rr )['html'];
$sp       = new ReflectionProperty( 'Resandpay_Mailer', 'sending' );
$sp->setAccessible( true );
$sp->setValue( null, true );
Resandpay_Mailer::phpmailer_parts( $pm );
$sp->setValue( null, false );
rap_t( (bool) array_filter( $pm->getAttachments(), fn( $a ) => 'rap-logo' === $a[7] ), 'e-mail: logo vloženo jako obrázek (cid:rap-logo)' );
Resandpay_Settings::update( array( 'logo_source' => 'plugin' ) );
rap_t( 'mark' === Resandpay_Brand::logo()['type'], 'volba „Vždy logo z nastavení pluginu“' );
Resandpay_Settings::update( array( 'logo_source' => 'site' ) );
$svgf = trailingslashit( $up['path'] ) . 'rap-test-logo.svg';
file_put_contents( $svgf, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 80"><circle cx="40" cy="40" r="30" stroke="#8C6423" fill="none"/><script>alert(1)</script></svg>' );
$aid2 = wp_insert_attachment( array( 'post_mime_type' => 'image/svg+xml', 'post_title' => 'rap-test-logo-svg', 'post_status' => 'inherit' ), $svgf );
set_theme_mod( 'custom_logo', $aid2 );
$lg2 = Resandpay_Brand::logo();
rap_t( 'svg' === $lg2['type'] && false === strpos( $lg2['svg'], 'script' ) && 0 === strpos( Resandpay_PDF::voucher( array( Resandpay_Discounts::all()[0] ) ), '%PDF' ) && false === strpos( Resandpay_Brand::email_header( array( 'ink' => '#000', 'accent' => '#000' ) ), 'cid:' ), 'logo webu SVG: v PDF vektorově, v e-mailu text (SVG e-mailové programy nezobrazí)' );
remove_theme_mod( 'custom_logo' );
wp_delete_attachment( $aid, true );
wp_delete_attachment( $aid2, true );

update_option( 'rap_test_concurrency_event', rap_event( 'Souběh', $now + 20 * $day, $now + 20 * $day + HOUR_IN_SECONDS, 3 ) );

printf( "\nVýsledek: %d PASS, %d FAIL\n", $GLOBALS['rap_pass'], $GLOBALS['rap_fail'] );
if ( $GLOBALS['rap_fail'] ) {
	exit( 1 );
}
