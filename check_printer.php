#!/usr/bin/php
<?php
/*
 * check_printer.php - Nagios/Icinga plugin that checks a printer via SNMP:
 * toner and ink, paper trays, covers, hardware, alerts and page counters.
 *
 * Advanced Nagios printer check
 *
 * @author: jk@ciphron.de
 * @updated by: oliskibbe@gmail.com
 * @bugfixes / improvements by:
 *      - Sebastian Puschhof (MIDAN Software GmbH)
 *      - Juan Jesús Cervera (Teruel)
 *      - vKnmnn
 *      - Thomas Wagner
 *
 * See README.md for the license.
 */

const VERSION = '0.1';

const OK = 0;
const WARNING = 1;
const CRITICAL = 2;
const UNKNOWN = 3;
const STATE_NAMES = array('OK', 'WARNING', 'CRITICAL', 'UNKNOWN');

// Printer-MIB (RFC 3805) and Host Resources MIB
const OID_SYS_DESCR = '.1.3.6.1.2.1.1.1.0';
const OID_SERIAL = '.1.3.6.1.2.1.43.5.1.1.17.1';
const OID_LIFE_COUNT = '.1.3.6.1.2.1.43.10.2.1.4.1.1';
const OID_SUPPLY_DESCR = '.1.3.6.1.2.1.43.11.1.1.6.1';
const OID_SUPPLY_MAX = '.1.3.6.1.2.1.43.11.1.1.8.1';
const OID_SUPPLY_LEVEL = '.1.3.6.1.2.1.43.11.1.1.9.1';
const OID_INPUT_MAX = '.1.3.6.1.2.1.43.8.2.1.9.1';
const OID_INPUT_LEVEL = '.1.3.6.1.2.1.43.8.2.1.10.1';
const OID_INPUT_NAME = '.1.3.6.1.2.1.43.8.2.1.13.1';
const OID_INPUT_DESCR = '.1.3.6.1.2.1.43.8.2.1.18.1';
const OID_COVER_DESCR = '.1.3.6.1.2.1.43.6.1.1.2.1';
const OID_COVER_STATUS = '.1.3.6.1.2.1.43.6.1.1.3.1';
const OID_ALERT = '.1.3.6.1.2.1.43.18.1.1';
const OID_CONSOLE_TEXT = '.1.3.6.1.2.1.43.16.5.1.2';
const OID_HR_DEVICE_DESCR = '.1.3.6.1.2.1.25.3.2.1.3';
const OID_HR_DEVICE_STATUS = '.1.3.6.1.2.1.25.3.2.1.5';
const OID_HR_DEVICE_ERRORS = '.1.3.6.1.2.1.25.3.2.1.6';
// Ricoh
const OID_RICOH_COUNTER_NAME = '.1.3.6.1.4.1.367.3.2.1.2.19.5.1.5';
const OID_RICOH_COUNTER_VALUE = '.1.3.6.1.4.1.367.3.2.1.2.19.5.1.9';
const OID_RICOH_TONER_NAME = '.1.3.6.1.4.1.367.3.2.1.2.24.1.1.3';
const OID_RICOH_TONER_LEVEL = '.1.3.6.1.4.1.367.3.2.1.2.24.1.1.5';
// Kyocera
const OID_KYOCERA_PASSWORD = '.1.3.6.1.4.1.1347.42.1.2.1.1.1.2.1';
const OID_KYOCERA_ACCOUNT_NAME = '.1.3.6.1.4.1.1347.42.1.2.1.2.1.2.1';
const OID_KYOCERA_ACCOUNT_COUNT = '.1.3.6.1.4.1.1347.42.1.1.1.1.3.1';
const OID_KYOCERA_ACCOUNT_LIMIT = '.1.3.6.1.4.1.1347.42.1.1.1.1.6.1';

