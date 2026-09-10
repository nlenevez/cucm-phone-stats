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

section('unit: CDP / LLDP neighbour extraction');
$n = cps_normalise_neighbour([
    'CDP Neighbor Device ID'  => 'sw-access-01',
    'CDP Neighbor Port'       => 'GigabitEthernet1/0/12',
    'CDP Neighbor IP Address' => '10.0.0.201',
]);
eq('CDP switch name',  $n['cdp_neighbour'], 'sw-access-01');
eq('CDP switch port',  $n['cdp_neighbour_port'], 'GigabitEthernet1/0/12');
eq('CDP switch IP',    $n['cdp_neighbour_ip'], '10.0.0.201');

// LLDP-MED is a distinct spelling that has to land in the same fields.
$n = cps_normalise_neighbour([
    'LLDP-MED Neighbor Device ID' => 'sw-edge-07',
    'LLDP-MED Neighbor Port'      => 'Gi1/0/33',
]);
eq('LLDP-MED name maps to lldp_neighbour', $n['lldp_neighbour'], 'sw-edge-07');
eq('LLDP-MED port maps to lldp_neighbour_port', $n['lldp_neighbour_port'], 'Gi1/0/33');

// A placeholder must not be stored as a switch name - phones report the
// field with filler when CDP is off upstream.
foreach (['not available', 'None', 'N/A', '', '-'] as $ph) {
    $n = cps_normalise_neighbour(['CDP Neighbor Device ID' => $ph]);
    ok("placeholder '$ph' is treated as absent", !isset($n['cdp_neighbour']), json_encode($n));
}

// A spelling the alias table has never seen must still be picked up.
$n = cps_normalise_neighbour(['CDP Nbr Device'  => 'sw-weird-01', 'CDP Nbr Port Id' => 'Fa0/3']);
eq('unknown CDP name spelling caught by fallback', $n['cdp_neighbour'] ?? null, 'sw-weird-01');
eq('unknown CDP port spelling caught by fallback', $n['cdp_neighbour_port'] ?? null, 'Fa0/3');
// ...and the fallback must not mistake a port field for a name.
ok('"port id" is not read as a device name',
   ($n['cdp_neighbour'] ?? '') !== 'Fa0/3', json_encode($n));

$sum = cps_neighbour_summary(cps_normalise_neighbour([
    'CDP Neighbor Device ID'  => 'sw-access-02',
    'CDP Neighbor Port'       => 'Gi1/0/7',
    'LLDP Neighbor Device ID' => '00:11:22:33:44:55',
    'LLDP Neighbor Port'      => '7',
]));
eq('CDP wins over LLDP for the switch name', $sum['switch_name'], 'sw-access-02');
eq('CDP wins over LLDP for the port', $sum['switch_port'], 'Gi1/0/7');
eq('and the source is recorded', $sum['source'], 'cdp');

$sum = cps_neighbour_summary(cps_normalise_neighbour(['LLDP Neighbor Device ID' => 'sw-lldp-only']));
eq('LLDP is used when CDP is absent', $sum['switch_name'], 'sw-lldp-only');
eq('source says lldp', $sum['source'], 'lldp');

$sum = cps_neighbour_summary([]);
eq('no neighbour info yields nulls', $sum['switch_name'], null);
eq('and no source', $sum['source'], null);

section('unit: exact partitions computed from device names');
// Blind prefix splitting has to DISCOVER where an estate divides, one
// expensive level at a time. Given the real names it can be computed.
$oui = [];
for ($i = 0; $i < 2300; $i++) { $oui[] = 'SEP44B6BE' . strtoupper(str_pad(dechex($i), 3, '0', STR_PAD_LEFT)); }
$p = CpsRisportClient::partitionsFromNames($oui, ['SEP'], 1000);
ok('an all-one-OUI estate splits straight at the serial digits',
   count($p) >= 3 && count($p) <= 20, count($p) . ' buckets: ' . implode(',', array_slice($p, 0, 4)));
ok('every bucket descends past the shared OUI',
   count(array_filter($p, fn($x) => str_starts_with($x, 'SEP44B6BE'))) === count($p), implode(',', $p));

// No bucket may exceed the cap, or the split has not done its job.
foreach ($p as $pat) {
    $stem = rtrim($pat, '*');
    $n = count(array_filter($oui, fn($x) => str_starts_with($x, $stem)));
    ok("bucket $pat holds $n names, under the cap", $n <= 1000, "$n");
}

