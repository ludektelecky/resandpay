#!/bin/bash
# Test souběhu: N současných rezervací na akci s kapacitou 3 (připravenou v run-tests.php).
# Spuštění z kořene WordPressu: bash wp-content/plugins/reserveandpay/tests/concurrency.sh
N=${1:-10}
EVENT=$(wp --allow-root option get rap_test_concurrency_event)
for i in $(seq 1 $N); do
  wp --allow-root eval "add_filter('pre_wp_mail','__return_true'); \$r = Resandpay_Reservations::create($EVENT, array('customer_name'=>'Soubeh $i','email'=>'s$i@example.com','phone'=>'1','consent_gdpr'=>1,'consent_terms'=>1)); echo is_wp_error(\$r) ? 'ERR:'.\$r->get_error_code() : 'OK';" > /tmp/rap_conc_$i.txt 2>&1 &
done
wait
OK=0; FULL=0; OTHER=""
for f in /tmp/rap_conc_*.txt; do
  c=$(cat "$f")
  case "$c" in OK) OK=$((OK+1));; ERR:full) FULL=$((FULL+1));; *) OTHER="$OTHER $c";; esac
done
ACTIVE=$(wp --allow-root eval "echo Resandpay_Reservations::count_active($EVENT);")
VS_UNIQUE=$(wp --allow-root eval "global \$wpdb; echo (int)\$wpdb->get_var('SELECT COUNT(*)-COUNT(DISTINCT vs) FROM '.Resandpay_Reservations::table());")
rm -f /tmp/rap_conc_*.txt
echo "Souběh: $N požadavků, OK=$OK, obsazeno=$FULL, aktivních v DB=$ACTIVE, duplicitních VS=$VS_UNIQUE"
[ -n "$OTHER" ] && echo "Jiné výsledky: $OTHER"
if [ "$ACTIVE" = "3" ] && [ "$OK" = "3" ] && [ "$VS_UNIQUE" = "0" ]; then echo "PASS  souběh nepřeplní akci"; else echo "FAIL  souběh"; exit 1; fi
