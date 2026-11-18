# check_printer.php

A Nagios/Icinga plugin that checks a printer via SNMP: toner and ink levels,
paper trays, covers, hardware, the alert table and page counters. It uses the
standard Printer-MIB (RFC 3805) and Host Resources MIB, so it works with most
network printers, plus private MIBs of Ricoh and Kyocera.

It is named `check_printer.php` because Debian's monitoring-plugins-contrib
already ships a different `check_printer`.

```sh
check_printer.php printer.example.com public 2c list
check_printer.php printer.example.com public 2c toner all
check_printer.php printer.example.com public 2c toner 1 0.2 0.05
check_printer.php printer.example.com public 2c paper 2
check_printer.php printer.example.com public 2c alerts
check_printer.php printer.example.com public 2c counter 1
```

```
PRINTER WARNING - Cyan Toner 10% (600 of 6000 left) (WARNING), Black Toner 45% (4500 of 10000 left)|'Black Toner'=4500;2000:;0:;0;10000 'Cyan Toner'=600;1200:;0:;0;6000
```

## Usage

```
check_printer.php [-t SECONDS] [-r RETRIES] HOST COMMUNITY VERSION COMMAND [ARGUMENTS]
```

| Option | Meaning |
|---|---|
| `-t` | Seconds to wait for an SNMP answer (default: 1) |
| `-r` | Retries of an SNMP request (default: 2) |
| `-V` | Show the version |
| `-h` | Show the help |

`VERSION` is the SNMP version, `1` or `2c` (also `v1`, `v2c`). `NUM` is the
index the printer uses for a supply, tray or other part; `list` shows them.
`WARN` and `CRIT` are fractions from 0.0 to 1.0 of the maximum.

| Command | Checks |
|---|---|
| `list` | Lists the supplies, paper trays, covers, hardware and alerts with their indices |
| `toner NUM\|all [WARN [CRIT [MAX]]]` | A toner, ink or other supply, or all of them; also `ink`, `toner/ink` |
| `toner_kyocera_monochrome [WARN [CRIT]]` | `toner 1` with the defaults 0.10 and 0.05 |
| `toner_ricoh_monochrome NUM [WARN [CRIT]]`, `toner_ricoh_color …` | A toner from the private MIB of Ricoh, in percent |
| `paper NUM [WARN [CRIT]]` | A paper tray; also `tray` |
| `parts NUM` | A cover or interlock |
| `hardware NUM` | A device of the Host Resources MIB, e.g. CPU or disk |
| `alerts` | The alert table of the printer |
| `counter NUM` | A page counter: 1 is the lifetime count, others are Ricoh specific; also `count`, `pagecount` |
| `stats_counter NUM` | Like `counter`, but prints `SERIAL;NAME: COUNT` for statistics scripts |
| `accounting PASSWORD NUM [WARN]` | The page count of a Kyocera department against its limit; needs a write community |

## States

| Check | OK | WARNING | CRITICAL | UNKNOWN |
|---|---|---|---|---|
| toner, paper | above `WARN` (default 0.2), or "some left" | at or below `WARN`; Ricoh "almost empty" | at or below `CRIT` (default 0.0), empty | level or maximum unknown, level above the maximum |
| parts | closed | open | | other status |
| hardware | running | warning, testing | down | unknown |
| alerts | no alert, or only alerts like sleep mode, warming up, low paper | other alerts | jam, toner or ink empty, OPC life over, developer empty, toner cartridge missing | |
| accounting | below the limit minus `WARN` | less than `WARN` of the limit left | at the limit | |

With `WARN` and `CRIT` both 0, a level never alerts. `MAX` replaces a wrong
maximum the printer reports, e.g. 100 for HP Businessink. Printers often cannot
tell the level of a non-original cartridge; `toner NUM` is UNKNOWN then,
`toner all` only shows it.

A printer that does not answer is CRITICAL; check the host, community and SNMP
version. A missing supply, tray, part or counter is UNKNOWN.

Levels go into the performance data with the thresholds as lower limits
(`2000:`), counters with the unit `c`.

## Changes from the collection version

Up to the monitoring-plugins collection, the plugin was the unchanged upstream
script. Version 0.1 fixes these bugs:

- `alerts` was UNKNOWN "Internal error" as soon as the printer had any alert,
  even sleep mode: the plugin asked for numeric OIDs, but only parsed named ones.
- `alerts` printed PHP warnings, and its text listed the raw columns of the
  alert table; it now shows the alert text and code, e.g. `Sleep (code 504)`.
- A level of -3, "at least one unit left", was CRITICAL for toner and paper.
- `parts` was CRITICAL for a closed interlock.
- `toner_ricoh_monochrome` was UNKNOWN with an empty text for a level in percent;
  `toner_kyocera_monochrome` had a perfdata maximum and thresholds in the wrong
  unit, and a PHP 8.2 deprecation message.
- All performance data had the unit `c`, also levels and states.
- Without arguments, or with a wrong one, PHP printed warnings; an invalid
  number was taken as 0.
- An unreachable printer was UNKNOWN "Toner/Ink 1 does not exist!", after about
  6 seconds per SNMP request.
- `accounting` without a limit used an undefined state.

And adds `list`, `toner all`, `-t`, `-r`, `-V`, a help in English, and a bash
completion.

What changes for existing service definitions:

- The output starts with `PRINTER OK -` instead of `OK -`, and `Warning`/`Critical`
  are now upper case.
- `WARN` and `CRIT` must be between 0.0 and 1.0, with `CRIT` not above `WARN`.
  The Ricoh commands used -100 and 0 as defaults; they now use 0.2 and 0.0 like
  the others, and still WARN on "almost empty".
- SNMP version 1 is now also accepted as `v1`, and 2c as `2`.

## Requirements

PHP 8 with the SNMP extension: `php8-cli` and `php8-snmp` on openSUSE,
`php-cli` and `php-snmp` on Debian and Ubuntu.

## Tests

```sh
tests/test_check_printer.php.sh
tests/test_completion.sh
tests/test_snmpd.sh
```

The first two need only `php`. Instead of a printer, the plugin reads the file
`CHECK_PRINTER_FIXTURE` names, in the output format of `snmpwalk -On`; the
fixture is `tests/data/printer.walk`. To make one from a real printer:

```sh
snmpwalk -v 2c -c public -On printer.example.com .1.3.6.1.2.1 > printer.walk
```

`tests/test_snmpd.sh` checks the plugin over real SNMP, versions 1 and 2c: it
starts a snmpd as the user on 127.0.0.1, which serves the same fixture. It
needs snmpd, snmpget and the SNMP extension of PHP, and skips otherwise.

## License

The upstream script, the Advanced Nagios printer check by jk@ciphron.de and
Oliver Skibbe (oliskibbe@gmail.com) with fixes by Sebastian Puschhof (MIDAN
Software GmbH), Juan Jesús Cervera and vKnmnn, was published without a license
([riskersen/Monitoring](https://github.com/riskersen/Monitoring/tree/master/Printer)).
Until upstream names one, the license is unknown.