// Names outside the dominant group must not be dragged deep with it.
$mixed = array_merge($oui, array_map(fn($i) => 'SEP0011223344' . dechex($i % 16), range(0, 39)));
$p2 = CpsRisportClient::partitionsFromNames($mixed, ['SEP'], 1000);
ok('a small second OUI gets one shallow bucket, not sixteen deep ones',
   in_array('SEP0*', $p2, true), implode(',', $p2));

// An estate that fits in one query must not be split at all.
eq('a small estate needs no splitting',
   CpsRisportClient::partitionsFromNames(['SEPAAAA00000001', 'SEPBBBB00000002'], ['SEP'], 1000), ['SEP*']);
eq('no names yields no buckets',
   CpsRisportClient::partitionsFromNames([], ['SEP'], 1000), []);

section('unit: throttle-fault recognition');
// A throttle fault is transient. Misclassifying one as a hard failure meant
// a partition was skipped and its devices vanished from the result, which
// is the same silent undercount the partitioning exists to prevent.
foreach ([
    'Exceeded allowed rate for Realtime information',
    'exceeded allowed rate',
    'Rate Control: too many requests',
    'Request throttled',
] as $msg) {
    ok("recognised: $msg", CpsRisportClient::isRateLimitFault(new RuntimeException($msg)), $msg);
}
foreach ([
    'Unknown schema version 15.0',
    'Column (foo) not found in any table in the query',
    'connection refused',
] as $msg) {
    ok("not a throttle: $msg", !CpsRisportClient::isRateLimitFault(new RuntimeException($msg)), $msg);
}

section('unit: partition subdivision');
$sub = new ReflectionMethod(CpsRisportClient::class, 'subdivide');
$sub->setAccessible(true);
// A MAC-shaped stem can only continue with a hex digit, so 36 children
// would be 20 queries guaranteed to return nothing - expensive against a
// rate-limited API every time a bucket is subdivided.
$kids = $sub->invoke(null, 'SEP0*');
eq('MAC-shaped stem subdivides into 16 hex children', count($kids), 16);
eq('first child extends the stem', $kids[0], 'SEP00*');
eq('last hex child is F', $kids[15], 'SEP0F*');
ok('every child keeps the parent stem',
   count(array_filter($kids, fn($k) => str_starts_with($k, 'SEP0'))) === 16, implode(',', array_slice($kids, 0, 3)));

// A soft-client prefix is followed by a username, so it needs the letters.
$csf = $sub->invoke(null, 'CSF*');
eq('username-shaped stem keeps the full alphabet', count($csf), 36);
eq('and reaches Z', $csf[35], 'CSFZ*');

// Only the prefixes actually being polled get enumerated.
$parts = CpsRisportClient::partitionsFor(['SEP']);
eq('SEP alone yields 16 buckets and nothing else', count($parts), 16);
ok('no CSF bucket when only SEP is polled',
   !in_array('CSF*', $parts, true), implode(',', $parts));
ok('polling everything falls back to the full default set',
   count(CpsRisportClient::partitionsFor([])) > 16);

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
ris_rate_per_min = 0
phone_url_base = http://127.0.0.1:$port/phone/{ip}
phone_parallel = 8
phone_timeout  = 5
adapters       = network,access,device,config
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

section('integration: an older cluster (AXL 11.5)');
// An estate can run more than one CUCM release. The client must negotiate
// DOWN to a schema the cluster admits rather than assume the newest, and
// this is a regression test for exactly that - a 11.5 cluster is live.
// The cluster refuses an unknown schema with HTTP 599 and a bare
// <axlError>, not a SOAP Fault. Treated as a transport failure that ends the
// request, negotiation stopped on its first candidate and an older cluster
// was never reached - so this asserts the walk survives a 599.
foreach (['11.5', '10.5'] as $ver) {
    file_get_contents("http://127.0.0.1:$port/_axlver/$ver");
    $r = run("$php $root/bin/cucm-phones.php --config $C --source axl-sql --verbose --json");
    ok("AXL $ver is negotiated",
       str_contains($r['err'], "negotiated: $ver"),
       implode(' | ', array_slice(array_filter(explode("\n", $r['err']), fn($l) => str_contains($l, 'schema')), 0, 2)));
    ok("and it returns rows on AXL $ver", count(json_decode($r['out'], true) ?: []) > 0, substr($r['out'], 0, 120));
}

