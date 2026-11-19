#!/bin/bash
# Tests for check_printer.php. Instead of a printer, the plugin reads
# CHECK_PRINTER_FIXTURE, a file in the format of "snmpwalk -On".
# Run: tests/test_check_printer.php.sh

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHECK="$ROOT/check_printer.php"
DATA="$ROOT/tests/data/printer.walk"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

FAILURES=0
TESTS=0

# run [--fixture FILE] [ARGS...]: the plugin with all PHP warnings on stderr;
# sets OUT, ERR and RC
run(){
	local fixture=$DATA
	if [ "$1" = --fixture ]; then fixture=$2; shift 2; fi
	OUT=$(CHECK_PRINTER_FIXTURE=$fixture php -d error_reporting=-1 -d display_errors=stderr -d display_startup_errors=1 "$CHECK" "$@" 2>"$TMP/err")
	RC=$?
	ERR=$(<"$TMP/err")
}

# check NAME RC PATTERN [--fixture FILE] [ARGS...]: exit code, a regex for
# stdout and nothing on stderr, e.g. PHP warnings
check(){
	local name=$1 rc=$2 pattern=$3
	shift 3
	run "$@"
	TESTS=$((TESTS + 1))
	if [ "$RC" != "$rc" ] || ! grep -qE -- "$pattern" <<< "$OUT" || [ -n "$ERR" ]; then
		FAILURES=$((FAILURES + 1))
		printf 'FAIL %s: rc %s (expected %s)\n     out: %s\n     err: %s\n' "$name" "$RC" "$rc" "$OUT" "$ERR"
	else
		printf 'ok   %s\n' "$name"
	fi
}

P=(printer public v2c)

# fixture FILE: a copy of the fixture, changed by the lines on stdin (later lines win)
fixture(){
	{ cat "$DATA"; cat; } > "$TMP/$1"
	echo "$TMP/$1"
}

# without the alert table
grep -v '^\.1\.3\.6\.1\.2\.1\.43\.18\.' "$DATA" > "$TMP/no_alerts.walk"

echo "--- command line"
check "help" 3 "^Usage: check_printer.php" -h
check "help the old way" 3 "^Usage: check_printer.php" printer public v2c help
check "version" 3 "^check_printer.php [0-9]" -V
check "no arguments" 3 "^PRINTER UNKNOWN - Missing arguments"
check "too few arguments" 3 "^PRINTER UNKNOWN - Missing arguments" printer public v2c
check "unknown option" 3 "^PRINTER UNKNOWN - Unknown option -x" -x "${P[@]}" alerts
check "invalid timeout" 3 "^PRINTER UNKNOWN - Invalid value for -t: 0" -t 0 "${P[@]}" alerts
check "missing retries" 3 "^PRINTER UNKNOWN - Invalid value for -r: missing" -r
check "options" 0 "^PRINTER OK" -t 3 -r 1 "${P[@]}" alerts
check "SNMP version 3" 3 "^PRINTER UNKNOWN - Unsupported SNMP version 3" printer public 3 alerts
check "SNMP version 1" 0 "^PRINTER OK" printer public 1 alerts
check "SNMP version 2" 0 "^PRINTER OK" printer public 2 alerts
check "unknown command" 3 "^PRINTER UNKNOWN - Unknown command foo" "${P[@]}" foo
check "missing number" 3 "^PRINTER UNKNOWN - Missing the number of the toner/ink" "${P[@]}" toner
check "invalid number" 3 "^PRINTER UNKNOWN - Invalid number of the tray: x" "${P[@]}" paper x
check "invalid level" 3 "^PRINTER UNKNOWN - Invalid warning level: x" "${P[@]}" toner 1 x
check "level above 1" 3 "^PRINTER UNKNOWN - The levels must be between" "${P[@]}" toner 1 20
check "critical above warning" 3 "^PRINTER UNKNOWN - The critical level must not be above" "${P[@]}" toner 1 0.1 0.2
check "maximum 0" 3 "^PRINTER UNKNOWN - The maximum must be above 0" "${P[@]}" toner 1 0.2 0 0