function help() {
	echo 'check_printer.php ' . VERSION . ' - check a printer via SNMP

Usage: check_printer.php [-t SECONDS] [-r RETRIES] HOST COMMUNITY VERSION COMMAND [ARGUMENTS]
       check_printer.php -V | -h

-t      Seconds to wait for an SNMP answer (default: 1)
-r      Retries of an SNMP request (default: 2)
-V      Show the version
-h      Show this help

VERSION is the SNMP version: 1 or 2c (also v1, v2c). NUM is the index the
printer uses for a supply, tray or other part; "list" shows them. WARN and
CRIT are fractions from 0.0 to 1.0 of the maximum.

Commands:

list
    List the supplies, paper trays, covers, hardware and alerts with their
    indices. Use it to find NUM for the other commands.

toner NUM|all [WARN [CRIT [MAX]]]           (also: ink, toner/ink)
    The level of a toner, ink or other supply, or of all supplies.
    WARNING at or below WARN (default 0.2), CRITICAL at or below CRIT
    (default 0.0) and when empty. MAX replaces a wrong maximum the printer
    reports (e.g. 100 for HP Businessink). With WARN and CRIT both 0, the
    level never alerts. A level the printer cannot tell, e.g. of a
    non-original cartridge, is UNKNOWN; with "all" it is only shown.

toner_kyocera_monochrome [WARN [CRIT]]
    Like "toner 1", with the defaults WARN 0.10 and CRIT 0.05.

toner_ricoh_monochrome NUM [WARN [CRIT]]
toner_ricoh_color NUM [WARN [CRIT]]
    A toner from the private MIB of Ricoh, in percent. The printer may only
    tell "almost empty" (WARNING) or "some left" (OK).

paper NUM [WARN [CRIT]]                     (also: tray)
    The level of a paper tray, like "toner".

parts NUM
    A cover or interlock: OK when closed, WARNING when open.

hardware NUM
    A device of the Host Resources MIB, e.g. CPU or disk: OK when running,
    WARNING when in warning or testing state, CRITICAL when down.

alerts
    The alert table of the printer. Most alerts are WARNING; a jam, empty
    toner or ink, OPC life over, empty developer and a missing toner
    cartridge are CRITICAL. Alerts like sleep mode, warming up, a low paper
    supply or a closed cover do not count.

counter NUM                                 (also: count, pagecount)
    A page counter; 1 is the lifetime count of printed pages, others are
    Ricoh specific. Always OK when the counter exists.

stats_counter NUM
    Like "counter", but prints "SERIAL;NAME: COUNT" for statistics scripts.

accounting PASSWORD NUM [WARN]
    The page count of Kyocera department NUM against its limit: WARNING
    when less than WARN (default 0.2) of the limit is left, CRITICAL at the
    limit. PASSWORD is the printer number padded with 0 to 15 digits
    (FS-2000: 200000000000000). Needs a write community.
';
}

// $str with the state, perfdata and a line end; exits with $state
function nagios_return($state, $str, $perfdata = array()) {
	$str = trim(preg_replace('/\s+/', ' ', $str));
	echo 'PRINTER ' . STATE_NAMES[$state] . ' - ' . $str;
	print_perfdata($perfdata);
	exit($state);
}

/*
 * each entry: label => array('value' =>, 'uom' =>, 'warn' =>, 'crit' =>,
 * 'min' =>, 'max' =>, 'low' =>); with 'low', warn and crit are lower limits
 */
function print_perfdata($perfdata) {
	$items = array();
	foreach ($perfdata as $label => $perf) {
		$label = str_replace(array("'", '='), '_', $label);
		if (preg_match('/[\s|]/', $label))
			$label = "'" . $label . "'";
		$fields = array($perf['value'] . ($perf['uom'] ?? ''));
		foreach (array('warn', 'crit') as $key)
			$fields[] = isset($perf[$key]) ? perf_number($perf[$key]) . (empty($perf['low']) ? '' : ':') : '';
		foreach (array('min', 'max') as $key)
			$fields[] = isset($perf[$key]) ? perf_number($perf[$key]) : '';
		$items[] = $label . '=' . rtrim(implode(';', $fields), ';');
	}
	if ($items)
		echo '|' . implode(' ', $items);
	echo "\n";
}