// The probe must name what was rejected, which is how a version problem on
// one cluster of several gets identified at all.
file_get_contents("http://127.0.0.1:$port/_axlver/11.5");
$r = run("$php $root/bin/cucm-probe.php --config $C --server 127.0.0.1:$port --out /tmp/cps-probe-115");
ok('the probe reports the accepted schema', str_contains($r['out'], 'schema 11.5 accepted'), substr($r['out'], 0, 400));
ok('and lists the ones the cluster refused', str_contains($r['out'], 'rejected:'), substr($r['out'], 0, 400));
ok('--server narrows the probe to one cluster', str_contains($r['err'] . $r['out'], "probing: http://127.0.0.1:$port"),
   substr($r['err'], 0, 200));

// A 599 must be an application-level refusal, not a fatal transport error.
file_get_contents("http://127.0.0.1:$port/_axlver/11.5");
$r = run("$php $root/bin/cucm-phones.php --config $C --source axl-sql --json");
ok('a 599 on the first candidates does not abort the run', $r['rc'] === 0, substr($r['err'], 0, 200));
ok('and no uncaught error escapes', !str_contains($r['err'], 'Uncaught'), substr($r['err'], 0, 200));

// A cluster that accepts nothing we know must fail cleanly, not with a trace.
file_get_contents("http://127.0.0.1:$port/_axlver/9.1");
$r = run("$php $root/bin/cucm-phones.php --config $C --source axl-sql --json");
ok('an unusable cluster fails cleanly', $r['rc'] !== 0 && !str_contains($r['err'], 'Uncaught'),
   substr($r['err'], 0, 200));
ok('and says which versions it tried', str_contains($r['err'], 'no AXL schema version accepted'),
   substr($r['err'], 0, 200));

file_get_contents("http://127.0.0.1:$port/_axlver/14.0,12.5");

section('integration: model enums become readable names');
// RisPort reports a model as a numeric enum; the name lives in CUCM's
// typemodel table. A number is useless in a report, and a hard-coded list
// would be wrong for some release without saying so - so it is read from
// the cluster.
file_get_contents("http://127.0.0.1:$port/_modelenum/1");

$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$byName = array_column(json_decode($r['out'], true) ?: [], null, 'name');
eq('a numeric model enum is resolved', $byName['SEP001122334401']['model'] ?? null, 'Cisco 8841');
eq('and a different one too', $byName['SEP001122334403']['model'] ?? null, 'Cisco 7841');
ok('no bare enum survives',
   !array_filter($byName, fn($x) => ctype_digit((string)($x['model'] ?? ''))), 'an enum remained');

// If AXL cannot be reached the enums must SURVIVE, not be lost - a number is
// poor, but a blank model is worse.
file_get_contents("http://127.0.0.1:$port/_axlver/9.1");   // no schema we know
$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$fallback = array_column(json_decode($r['out'], true) ?: [], null, 'name');
ok('without AXL the enum is kept rather than dropped',
   ($fallback['SEP001122334401']['model'] ?? '') !== '', json_encode($fallback['SEP001122334401'] ?? null));
ok('and it says why the models stayed numeric',
   str_contains($r['err'], 'model names'), substr($r['err'], 0, 200));
file_get_contents("http://127.0.0.1:$port/_axlver/14.0,12.5");

// A cluster that already returns names must not be touched.
file_get_contents("http://127.0.0.1:$port/_modelenum/0");
$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$plain = array_column(json_decode($r['out'], true) ?: [], null, 'name');
eq('a name from the cluster is left alone', $plain['SEP001122334401']['model'] ?? null, 'Cisco 8841');

file_get_contents("http://127.0.0.1:$port/_modelenum/0");

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
section('integration: model names are mirrored and backfilled');
// Sweeping mirrors the table, so rows collected earlier can be fixed
// without waiting for those handsets to register again.
file_get_contents("http://127.0.0.1:$port/_modelenum/1");
run("$php $root/bin/cucm-sweep.php --config $C --json");
ok('the model table is mirrored locally',
   (int)$db->query('SELECT COUNT(*) c FROM device_models')[0]['c'] > 1, 'device_models empty');

