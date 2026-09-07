<?php
/**
 * mock-server.php - a stand-in CUCM and a set of stand-in phones, for
 * PHP's built-in web server:
 *
 *   php -S 127.0.0.1:8099 tests/mock-server.php
 *
 * WHAT THIS CAN AND CANNOT PROVE
 * ------------------------------
 * It exercises the plumbing: SOAP envelope construction, namespace-blind
 * parsing, RisPort StateInfo paging, the tolerant HTML table parser, label
 * normalisation, the database writes and the reporting views.
 *
 * It CANNOT prove that Cisco's real field names match the ones this tool
 * looks for, because the fixtures here were written from the same reading of
 * the documentation as the code was. A fixture cannot disagree with the
 * assumption that produced it. That is what bin/cucm-probe.php is for, and
 * it is why the phone fixtures below deliberately spell their labels several
 * different ways: the normaliser has to survive drift it was not shown.
 *
 * Generation: GET /_gen/<n> switches the phone counter set, so a sweep can
 * be run repeatedly to produce a trend (2 = errors grown, 3 = counters reset
 * as if the handset rebooted).
 */

const GEN_FILE = __DIR__ . '/.mock-generation';
const BIG_FILE = __DIR__ . '/.mock-big';

/**
 * Large-estate mode: GET /_big/<n> synthesises n registered phones.
 *
 * It reproduces the failure seen on the real cluster: an unpartitioned
 * query is silently capped at BIG_CAP devices and then simply stops
 * returning StateInfo - a well formed, error free, WRONG answer - while
 * TotalDevicesFound keeps reporting the true total. A query narrowed by
 * name pattern is served in full. Without this the partitioning fallback
 * would be untestable, and an untested fallback for a fault you cannot
 * reproduce is just a hopeful guess.
 */
const BIG_CAP = 120;
const SOFT_FILE = __DIR__ . '/.mock-soft';
const CONC_FILE = __DIR__ . '/.mock-conc';
const SLOW_FILE = __DIR__ . '/.mock-slow';

/**
 * Track how many phone requests are being served at once.
 *
 * The point is to assert overlap directly rather than infer it from elapsed
 * time. A timing assertion on a build machine is a flaky test; "the server
 * saw six requests in flight simultaneously" is not. Needs the built-in
 * server started with PHP_CLI_SERVER_WORKERS so requests are not serialised
 * by a single process - hence the file plus flock rather than a static.
 */
function concEnter(): void {
    $fh = fopen(CONC_FILE, 'c+');
    if (!$fh) { return; }
    flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true) ?: ['cur' => 0, 'max' => 0];
    $d['cur']++;
    $d['max'] = max($d['max'], $d['cur']);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN); fclose($fh);
}
function concLeave(): void {
    $fh = fopen(CONC_FILE, 'c+');
    if (!$fh) { return; }
    flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true) ?: ['cur' => 0, 'max' => 0];
    $d['cur'] = max(0, $d['cur'] - 1);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN); fclose($fh);
}
const SLOWMOD_FILE = __DIR__ . '/.mock-slowmod';

function slowMs(): int {
    return is_readable(SLOW_FILE) ? max(0, (int)file_get_contents(SLOW_FILE)) : 0;
}
/** 0 = every phone is slow; n > 0 = roughly one phone in n is slow. */
function slowMod(): int {
    return is_readable(SLOWMOD_FILE) ? max(0, (int)file_get_contents(SLOWMOD_FILE)) : 0;
}
/**
 * Should this phone be slow? A realistic estate is not uniformly slow - it
 * is mostly fast with a scattering of handsets that crawl or time out, and
 * that VARIANCE is the entire difference between batching and a rolling
 * window. With uniform latency the two are indistinguishable.
 */
function isSlowPhone(string $ip): bool {
    $mod = slowMod();
    if ($mod <= 0) { return true; }
    return (hexdec(substr(md5($ip), 0, 4)) % $mod) === 0;
}

/**
 * GET /_soft/1 adds registered soft clients to the estate.
 *
 * Behind a toggle so the counts every other test asserts stay stable. Their
 * addresses are deliberately in the same range as the handsets: a Jabber
 * client registers from the user's workstation, and the point of the
 * device-prefix filter is that nothing about the ADDRESS tells you not to
 * poll it - only the device name does.
 */