function perf_number($value) {
	return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
}

function usage_error($str) {
	nagios_return(UNKNOWN, "$str (see check_printer.php -h)");
}

// SNMP strings may have trailing NULs or line ends
function clean_string($value) {
	if ($value === FALSE || $value === NULL)
		return FALSE;
	return trim(preg_replace('/[\x00-\x1f]+/', ' ', $value));
}

// For the tests: CHECK_PRINTER_FIXTURE names a file in the output format of
// "snmpwalk -On", used instead of a printer.
function fixture() {
	static $data = NULL;
	if ($data === NULL) {
		$data = array();
		$lines = @file(getenv('CHECK_PRINTER_FIXTURE'), FILE_IGNORE_NEW_LINES);
		if ($lines === FALSE)
			nagios_return(UNKNOWN, 'Cannot read ' . getenv('CHECK_PRINTER_FIXTURE'));
		foreach ($lines as $line) {
			if (!preg_match('/^(\.[0-9.]+) = (?:[A-Za-z0-9-]+: )?(.*)$/', $line, $m))
				continue;
			$value = $m[2];
			if (preg_match('/^"(.*)"$/', $value, $q))
				$value = $q[1];
			$data[$m[1]] = $value;
		}
	}
	return $data;
}

function snmp_get_value($oid) {
	global $host, $community, $snmpversion, $timeout, $retries;
	if (getenv('CHECK_PRINTER_FIXTURE') !== FALSE)
		return fixture()[$oid] ?? FALSE;
	if ($snmpversion == '2c')
		return @snmp2_get($host, $community, $oid, $timeout, $retries);
	return @snmpget($host, $community, $oid, $timeout, $retries);
}

// all values below $oid, keyed by the numeric OID with a leading dot
function snmp_walk_values($oid) {
	global $host, $community, $snmpversion, $timeout, $retries;
	if (getenv('CHECK_PRINTER_FIXTURE') !== FALSE) {
		$values = array();
		foreach (fixture() as $key => $value)
			if (strpos($key, $oid . '.') === 0)
				$values[$key] = $value;
		return $values;
	}
	if ($snmpversion == '2c')
		$values = @snmp2_real_walk($host, $community, $oid, $timeout, $retries);
	else
		$values = @snmprealwalk($host, $community, $oid, $timeout, $retries);
	if (!is_array($values))
		return array();
	// net-snmp may print the OIDs as iso.3.6.1... or without the leading dot
	$normalized = array();
	foreach ($values as $key => $value)
		$normalized[preg_replace('/^(?:iso|\.?1)\./', '.1.', $key)] = $value;
	return $normalized;
}

// values below $oid, keyed by the last number of their OID
function snmp_column($oid) {
	$column = array();
	foreach (snmp_walk_values($oid) as $key => $value)
		$column[(int) substr($key, strrpos($key, '.') + 1)] = $value;
	return $column;
}

function snmp_set_value($oid, $type, $value) {
	global $host, $community, $snmpversion, $timeout, $retries;
	if (getenv('CHECK_PRINTER_FIXTURE') !== FALSE)
		return TRUE;
	if ($snmpversion == '2c')
		return @snmp2_set($host, $community, $oid, $type, $value, $timeout, $retries);
	return @snmpset($host, $community, $oid, $type, $value, $timeout, $retries);
}

// a NUM argument
function index_arg($pos, $what) {
	global $args;
	if (!isset($args[$pos]))
		usage_error("Missing the number of the $what");
	if (!preg_match('/^[0-9]+$/', $args[$pos]))
		usage_error("Invalid number of the $what: " . $args[$pos]);
	return (int) $args[$pos];
}

