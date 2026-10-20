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

foreach ( array( 'class-admin-events.php', 'class-admin-settings.php' ) as $f ) {
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
foreach ( get_posts( array( 'post_type' => array( Resandpay_Post_Types::EVENT, Resandpay_Post_Types::LOCATION ), 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $pid ) {
	wp_delete_post( $pid, true );
}
Resandpay_Settings::update(
	array(
		'test_mode'         => 1,
		'test_email'        => 'tester@example.com',
		'company_name'      => 'Testovací provozovatel',
		'bank_account'      => '123456789/2700',
		'iban'              => 'CZ6527000000000123456789',
		'due_days'          => 7,
		'last_minute_hours' => 24,
		'vs_prefix'         => '26',
		'notify_admin'      => 1,
		'admin_email'       => 'admin@example.com',
	)
);
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
$sl = rap_event( 'Termín masáže', $now + 4 * $day, $now + 4 * $day + HOUR_IN_SECONDS, 5, array( 'rap_type' => 'slot' ) );
rap_t( 1 === Resandpay_Events::get( $sl )['capacity'], 'individuální termín má vždy kapacitu 1' );

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
rap_t( $first && false !== strpos( $first['message'], 'Variabilní symbol: 26' ), 'text obsahuje variabilní symbol' );
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

update_option( 'rap_test_concurrency_event', rap_event( 'Souběh', $now + 20 * $day, $now + 20 * $day + HOUR_IN_SECONDS, 3 ) );

printf( "\nVýsledek: %d PASS, %d FAIL\n", $GLOBALS['rap_pass'], $GLOBALS['rap_fail'] );
if ( $GLOBALS['rap_fail'] ) {
	exit( 1 );
}