function softClients(): array {
    if (!is_readable(SOFT_FILE) || trim((string)file_get_contents(SOFT_FILE)) !== '1') { return []; }
    return [
        'CSFJOSEPH'       => ['ip' => '10.10.10.51', 'status' => 'Registered', 'model' => 'Cisco Unified Client',
                              'descr' => 'Jabber desktop', 'http' => 'No', 'behaviour' => 'noweb'],
        'TCTJSMITH'       => ['ip' => '10.10.10.52', 'status' => 'Registered', 'model' => 'Cisco Jabber for iPhone',
                              'descr' => 'Jabber iPhone', 'http' => 'No', 'behaviour' => 'noweb'],
        'BOTJSMITH'       => ['ip' => '10.10.10.53', 'status' => 'Registered', 'model' => 'Cisco Jabber for Android',
                              'descr' => 'Jabber Android', 'http' => 'No', 'behaviour' => 'noweb'],
        'ATA001122335501' => ['ip' => '10.10.10.54', 'status' => 'Registered', 'model' => 'Cisco ATA 192',
                              'descr' => 'Analog adapter', 'http' => 'No', 'behaviour' => 'noweb'],
    ];
}

function bigCount(): int {
    return is_readable(BIG_FILE) ? max(0, (int)file_get_contents(BIG_FILE)) : 0;
}

/** Deterministic synthetic estate, spread across all 16 SEP buckets. */
function bigEstate(): array {
    $out = [];
    for ($i = 0; $i < bigCount(); $i++) {
        $name = 'SEP' . strtoupper(substr(md5('dev' . $i), 0, 12));
        $out[$name] = [
            'ip' => sprintf('10.%d.%d.%d', 20 + intdiv($i, 65025), intdiv($i, 255) % 255, ($i % 254) + 1),
            'status' => 'Registered', 'model' => 'Cisco 8841',
            'descr' => "Synthetic $i", 'http' => 'Yes', 'behaviour' => 'clean',
        ];
    }
    return $out;
}

function gen(): int {
    return is_readable(GEN_FILE) ? max(1, (int)file_get_contents(GEN_FILE)) : 1;
}

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

if ($uri === '/_conc') {
    header('Content-Type: application/json');
    echo is_readable(CONC_FILE) ? (string)file_get_contents(CONC_FILE) : '{"cur":0,"max":0}';
    return;
}
if ($uri === '/_conc/reset') {
    file_put_contents(CONC_FILE, json_encode(['cur' => 0, 'max' => 0]));
    echo "reset\n";
    return;
}
if (preg_match('#^/_slowmod/(\d+)$#', $uri, $m)) {
    file_put_contents(SLOWMOD_FILE, $m[1]);
    echo "slowmod {$m[1]}\n";
    return;
}
if (preg_match('#^/_slow/(\d+)$#', $uri, $m)) {
    file_put_contents(SLOW_FILE, $m[1]);
    echo "slow {$m[1]}ms\n";
    return;
}
if (preg_match('#^/_soft/([01])$#', $uri, $m)) {
    file_put_contents(SOFT_FILE, $m[1]);
    header('Content-Type: text/plain');
    echo "soft clients {$m[1]}\n";
    return;
}
if (preg_match('#^/_big/(\d+)$#', $uri, $m)) {
    file_put_contents(BIG_FILE, $m[1]);
    header('Content-Type: text/plain');
    echo "big estate {$m[1]}\n";
    return;
}
if (preg_match('#^/_gen/(\d+)$#', $uri, $m)) {
    file_put_contents(GEN_FILE, $m[1]);
    header('Content-Type: text/plain');
    echo "generation {$m[1]}\n";
    return;
}

if ($uri === '/axl/')                                     { axl($body); return; }
if ($uri === '/realtimeservice2/services/RISService70')   { ris($body); return; }
if (preg_match('#^/phone/([^/]+)/CGI/Java/Serviceability$#', $uri, $m)) {
    phone($m[1], $_GET['adapter'] ?? '');
    return;
}
http_response_code(404);
echo "no mock route for $uri\n";