$db->pdo()->exec("INSERT INTO phones (device_name, model, first_seen, last_seen)
                  VALUES ('SEPHISTORIC01', '684', NOW(), NOW())
                  ON DUPLICATE KEY UPDATE model = '684'");
eq('a historical row starts as an enum',
   $db->query("SELECT model FROM phones WHERE device_name='SEPHISTORIC01'")[0]['model'], '684');
$fixedRows = $db->backfillModelNames();
ok('the backfill resolves it', $fixedRows >= 1, "$fixedRows rows");
eq('and it is now readable',
   $db->query("SELECT model FROM phones WHERE device_name='SEPHISTORIC01'")[0]['model'], 'Cisco 8845');
$db->pdo()->exec("DELETE FROM phones WHERE device_name='SEPHISTORIC01'");
file_get_contents("http://127.0.0.1:$port/_modelenum/0");

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

section('report: switch name and port reach the CSVs');
$r = run("$php $root/bin/cucm-report.php uplinks --config $C --json");
$up = array_column(json_decode($r['out'], true) ?: [], null, 'device_name');
eq('CDP switch recorded', $up['SEP001122334401']['switch_name'] ?? null, 'sw-access-01');
eq('CDP port recorded',   $up['SEP001122334401']['switch_port'] ?? null, 'GigabitEthernet1/0/12');
eq('CDP preferred where both are present', $up['SEP001122334403']['switch_name'] ?? null, 'sw-access-02');
eq('LLDP used where CDP is a placeholder', $up['SEP001122334404']['switch_name'] ?? null, 'sw-edge-07.example.local');
eq('and its source is recorded as lldp',   $up['SEP001122334404']['neighbour_source'] ?? null, 'lldp');
ok('the CDP placeholder is not stored as a switch name',
   ($up['SEP001122334404']['cdp_neighbour'] ?? null) === null, json_encode($up['SEP001122334404'] ?? null));
ok('a phone reporting no neighbour has no uplink row',
   !isset($up['SEP001122334408']), implode(',', array_keys($up)));

// The point of the exercise: an errors CSV must name the switch port.
$r = run("$php $root/bin/cucm-report.php errors --config $C --csv");
$hdr = explode("\n", trim($r['out']))[0] ?? '';
ok('errors CSV header carries switch_name and switch_port',
   str_contains($hdr, 'switch_name') && str_contains($hdr, 'switch_port'), $hdr);
ok('errors CSV rows carry an actual switch', str_contains($r['out'], 'sw-edge-07'), substr($r['out'], 0, 300));

foreach (['speed-mismatch', 'error-trend'] as $rep) {
    $r = run("$php $root/bin/cucm-report.php $rep --config $C --csv");
    $h = explode("\n", trim($r['out']))[0] ?? '';
    ok("$rep CSV carries switch columns",
       str_contains($h, 'switch_name') && str_contains($h, 'switch_port'), $h);
}

section('report: labels tells us what the phones actually send');
$r = run("$php $root/bin/cucm-report.php labels --config $C --grep neighbor --json");
$lb = json_decode($r['out'], true) ?: [];
ok('labels finds the CDP fields', count($lb) > 0, substr($r['out'], 0, 200));
$unmapped = array_filter($lb, fn($x) => $x['mapped_to'] === '- NOT MAPPED -');
eq('every neighbour label the mock sends is mapped', count($unmapped), 0);
ok('labels reports which adapter carried them',
   str_contains($r['out'], 'device.statistics.configuration'), substr($r['out'], 0, 200));

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

section('report: error-trend gives one row per phone and port');
// A time series shows the same phone and port once per transition, which
// reads as duplicate rows with contradictory counts rather than as history.
// Several more growing sweeps, then the default must collapse them.
foreach ([4, 5, 6] as $g) {
    file_get_contents("http://127.0.0.1:$port/_gen/$g");
    run("$php $root/bin/cucm-sweep.php --config $C --json");
    sleep(1);   // DATETIME is second-resolution; keep the order unambiguous
}
$r  = run("$php $root/bin/cucm-report.php error-trend --config $C --json");
$tr = json_decode($r['out'], true) ?: [];
$pairs = array_map(fn($x) => $x['device_name'] . '|' . $x['port'], $tr);
eq('no phone and port appears twice', count($pairs), count(array_unique($pairs)));
ok('and there is still something to report', count($tr) > 0, $r['out']);

$one = null;
foreach ($tr as $x) { if ($x['device_name'] === 'SEP001122334404' && $x['port'] === 'network') { $one = $x; } }
ok('the row kept is the most recent growth', $one !== null, json_encode(array_slice($tr, 0, 2)));
ok('times_grown counts the sweeps it grew in', (int)($one['times_grown'] ?? 0) >= 3, json_encode($one));
ok('total_growth exceeds the last delta alone',
   (int)($one['total_growth'] ?? 0) > (int)($one['errors_delta'] ?? 0), json_encode($one));

// The full series is still available when it is actually wanted.
$r = run("$php $root/bin/cucm-report.php error-trend --config $C --history --json");
$hist = json_decode($r['out'], true) ?: [];
ok('--history returns the whole series', count($hist) > count($tr), count($hist) . ' vs ' . count($tr));

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
   str_contains($r['err'], '300') && str_contains($r['err'], 'RisPort returned'),
   substr($r['err'], 0, 200));
