#!/usr/bin/env php
<?php
/**
 * run-tests.php - end-to-end tests against the mock CUCM and a real MySQL.
 *
 * Usage:
 *   php tests/run-tests.php              # unit tests + integration (needs MySQL)
 *   php tests/run-tests.php --unit       # unit tests only, no server, no DB
 *
 * Environment for the integration half:
 *   CPS_TEST_DB_HOST / _PORT / _USER / _PASS / _NAME
 *
 * See the header of mock-server.php for what these tests do and do not
 * prove. In short: the plumbing, not Cisco's field names.
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';

$FAILS = 0;
$RUN   = 0;

function ok(string $what, bool $cond, string $detail = ''): void {
    global $FAILS, $RUN;
    $RUN++;
    if ($cond) {
        printf("  ok   %s\n", $what);
    } else {
        $FAILS++;
        printf("  FAIL %s%s\n", $what, $detail !== '' ? "  -- $detail" : '');
    }
}
function eq(string $what, mixed $got, mixed $want): void {
    ok($what, $got === $want, 'got ' . json_encode($got) . ', want ' . json_encode($want));
}
function section(string $s): void { printf("\n== %s ==\n", $s); }

// ===========================================================================
section('unit: link parsing');
// ===========================================================================
$cases = [
    ['100 Mbps Full Duplex', 100,  'full', 1],
    ['1000Mb Full',          1000, 'full', 1],
    ['100Mb Full',           100,  'full', 1],
    ['100 Full',             100,  'full', 1],
    ['100 Half',             100,  'half', 1],
    ['10 Half',              10,   'half', 1],
    ['1 Gbps Full Duplex',   1000, 'full', 1],
    ['No Link',              null, null,   0],
    ['Down',                 null, null,   0],
];
foreach ($cases as [$in, $speed, $duplex, $up]) {
    $r = cps_parse_link($in);
    ok("parse '$in' -> {$speed}/{$duplex}",
        $r['speed_mbps'] === $speed && $r['duplex'] === $duplex && $r['link_up'] === $up,
        json_encode($r));
}
// Negative control: "Auto Negotiation" states a setting, not a result. If
// this ever starts returning a speed, the parser is inventing data.
$r = cps_parse_link('Auto Negotiation');
ok('"Auto Negotiation" yields no speed/duplex/link claim',
    $r['speed_mbps'] === null && $r['duplex'] === null && $r['link_up'] === null, json_encode($r));
// Negative control: a bare number that is not an ethernet rate is not a speed.
$r = cps_parse_link('Errors 57 Full');
ok('non-rate number is not read as a speed', $r['speed_mbps'] === null, json_encode($r));

// ===========================================================================
section('unit: label normalisation absorbs spelling drift');
// ===========================================================================
eq('"Rx totalPkt" and "RxTotalPkt" collapse',
   cps_norm_key('Rx totalPkt'), cps_norm_key('RxTotalPkt'));
eq('"Tx Total Collision" collapses to txtotalcollision',
   cps_norm_key('Tx Total Collision'), 'txtotalcollision');

$n = cps_normalise_port([
    'Port Information'  => '100 Mbps Full Duplex',
    'RxTotalPkt'        => '1,234',      // comma-formatted
    'Rx crcErr'         => '42',
    'TxLateCollision'   => '7 pkts',     // suffixed
    'Rx tokenDrop'      => '3',          // unmapped
]);
eq('speed parsed',            $n['link']['speed_mbps'], 100);
eq('duplex parsed',           $n['link']['duplex'], 'full');
eq('comma-formatted counter', $n['counters']['rx_total_pkt'], 1234);
eq('suffixed counter',        $n['counters']['tx_late_collision'], 7);
eq('errors rolled up',        $n['errors_total'], 49);   // 42 crc + 7 late collision
eq('unmapped counted',        $n['unmapped'], 1);
// Negative control: a traffic counter must not be counted as an error.
$n2 = cps_normalise_port(['RxBroadcast' => '900000', 'Port Information' => '100 Full']);
eq('broadcasts are not errors', $n2['errors_total'], 0);

// ===========================================================================
section('unit: HTML table layouts');
// ===========================================================================
$two    = '<table><tr><td>Rx totalPkt</td><td>10</td></tr></table>';
$three  = '<table><tr><td>Rx totalPkt</td><td>:</td><td>10</td></tr></table>';
$padded = '<table><tr><td></td><td>Rx totalPkt</td><td>&nbsp;</td><td>10</td><td></td></tr></table>';
foreach (['2-column' => $two, '3-column with ":"' => $three, 'padded spacers' => $padded] as $label => $html) {
    $p = cps_parse_stats_html($html);
    ok("$label layout parses", ($p['Rx totalPkt'] ?? null) === '10', json_encode($p));
}
// Negative control: a row with only one cell is not a stat.
eq('single-cell row ignored', cps_parse_stats_html('<table><tr><td>Heading</td></tr></table>'), []);

// A phone answering 200 with nothing in it reached DOMDocument::loadHTML(''),
// which throws a ValueError under PHP 8 and killed a whole sweep. These must
// return no pairs, not throw.
eq('empty body parses to nothing', cps_parse_stats_html(''), []);
eq('whitespace-only body parses to nothing', cps_parse_stats_html("  \n\t "), []);
eq('markup with no table parses to nothing',
   cps_parse_stats_html('<html><body><h1>Service unavailable</h1></body></html>'), []);

section('unit: CSV rendering');
// CUCM descriptions really do contain commas, quotes and the occasional
// pasted newline. A report that corrupts the file on those is worse than no
// report, because the damage is invisible until someone reads a spreadsheet.
$csv = cps_render_csv([
    ['name' => 'SEP001122334401', 'description' => 'Level 2, Room 3 "Boardroom"', 'errors' => 7],
    ['name' => 'SEP001122334402', 'description' => "Line1\nLine2", 'errors' => 0],
    ['name' => 'SEP001122334403', 'description' => null, 'errors' => 12],
]);
$fh = fopen('php://memory', 'r+');
fwrite($fh, $csv);
rewind($fh);
$head = fgetcsv($fh, 0, ',', '"', '\\');
$back = [];
while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    if ($row === [null] || $row === ['']) { continue; }
    $back[] = array_combine($head, $row);
}
fclose($fh);
eq('header is the union of keys', $head, ['name', 'description', 'errors']);
eq('all rows survive a round trip', count($back), 3);
eq('comma and embedded quotes preserved', $back[0]['description'], 'Level 2, Room 3 "Boardroom"');
eq('embedded newline preserved', $back[1]['description'], "Line1\nLine2");
eq('null renders as empty, not the word null', $back[2]['description'], '');
eq('numeric column intact', $back[2]['errors'], '12');

section('unit: device-name prefixes');
eq('SEP is a hard phone',        cps_device_prefix('SEP001122334455'), 'SEP');
eq('CSF is a Jabber desktop',    cps_device_prefix('CSFJOSEPH'), 'CSF');
eq('TCT is Jabber for iPhone',   cps_device_prefix('TCTJSMITH'), 'TCT');
eq('CIPC not shadowed by a shorter prefix', cps_device_prefix('CIPCJSMITH'), 'CIPC');
eq('ATA analog adapter',         cps_device_prefix('ATA001122335501'), 'ATA');
eq('unknown name buckets as other', cps_device_prefix('WEIRDNAME'), 'other');
// Case must not decide the answer: CUCM names are conventionally upper, but
// nothing enforces it.
eq('lowercase still matches',    cps_device_prefix('sep001122334455'), 'SEP');

$rows = [['name' => 'SEP001122334401'], ['name' => 'CSFJOSEPH'], ['name' => 'TCTJSMITH'], ['name' => 'SEP001122334402']];
$f = cps_filter_by_device_prefix($rows, ['SEP']);
eq('SEP filter keeps the handsets', count($f['kept']), 2);
eq('SEP filter drops the soft clients', count($f['excluded']), 2);
eq('exclusions are counted per prefix', $f['by_prefix'], ['CSF' => 1, 'TCT' => 1]);
eq('an empty prefix list keeps everything', count(cps_filter_by_device_prefix($rows, [])['kept']), 4);

section('unit: partition subdivision');
$sub = new ReflectionMethod(CpsRisportClient::class, 'subdivide');
$sub->setAccessible(true);
$kids = $sub->invoke(null, 'SEP0*');
eq('one child per 0-9A-Z', count($kids), 36);
eq('first child extends the stem', $kids[0], 'SEP00*');
eq('last child is Z, not F - names are not always hex', $kids[35], 'SEP0Z*');
eq('a soft-client prefix subdivides too', $sub->invoke(null, 'CSF*')[0], 'CSF0*');
ok('every child keeps the parent stem',
   count(array_filter($kids, fn($k) => str_starts_with($k, 'SEP0'))) === 36, implode(',', array_slice($kids, 0, 3)));

section('unit: config values that look boolean');
// parse_ini_file's TYPED and NORMAL scanners turn "off"/"no"/"false"/"none"
// into false or "". That silently emptied ris_partitions and made discovery
// return nothing, so these guard the RAW scanner staying in place.
$tmpConf = sys_get_temp_dir() . '/cps-bool-test.conf';
file_put_contents($tmpConf, "ris_partitions = off\nadapters = network\nverify_tls = off\nphone_url_base = https://{ip}\ndb_name = none\n");
$bc = cps_load_config($tmpConf);
eq('"off" survives as the string "off"', $bc['ris_partitions'], 'off');
eq('"none" is not turned into null', $bc['db_name'], 'none');
eq('verify_tls = off reads as false', cps_cfg_bool($bc, 'verify_tls'), false);
file_put_contents($tmpConf, "verify_tls = true\ndb_enabled = 0\n");
$bc = cps_load_config($tmpConf);
eq('verify_tls = true reads as true', cps_cfg_bool($bc, 'verify_tls'), true);
eq('db_enabled = 0 reads as false', cps_cfg_bool($bc, 'db_enabled', true), false);
file_put_contents($tmpConf, "phone_url_base = \"https://{ip}:8443\"\n");
$bc = cps_load_config($tmpConf);
eq('quoted values have their quotes stripped', $bc['phone_url_base'], 'https://{ip}:8443');
@unlink($tmpConf);

section('unit: CIDR matching');
$cidrCases = [
    ['10.10.10.5',  '10.10.10.0/24', true],
    ['10.10.11.5',  '10.10.10.0/24', false],
    ['10.10.10.5',  '10.10.10.5/32', true],
    ['10.10.10.6',  '10.10.10.5/32', false],
    ['10.10.10.5',  '10.10.10.5',    true],   // bare address == /32
    ['192.168.1.1', '0.0.0.0/0',     true],   // /0 matches everything
    ['10.10.10.6',  '10.10.10.4/30', true],   // .4-.7
    ['10.10.10.8',  '10.10.10.4/30', false],
    ['10.1.2.3',    '10.0.0.0/8',    true],
    ['11.1.2.3',    '10.0.0.0/8',    false],
    ['10.10.10.130','10.10.10.128/25', true], // non-byte-boundary prefix
    ['10.10.10.127','10.10.10.128/25', false],
];
foreach ($cidrCases as [$ip, $cidr, $want]) {
    eq("$ip in $cidr", cps_ip_in_cidr($ip, $cidr), $want);
}
// Family mismatch is false, not a crash or a wrong true.
eq('IPv4 against an IPv6 prefix', cps_ip_in_cidr('10.0.0.1', '2001:db8::/32'), false);
eq('IPv6 against an IPv4 prefix', cps_ip_in_cidr('2001:db8::1', '10.0.0.0/8'), false);
eq('garbage address', cps_ip_in_cidr('not-an-ip', '10.0.0.0/8'), false);
eq('empty address', cps_ip_in_cidr('', '10.0.0.0/8'), false);

$f = cps_filter_by_cidr(
    [['ip' => '10.0.0.1'], ['ip' => '10.1.0.1'], ['ip' => ''], ['ip' => '10.0.0.9']],
    ['10.0.0.0/24']
);
eq('filter keeps non-matching and IP-less rows', count($f['kept']), 2);
eq('filter excludes matching rows', count($f['excluded']), 2);
eq('filter counts per prefix', $f['by_cidr']['10.0.0.0/24'], 2);
eq('excluded rows record which prefix hit', $f['excluded'][0]['excluded_by'], '10.0.0.0/24');

section('unit: phone URL construction');
ok('IPv4 unbracketed',
   str_contains(cps_phone_url('10.0.0.5', 'a'), 'https://10.0.0.5/CGI/'), cps_phone_url('10.0.0.5','a'));
ok('IPv6 literal bracketed',
   str_contains(cps_phone_url('2001:db8::1', 'a'), 'https://[2001:db8::1]/CGI/'), cps_phone_url('2001:db8::1','a'));
ok('host:port is NOT mistaken for IPv6',
   str_contains(cps_phone_url('10.0.0.5:8443', 'a'), 'https://10.0.0.5:8443/CGI/'), cps_phone_url('10.0.0.5:8443','a'));
ok('already-bracketed IPv6 not double-bracketed',
   !str_contains(cps_phone_url('[2001:db8::1]', 'a'), '[['), cps_phone_url('[2001:db8::1]','a'));

section('unit: scheme confinement');
$https = cps_curl_scheme_limits('https://{ip}');
eq('https base never permits a redirect to http',
   $https[CURLOPT_REDIR_PROTOCOLS], CURLPROTO_HTTPS);
$http = cps_curl_scheme_limits('http://{ip}');
eq('http base may still upgrade to https',
   $http[CURLOPT_REDIR_PROTOCOLS], CURLPROTO_HTTP | CURLPROTO_HTTPS);
eq('non-default https base is still pinned',
   cps_curl_scheme_limits('https://{ip}:8443/x')[CURLOPT_REDIR_PROTOCOLS], CURLPROTO_HTTPS);

eq('MAC from device name', cps_mac_from_device_name('SEP001122334455'), '001122334455');
eq('non-device name gives null', cps_mac_from_device_name('not-a-phone'), null);
eq('tri-bool Yes', cps_tri_bool('Yes'), 1);
eq('tri-bool No',  cps_tri_bool('No'), 0);
eq('tri-bool unknown stays null', cps_tri_bool('maybe'), null);

if (in_array('--unit', $argv, true)) {
    printf("\n%d checks, %d failures\n", $RUN, $FAILS);
    exit($FAILS ? 1 : 0);
}

// ===========================================================================
// Integration: mock CUCM over real HTTP, real MySQL.
// ===========================================================================
$port   = (int)(getenv('CPS_TEST_PORT') ?: 8099);
$dbHost = getenv('CPS_TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int)(getenv('CPS_TEST_DB_PORT') ?: 3306);
$dbUser = getenv('CPS_TEST_DB_USER') ?: 'root';
$dbPass = getenv('CPS_TEST_DB_PASS') ?: '';
$dbName = getenv('CPS_TEST_DB_NAME') ?: 'cucm_phone_stats_test';

$root = dirname(__DIR__);
@unlink(__DIR__ . '/.mock-generation');

section("integration: starting mock CUCM on 127.0.0.1:$port");
$desc = [1 => ['file', '/tmp/cps-mock.log', 'a'], 2 => ['file', '/tmp/cps-mock.log', 'a']];
// PHP_CLI_SERVER_WORKERS: without it the built-in server handles one
// request at a time and every concurrency assertion below would pass or
// fail for reasons having nothing to do with the client.
$srv  = proc_open(
    sprintf('exec env PHP_CLI_SERVER_WORKERS=12 php -S 127.0.0.1:%d %s', $port, escapeshellarg(__DIR__ . '/mock-server.php')),
    $desc, $pipes
);
if (!is_resource($srv)) { fwrite(STDERR, "cannot start mock server\n"); exit(1); }
register_shutdown_function(function () use ($srv) {
    $s = proc_get_status($srv);
    // proc_terminate() already defaults to SIGTERM. Naming the constant
    // requires ext-pcntl, which the official PHP images do not ship - the
    // tests would pass and then fatal in shutdown, turning a green run into
    // a non-zero exit code.
    if ($s['running']) { proc_terminate($srv); }
});
for ($i = 0; $i < 50; $i++) {
    $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($f) { fclose($f); break; }
    usleep(100000);
}
ok('mock server accepting connections', (bool)@fsockopen('127.0.0.1', $port, $e1, $e2, 0.5));

// Test config: CUCM and phones both point at the mock.
$conf = "$root/tests/.cucm-test.conf";
file_put_contents($conf, <<<INI
cucm_servers   = http://127.0.0.1:$port
axl_user       = axluser
axl_pass       = axlpass
verify_tls     = 0
ris_gap_ms     = 0
phone_url_base = http://127.0.0.1:$port/phone/{ip}
phone_parallel = 8
phone_timeout  = 5
adapters       = network,access,device
db_host        = $dbHost
db_port        = $dbPort
db_name        = $dbName
db_user        = $dbUser
db_pass        = $dbPass
INI);

function run(string $cmd, array $env = []): array {
    $full = '';
    foreach ($env as $k => $v) { $full .= escapeshellarg("$k=$v") . ' '; }
    $full = ($full ? 'env ' . $full : '') . $cmd . ' 2>/tmp/cps-test-stderr';
    exec($full, $out, $rc);
    return ['out' => implode("\n", $out), 'rc' => $rc, 'err' => (string)@file_get_contents('/tmp/cps-test-stderr')];
}

$php  = PHP_BINARY;
$C    = escapeshellarg($conf);

// ---------------------------------------------------------------- discovery
section('integration: discovery via RisPort70');
$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$phones = json_decode($r['out'], true);
ok('cucm-phones.php exits 0', $r['rc'] === 0, $r['err']);
ok('returns an array', is_array($phones), substr($r['out'], 0, 200));
$names = array_column($phones ?: [], 'name');
eq('7 registered phones found (StateInfo paging worked)', count($names), 7);
ok('SEP001122334401 present', in_array('SEP001122334401', $names, true));
// Negative control: the UnRegistered handset must not be here. If it is,
// the Status filter is being ignored and every later count is wrong.
ok('UnRegistered phone excluded', !in_array('SEP001122334407', $names, true), implode(',', $names));
$byName = array_column($phones ?: [], null, 'name');
eq('IP address extracted', $byName['SEP001122334402']['ip'] ?? null, '10.10.10.2');
eq('HttpSupported carried', $byName['SEP001122334406']['http_supported'] ?? null, 'No');

section('integration: AXL fallback source');
$r = run("$php $root/bin/cucm-phones.php --config $C --source axl-sql --json");
$axlPhones = json_decode($r['out'], true);
ok('axl-sql source works', is_array($axlPhones) && count($axlPhones) > 0, substr($r['out'], 0, 200));
eq('axl-sql rows are labelled LastKnown, not Registered',
   $axlPhones[0]['status'] ?? null, 'LastKnown');

// ------------------------------------------------------------------ database
section('integration: schema');
try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "\nMySQL unavailable ($dbHost:$dbPort): " . $e->getMessage()
                 . "\nRun with --unit to skip the integration half.\n");
    exit(1);
}
$r = run("$php $root/bin/cucm-initdb.php --config $C");
ok('cucm-initdb.php exits 0', $r['rc'] === 0, $r['err']);
ok('views created', str_contains($r['out'], 'v_port_speed_mismatch'), $r['out']);

// -------------------------------------------------------------------- sweep
section('integration: sweep #1');
$r = run("$php $root/bin/cucm-sweep.php --config $C --json");
$s1 = json_decode($r['out'], true);
ok('sweep exits 0', $r['rc'] === 0, $r['err']);
eq('7 discovered', $s1['discovered'] ?? null, 7);
eq('7 polled',     $s1['polled'] ?? null, 7);
// 405 (401s) and 406 (404s) must fail; the other five must succeed.
eq('5 ok',     $s1['ok'] ?? null, 5);
eq('2 failed', $s1['failed'] ?? null, 2);

$db = new CpsDb(['db_host' => $dbHost, 'db_port' => $dbPort, 'db_name' => $dbName,
                 'db_user' => $dbUser, 'db_pass' => $dbPass]);
eq('phones stored',   (int)$db->query('SELECT COUNT(*) c FROM phones')[0]['c'], 7);
eq('registrations stored', (int)$db->query('SELECT COUNT(*) c FROM registrations')[0]['c'], 7);
ok('port_stats stored', (int)$db->query('SELECT COUNT(*) c FROM port_stats')[0]['c'] >= 10);

// The failures must be recorded, with the reason kept distinct - an auth
// problem and a switched-off web server need different remedies.
$kinds = $db->query("SELECT DISTINCT error_kind FROM page_fetches WHERE ok = 0 ORDER BY error_kind");
$kindList = array_column($kinds, 'error_kind');
ok('401 recorded as auth', in_array('auth', $kindList, true), implode(',', $kindList));
ok('404 recorded as http', in_array('http', $kindList, true), implode(',', $kindList));

// The raw pairs must survive, including the label the normaliser did not know.
$raw = $db->query("SELECT pairs FROM page_fetches pf JOIN phones p ON p.id = pf.phone_id
                   WHERE p.device_name = 'SEP001122334404' AND pf.adapter LIKE '%port.network'");
ok('unmapped label preserved in raw JSON',
   str_contains($raw[0]['pairs'] ?? '', 'tokenDrop'), substr($raw[0]['pairs'] ?? '', 0, 200));
eq('unmapped_labels counted',
   (int)$db->query("SELECT unmapped_labels u FROM port_stats ps JOIN phones p ON p.id = ps.phone_id
                    WHERE p.device_name = 'SEP001122334404' AND ps.port = 'network'")[0]['u'], 1);

// ------------------------------------------------------------------ reports
section('report: speed / duplex mismatch');
$r = run("$php $root/bin/cucm-report.php speed-mismatch --config $C --json");
$mm = json_decode($r['out'], true);
$mmNames = array_column($mm ?: [], 'device_name');
ok('402 flagged (1000 vs 100)', in_array('SEP001122334402', $mmNames, true), implode(',', $mmNames));
ok('403 flagged (full vs half)', in_array('SEP001122334403', $mmNames, true), implode(',', $mmNames));
// Negative controls: a matched pair and a down access port are not mismatches.
ok('401 not flagged (both 100/full)', !in_array('SEP001122334401', $mmNames, true), implode(',', $mmNames));
ok('408 not flagged (access port down)', !in_array('SEP001122334408', $mmNames, true), implode(',', $mmNames));
$byMm = array_column($mm ?: [], null, 'device_name');
eq('402 mismatch kind is speed', $byMm['SEP001122334402']['mismatch_kind'] ?? null, 'speed');
eq('403 mismatch kind is duplex', $byMm['SEP001122334403']['mismatch_kind'] ?? null, 'duplex');
eq('402 network speed', (int)($byMm['SEP001122334402']['network_speed_mbps'] ?? 0), 1000);
eq('402 access speed',  (int)($byMm['SEP001122334402']['access_speed_mbps'] ?? 0), 100);

section('report: CSV output');
foreach (['speed-mismatch', 'errors', 'error-trend', 'phones', 'sweeps'] as $rep) {
    $r = run("$php $root/bin/cucm-report.php $rep --config $C --csv");
    $lines = array_values(array_filter(explode("\n", trim($r['out'])), fn($l) => $l !== ''));
    ok("$rep --csv exits 0 and emits a header", $r['rc'] === 0 && count($lines) >= 1,
       "rc={$r['rc']} out=" . substr($r['out'], 0, 120));
    ok("$rep --csv header has no stray table formatting",
       !str_contains($lines[0] ?? '', '  ') && str_contains($lines[0] ?? '', ','),
       $lines[0] ?? '(empty)');
}
// The count footer must not land in the CSV - it goes to stderr.
$r = run("$php $root/bin/cucm-report.php errors --config $C --csv");
ok('row-count footer stays out of the CSV body', !str_contains($r['out'], 'row(s)'), substr($r['out'], -120));

// A report with no findings must still be a valid CSV. error-trend has
// nothing to say until a second sweep exists, which makes it the natural
// case to check.
$r = run("$php $root/bin/cucm-report.php error-trend --config $C --csv");
$tl = array_values(array_filter(explode("\n", trim($r['out'])), fn($l) => $l !== ''));
eq('an empty report is a header and no data rows', count($tl), 1);
ok('that header names the real columns',
   str_contains($tl[0] ?? '', 'device_name') && str_contains($tl[0] ?? '', 'errors_delta'), $tl[0] ?? '(empty)');
// And it must parse as CSV, not merely be non-empty.
$fh = fopen('php://memory', 'r+'); fwrite($fh, $r['out']); rewind($fh);
$hdr = fgetcsv($fh, 0, ',', '"', '\\'); fclose($fh);
ok('empty-report header parses as CSV', is_array($hdr) && count($hdr) > 3, json_encode($hdr));

section('report: non-zero errors');
$r = run("$php $root/bin/cucm-report.php errors --config $C --json");
$er = json_decode($r['out'], true);
$erNames = array_unique(array_column($er ?: [], 'device_name'));
ok('404 listed', in_array('SEP001122334404', $erNames, true), implode(',', $erNames));
ok('401 not listed (clean phone)', !in_array('SEP001122334401', $erNames, true), implode(',', $erNames));
$e404 = null;
foreach ($er ?: [] as $row) { if ($row['device_name'] === 'SEP001122334404' && $row['port'] === 'network') { $e404 = $row; } }
eq('404 network errors_total', (int)($e404['errors_total'] ?? 0), 54);  // 42 crc + 5 align + 7 late

// -------------------------------------------------------------------- trend
section('report: error trend across sweeps');
file_get_contents("http://127.0.0.1:$port/_gen/2");   // counters grow
$r = run("$php $root/bin/cucm-sweep.php --config $C --json");
ok('sweep #2 exits 0', $r['rc'] === 0, $r['err']);

$r  = run("$php $root/bin/cucm-report.php error-trend --config $C --json");
$tr = json_decode($r['out'], true);
$trNames = array_column($tr ?: [], 'device_name');
ok('404 shows a growing error count', in_array('SEP001122334404', $trNames, true), implode(',', $trNames));
$t404 = null;
foreach ($tr ?: [] as $row) { if ($row['device_name'] === 'SEP001122334404' && $row['port'] === 'network') { $t404 = $row; } }
eq('delta is the increase', (int)($t404['errors_delta'] ?? 0), 24);   // 17 crc + 3 align + 4 late
ok('clean phone absent from the trend', !in_array('SEP001122334401', $trNames, true), implode(',', $trNames));

section('report: a reboot is a reset, not an improvement');
file_get_contents("http://127.0.0.1:$port/_gen/3");   // counters clear
$r = run("$php $root/bin/cucm-sweep.php --config $C --json");
ok('sweep #3 exits 0', $r['rc'] === 0, $r['err']);
$reset = $db->query("SELECT counter_reset, errors_delta FROM v_port_error_deltas d
                     WHERE d.device_name = 'SEP001122334404' AND d.port = 'network'
                     ORDER BY started_at DESC, sweep_id DESC LIMIT 1");
eq('counter_reset flagged', (int)($reset[0]['counter_reset'] ?? -1), 1);
ok('no negative delta reported', $reset[0]['errors_delta'] === null, json_encode($reset[0] ?? null));
$r  = run("$php $root/bin/cucm-report.php error-trend --config $C --json");
$tr = json_decode($r['out'], true);
$stillNeg = false;
foreach ($tr ?: [] as $row) { if ((int)$row['errors_delta'] < 0) { $stillNeg = true; } }
ok('trend report contains no negative deltas', !$stillNeg);

// ------------------------------------------- large estate / RisPort cap ---
section('integration: RisPort response cap and the partitioning fallback');
file_get_contents("http://127.0.0.1:$port/_big/300");   // 300 phones, cap 120

// The harder variant, and the one seen in the field: the cluster caps the
// response AND reports TotalDevicesFound as the capped number, so the answer
// is internally consistent and comparing the two proves nothing. Detection
// has to fall back on the result sitting at the cap.
file_get_contents("http://127.0.0.1:$port/_bigcap/1");
$confCap = "$root/tests/.cucm-test-cap.conf";
// ris_max_devices matching the server's cap mirrors production, where we ask
// for 1000 and the cluster returns exactly 1000.
file_put_contents($confCap, file_get_contents($conf) . "\nris_max_devices = 120\n");

$r = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confCap) . " --json");
eq('a self-consistent capped answer is still detected and recovered',
   count(json_decode($r['out'], true) ?: []), 300);
ok('the at-cap reason is reported', str_contains($r['err'], 'at or near the'), substr($r['err'], 0, 200));

// Negative control: with partitioning off, the same config must come back
// short. If it does not, the mock has stopped modelling the fault.
file_put_contents($confCap, file_get_contents($conf) . "\nris_max_devices = 120\nris_partitions = off\n");
$r = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confCap) . " --json");
eq('with partitioning off the cap still truncates', count(json_decode($r['out'], true) ?: []), 120);
@unlink($confCap);
file_get_contents("http://127.0.0.1:$port/_bigcap/0");

// Reproduce the fault first. If this ever stops returning a short count the
// mock has stopped modelling the bug, and the next assertion proves nothing.
$confOff = "$root/tests/.cucm-test-off.conf";
file_put_contents($confOff, file_get_contents($conf) . "\nris_partitions = off\n");
$r = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confOff) . " --json");
$off = json_decode($r['out'], true);
eq('unpartitioned query is truncated by the cap', count($off ?: []), 120);

// And now the fix.
$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$auto = json_decode($r['out'], true);
eq('auto partitioning recovers the full estate', count($auto ?: []), 300);
// Assert on the facts in the message, not its exact wording - the phrasing
// has been reworded once already and a brittle match just fails later for
// no reason.
ok('truncation is reported, not silent',
   str_contains($r['err'], 're-running split by name prefix')
   && str_contains($r['err'], '300'), substr($r['err'], 0, 200));

// Unique device names: a merge bug would inflate or collapse the count.
$names = array_column($auto ?: [], 'name');
eq('no duplicates across partitions', count(array_unique($names)), count($names));

// A sweep over the recovered estate must poll all of them.
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --sample 25 --json");
$bs = json_decode($r['out'], true);
eq('sweep sees the full estate after partitioning', $bs['discovered'] ?? null, 300);
eq('sample still honoured on a large estate', $bs['polled'] ?? null, 25);

@unlink($confOff);
file_get_contents("http://127.0.0.1:$port/_big/0");     // back to the small estate

// ------------------------------------------------- badly behaved phones ---
section('integration: a bad handset cannot abort the sweep');
file_get_contents("http://127.0.0.1:$port/_edge/1");   // 200+empty, 200+whitespace, 200+no table

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --json");
$eg = json_decode($r['out'], true);
ok('the sweep completes instead of dying', is_array($eg) && isset($eg['polled']),
   substr($r['out'] . ' ' . $r['err'], 0, 300));
eq('every phone is still accounted for', $eg['polled'] ?? null, 10);
eq('the good phones still succeed', $eg['ok'] ?? null, 5);
ok('no ValueError escaped', !str_contains($r['err'], 'ValueError'), substr($r['err'], 0, 200));

// The distinct causes must stay distinct: an empty body is the phone's
// fault, unparseable markup is arguably ours, and they lead different places.
$errs = [];
foreach ($eg['phones'] ?? [] as $ph) {
    foreach ($ph['errors'] ?? [] as $msg) { $errs[$ph['name']] = $msg; }
}
ok('empty body reported as such',
   str_contains($errs['SEP00112233EE01'] ?? '', 'empty body'), $errs['SEP00112233EE01'] ?? '(none)');
ok('whitespace-only body reported as empty too',
   str_contains($errs['SEP00112233EE02'] ?? '', 'empty body'), $errs['SEP00112233EE02'] ?? '(none)');
ok('markup with no table reported as a parse failure, not as empty',
   str_contains($errs['SEP00112233EE03'] ?? '', 'no label/value rows'), $errs['SEP00112233EE03'] ?? '(none)');

// And the classification must survive into the database.
$r = run("$php $root/bin/cucm-sweep.php --config $C --json");
$kinds = array_column($db->query(
    "SELECT DISTINCT error_kind FROM page_fetches WHERE ok = 0 AND error_kind IS NOT NULL"), 'error_kind');
ok("'empty' is a recorded error_kind", in_array('empty', $kinds, true), implode(',', $kinds));

file_get_contents("http://127.0.0.1:$port/_edge/0");

// -------------------------------------------------------- concurrency -----
section('integration: phones are polled concurrently, not in batches');
file_get_contents("http://127.0.0.1:$port/_big/24");
file_get_contents("http://127.0.0.1:$port/_slow/250");   // 250ms per page
file_get_contents("http://127.0.0.1:$port/_conc/reset");

$confPar = "$root/tests/.cucm-test-par.conf";
file_put_contents($confPar, file_get_contents($conf) . "\nadapters = network\n");

$t = microtime(true);
$r = run("$php $root/bin/cucm-sweep.php --config " . escapeshellarg($confPar) . " --no-db --parallel 8 --json");
$elapsed = microtime(true) - $t;
$pr = json_decode($r['out'], true);
eq('all 24 phones polled', $pr['polled'] ?? null, 24);

$conc = json_decode((string)file_get_contents("http://127.0.0.1:$port/_conc"), true);
$max  = (int)($conc['max'] ?? 0);
// The server itself observed the overlap, so this is not an inference from
// elapsed time. 8 requested; anything above 3 proves a rolling window
// rather than one-at-a-time, with slack for worker scheduling.
ok("server saw concurrent requests (max in flight = $max, asked for 8)", $max >= 4, json_encode($conc));

// And a loose upper bound on time: 24 pages at 250ms serially is 6s. Under
// half of that cannot happen without real concurrency. Deliberately not
// tight, so it does not go flaky on a loaded machine.
ok(sprintf('24 x 250ms pages finished in %.1fs, well under the %.1fs serial cost', $elapsed, 6.0),
   $elapsed < 3.0, sprintf('%.2fs', $elapsed));

// A slow phone must not hold up phones that already answered: with the old
// chunked implementation the window drained to zero between batches.
file_get_contents("http://127.0.0.1:$port/_conc/reset");
$r = run("$php $root/bin/cucm-sweep.php --config " . escapeshellarg($confPar) . " --no-db --parallel 4 --json");
$conc2 = json_decode((string)file_get_contents("http://127.0.0.1:$port/_conc"), true);
ok('window is respected, not exceeded (max ' . ($conc2['max'] ?? '?') . ' for --parallel 4)',
   (int)($conc2['max'] ?? 99) <= 6, json_encode($conc2));

@unlink($confPar);
file_get_contents("http://127.0.0.1:$port/_slow/0");
file_get_contents("http://127.0.0.1:$port/_big/0");

// ------------------------------------------------- device-prefix filter ---
section('integration: soft clients are not polled by default');
file_get_contents("http://127.0.0.1:$port/_soft/1");   // + CSF, TCT, BOT, ATA

$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$def = array_column(json_decode($r['out'], true) ?: [], 'name');
eq('default lists only SEP handsets', count($def), 7);
ok('no Jabber desktop in the default list', !in_array('CSFJOSEPH', $def, true), implode(',', $def));
ok('no analog adapter in the default list', !in_array('ATA001122335501', $def, true), implode(',', $def));

$r = run("$php $root/bin/cucm-phones.php --config $C --all-devices --json");
$all = array_column(json_decode($r['out'], true) ?: [], 'name');
eq('--all-devices shows every registered device', count($all), 11);
ok('soft clients are visible when asked for', in_array('CSFJOSEPH', $all, true), implode(',', $all));

// The skip must be reported, not silent - this is the answer to "CUCM says
// 3000 and I only see 2000".
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --json");
ok('the skip is reported with a per-prefix breakdown',
   str_contains($r['err'], 'skipped as not SEP') && str_contains($r['err'], 'CSF=1'),
   substr($r['err'], 0, 300));
eq('sweep polls only the handsets', (json_decode($r['out'], true)['polled'] ?? null), 7);

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --all-devices --json");
eq('--all-devices sweeps everything', (json_decode($r['out'], true)['polled'] ?? null), 11);

// An explicit list must be honoured over the default.
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --device-prefixes SEP,CSF --json");
eq('explicit prefix list is honoured', (json_decode($r['out'], true)['polled'] ?? null), 8);

// The reported trap, pinned: --match is a substring search, so "SEP" also
// matches CSFJOSEPH (JO-SEP-H). This asserts the behaviour deliberately so
// nobody "fixes" it into a silent anchored match, and proves the two safe
// routes out of it.
section('integration: --match SEP is a substring, and catches CSFJOSEPH');
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --all-devices --match SEP --json");
$m = array_column(json_decode($r['out'], true)['phones'] ?? [], 'name');
ok('--match SEP does catch CSFJOSEPH (substring, as designed)',
   in_array('CSFJOSEPH', $m, true), implode(',', $m));

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --all-devices --match name:SEP0 --json");
$m2 = array_column(json_decode($r['out'], true)['phones'] ?? [], 'name');
ok('field-scoped --match name:SEP0 excludes it', !in_array('CSFJOSEPH', $m2, true), implode(',', $m2));
ok('field-scoped --match still returns the handsets', count($m2) === 7, implode(',', $m2));

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --match SEP --json");
$m3 = array_column(json_decode($r['out'], true)['phones'] ?? [], 'name');
ok('the device-prefix default also excludes it', !in_array('CSFJOSEPH', $m3, true), implode(',', $m3));

file_get_contents("http://127.0.0.1:$port/_soft/0");

// -------------------------------------------------------- subnet excludes --
section('integration: --exclude-subnet');
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --exclude-subnet 10.10.10.8/32 --json");
eq('/32 excludes exactly one phone', (json_decode($r['out'], true)['polled'] ?? null), 6);

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --exclude-subnet 10.10.10.4/30 --json");
$ex = json_decode($r['out'], true);
eq('/30 excludes .4 .5 .6', $ex['polled'] ?? null, 4);
$left = array_column($ex['phones'] ?? [], 'ip');
ok('excluded addresses really are gone',
   !in_array('10.10.10.4', $left, true) && !in_array('10.10.10.5', $left, true)
   && !in_array('10.10.10.6', $left, true), implode(',', $left));
ok('addresses outside the prefix survive', in_array('10.10.10.8', $left, true), implode(',', $left));

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --exclude-subnet '10.10.10.0/24,10.99.0.0/16' --json");
ok('multiple prefixes: everything excluded leaves nothing to poll', $r['rc'] !== 0, "rc={$r['rc']}");

// The listing must agree with the sweep, or a CSV carried between them
// silently puts the excluded phones back.
$r = run("$php $root/bin/cucm-phones.php --config $C --exclude-subnet 10.10.10.4/30 --json");
eq('cucm-phones.php applies the same exclusions', count(json_decode($r['out'], true) ?: []), 4);

// Negative controls: a bad prefix must stop the run, not be ignored.
foreach (['10.10.10.0/33', 'not-a-subnet', '10.10.10.0/x'] as $bad) {
    $r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --exclude-subnet " . escapeshellarg($bad) . " --json");
    ok("invalid prefix '$bad' is fatal, not ignored", $r['rc'] !== 0, "rc={$r['rc']} err={$r['err']}");
}

// ------------------------------------------------------- sampling knobs ---
section('integration: --sample / --limit / --match');
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --sample 3 --json");
$s3 = json_decode($r['out'], true);
eq('--sample 3 polls exactly 3', $s3['polled'] ?? null, 3);
eq('--sample still reports the full discovered count', $s3['discovered'] ?? null, 7);

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --limit 2 --json");
eq('--limit 2 polls exactly 2', (json_decode($r['out'], true)['polled'] ?? null), 2);

$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --match 334404 --json");
$sm = json_decode($r['out'], true);
eq('--match narrows to the one phone', $sm['polled'] ?? null, 1);
eq('--match picked the right one', $sm['phones'][0]['name'] ?? null, 'SEP001122334404');

// Negative control: a sample larger than the population must not shrink it
// or duplicate rows - array_rand() with n == count() behaves differently.
$r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --sample 99 --json");
eq('--sample larger than the estate polls everything', (json_decode($r['out'], true)['polled'] ?? null), 7);

// Sampling must actually vary, or it is just --limit wearing a hat.
$seen = [];
for ($i = 0; $i < 6; $i++) {
    $r = run("$php $root/bin/cucm-sweep.php --config $C --no-db --sample 2 --json");
    $names = array_column(json_decode($r['out'], true)['phones'] ?? [], 'name');
    sort($names);
    $seen[implode(',', $names)] = true;
}
ok('--sample returns differing sets across runs', count($seen) > 1,
   'always picked: ' . implode(' | ', array_keys($seen)));

// --------------------------------------------------------------- phone list
section('integration: --phones-from round trip');
$csv = '/tmp/cps-phones.csv';
$r = run("$php $root/bin/cucm-phones.php --config $C --csv");
file_put_contents($csv, $r['out']);
$r = run("$php $root/bin/cucm-sweep.php --config $C --phones-from $csv --no-db --json");
$sf = json_decode($r['out'], true);
eq('phone list round-trips through CSV', $sf['polled'] ?? null, 7);

// ------------------------------------------------------------ single phone
section('integration: single-phone CLI');
// Pin the generation rather than inheriting whatever the trend tests left
// behind - a test whose expected value depends on the order tests ran in is
// a test that will lie later.
file_get_contents("http://127.0.0.1:$port/_gen/1");
$r = run("$php $root/bin/cucm-phone-netstats.php 10.10.10.4 --config $C --json");
$one = json_decode($r['out'], true);
ok('single-phone JSON has both adapters',
   isset($one['adapters']['network'], $one['adapters']['access']), substr($r['out'], 0, 200));
// generation 1: 42 crc + 5 align + 7 late collision
eq('network errors normalised', (int)($one['adapters']['network']['normalised']['errors_total'] ?? 0), 54);

$r = run("$php $root/bin/cucm-phone-netstats.php 10.10.10.5 --config $C --json");
eq('auth-required phone exits 2', $r['rc'], 2);

// ------------------------------------------------------------------- probe
section('integration: probe honours --schemes');
$r = run("$php $root/bin/cucm-probe.php --config $C --phone 10.10.10.4 --schemes https --out /tmp/cps-probe-https");
ok('no http phone probe when --schemes https', !str_contains($r['out'], 'phone:http:'),
   implode(' ', array_filter(explode("\n", $r['out']), fn($l) => str_contains($l, 'phone:'))));
$r = run("$php $root/bin/cucm-probe.php --config $C --phone 10.10.10.4 --schemes https,http --out /tmp/cps-probe-both");
ok('http phone probe present when asked for', str_contains($r['out'], 'phone:http:'));

section('integration: probe script');
$r = run("$php $root/bin/cucm-probe.php --config $C --phone 10.10.10.4 --out /tmp/cps-probe");
ok('probe runs', str_contains($r['out'], 'PASS'), substr($r['out'], -400));
ok('probe negotiated a schema version the mock supports',
   str_contains($r['out'], 'axl:auth+schema') && !str_contains($r['out'], 'axl:auth+schema  FAIL'));
// The mock rejects a wrong RegistrationDynamic column, so a probe of one
// must FAIL. If every probe passes regardless, the probe proves nothing.
$evidence = glob('/tmp/cps-probe/probe-*.json');
ok('probe wrote evidence', !empty($evidence));
if ($evidence) {
    $ev = json_decode(file_get_contents(end($evidence)), true);
    $ids = array_column($ev['probes'], 'status', 'id');
    ok('regdyn.lastknownipaddress probed individually', isset($ids['axl:sql:regdyn.lastknownipaddress']));
    eq('known column passes', $ids['axl:sql:regdyn.lastknownipaddress'] ?? null, 'PASS');
    eq('Informix FIRST passes', $ids['axl:sql:informix-first'] ?? null, 'PASS');
}

// Negative control on the probe itself: ask for a column the mock does not
// have, and confirm the machinery reports FAIL rather than PASS.
section('negative control: a wrong column must fail');
$axl = new CpsAxlClient(new CpsSoapClient("http://127.0.0.1:$port", 'axluser', 'axlpass', false, 10), '14.0');
$threw = false;
try { $axl->executeSqlQuery('SELECT FIRST 1 lastknownipv6address FROM registrationdynamic'); }
catch (Throwable $e) { $threw = true; }
ok('unknown column raises a fault', $threw);
$threw = false;
try { $axl->executeSqlQuery('SELECT name FROM device LIMIT 1'); }
catch (Throwable $e) { $threw = true; }
ok('MySQL LIMIT syntax is rejected (Informix dialect enforced)', $threw);

@unlink($conf);
printf("\n%d checks, %d failures\n", $RUN, $FAILS);
exit($FAILS ? 1 : 0);