// an optional WARN, CRIT or MAX argument
function number_arg($pos, $default, $what) {
	global $args;
	if (!isset($args[$pos]))
		return $default;
	if (!is_numeric($args[$pos]))
		usage_error("Invalid $what: " . $args[$pos]);
	return (float) $args[$pos];
}

function threshold_args($pos, $warn, $crit) {
	$warn = number_arg($pos, $warn, 'warning level');
	$crit = number_arg($pos + 1, $crit, 'critical level');
	if ($warn < 0 || $warn > 1 || $crit < 0 || $crit > 1)
		usage_error('The levels must be between 0.0 and 1.0');
	if ($crit > $warn)
		usage_error('The critical level must not be above the warning level');
	return array($warn, $crit);
}

/*
 * The state and text of a supply or tray level. The Printer-MIB uses -3 for
 * "at least one unit left", -2 for unknown and -1 for other. Ricoh uses -100
 * for "almost empty".
 */
function level_state($cur, $max, $warn, $crit) {
	$cur = (int) $cur;
	$max = (float) $max;
	$alerts = $warn > 0 || $crit > 0;
	if ($cur == -3)
		return array(OK, 'some left');
	if ($cur == -100)
		return array($alerts ? WARNING : OK, 'almost empty');
	if ($cur < 0)
		return array(UNKNOWN, 'level unknown');
	if ($max <= 0)
		return array(UNKNOWN, "level $cur, maximum unknown");
	if ($cur > $max)
		return array(UNKNOWN, "level $cur above the maximum " . perf_number($max));
	$text = sprintf('%d%% (%d of %s left)', floor($cur * 100 / $max), $cur, perf_number($max));
	if (!$alerts)
		return array(OK, $text);
	if ($cur == 0 || $cur <= $crit * $max)
		return array(CRITICAL, $text);
	if ($cur <= $warn * $max)
		return array(WARNING, $text);
	return array(OK, $text);
}

function level_perf($cur, $max, $warn, $crit) {
	$cur = (int) $cur;
	$max = (float) $max;
	if ($cur < 0 || $max <= 0 || $cur > $max)
		return NULL;
	return array('value' => $cur, 'warn' => $warn * $max, 'crit' => $crit * $max, 'low' => TRUE,
		'min' => 0, 'max' => $max);
}

function check_supply($num, $warn, $crit, $max_override) {
	$name = clean_string(snmp_get_value(OID_SUPPLY_DESCR . ".$num"));
	$max = snmp_get_value(OID_SUPPLY_MAX . ".$num");
	$cur = snmp_get_value(OID_SUPPLY_LEVEL . ".$num");
	if ($name === FALSE || $max === FALSE || $cur === FALSE)
		nagios_return(UNKNOWN, "Toner/Ink $num does not exist");
	if ($max_override !== NULL)
		$max = $max_override;
	list($state, $text) = level_state($cur, $max, $warn, $crit);
	$perf = level_perf($cur, $max, $warn, $crit);
	nagios_return($state, "$name $text", $perf ? array('toner' => $perf) : array());
}

function check_all_supplies($warn, $crit, $max_override) {
	$names = snmp_column(OID_SUPPLY_DESCR);
	$maxes = snmp_column(OID_SUPPLY_MAX);
	$levels = snmp_column(OID_SUPPLY_LEVEL);
	if (!$names)
		nagios_return(UNKNOWN, 'The printer reports no supplies');
	$worst = OK;
	$problems = array();
	$fine = array();
	$perfdata = array();
	foreach ($names as $num => $name) {
		$name = clean_string($name);
		$max = $max_override ?? ($maxes[$num] ?? -2);
		$cur = $levels[$num] ?? -2;
		list($state, $text) = level_state($cur, $max, $warn, $crit);
		if ($state == OK || $state == UNKNOWN) {
			$fine[] = "$name $text";
		} else {
			$problems[] = "$name $text (" . STATE_NAMES[$state] . ')';
			$worst = max($worst, $state);
		}
		$perf = level_perf($cur, $max, $warn, $crit);
		if ($perf)
			$perfdata[isset($perfdata[$name]) ? "$name $num" : $name] = $perf;
	}
	nagios_return($worst, implode(', ', array_merge($problems, $fine)), $perfdata);
}