// Per-node is the cheap route past the cap and must be preferred: three
// node queries instead of sixteen name buckets against a rate-limited API.
ok('per-node enumeration is used in preference to name prefixes',
   str_contains($r['err'], 'per-node enumeration'), substr($r['err'], 0, 300));

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
eq('every phone is still accounted for', $eg['polled'] ?? null, 11);
eq('the good phones still succeed', $eg['ok'] ?? null, 6);
ok('no ValueError escaped', !str_contains($r['err'], 'ValueError'), substr($r['err'], 0, 200));

// A phone with 60 line appearances has a DirNumber far longer than the
// original column, which failed the INSERT and - because writes are one
// transaction per phone - cost that entire handset over one field.
$r2 = run("$php $root/bin/cucm-sweep.php --config $C --json");
ok('the long-DirNumber phone is stored, not lost',
   !str_contains($r2['err'], 'Data too long'), substr($r2['err'], 0, 300));
$long = $db->query("SELECT dir_number FROM phones WHERE device_name = 'SEP00112233EE04'");
ok('its dir_number is present', !empty($long[0]['dir_number']), json_encode($long));
ok('and holds the whole list, not a truncated stub',
   strlen((string)($long[0]['dir_number'] ?? '')) > 64, strlen((string)($long[0]['dir_number'] ?? '')) . ' chars');

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

// Stronger than "they are filtered out": the cluster must never be ASKED
// for them. Filtering client-side would look the same from here while still
// costing a query and, on a rate-limited API, real time.
file_get_contents("http://127.0.0.1:$port/_ris/reset");
$r = run("$php $root/bin/cucm-phones.php --config $C --json");
$seen = json_decode((string)file_get_contents("http://127.0.0.1:$port/_ris/seen"), true) ?: [];
ok('RisPort never returned a CSF device', !isset($seen['CSF']), json_encode($seen));
ok('nor TCT / BOT / TAB / ATA',
   !isset($seen['TCT']) && !isset($seen['BOT']) && !isset($seen['TAB']) && !isset($seen['ATA']),
   json_encode($seen));
ok('SEP handsets were returned', ($seen['SEP'] ?? 0) > 0, json_encode($seen));

// ...and --all-devices must widen the query again, or the flag is a lie.
file_get_contents("http://127.0.0.1:$port/_ris/reset");
$r = run("$php $root/bin/cucm-phones.php --config $C --all-devices --json");
$seen = json_decode((string)file_get_contents("http://127.0.0.1:$port/_ris/seen"), true) ?: [];
ok('--all-devices does ask for CSF', isset($seen['CSF']), json_encode($seen));

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
// Scoping the query leaves nothing to skip, so what must be reported is the
// scope itself - a quietly smaller number is exactly what makes an operator
// wonder whether devices went missing.
ok('the scope of the query is reported',
   str_contains($r['err'], 'querying only SEP*'), substr($r['err'], 0, 300));
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

// --------------------------------------- OUI-clustered estate (the hard one)
section('integration: every phone on one OUI, one node');
file_get_contents("http://127.0.0.1:$port/_big/300");
file_get_contents("http://127.0.0.1:$port/_oui/1");
$confOui = "$root/tests/.cucm-test-oui.conf";
file_put_contents($confOui, file_get_contents($conf) . "\nris_max_devices = 120\n");

