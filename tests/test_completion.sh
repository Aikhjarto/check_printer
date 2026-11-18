#!/bin/bash
# Tests for the bash completion in completions/check_printer.php.
# Run: tests/test_completion.sh

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHECK="$ROOT/check_printer.php"
COMPLETION="$ROOT/completions/check_printer.php"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
echo "192.0.2.1 printer1.example.com printer1" > "$TMP/hosts"

FAILURES=0
TESTS=0

# the candidates, one per line, bash offers for the last argument, which may be empty
complete_words(){
	HOSTFILE="$TMP/hosts" bash -c 'source "$1"; shift; COMP_WORDS=(check_printer.php "$@"); COMP_CWORD=$#
		_check_printer_php; printf "%s\n" "${COMPREPLY[@]}"' bash "$COMPLETION" "$@" | grep . | sort
}

# expect NAME EXPECTED ACTUAL
expect(){
	TESTS=$((TESTS + 1))
	if [ "$2" == "$3" ]; then
		printf 'ok   %s\n' "$1"
	else
		FAILURES=$((FAILURES + 1))
		printf 'FAIL %s: expected\n%s\n     got\n%s\n' "$1" "$2" "$3"
	fi
}

TESTS=$((TESTS + 1))
if bash -n "$COMPLETION"; then echo "ok   syntax"; else FAILURES=$((FAILURES + 1)); echo "FAIL syntax"; fi

# the options and commands -h lists
HELP_OPTIONS=$(php "$CHECK" -h | sed -n 's/^\(-[[:alnum:]]\) .*/\1/p' | sort)
HELP_COMMANDS=$(php "$CHECK" -h | sed -n '/^Commands:/,$ s/^\([a-z_]\+\)\( .*\)\?$/\1/p' | sort)
expect "-h lists options" 4 "$(wc -l <<< "$HELP_OPTIONS")"
expect "-h lists commands" 12 "$(wc -l <<< "$HELP_COMMANDS")"
expect "exactly the options of -h" "$HELP_OPTIONS" "$(complete_words -)"
expect "hosts" "$(printf '%s\n' printer1 printer1.example.com)" "$(complete_words printer)"
expect "no candidates for a community" "" "$(complete_words printer "")"
expect "SNMP versions" "$(printf '%s\n' 1 2c)" "$(complete_words printer public "")"
expect "exactly the commands of -h" "$HELP_COMMANDS" "$(complete_words printer public 2c "")"
expect "command prefix" "$(printf '%s\n' toner toner_kyocera_monochrome toner_ricoh_color toner_ricoh_monochrome)" \
	"$(complete_words printer public 2c ton)"
expect "commands after options" "alerts" "$(complete_words -t 3 -r 1 printer public 2c al)"
expect "all supplies" "all" "$(complete_words printer public 2c toner "")"
expect "no candidates for a tray" "" "$(complete_words printer public 2c paper "")"
expect "no candidates for a timeout" "" "$(complete_words -t "")"

echo "$((TESTS - FAILURES)) of $TESTS tests passed"
[ $FAILURES -eq 0 ]