function check_ricoh_toner($num, $warn, $crit) {
	$name = clean_string(snmp_get_value(OID_RICOH_TONER_NAME . ".$num"));
	$cur = snmp_get_value(OID_RICOH_TONER_LEVEL . ".$num");
	if ($name === FALSE || $cur === FALSE)
		nagios_return(UNKNOWN, "Toner/Ink $num does not exist");
	list($state, $text) = level_state($cur, 100, $warn, $crit);
	$perf = level_perf($cur, 100, $warn, $crit);
	if ($perf)
		$perf['uom'] = '%';
	nagios_return($state, "$name $text", $perf ? array('toner' => $perf) : array());
}

// prtCoverStatus
function cover_status($status) {
	switch ((int) $status) {
	case 4: return array(OK, 'closed');
	case 6: return array(OK, 'interlock closed');
	case 3: return array(WARNING, 'open');
	case 5: return array(WARNING, 'interlock open');
	case 1: return array(UNKNOWN, 'other');
	default: return array(UNKNOWN, "unknown status $status");
	}
}

// hrDeviceStatus
function device_status($status) {
	switch ((int) $status) {
	case 2: return array(OK, 'running');
	case 3: return array(WARNING, 'warning');
	case 4: return array(WARNING, 'testing');
	case 5: return array(CRITICAL, 'down');
	default: return array(UNKNOWN, 'unknown');
	}
}

// the rows of prtAlertTable with their location, code and text
function alert_rows() {
	$rows = array();
	foreach (snmp_walk_values(OID_ALERT) as $key => $value) {
		if (preg_match('/^' . preg_quote(OID_ALERT, '/') . '\.(\d+)\.1\.(\d+)$/', $key, $m))
			$rows[(int) $m[2]][(int) $m[1]] = $value;
	}
	$alerts = array();
	foreach ($rows as $row) {
		$location = (int) ($row[6] ?? 0);
		$code = (int) ($row[7] ?? 0);
		$text = clean_string($row[8] ?? '');
		// some printers put the location into the text: "... {13100}"
		if ($code == 1 && $location == 0 && preg_match('/{(\d+)}/', $text, $m))
			$location = (int) $m[1];
		$alerts[] = array('location' => $location, 'code' => $code,
			'text' => $text !== '' ? $text : 'Alert');
	}
	return $alerts;
}

/*
 * Codes are not always correct, but mostly; the severity is often strange.
 * So every alert is a warning, except the codes below.
 */