// Prefix splitting is useless here: SEP4*, SEP44*, SEP44B*, SEP44B6*,
// SEP44B6B* and SEP44B6BE* are all over the cap in turn, because the OUI is
// shared. The trailing digits are the serial and split evenly at once.
$r = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confOui) . " --json");
eq('the whole estate is recovered', count(json_decode($r['out'], true) ?: []), 300);
ok('splitting on the last character is used', str_contains($r['err'], 'last character'), substr($r['err'], 0, 300));
eq('and no bucket needed subdividing', substr_count($r['err'], 'hit the'), 0);

// A cluster that will not match on the end of a name must still get there.
file_get_contents("http://127.0.0.1:$port/_nosuffix/1");
$r = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confOui) . " --json");
eq('prefix fallback still recovers the whole estate', count(json_decode($r['out'], true) ?: []), 300);
ok('and it is the prefix path, not the suffix one',
   !str_contains($r['err'], 'last character'), substr($r['err'], 0, 200));
// Without AXL there is nothing to compute from, so it must still get there
// the slow way rather than giving up.
// Disabling the name provider must NOT mean blanking axl_user: RisPort
// authenticates with the same credentials, so that would test a 401 rather
// than the blind-prefix path.
$confBlind = "$root/tests/.cucm-test-blind.conf";
file_put_contents($confBlind, file_get_contents($conf) . "\nris_max_devices = 120\nris_use_axl_names = 0\n");
$rb = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confBlind) . " --json");
eq('blind prefix splitting still recovers everything', count(json_decode($rb['out'], true) ?: []), 300);
ok('and it says it is doing so blindly', str_contains($rb['err'], 'BLIND'), substr($rb['err'], 0, 200));
ok('which needs several subdivisions to cross the OUI',
   substr_count($rb['err'], 'hit the') >= 4, substr_count($rb['err'], 'hit the') . ' subdivisions');
@unlink($confBlind);

// With AXL reachable, the buckets are computed rather than discovered, so
// even the worst-shaped estate resolves without walking the OUI.
$r = run("$php $root/bin/cucm-phones.php --config " . escapeshellarg($confOui) . " --json");
eq('AXL-derived buckets recover the whole estate', count(json_decode($r['out'], true) ?: []), 300);
ok('the buckets came from AXL', str_contains($r['err'], 'computed'), substr($r['err'], 0, 300));
eq('and nothing needed subdividing', substr_count($r['err'], 'hit the'), 0);

file_get_contents("http://127.0.0.1:$port/_nosuffix/0");
file_get_contents("http://127.0.0.1:$port/_oui/0");
file_get_contents("http://127.0.0.1:$port/_big/0");
@unlink($confOui);

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

// ------------------------------------------------------------- web report --
section('integration: the single-file web report');
$webPort = $port + 300;
$webSrv = proc_open(
    sprintf('exec env CPS_DB_HOST=%s CPS_DB_PORT=%d CPS_DB_NAME=%s CPS_DB_USER=%s CPS_DB_PASS=%s '
          . 'php -S 127.0.0.1:%d -t %s',
        escapeshellarg($dbHost), $dbPort, escapeshellarg($dbName),
        escapeshellarg($dbUser), escapeshellarg($dbPass), $webPort, escapeshellarg("$root/web")),
    [1 => ['file', '/tmp/cps-web.log', 'a'], 2 => ['file', '/tmp/cps-web.log', 'a']], $wpipes
);
register_shutdown_function(function () use ($webSrv) {
    if (is_resource($webSrv)) {
        $st = proc_get_status($webSrv);
        if ($st['running']) { proc_terminate($webSrv); }
    }
});
for ($i = 0; $i < 40; $i++) {
    $f = @fsockopen('127.0.0.1', $webPort, $e1, $e2, 0.2);
    if ($f) { fclose($f); break; }
    usleep(100000);
}

$fetch = function (string $qs) use ($webPort) {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body = @file_get_contents("http://127.0.0.1:$webPort/cucm-reports.php$qs", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $hline) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $hline, $m)) { $code = (int)$m[1]; }
    }
    return ['code' => $code, 'body' => (string)$body];
};