// ---------------------------------------------------------------------------
// The fixture estate.
//
// status/ip come from RisPort; the phone entries drive the HTTP fixtures.
// Each phone is here to produce a specific, checkable outcome.
// ---------------------------------------------------------------------------
function estate(): array {
    return softClients() + [
        // clean baseline: both ports 100/full, no errors
        'SEP001122334401' => ['ip' => '10.10.10.1', 'status' => 'Registered', 'model' => 'Cisco 8841',
                              'descr' => 'Reception', 'http' => 'Yes', 'behaviour' => 'clean'],
        // speed mismatch: network 1000/full, access 100/full
        'SEP001122334402' => ['ip' => '10.10.10.2', 'status' => 'Registered', 'model' => 'Cisco 8851',
                              'descr' => 'Finance 1', 'http' => 'Yes', 'behaviour' => 'speed-mismatch'],
        // duplex mismatch: 100/full vs 100/half
        'SEP001122334403' => ['ip' => '10.10.10.3', 'status' => 'Registered', 'model' => 'Cisco 7841',
                              'descr' => 'Warehouse', 'http' => 'Yes', 'behaviour' => 'duplex-mismatch'],
        // non-zero errors, and they grow between generations
        'SEP001122334404' => ['ip' => '10.10.10.4', 'status' => 'Registered', 'model' => 'Cisco 8845',
                              'descr' => 'Loading dock', 'http' => 'Yes', 'behaviour' => 'errors'],
        // negative control: web access is authenticated, so the fetch 401s
        'SEP001122334405' => ['ip' => '10.10.10.5', 'status' => 'Registered', 'model' => 'Cisco 8841',
                              'descr' => 'Locked down', 'http' => 'Yes', 'behaviour' => 'auth'],
        // negative control: registered, but the web server is off (404)
        'SEP001122334406' => ['ip' => '10.10.10.6', 'status' => 'Registered', 'model' => 'Cisco 7821',
                              'descr' => 'Web access disabled', 'http' => 'No', 'behaviour' => 'noweb'],
        // negative control: must NOT appear in a Registered-only discovery
        'SEP001122334407' => ['ip' => '10.10.10.7', 'status' => 'UnRegistered', 'model' => 'Cisco 8841',
                              'descr' => 'Unplugged', 'http' => 'Yes', 'behaviour' => 'clean'],
        // access port down: not a mismatch even though speeds differ
        'SEP001122334408' => ['ip' => '10.10.10.8', 'status' => 'Registered', 'model' => 'Cisco 8841',
                              'descr' => 'No PC attached', 'http' => 'Yes', 'behaviour' => 'access-down'],
    ];
}

function soap_out(string $inner): void {
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>'
       . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
       . '<soapenv:Body>' . $inner . '</soapenv:Body></soapenv:Envelope>';
}

function soap_fault(string $string, string $axlcode = '5003'): void {
    http_response_code(500);
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>'
       . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
       . '<soapenv:Fault><faultcode>soapenv:Client</faultcode>'
       . '<faultstring>' . htmlspecialchars($string) . '</faultstring>'
       . '<detail><axlError><axlcode>' . $axlcode . '</axlcode>'
       . '<axlmessage>' . htmlspecialchars($string) . '</axlmessage></axlError></detail>'
       . '</soapenv:Fault></soapenv:Body></soapenv:Envelope>';
}

// ---------------------------------------------------------------------- AXL
function axl(string $body): void {
    if (($_SERVER['PHP_AUTH_USER'] ?? '') === '' ) {
        // Basic auth is mandatory, exactly as the real thing is, so the
        // client's auth handling is actually exercised.
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="mock"');
        echo 'auth required';
        return;
    }
    // Only 14.0 and 12.5 are "supported", so version negotiation has
    // something to walk down through - starting at 15.0, it must fall back.
    if (preg_match('#AXL/API/([0-9.]+)#', $body, $m) && !in_array($m[1], ['14.0', '12.5'], true)) {
        soap_fault("Unsupported AXL schema version {$m[1]}", '5007');
        return;
    }

    if (str_contains($body, 'getCCMVersion')) {
        soap_out('<ns:getCCMVersionResponse xmlns:ns="http://www.cisco.com/AXL/API/14.0"><return>'
               . '<componentVersion><version>14.0.1.13900-155</version></componentVersion>'
               . '</return></ns:getCCMVersionResponse>');
        return;
    }

    if (str_contains($body, 'executeSQLQuery')) {
        preg_match('#<sql>(.*?)</sql>#s', $body, $m);
        $sql = html_entity_decode($m[1] ?? '');
        axl_sql($sql);
        return;
    }

    if (str_contains($body, 'listPhone')) {
        preg_match('#<skip>(\d+)</skip>#', $body, $s);
        preg_match('#<first>(\d+)</first>#', $body, $f);
        $skip  = (int)($s[1] ?? 0);
        $first = (int)($f[1] ?? 500);
        $all   = array_keys(estate());
        $page  = array_slice($all, $skip, $first);
        $xml   = '';
        foreach ($page as $name) {
            $e = estate()[$name];
            $xml .= '<phone uuid="{' . strtolower(md5($name)) . '}">'
                  . "<name>$name</name><description>{$e['descr']}</description>"
                  . "<model>{$e['model']}</model></phone>";
        }
        soap_out('<ns:listPhoneResponse xmlns:ns="http://www.cisco.com/AXL/API/14.0"><return>'
               . $xml . '</return></ns:listPhoneResponse>');
        return;
    }
    soap_fault('unknown AXL method');
}