function check_alerts() {
	// jam, toner or ink empty, OPC life over, developer empty, toner cartridge missing
	$critical_codes = array(8, 1101, 1102, 1112, 1114, 1115);
	// e.g. cover closed, power saver, warming up, ready, paper low or empty, waste toner almost full
	$ok_codes = array(4, 6, 7, 19, 20, 23, 24, 25, 27, 35, 36, 37, 38, 255, 502, 503, 504, 505, 506, 507,
		802, 803, 804, 805, 806, 807, 808, 809, 810, 1001, 1002, 1005, 1106, 1107, 1108, 1111, 1113,
		1302, 1304, 1501, 1502, 1503, 1504, 1505, 1506, 1509, 40000);
	$location_ignore_codes = array(10003, 10033, 10034);
	$location_ok_codes = array(13100, 13200, 13300, 13400, 13500);
	$ignore_codes = array(0, -1);

	$state = OK;
	$critical = array();
	$warning = array();
	$texts = array();
	foreach (alert_rows() as $alert) {
		$code = $alert['code'];
		$location = $alert['location'];
		// skip really unimportant messages, like energy saving
		if (in_array($location, $location_ignore_codes) || in_array($code, $ignore_codes))
			continue;
		$texts[] = $alert['text'] . " (code $code)";
		// do not escalate those
		if (($code > 0 && in_array($code, $ok_codes)) || ($location > 0 && in_array($location, $location_ok_codes)))
			continue;
		if ($code > 0 && in_array($code, $critical_codes)) {
			$critical[] = $code;
			$state = CRITICAL;
		} else {
			$warning[] = $code;
			$state = max($state, WARNING);
		}
	}

	$str = implode(', ', $texts);
	// without alert texts (Kyocera), show the display of the printer
	if ($str == '') {
		$display = array_filter(array_map('clean_string', snmp_column(OID_CONSOLE_TEXT)));
		$str = $display ? implode(' ', $display) : 'no alerts';
	}
	if ($warning)
		$str = 'Warning codes: ' . implode(',', $warning) . ' ' . $str;
	if ($critical)
		$str = 'Critical codes: ' . implode(',', $critical) . ' ' . $str;
	nagios_return($state, $str);
}

function check_counter($num, $stats) {
	if ($num == 1) {
		// for printers without private MIB
		$count = snmp_get_value(OID_LIFE_COUNT);
		$name = 'Total';
	} else {
		$name = clean_string(snmp_get_value(OID_RICOH_COUNTER_NAME . ".$num"));
		$count = snmp_get_value(OID_RICOH_COUNTER_VALUE . ".$num");
	}
	$serial = $stats ? clean_string(snmp_get_value(OID_SERIAL)) : '';
	if ($name === FALSE || $count === FALSE || $serial === FALSE)
		nagios_return(UNKNOWN, "Counter $num does not exist");
	$perf = array('pagecount' => array('value' => (int) $count, 'uom' => 'c'));
	if ($stats) {
		echo "$serial;$name: " . (int) $count;
		print_perfdata($perf);
		exit(OK);
	}
	nagios_return(OK, "$name: " . (int) $count, $perf);
}

function check_paper($num, $warn, $crit) {
	$name = clean_string(snmp_get_value(OID_INPUT_NAME . ".$num"));
	if ($name === FALSE || $name === '')
		$name = clean_string(snmp_get_value(OID_INPUT_DESCR . ".$num"));
	$max = snmp_get_value(OID_INPUT_MAX . ".$num");
	$cur = snmp_get_value(OID_INPUT_LEVEL . ".$num");
	if ($name === FALSE || $max === FALSE || $cur === FALSE)
		nagios_return(UNKNOWN, "Tray $num does not exist");
	list($state, $text) = level_state($cur, $max, $warn, $crit);
	$perf = level_perf($cur, $max, $warn, $crit);
	nagios_return($state, "$name $text", $perf ? array('paper' => $perf) : array());
}

function check_parts($num) {
	$name = clean_string(snmp_get_value(OID_COVER_DESCR . ".$num"));
	$status = snmp_get_value(OID_COVER_STATUS . ".$num");
	if ($name === FALSE || $status === FALSE)
		nagios_return(UNKNOWN, "Part $num does not exist");
	list($state, $text) = cover_status($status);
	nagios_return($state, "$name $text");
}

function check_hardware($num) {
	$name = clean_string(snmp_get_value(OID_HR_DEVICE_DESCR . ".$num"));
	$status = snmp_get_value(OID_HR_DEVICE_STATUS . ".$num");
	$errors = snmp_get_value(OID_HR_DEVICE_ERRORS . ".$num");
	if ($name === FALSE || $status === FALSE)
		nagios_return(UNKNOWN, "Hardware $num does not exist");
	list($state, $text) = device_status($status);
	$perf = is_numeric($errors) ? array('errors' => array('value' => (int) $errors, 'uom' => 'c')) : array();
	nagios_return($state, "$name $text", $perf);
}