echo "--- SNMP"
grep -v '^\.1\.3\.6\.1\.2\.1\.1\.1\.0 ' "$DATA" > "$TMP/mute.walk"
check "no answer" 2 "^PRINTER CRITICAL - No SNMP answer from printer" --fixture "$TMP/mute.walk" "${P[@]}" alerts
check "unreadable fixture" 3 "^PRINTER UNKNOWN - Cannot read" --fixture "$TMP/missing" "${P[@]}" alerts
TESTS=$((TESTS + 1))
if php -m | grep -qx snmp; then
	echo "skip no SNMP extension: it is installed"
	TESTS=$((TESTS - 1))
else
	OUT=$(php "$CHECK" "${P[@]}" alerts 2>&1); RC=$?
	if [ $RC = 3 ] && [ "$OUT" = "PRINTER UNKNOWN - The SNMP extension of PHP (php-snmp) is not installed" ]; then
		echo "ok   no SNMP extension"
	else
		FAILURES=$((FAILURES + 1)); echo "FAIL no SNMP extension: $RC $OUT"
	fi
fi

echo "--- toner"
check "toner OK" 0 "^PRINTER OK - Black Toner 45% \(4500 of 10000 left\)\|toner=4500;2000:;0:;0;10000$" "${P[@]}" toner 1
check "toner WARNING" 1 "^PRINTER WARNING - Cyan Toner 10% \(600 of 6000 left\)" "${P[@]}" toner 2
check "toner CRITICAL level" 2 "^PRINTER CRITICAL - Cyan Toner 10%.*toner=600;3000:;1200:;" "${P[@]}" toner 2 0.5 0.2
check "toner ink alias" 1 "^PRINTER WARNING - Cyan Toner" "${P[@]}" ink 2
check "toner/ink alias" 1 "^PRINTER WARNING - Cyan Toner" "${P[@]}" toner/ink 2
check "toner levels off" 0 "^PRINTER OK - Cyan Toner 10%" "${P[@]}" toner 2 0 0
check "toner some left" 0 "^PRINTER OK - Magenta Toner some left$" "${P[@]}" toner 3
check "toner level unknown" 3 "^PRINTER UNKNOWN - Yellow Toner level unknown$" "${P[@]}" toner 4
check "toner maximum" 0 "^PRINTER OK - Cyan Toner 60% \(600 of 1000 left\)\|toner=600;200:;0:;0;1000$" "${P[@]}" toner 2 0.2 0 1000
check "toner missing" 3 "^PRINTER UNKNOWN - Toner/Ink 9 does not exist$" "${P[@]}" toner 9
F=$(fixture empty.walk <<< ".1.3.6.1.2.1.43.11.1.1.9.1.1 = INTEGER: 0")
check "toner empty" 2 "^PRINTER CRITICAL - Black Toner 0% \(0 of 10000 left\)" --fixture "$F" "${P[@]}" toner 1
F=$(fixture nomax.walk <<< ".1.3.6.1.2.1.43.11.1.1.8.1.1 = INTEGER: -2")
check "toner maximum unknown" 3 "^PRINTER UNKNOWN - Black Toner level 4500, maximum unknown$" --fixture "$F" "${P[@]}" toner 1
F=$(fixture over.walk <<< ".1.3.6.1.2.1.43.11.1.1.9.1.1 = INTEGER: 12000")
check "toner above maximum" 3 "^PRINTER UNKNOWN - Black Toner level 12000 above the maximum 10000$" --fixture "$F" "${P[@]}" toner 1
check "kyocera monochrome" 0 "^PRINTER OK - Black Toner 45%.*toner=4500;1000:;500:;0;10000$" "${P[@]}" toner_kyocera_monochrome
check "kyocera monochrome levels" 1 "^PRINTER WARNING - Black Toner 45%" "${P[@]}" toner_kyocera_monochrome 0.5 0.1