foreach (['speed-mismatch' => 'Speed mismatch',
          'errors'         => 'Errors',
          'error-trend'    => 'Increasing errors'] as $key => $title) {
    $res = $fetch("?r=$key");
    eq("web: $key returns 200", $res['code'], 200);
    ok("web: $key renders its title", str_contains($res['body'], $title), substr($res['body'], 0, 200));
    // Tab links are relative, so match on the query string itself rather
    // than assuming the script name appears in the href.
    foreach (['speed-mismatch', 'errors', 'error-trend'] as $tab) {
        ok("web: $key links to the $tab tab",
           str_contains($res['body'], 'r=' . $tab), 'tab link for ' . $tab);
    }
    ok("web: $key ships its own CSS and JS (no CDN)",
       !preg_match('#<(script|link)[^>]+(src|href)=["\']https?://#i', $res['body']),
       'external asset reference found');
}

// PHP errors must not leak into the page.
$res = $fetch('?r=errors');
ok('web: no PHP warnings or notices in the output',
   !preg_match('/(Warning|Notice|Fatal error|Deprecated):/', $res['body']), substr($res['body'], 0, 300));
ok('web: the errors table carries the switch columns',
   str_contains($res['body'], 'Switch port'), 'switch columns');

// An unknown report must fall back, not error.
$res = $fetch('?r=../../etc/passwd');
eq('web: an unknown report falls back to the default', $res['code'], 200);
ok('web: and does not echo the input', !str_contains($res['body'], 'etc/passwd'), 'input echoed');

