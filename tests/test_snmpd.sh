#!/bin/bash
# Tests check_printer.php over real SNMP: a snmpd of the user on 127.0.0.1
# serves tests/data/printer.walk through a pass script. Needs snmpd and the
# SNMP extension of PHP; skips otherwise.
# Run: tests/test_snmpd.sh

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHECK="$ROOT/check_printer.php"
DATA="$ROOT/tests/data/printer.walk"
SNMPD=$(command -v snmpd || echo /usr/sbin/snmpd)

if ! php -m | grep -qx snmp; then
	echo "skip: the SNMP extension of PHP is not installed"
	exit 0
fi
if [ ! -x "$SNMPD" ]; then
	echo "skip: snmpd is not installed"
	exit 0
fi

TMP=$(mktemp -d)
cleanup(){
	[ -f "$TMP/snmpd.pid" ] && kill "$(<"$TMP/snmpd.pid")" 2>/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT

# the fixture as "OID TYPE VALUE" lines in OID order, for the pass script
sed -n 's/^\(\.[0-9.]*\) = \([A-Za-z0-9-]*\): \(.*\)$/\1\t\2\t\3/p' "$DATA" \
	| sed 's/\t"\(.*\)"$/\t\1/' | sort -t "$(printf '\t')" -k1,1V > "$TMP/data"

# snmpd pass protocol: -g OID (get) or -n OID (getnext) prints OID, type and
# value; ROOT is the subtree the script serves
cat > "$TMP/pass" <<EOF
#!/bin/bash
awk -F '\t' -v mode="\$1" -v oid="\$2" -v root="\$ROOT." '
	function lower(a, b,   i, n, m, x, y) {
		n = split(a, x, "."); m = split(b, y, ".")
		for (i = 2; i <= n && i <= m; i++)
			if (x[i] + 0 != y[i] + 0)
				return x[i] + 0 < y[i] + 0
		return n < m
	}
	index(\$1, root) == 1 && (mode == "-g" && \$1 == oid || mode == "-n" && lower(oid, \$1)) {
		type = tolower(\$2)
		if (type == "counter32") type = "counter"
		if (type == "timeticks") { type = "timeticks"; sub(/^\\(/, "", \$3); sub(/\\).*/, "", \$3) }
		print \$1; print type; print \$3; exit
	}' "$TMP/data"
EOF
chmod +x "$TMP/pass"
ROOTS=(.1.3.6.1.2.1.43 .1.3.6.1.2.1.25.3.2 .1.3.6.1.4.1.367 .1.3.6.1.4.1.1347)
for root in "${ROOTS[@]}"; do
	printf '#!/bin/bash\nROOT=%s exec %s "$@"\n' "$root" "$TMP/pass" > "$TMP/pass$root"
	chmod +x "$TMP/pass$root"
done

PORT=$((20000 + RANDOM % 20000))
cat > "$TMP/snmpd.conf" <<EOF
rocommunity public 127.0.0.1
sysDescr Example Color Laser 4200
EOF
for root in "${ROOTS[@]}"; do
	echo "pass $root $TMP/pass$root" >> "$TMP/snmpd.conf"
done
SNMP_PERSISTENT_DIR=$TMP "$SNMPD" -C -c "$TMP/snmpd.conf" -p "$TMP/snmpd.pid" -Lf "$TMP/snmpd.log" \
	-I -smux,hr_device -M /usr/share/snmp/mibs -m '' "udp:127.0.0.1:$PORT" || { echo "FAIL: snmpd did not start"; exit 1; }
for i in 1 2 3 4 5 6 7 8 9 10; do
	snmpget -v 2c -c public -t 1 -r 0 "127.0.0.1:$PORT" .1.3.6.1.2.1.1.1.0 >/dev/null 2>&1 && break
	sleep 0.5
done

FAILURES=0
TESTS=0

# check NAME RC PATTERN [ARGS...]: like tests/test_check_printer.php.sh, over SNMP
check(){
	local name=$1 rc=$2 pattern=$3
	shift 3
	OUT=$(php -d error_reporting=-1 -d display_errors=stderr "$CHECK" "$@" 2>"$TMP/err")
	RC=$?
	ERR=$(<"$TMP/err")
	TESTS=$((TESTS + 1))
	if [ "$RC" != "$rc" ] || ! grep -qE -- "$pattern" <<< "$OUT" || [ -n "$ERR" ]; then
		FAILURES=$((FAILURES + 1))
		printf 'FAIL %s: rc %s (expected %s)\n     out: %s\n     err: %s\n' "$name" "$RC" "$rc" "$OUT" "$ERR"
	else
		printf 'ok   %s\n' "$name"
	fi
}

for V in 1 2c; do
	P=("127.0.0.1:$PORT" public $V)
	echo "--- SNMP version $V"
	check "toner" 1 "^PRINTER WARNING - Cyan Toner 10% \(600 of 6000 left\)\|toner=600;1200:;0:;0;6000$" "${P[@]}" toner 2
	check "toner all" 1 "^PRINTER WARNING - Cyan Toner 10% .*, Magenta Toner some left, Yellow Toner level unknown," "${P[@]}" toner all
	check "toner missing" 3 "^PRINTER UNKNOWN - Toner/Ink 9 does not exist$" "${P[@]}" toner 9
	check "paper" 0 "^PRINTER OK - Tray 1 80% \(200 of 250 left\)" "${P[@]}" paper 1
	check "paper name from the description" 2 "^PRINTER CRITICAL - Bypass Tray 0%" "${P[@]}" paper 2
	check "parts" 1 "^PRINTER WARNING - Duplex Unit open$" "${P[@]}" parts 2
	check "hardware" 2 "^PRINTER CRITICAL - Hard disk down\|errors=17c$" "${P[@]}" hardware 2
	check "alerts" 0 "^PRINTER OK - Sleep \(code 504\)$" "${P[@]}" alerts
	check "counter" 0 "^PRINTER OK - Total: 123456\|pagecount=123456c$" "${P[@]}" counter 1
	check "ricoh toner" 1 "^PRINTER WARNING - Black Toner almost empty$" "${P[@]}" toner_ricoh_color 1
	check "list" 0 "^  Sleep \(code 504, location 122814464\)$" "${P[@]}" list
done
check "wrong community" 2 "^PRINTER CRITICAL - No SNMP answer from 127.0.0.1:$PORT" -t 1 -r 0 "127.0.0.1:$PORT" private 2c alerts

echo "$((TESTS - FAILURES)) of $TESTS tests passed"
[ $FAILURES -eq 0 ]