echo "--- all supplies"
check "all: worst state, problems first" 1 "^PRINTER WARNING - Cyan Toner 10% \(600 of 6000 left\) \(WARNING\), Black Toner 45%" "${P[@]}" toner all
check "all: some left and unknown only shown" 1 "Magenta Toner some left, Yellow Toner level unknown, Waste Toner Box 100%" "${P[@]}" toner all
check "all: perfdata" 1 "\|'Black Toner'=4500;2000:;0:;0;10000 'Cyan Toner'=600;1200:;0:;0;6000 'Waste Toner Box'=100;20:;0:;0;100$" "${P[@]}" toner all
check "all: levels" 0 "^PRINTER OK - Black Toner 45%" "${P[@]}" toner all 0.05 0
F=$(fixture dup.walk <<< ".1.3.6.1.2.1.43.11.1.1.6.1.2 = STRING: \"Black Toner\"")
check "all: same names" 1 "'Black Toner'=4500;.* 'Black Toner 2'=600;" --fixture "$F" "${P[@]}" toner all
grep -v '^\.1\.3\.6\.1\.2\.1\.43\.11\.' "$DATA" > "$TMP/nosupplies.walk"
check "all: no supplies" 3 "^PRINTER UNKNOWN - The printer reports no supplies$" --fixture "$TMP/nosupplies.walk" "${P[@]}" toner all

echo "--- Ricoh toner"
check "ricoh almost empty" 1 "^PRINTER WARNING - Black Toner almost empty$" "${P[@]}" toner_ricoh_color 1
check "ricoh percent" 0 "^PRINTER OK - Cyan Toner 80% \(80 of 100 left\)\|toner=80%;20:;0:;0;100$" "${P[@]}" toner_ricoh_color 2
check "ricoh percent WARNING" 1 "^PRINTER WARNING - Cyan Toner 80%" "${P[@]}" toner_ricoh_color 2 0.9 0
check "ricoh monochrome some left" 0 "^PRINTER OK - Magenta Toner some left$" "${P[@]}" toner_ricoh_monochrome 3
check "ricoh monochrome percent" 0 "^PRINTER OK - Cyan Toner 80%" "${P[@]}" toner_ricoh_monochrome 2
check "ricoh missing" 3 "^PRINTER UNKNOWN - Toner/Ink 7 does not exist$" "${P[@]}" toner_ricoh_color 7

echo "--- paper"
check "paper OK" 0 "^PRINTER OK - Tray 1 80% \(200 of 250 left\)\|paper=200;50:;0:;0;250$" "${P[@]}" paper 1
check "paper empty, name from the description" 2 "^PRINTER CRITICAL - Bypass Tray 0% \(0 of 100 left\)" "${P[@]}" paper 2
check "paper some left" 0 "^PRINTER OK - Tray 2 some left$" "${P[@]}" tray 3
check "paper missing" 3 "^PRINTER UNKNOWN - Tray 4 does not exist$" "${P[@]}" paper 4