function axl_sql(string $sql): void {
    $l = strtolower($sql);

    // The Informix dialect trap, enforced: LIMIT must fail here, the way it
    // fails on a real cluster, so a regression to MySQL syntax is caught.
    if (preg_match('/\blimit\b/', $l)) {
        soap_fault('A syntax error has occurred near LIMIT', '217');
        return;
    }
    if (str_contains($l, 'count(*)')) {
        soap_out(axl_rows([['c' => (string)count(estate())]]));
        return;
    }
    if (str_contains($l, 'from registrationdynamic')) {
        // Only the columns this tool relies on exist; anything else is an
        // Informix "column not found", so a probe of a wrong name fails.
        $known = ['fkdevice', 'lastknownipaddress', 'lastknownucm', 'lastseen', 'pkid'];
        preg_match('/select\s+(?:first\s+\d+\s+)?(.*?)\s+from/s', $l, $m);
        foreach (preg_split('/\s*,\s*/', $m[1] ?? '') as $col) {
            $col = trim(preg_replace('/^\w+\./', '', $col));
            if ($col !== '' && $col !== '*' && !in_array($col, $known, true)) {
                soap_fault("Column ($col) not found in any table in the query", '217');
                return;
            }
        }
        $rows = [];
        foreach (estate() as $name => $e) {
            $rows[] = ['fkdevice' => md5($name), 'lastknownipaddress' => $e['ip'],
                       'lastknownucm' => 'CUCM-SUB1', 'lastseen' => '1757000000', 'pkid' => md5($name)];
        }
        soap_out(axl_rows($rows));
        return;
    }
    if (str_contains($l, 'from typemodel')) {
        soap_out(axl_rows([['enum' => '627', 'name' => 'Cisco 8841']]));
        return;
    }
    if (str_contains($l, 'from device')) {
        $rows = [];
        foreach (estate() as $name => $e) {
            $rows[] = ['name' => $name, 'description' => $e['descr'], 'model' => $e['model'],
                       'lastknownipaddress' => $e['ip'], 'lastknownucm' => 'CUCM-SUB1',
                       'lastseen' => '1757000000', 'devicename' => $name];
        }
        if (preg_match('/select\s+first\s+(\d+)/', $l, $m)) { $rows = array_slice($rows, 0, (int)$m[1]); }
        soap_out(axl_rows($rows));
        return;
    }
    soap_fault('mock does not implement: ' . substr($sql, 0, 80));
}

function axl_rows(array $rows): string {
    $xml = '';
    foreach ($rows as $r) {
        $xml .= '<row>';
        foreach ($r as $k => $v) { $xml .= "<$k>" . htmlspecialchars((string)$v) . "</$k>"; }
        $xml .= '</row>';
    }
    return '<ns:executeSQLQueryResponse xmlns:ns="http://www.cisco.com/AXL/API/14.0"><return>'
         . $xml . '</return></ns:executeSQLQueryResponse>';
}

