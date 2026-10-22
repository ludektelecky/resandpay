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
foreach ( array( 'class-admin.php', 'class-admin-events.php', 'class-admin-settings.php', 'class-admin-services.php', 'class-admin-checkin.php' ) as $f ) {
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
rap_t( $first && false !== strpos( $first['subject'], '[TEST → osoba' ), 'předmět obsahuje původního příjemce' );
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
rap_t( false !== strpos( $list, 'Nejbližší volný termín: út 2. 3. 10:15' ), 'karta služby ukazuje nejbližší volný termín (obsazený 9:00 přeskočí)' );
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
rap_t( 1 === $cnt && 'overdue' === $rO['status'] && $rO['reminded_at'], 'nezaplacená rezervace se splatností po termínu → Po splatnosti' );
rap_t( 1 === count( array_filter( $GLOBALS['rap_mails'], fn( $m ) => false !== strpos( $m['subject'], 'Připomínka platby' ) && false !== strpos( $m['message'], $rO['vs'] ) ) ), 'zákaznice dostane jednu připomínku s VS' );
rap_t( 'pending' === Resandpay_Reservations::get( $rB['id'] )['status'], 'splatnost před termínem → Po splatnosti se nepoužije (expirace v E6)' );
rap_t( 1 === (int) $rL['last_minute'] && 'onsite' === Resandpay_Reservations::get( $rL['id'] )['status'], 'rezervace na poslední chvíli bez platby → Platba na místě' );
$GLOBALS['rap_mails'] = array();
Resandpay_Reservations::process_due();
rap_t( ! $GLOBALS['rap_mails'], 'opakovaný běh připomínku znovu neposílá' );
rap_t( 2 === Resandpay_Reservations::count_active( $pastB ) && 1 === Resandpay_Reservations::count_active( $past ), 'rezervace Po splatnosti dál blokuje místo' );
rap_t( 'paid' === Resandpay_Reservations::set_status( $rO['id'], Resandpay_Reservations::PAID )['status'], 'po splatnosti → zaplaceno je povoleno' );

echo "== 0.3.3: platba v hotovosti\n";
$cE = rap_event( 'Sound Healing večer', $now + 12 * $day, $now + 12 * $day + 2 * HOUR_IN_SECONDS, 15 );
$GLOBALS['rap_mails'] = array();
$rT = Resandpay_Reservations::create( $cE, array_merge( rap_person( 80 ), array( 'pay_choice' => 'cash' ) ) );
rap_t( 'pending' === $rT['status'] && 'transfer' === $rT['pay_choice'], 'hotovost vypnutá: volba hotovosti z webu se ignoruje (převod)' );
rap_t( false === strpos( Resandpay_Frontend::render_booking( $cE ), 'rap_pay' ), 'hotovost vypnutá: formulář volbu platby neukazuje' );
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
rap_t( false !== strpos( $bp['html'], '<!doctype html>' ) && false !== strpos( $bp['html'], '#8C6423' ) && false !== strpos( $bp['html'], 'S lehkou hlavou…' ), 'HTML e-mail v barvách webu se značkou' );
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

update_option( 'rap_test_concurrency_event', rap_event( 'Souběh', $now + 20 * $day, $now + 20 * $day + HOUR_IN_SECONDS, 3 ) );

printf( "\nVýsledek: %d PASS, %d FAIL\n", $GLOBALS['rap_pass'], $GLOBALS['rap_fail'] );
if ( $GLOBALS['rap_fail'] ) {
	exit( 1 );
}