echo "--- parts and hardware"
check "cover closed" 0 "^PRINTER OK - Front Cover closed$" "${P[@]}" parts 1
check "cover open" 1 "^PRINTER WARNING - Duplex Unit open$" "${P[@]}" parts 2
check "interlock closed" 0 "^PRINTER OK - Rear Interlock interlock closed$" "${P[@]}" parts 3
check "part missing" 3 "^PRINTER UNKNOWN - Part 4 does not exist$" "${P[@]}" parts 4
check "hardware running" 0 "^PRINTER OK - Example Color Laser 4200 running\|errors=3c$" "${P[@]}" hardware 1
check "hardware down" 2 "^PRINTER CRITICAL - Hard disk down\|errors=17c$" "${P[@]}" hardware 2
check "hardware missing" 3 "^PRINTER UNKNOWN - Hardware 3 does not exist$" "${P[@]}" hardware 3
# hrPrinterDetectedErrorState: byte 1 0x01 = service requested, 0x20 = low toner, 0x04 = jammed;
# byte 2 0x02 = overdue preventive maintenance
F=$(fixture sleep.walk <<< $'.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 3\n.1.3.6.1.2.1.25.3.5.1.2.1 = Hex-STRING: 01 00 ')
check "hardware sleeping" 0 "^PRINTER OK - Example Color Laser 4200 warning: service requested \(no active alert: Sleep \(code 504\)\)\|errors=3c$" --fixture "$F" "${P[@]}" hardware 1
grep -v '^\.1\.3\.6\.1\.2\.1\.43\.18\.' "$F" > "$TMP/sleep_noalerts.walk"
F=$TMP/sleep_noalerts.walk
check "hardware service requested without alerts" 0 "^PRINTER OK - Example Color Laser 4200 warning: service requested \(no active alert\)\|" --fixture "$F" "${P[@]}" hardware 1
F=$(fixture lowtoner.walk <<< $'.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 3\n.1.3.6.1.2.1.25.3.5.1.2.1 = Hex-STRING: 21 00 ')
check "hardware low toner" 1 "^PRINTER WARNING - Example Color Laser 4200 warning: low toner, service requested\|" --fixture "$F" "${P[@]}" hardware 1
F=$(fixture maintenance.walk <<< $'.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 3\n.1.3.6.1.2.1.25.3.5.1.2.1 = Hex-STRING: 01 02 ')
check "hardware overdue maintenance" 1 "^PRINTER WARNING - Example Color Laser 4200 warning: service requested, overdue preventive maintenance\|" --fixture "$F" "${P[@]}" hardware 1
F=$(fixture nobits.walk <<< $'.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 3\n.1.3.6.1.2.1.25.3.5.1.2.1 = Hex-STRING: 00 00 ')
check "hardware warning without error bits" 1 "^PRINTER WARNING - Example Color Laser 4200 warning\|" --fixture "$F" "${P[@]}" hardware 1
F=$(fixture noerrorvalue.walk <<< '.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 3')
check "hardware warning without error value" 1 "^PRINTER WARNING - Example Color Laser 4200 warning\|" --fixture "$F" "${P[@]}" hardware 1
F=$(fixture jamalert.walk <<< $'.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 3\n.1.3.6.1.2.1.25.3.5.1.2.1 = Hex-STRING: 01 00 \n.1.3.6.1.2.1.43.18.1.1.7.1.746 = INTEGER: 8\n.1.3.6.1.2.1.43.18.1.1.8.1.746 = STRING: "Paper jam"')
check "hardware service requested with a jam alert" 1 "^PRINTER WARNING - Example Color Laser 4200 warning: service requested\|" --fixture "$F" "${P[@]}" hardware 1
F=$(fixture jammed.walk <<< $'.1.3.6.1.2.1.25.3.2.1.5.1 = INTEGER: 5\n.1.3.6.1.2.1.25.3.5.1.2.1 = Hex-STRING: 04 00 ')
check "hardware down and jammed" 2 "^PRINTER CRITICAL - Example Color Laser 4200 down: jammed\|" --fixture "$F" "${P[@]}" hardware 1

echo "--- alerts"
check "sleep alert of a bizhub is OK" 0 "^PRINTER OK - Sleep \(code 504\)$" "${P[@]}" alerts
check "no alerts: the display" 0 "^PRINTER OK - Ready$" --fixture "$TMP/no_alerts.walk" "${P[@]}" alerts
grep -v '^\.1\.3\.6\.1\.2\.1\.43\.16\.' "$TMP/no_alerts.walk" > "$TMP/no_display.walk"
check "no alerts and no display" 0 "^PRINTER OK - no alerts$" --fixture "$TMP/no_display.walk" "${P[@]}" alerts
F=$(fixture jam.walk <<'EOF'
.1.3.6.1.2.1.43.18.1.1.6.1.747 = INTEGER: 0
.1.3.6.1.2.1.43.18.1.1.7.1.747 = INTEGER: 8
.1.3.6.1.2.1.43.18.1.1.8.1.747 = STRING: "Paper jam"
.1.3.6.1.2.1.43.18.1.1.7.1.748 = INTEGER: 13
.1.3.6.1.2.1.43.18.1.1.8.1.748 = STRING: "Staples empty"
EOF
)
check "jam is CRITICAL" 2 "^PRINTER CRITICAL - Critical codes: 8 Warning codes: 13 Sleep \(code 504\), Paper jam \(code 8\), Staples empty \(code 13\)$" \
	--fixture "$F" "${P[@]}" alerts