// ----------------------------------------------------------------- RisPort
function ris(string $body): void {
    if (($_SERVER['PHP_AUTH_USER'] ?? '') === '') {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="mock"');
        echo 'auth required';
        return;
    }
    preg_match('#<[^>]*Status>([^<]*)<#', $body, $m);
    $want = $m[1] ?? 'Any';
    preg_match('#<[^>]*StateInfo>([^<]*)<#', $body, $s);
    $state = $s[1] ?? '';
    preg_match('#<[^>]*MaxReturnedDevices>(\d+)<#', $body, $mx);
    $max = max(1, (int)($mx[1] ?? 1000));

    // Which name patterns did the caller ask for?
    preg_match_all('#<[^>]*Item>([^<]*)<#', $body, $im);
    $patterns = array_values(array_filter($im[1] ?? [], fn($x) => trim($x) !== ''));
    if (!$patterns) { $patterns = ['*']; }
    $narrowed = !(count($patterns) === 1 && $patterns[0] === '*');

    $source = bigCount() > 0 ? bigEstate() : estate();
    $all = [];
    foreach ($source as $name => $e) {
        if ($want !== 'Any' && strcasecmp($e['status'], $want) !== 0) { continue; }
        foreach ($patterns as $pat) {
            $re = '/^' . str_replace('\*', '.*', preg_quote($pat, '/')) . '$/i';
            if (preg_match($re, $name)) { $all[$name] = $e; break; }
        }
    }

    // The cap only bites an unnarrowed query, which is what makes splitting
    // by name prefix a workaround rather than a coincidence.
    $trueTotal = count($all);
    $capped    = (bigCount() > 0 && !$narrowed) ? BIG_CAP : PHP_INT_MAX;

    // Page deliberately at 3 devices regardless of MaxReturnedDevices, so
    // StateInfo paging is exercised even on a small fixture estate.
    $pageSize = min($max, bigCount() > 0 ? 50 : 3);
    $offset   = $state === '' ? 0 : (int)$state;
    $reachable = min(count($all), $capped);
    $slice    = array_slice($all, $offset, min($pageSize, max(0, $reachable - $offset)), true);
    // StateInfo simply stops once the cap is reached: no error, no warning.
    $next     = ($offset + $pageSize) < $reachable ? (string)($offset + $pageSize) : '';

    $xml = '';
    foreach ($slice as $name => $e) {
        $xml .= '<item><Name>' . $name . '</Name>'
              . '<DirNumber>' . (2000 + (int)substr($name, -2)) . '</DirNumber>'
              . '<Class>Phone</Class><Model>' . htmlspecialchars($e['model']) . '</Model>'
              . '<Product>' . htmlspecialchars($e['model']) . '</Product>'
              . '<Status>' . $e['status'] . '</Status><StatusReason>0</StatusReason>'
              . '<Protocol>SIP</Protocol>'
              . '<HttpSupported>' . $e['http'] . '</HttpSupported>'
              . '<ActiveLoadID>sip88xx.12-8-1-0001-337</ActiveLoadID>'
              . '<Description>' . htmlspecialchars($e['descr']) . '</Description>'
              . '<TimeStamp>1757000000</TimeStamp>'
              . '<IPAddress><item><IP>' . $e['ip'] . '</IP><IPAddrType>ipv4</IPAddrType></item></IPAddress>'
              . '</item>';
    }
    soap_out('<ns:selectCmDeviceResponse xmlns:ns="http://schemas.cisco.com/ast/soap">'
           . '<selectCmDeviceReturn><SelectCmDeviceResult>'
           . '<TotalDevicesFound>' . $trueTotal . '</TotalDevicesFound>'
           . '<CmNodes><item><Name>CUCM-SUB1</Name><NoChange>false</NoChange>'
           . '<CmDevices>' . $xml . '</CmDevices></item></CmNodes>'
           . '</SelectCmDeviceResult><StateInfo>' . $next . '</StateInfo>'
           . '</selectCmDeviceReturn></ns:selectCmDeviceResponse>');
}

// ------------------------------------------------------------------ phones
function phone(string $ip, string $adapter): void {
    concEnter();
    try {
        phoneInner($ip, $adapter);
    } finally {
        concLeave();
    }
}

function phoneInner(string $ip, string $adapter): void {
    if (($ms = slowMs()) > 0 && isSlowPhone($ip)) { usleep($ms * 1000); }
    $source = bigCount() > 0 ? bigEstate() + estate() : estate();
    $name = null;
    foreach ($source as $n => $e) { if ($e['ip'] === $ip) { $name = $n; break; } }
    if ($name === null) { http_response_code(404); echo 'no such phone'; return; }
    $e = $source[$name];

    if ($e['behaviour'] === 'auth' && ($_SERVER['PHP_AUTH_USER'] ?? '') === '') {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="phone"');
        echo 'authentication required';
        return;
    }
    if ($e['behaviour'] === 'noweb') { http_response_code(404); echo 'not found'; return; }

    header('Content-Type: text/html');
    echo phone_page($e['behaviour'], $adapter, $name, gen());
}

/**
 * Render a phone page. Each behaviour uses a DIFFERENT label spelling and a
 * DIFFERENT table layout on purpose - two-column, three-column with a ":"
 * spacer, and padded empty cells - because that is the variation real
 * firmware shows and the parser has to absorb it.
 */