function check_accounting($pw, $num, $warn) {
	// the password has to be set before each request
	snmp_set_value(OID_KYOCERA_PASSWORD, 's', $pw);
	$name = clean_string(snmp_get_value(OID_KYOCERA_ACCOUNT_NAME . ".$num"));
	snmp_set_value(OID_KYOCERA_PASSWORD, 's', $pw);
	$count = snmp_get_value(OID_KYOCERA_ACCOUNT_COUNT . ".$num.1");
	snmp_set_value(OID_KYOCERA_PASSWORD, 's', $pw);
	$limit = snmp_get_value(OID_KYOCERA_ACCOUNT_LIMIT . ".$num.1");
	if ($name === FALSE || $count === FALSE || $limit === FALSE)
		nagios_return(UNKNOWN, "Could not get department $num; are the password and the write community right?");
	$count = (int) $count;
	$limit = (int) $limit;
	if ($limit <= 0)
		nagios_return(OK, "$name=$count", array('pages' => array('value' => $count, 'min' => 0)));
	$perf = array('pages' => array('value' => $count, 'warn' => $limit * (1 - $warn), 'crit' => $limit,
		'min' => 0, 'max' => $limit));
	if ($count >= $limit)
		$state = CRITICAL;
	else if ($count >= $limit * (1 - $warn))
		$state = WARNING;
	else
		$state = OK;
	nagios_return($state, "$name=$count (limit: $limit)", $perf);
}

function list_printer() {
	echo 'Printer: ' . clean_string(snmp_get_value(OID_SYS_DESCR)) . "\n";
	$maxes = snmp_column(OID_SUPPLY_MAX);
	$levels = snmp_column(OID_SUPPLY_LEVEL);
	echo "\nSupplies (toner NUM):\n";
	foreach (snmp_column(OID_SUPPLY_DESCR) as $num => $name)
		printf("  %3d  %s: level %s of %s\n", $num, clean_string($name), $levels[$num] ?? '?', $maxes[$num] ?? '?');
	$maxes = snmp_column(OID_INPUT_MAX);
	$levels = snmp_column(OID_INPUT_LEVEL);
	$names = snmp_column(OID_INPUT_NAME);
	echo "\nPaper trays (paper NUM):\n";
	foreach (snmp_column(OID_INPUT_DESCR) + $names as $num => $descr) {
		$name = clean_string($names[$num] ?? '') ?: clean_string($descr);
		printf("  %3d  %s: level %s of %s\n", $num, $name, $levels[$num] ?? '?', $maxes[$num] ?? '?');
	}
	$status = snmp_column(OID_COVER_STATUS);
	echo "\nCovers (parts NUM):\n";
	foreach (snmp_column(OID_COVER_DESCR) as $num => $name)
		printf("  %3d  %s: %s\n", $num, clean_string($name), cover_status($status[$num] ?? 0)[1]);
	$status = snmp_column(OID_HR_DEVICE_STATUS);
	echo "\nHardware (hardware NUM):\n";
	foreach (snmp_column(OID_HR_DEVICE_DESCR) as $num => $name)
		printf("  %3d  %s: %s\n", $num, clean_string($name), device_status($status[$num] ?? 0)[1]);
	echo "\nAlerts:\n";
	$alerts = alert_rows();
	if (!$alerts)
		echo "  none\n";
	foreach ($alerts as $alert)
		printf("  %s (code %d, location %d)\n", $alert['text'], $alert['code'], $alert['location']);
	exit(OK);
}