F=$(fixture warn.walk <<'EOF'
.1.3.6.1.2.1.43.18.1.1.7.1.748 = INTEGER: 13
.1.3.6.1.2.1.43.18.1.1.8.1.748 = STRING: "Staples empty"
EOF
)
check "other alert is WARNING" 1 "^PRINTER WARNING - Warning codes: 13 Sleep \(code 504\), Staples empty \(code 13\)$" \
	--fixture "$F" "${P[@]}" alerts
F=$(fixture ignore.walk <<'EOF'
.1.3.6.1.2.1.43.18.1.1.6.1.746 = INTEGER: 10003
.1.3.6.1.2.1.43.18.1.1.7.1.746 = INTEGER: 13
EOF
)
check "ignored location: the display" 0 "^PRINTER OK - Ready$" --fixture "$F" "${P[@]}" alerts
F=$(fixture location.walk <<'EOF'
.1.3.6.1.2.1.43.18.1.1.6.1.746 = INTEGER: 0
.1.3.6.1.2.1.43.18.1.1.7.1.746 = INTEGER: 1
.1.3.6.1.2.1.43.18.1.1.8.1.746 = STRING: "Add paper {13100}"
EOF
)
check "location in the text" 0 "^PRINTER OK - Add paper \{13100\} \(code 1\)$" --fixture "$F" "${P[@]}" alerts
F=$(fixture notext.walk <<'EOF'
.1.3.6.1.2.1.43.18.1.1.2.1.749 = INTEGER: 3
.1.3.6.1.2.1.43.18.1.1.7.1.749 = INTEGER: 1101
EOF
)
check "alert without text" 2 "^PRINTER CRITICAL - Critical codes: 1101 Sleep \(code 504\), Alert \(code 1101\)$" \
	--fixture "$F" "${P[@]}" alerts

echo "--- counters"
check "lifetime counter" 0 "^PRINTER OK - Total: 123456\|pagecount=123456c$" "${P[@]}" counter 1
check "ricoh counter" 0 "^PRINTER OK - Copier: Black & White: 4321\|pagecount=4321c$" "${P[@]}" pagecount 2
check "counter missing" 3 "^PRINTER UNKNOWN - Counter 3 does not exist$" "${P[@]}" count 3
check "stats counter" 0 "^SN123456;Total: 123456\|pagecount=123456c$" "${P[@]}" stats_counter 1

echo "--- accounting"
check "accounting WARNING" 1 "^PRINTER WARNING - Department A=850 \(limit: 1000\)\|pages=850;800;1000;0;1000$" "${P[@]}" accounting 200000000000000 1
check "accounting OK" 0 "^PRINTER OK - Department A=850" "${P[@]}" accounting 200000000000000 1 0.1
F=$(fixture limit.walk <<< ".1.3.6.1.4.1.1347.42.1.1.1.1.3.1.1.1 = INTEGER: 1000")
check "accounting at the limit" 2 "^PRINTER CRITICAL - Department A=1000" --fixture "$F" "${P[@]}" accounting 200000000000000 1
F=$(fixture nolimit.walk <<< ".1.3.6.1.4.1.1347.42.1.1.1.1.6.1.1.1 = INTEGER: 0")
check "accounting without limit" 0 "^PRINTER OK - Department A=850\|pages=850;;;0$" --fixture "$F" "${P[@]}" accounting 200000000000000 1
check "accounting missing password" 3 "^PRINTER UNKNOWN - Missing the password \\(see check_printer.php -h\\)$" "${P[@]}" accounting
check "accounting missing department" 3 "^PRINTER UNKNOWN - Could not get department 2" "${P[@]}" accounting 200000000000000 2

echo "--- list"
check "list supplies" 0 "^    2  Cyan Toner: level 600 of 6000$" "${P[@]}" list
check "list trays" 0 "^    2  Bypass Tray: level 0 of 100$" "${P[@]}" list
check "list covers" 0 "^    2  Duplex Unit: open$" "${P[@]}" list
check "list hardware" 0 "^    2  Hard disk: down$" "${P[@]}" list
check "list alerts" 0 "^  Sleep \(code 504, location 122814464\)$" "${P[@]}" list
check "list no alerts" 0 "^  none$" --fixture "$TMP/no_alerts.walk" "${P[@]}" list

echo "$((TESTS - FAILURES)) of $TESTS tests passed"
[ $FAILURES -eq 0 ]