// The default must follow the NEWEST sweep, and must not pin: a page
// bookmarked today should show tomorrow's data.
$latest = (int)$db->query('SELECT id FROM sweeps WHERE finished_at IS NOT NULL
                           ORDER BY started_at DESC, id DESC LIMIT 1')[0]['id'];
$res = $fetch('?r=errors');
ok('web: defaults to the latest sweep', str_contains($res['body'], "Sweep #$latest"), "expected sweep #$latest");
ok('web: the latest option is selected by default',
   preg_match('/<option[^>]*selected[^>]*>\s*Latest sweep/', $res['body']) === 1, 'latest not selected');
ok('web: and no sweep id is pinned in the tab links',
   !preg_match('/href="\?r=[a-z-]+&amp;sweep=/', $res['body']), 'sweep pinned into tab links');

// Choosing a specific sweep pins it, and offers a way back.
$older = (int)$db->query('SELECT id FROM sweeps WHERE finished_at IS NOT NULL
                          ORDER BY started_at ASC, id ASC LIMIT 1')[0]['id'];
if ($older !== $latest) {
    $res = $fetch("?r=errors&sweep=$older");
    ok('web: an explicitly chosen sweep is honoured', str_contains($res['body'], "Sweep #$older"), "expected #$older");
    ok('web: and offers a way back to latest', str_contains($res['body'], 'Back to latest'), 'no way back');
}

// A non-existent sweep id must not reach SQL.
$res = $fetch('?r=errors&sweep=999999');
eq('web: an unknown sweep id is rejected safely', $res['code'], 200);
ok('web: no SQL error surfaced', !str_contains($res['body'], 'SQLSTATE'), substr($res['body'], 0, 200));

// A web server that drops the query string is the difference between "the
// tabs work" and "clicking a tab does nothing", so both broken shapes are
// pinned here. Each runs in its own process because the page defines
// functions and can only be included once.
$sim = function (string $prelude) use ($php, $root, $dbHost, $dbPort, $dbName, $dbUser, $dbPass) {
    $code = '$_GET=[];' . $prelude
          . ' ob_start(); include ' . var_export("$root/web/cucm-reports.php", true) . '; echo ob_get_clean();';
    return run(sprintf(
        'env CPS_DB_HOST=%s CPS_DB_PORT=%d CPS_DB_NAME=%s CPS_DB_USER=%s CPS_DB_PASS=%s %s -r %s',
        escapeshellarg($dbHost), $dbPort, escapeshellarg($dbName),
        escapeshellarg($dbUser), escapeshellarg($dbPass), $php, escapeshellarg($code)
    ))['out'];
};

// nginx try_files without ?$query_string: QUERY_STRING gone, REQUEST_URI
// intact, so the parameters are recoverable and the page keeps working.
$out = $sim('$_SERVER["REQUEST_URI"]="/cucm-reports.php?r=errors";');
ok('web: recovers parameters from REQUEST_URI when QUERY_STRING is dropped',
   preg_match('/<a class="on"[^>]*>\s*Errors/', $out) === 1, substr(strip_tags($out), 0, 200));
ok('web: and says the query string was not passed',
   str_contains($out, 'not passing the query string'), 'no footer note');

// Missing "include fastcgi_params": no CGI environment at all. Nothing can
// recover the parameters, so the page must SAY so rather than silently
// rendering the default report on every tab.
$out = $sim('unset($_SERVER["REQUEST_URI"],$_SERVER["QUERY_STRING"]);');
ok('web: detects a missing CGI environment',
   str_contains($out, 'not passing the CGI environment'), substr(strip_tags($out), 0, 200));
ok('web: and names the nginx directive that fixes it',
   str_contains($out, 'fastcgi_params'), 'directive not named');

// A correctly configured server must show neither warning.
$res = $fetch('?r=errors');
ok('web: a correct server shows no configuration warning',
   !str_contains($res['body'], 'not passing the'), 'spurious warning');

// Follow the browser by default, with an explicit override that wins in
// either direction. All three pieces must be present: the media query, the
// override selectors, and the pre-paint script that applies a stored choice
// before the body renders so there is no flash of the wrong theme.
$res = $fetch('?r=errors');
ok('web: follows the browser preference', str_contains($res['body'], 'prefers-color-scheme: dark'), 'no media query');
ok('web: an explicit light choice overrides the browser',
   str_contains($res['body'], ':root:not([data-theme="light"])'), 'no light override guard');
ok('web: an explicit dark choice overrides the browser',
   str_contains($res['body'], ':root[data-theme="dark"]'), 'no dark override');
ok('web: the stored choice is applied before first paint',
   strpos($res['body'], "localStorage.getItem('cps-theme')") < strpos($res['body'], '<body'), 'applied too late');
ok('web: there is a theme button', str_contains($res['body'], 'id="theme"'), 'no toggle');
ok('web: localStorage access is guarded',
   preg_match('/try\s*\{[^}]*localStorage/', $res['body']) === 1, 'unguarded localStorage');

// The file was deliberately stripped of comments; adding to it should not
// quietly put them back.
$pageSrc = (string)file_get_contents("$root/web/cucm-reports.php");
$commentLines = preg_grep('#(^\s*//|^\s*/\*|\s//\s)#', explode("\n", $pageSrc));
$commentLines = array_filter($commentLines, fn($l) => !str_contains($l, 'http://') && !str_contains($l, 'https://'));
eq('web: the page stays comment-free', $commentLines, []);

// A column empty for every row is dead weight, and which columns those are
// differs by firmware - so it is decided from the data, not hard-coded.
$db->pdo()->exec('UPDATE port_stats SET tx_total_pkt = NULL');
$res = $fetch('?r=errors');
ok('web: an all-empty column is hidden', !preg_match('/<th[^>]*>TX pkts/', $res['body']), 'TX pkts still shown');
ok('web: and is named rather than vanishing', str_contains($res['body'], 'TX pkts'), 'not named in the notice');
ok('web: with a link to show it again', str_contains($res['body'], 'Show all columns'), 'no show-all link');

$res = $fetch('?r=errors&cols=all');
ok('web: cols=all brings it back', preg_match('/<th[^>]*>TX pkts/', $res['body']) === 1, 'not restored');

// A column with data must never be hidden.
ok('web: a populated column is kept', preg_match('/<th[^>]*>RX CRC/', $fetch('?r=errors')['body']) === 1, 'RX CRC hidden');

// The CSV must match what is on screen.
$res = $fetch('?r=errors&csv=1');
ok('web: CSV omits the hidden column too', !str_contains(explode("\n", $res['body'])[0], 'TX pkts'),
   explode("\n", $res['body'])[0]);
$db->pdo()->exec('UPDATE port_stats SET tx_total_pkt = 1000 WHERE port = \'network\'');

$res = $fetch('?r=errors&csv=1');
eq('web: CSV export returns 200', $res['code'], 200);
ok('web: CSV has a header row', str_contains($res['body'], 'Device,'), substr($res['body'], 0, 120));

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