// command line
$timeout = 1;
$retries = 2;
$args = array_slice($argv, 1);
while ($args && preg_match('/^-./', $args[0])) {
	$opt = array_shift($args);
	if ($opt == '--')
		break;
	switch ($opt) {
	case '-h':
	case '--help':
		help();
		exit(UNKNOWN);
	case '-V':
	case '--version':
		echo 'check_printer.php ' . VERSION . "\n";
		exit(UNKNOWN);
	case '-t':
	case '-r':
		$value = array_shift($args);
		if ($value === NULL || !preg_match('/^[0-9]+$/', $value) || ($opt == '-t' && $value == 0))
			usage_error("Invalid value for $opt: " . ($value ?? 'missing'));
		if ($opt == '-t')
			$timeout = (int) $value;
		else
			$retries = (int) $value;
		break;
	default:
		usage_error("Unknown option $opt");
	}
}
// the old way to ask for help
if (in_array($args[3] ?? '', array('help', '--help', '-h'))) {
	help();
	exit(UNKNOWN);
}
if (count($args) < 4)
	usage_error('Missing arguments: HOST COMMUNITY VERSION COMMAND');
list($host, $community, $snmpversion, $command) = $args;
$args = array_slice($args, 4);
switch (strtolower($snmpversion)) {
case '1':
case 'v1':
	$snmpversion = '1';
	break;
case '2':
case '2c':
case 'v2c':
	$snmpversion = '2c';
	break;
default:
	usage_error("Unsupported SNMP version $snmpversion; use 1 or 2c");
}
// the PHP functions take microseconds
$timeout *= 1000000;

if (getenv('CHECK_PRINTER_FIXTURE') === FALSE) {
	if (!extension_loaded('snmp'))
		nagios_return(UNKNOWN, 'The SNMP extension of PHP (php-snmp) is not installed');
	snmp_set_quick_print(TRUE);
	snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
	snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
}

// tell an unreachable printer from a missing value
if (snmp_get_value(OID_SYS_DESCR) === FALSE)
	nagios_return(CRITICAL, "No SNMP answer from $host; are the host, community and SNMP version right?");

switch ($command) {
case 'list':
	list_printer();
case 'toner':
case 'toner/ink':
case 'ink':
	$all = ($args[0] ?? '') == 'all';
	$num = $all ? 0 : index_arg(0, 'toner/ink');
	list($warn, $crit) = threshold_args(1, 0.2, 0.0);
	$max = number_arg(3, NULL, 'maximum');
	if ($max !== NULL && $max <= 0)
		usage_error('The maximum must be above 0');
	if ($all)
		check_all_supplies($warn, $crit, $max);
	check_supply($num, $warn, $crit, $max);
case 'toner_kyocera_monochrome':
	list($warn, $crit) = threshold_args(0, 0.10, 0.05);
	check_supply(1, $warn, $crit, NULL);
case 'toner_ricoh_monochrome':
case 'toner_ricoh_color':
	$num = index_arg(0, 'toner/ink');
	list($warn, $crit) = threshold_args(1, 0.2, 0.0);
	check_ricoh_toner($num, $warn, $crit);
case 'tray':
case 'paper':
	$num = index_arg(0, 'tray');
	list($warn, $crit) = threshold_args(1, 0.2, 0.0);
	check_paper($num, $warn, $crit);
case 'parts':
	check_parts(index_arg(0, 'part'));
case 'hardware':
	check_hardware(index_arg(0, 'hardware'));
case 'alerts':
	check_alerts();
case 'pagecount':
case 'counter':
case 'count':
	check_counter(index_arg(0, 'counter'), FALSE);
case 'stats_counter':
	check_counter(index_arg(0, 'counter'), TRUE);
case 'accounting':
	if (!isset($args[0]))
		usage_error('Missing the password');
	$num = index_arg(1, 'department');
	$warn = number_arg(2, 0.2, 'warning level');
	if ($warn < 0 || $warn > 1)
		usage_error('The warning level must be between 0.0 and 1.0');
	check_accounting($args[0], $num, $warn);
default:
	usage_error("Unknown command $command");
}