function phone_page(string $behaviour, string $adapter, string $name, int $gen): string {
    $isAccess = str_contains($adapter, 'access');

    if (str_contains($adapter, 'device.statistics.device')) {
        return table2([
            'MAC Address'       => substr($name, 3),
            'Host Name'         => strtolower($name),
            'Serial Number'     => 'FCH' . substr(md5($name), 0, 8),
            'Model Number'      => 'CP-8841',
            'App Load ID'       => 'sip88xx.12-8-1-0001-337',
            'Boot Load ID'      => 'sboot88xx.012-8-1-0001',
            'Hardware Revision' => '2.0',
        ]);
    }
    if (str_contains($adapter, 'configuration')) {
        return table3([
            'DHCP Server'  => '10.0.0.1',
            'Domain Name'  => 'example.local',
            'TFTP Server 1'=> '10.0.0.10',
            'CDP Neighbor Device ID' => 'sw-access-01',
            'CDP Neighbor Port'      => 'GigabitEthernet1/0/12',
        ]);
    }

    // Counters grow with the generation; generation 3 models a reboot, where
    // the counters clear and start again from a low value.
    $bump = fn(int $base, int $step) => $gen === 3 ? (int)($step / 2) : $base + ($gen - 1) * $step;

    switch ($behaviour) {
        case 'speed-mismatch':
            // Uses "Port Speed" as the label, and a 3-column layout.
            return table3([
                'Port Speed'   => $isAccess ? '100Mb Full' : '1000Mb Full',
                'Rx totalPkt'  => $bump(120000, 5000),
                'Tx totalPkt'  => $bump(98000, 4000),
                'Rx crcErr'    => 0,
                'Tx totalCollision' => 0,
            ]);

        case 'duplex-mismatch':
            // Uses "Link State", and bare "100 Full" / "100 Half".
            return table2([
                'Link State'   => $isAccess ? '100 Half' : '100 Full',
                'RxTotalPkt'   => $bump(50000, 1000),
                'TxTotalPkt'   => $bump(48000, 900),
                'RxCrcErr'     => 0,
                'TxCollisions' => $isAccess ? $bump(3, 2) : 0,
            ]);

        case 'errors':
            // CamelCase labels, padded spacer cells, and a label the map has
            // never seen, to check unmapped_labels counting.
            return tablePadded([
                'Port Information'   => '100 Mbps Full Duplex',
                'RxTotalPkt'         => $bump(220000, 10000),
                'RxCrcErr'           => $bump(42, 17),
                'RxAlignErr'         => $bump(5, 3),
                'TxTotalPkt'         => $bump(180000, 9000),
                'TxLateCollision'    => $bump(7, 4),
                'TxExcessCollision'  => 0,
                'Rx tokenDrop'       => 3,   // intentionally unmapped
            ]);

        case 'access-down':
            return table2($isAccess
                ? ['Port Information' => 'No Link', 'RxTotalPkt' => 0, 'TxTotalPkt' => 0]
                : ['Port Information' => '1000 Mbps Full Duplex',
                   'RxTotalPkt' => $bump(75000, 2000), 'TxTotalPkt' => $bump(70000, 1800),
                   'RxCrcErr' => 0]);

        case 'clean':
        default:
            return table2([
                'Port Information' => '100 Mbps Full Duplex',
                'Rx totalPkt'      => $bump(31000, 800),
                'Rx crcErr'        => 0,
                'Rx alignErr'      => 0,
                'Tx totalPkt'      => $bump(29500, 700),
                'Tx totalCollision'=> 0,
            ]);
    }
}

function wrap(string $rows): string {
    return "<html><head><title>Network Statistics</title></head><body>"
         . "<table border=0 cellpadding=2>$rows</table></body></html>";
}
function table2(array $kv): string {
    $r = '';
    foreach ($kv as $k => $v) { $r .= "<tr><td>$k</td><td>$v</td></tr>"; }
    return wrap($r);
}
function table3(array $kv): string {   // label : value, with a ":" spacer cell
    $r = '';
    foreach ($kv as $k => $v) { $r .= "<tr><td>$k</td><td>:</td><td>$v</td></tr>"; }
    return wrap($r);
}
function tablePadded(array $kv): string {  // empty spacer cells around the value
    $r = '';
    foreach ($kv as $k => $v) { $r .= "<tr><td></td><td>$k</td><td>&nbsp;</td><td>$v</td><td></td></tr>"; }
    return wrap($r);
}
