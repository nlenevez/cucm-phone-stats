<?php
/**
 * mock-server.php - a stand-in CUCM and a set of stand-in phones, for
 * PHP's built-in web server:
 *
 *   php -S 127.0.0.1:8099 tests/mock-server.php
 *
 * Tests the plumbing, not Cisco's real field names (see bin/cucm-probe.php).
 *
 * GET /_gen/<n> switches the phone counter set (2 = errors grown, 3 = reset).
 */

const GEN_FILE = __DIR__ . '/.mock-generation';
// Common Phone Profile assignments, shared by getPhone, updatePhone and SQL.
const CPP_FILE = __DIR__ . '/.mock-cpp';
const CPP_PROFILES = [
    '1f5ae3f8-6f07-4e4b-91c5-1d4b6b1b0a01' => 'Standard Common Phone Profile',
    'ac243d17-98b4-4118-8feb-5ff2e1b781ac' => 'Warehouse CPP',
    '5b0c9e2a-3d4f-4a61-9c7e-2f8d1a6b4c90' => 'Fixed 100 Full CPP',
];
const BIG_FILE = __DIR__ . '/.mock-big';

/** GET /_big/<n> synthesises n phones; each query is silently capped at BIG_CAP. */
const BIG_CAP = 120;
const BIGCAP_FILE = __DIR__ . '/.mock-bigcap';

/** GET /_bigcap/1 makes TotalDevicesFound report the capped count. */
function capHidesTotal(): bool {
    return is_readable(BIGCAP_FILE) && trim((string)file_get_contents(BIGCAP_FILE)) === '1';
}
const SOFT_FILE = __DIR__ . '/.mock-soft';
const CONC_FILE = __DIR__ . '/.mock-conc';
const RISSEEN_FILE = __DIR__ . '/.mock-risseen';

/** Record which device-name prefixes RisPort handed back. */
function noteReturnedPrefixes(array $devices): void {
    $seen = is_readable(RISSEEN_FILE)
        ? (json_decode((string)file_get_contents(RISSEEN_FILE), true) ?: []) : [];
    foreach (array_keys($devices) as $name) {
        if (preg_match('/^(CIPC|SEP|CSF|TCT|BOT|TAB|ATA)/i', (string)$name, $m)) {
            $seen[strtoupper($m[1])] = ($seen[strtoupper($m[1])] ?? 0) + 1;
        }
    }
    file_put_contents(RISSEEN_FILE, json_encode($seen));
}
const EDGE_FILE = __DIR__ . '/.mock-edge';

/** GET /_edge/1 adds handsets that answer 200 with an empty, blank or tableless body. */
function edgeClients(): array {
    if (!is_readable(EDGE_FILE) || trim((string)file_get_contents(EDGE_FILE)) !== '1') { return []; }
    return [
        'SEP00112233EE01' => ['ip' => '10.10.10.61', 'status' => 'Registered', 'model' => 'Cisco 8841',
                              'descr' => 'Answers 200 with nothing', 'http' => 'Yes', 'behaviour' => 'empty'],
        'SEP00112233EE02' => ['ip' => '10.10.10.62', 'status' => 'Registered', 'model' => 'Cisco 8841',
                              'descr' => 'Answers 200 with whitespace', 'http' => 'Yes', 'behaviour' => 'blank'],
        'SEP00112233EE03' => ['ip' => '10.10.10.63', 'status' => 'Registered', 'model' => 'Cisco 8841',
                              'descr' => 'Answers 200 with no table', 'http' => 'Yes', 'behaviour' => 'notable'],
        // 60 line appearances: a very long DirNumber.
        'SEP00112233EE04' => ['ip' => '10.10.10.64', 'status' => 'Registered', 'model' => 'Cisco 8861',
                              'descr' => 'Many line appearances', 'http' => 'Yes', 'behaviour' => 'clean',
                              'dn' => implode(',', array_map(fn($i) => '6' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), range(1, 60)))],
    ];
}
const SLOW_FILE = __DIR__ . '/.mock-slow';

/** Track concurrent phone requests (needs PHP_CLI_SERVER_WORKERS). */
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
/** Should this phone be slow? */
function isSlowPhone(string $ip): bool {
    $mod = slowMod();
    if ($mod <= 0) { return true; }
    return (hexdec(substr(md5($ip), 0, 4)) % $mod) === 0;
}

/** GET /_soft/1 adds soft clients, in the same address range as the handsets. */
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

const AXLVER_FILE = __DIR__ . '/.mock-axlver';
const ENUM_FILE   = __DIR__ . '/.mock-modelenum';

/** GET /_modelenum/1 makes RisPort report models as numeric enums. */
function modelEnums(): bool {
    return is_readable(ENUM_FILE) && trim((string)file_get_contents(ENUM_FILE)) === '1';
}
/** The subset of CUCM's typemodel this mock knows. */
function modelTable(): array {
    return [
        30035 => 'Cisco 7821', 36213 => 'Cisco 7841', 627 => 'Cisco 8841',
        684   => 'Cisco 8845', 621   => 'Cisco 8851', 30032 => 'Cisco ATA 192',
        503   => 'Cisco Unified Client', 592 => 'Cisco Jabber for iPhone',
        593   => 'Cisco Jabber for Android',
    ];
}
function modelEnumFor(string $name): string {
    $flip = array_flip(modelTable());
    return (string)($flip[$name] ?? 627);
}

/** AXL schema versions this cluster admits; GET /_axlver/<list> sets them. */
function axlVersions(): array {
    if (is_readable(AXLVER_FILE)) {
        $v = trim((string)file_get_contents(AXLVER_FILE));
        if ($v !== '') { return preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY); }
    }
    return ['14.0', '12.5'];
}

/** Deterministic synthetic estate. */
function bigEstate(): array {
    $out = [];
    for ($i = 0; $i < bigCount(); $i++) {
        $name = 'SEP' . strtoupper(substr(md5('dev' . $i), 0, 12));
        $out[$name] = [
            'ip' => sprintf('10.%d.%d.%d', 20 + intdiv($i, 65025), intdiv($i, 255) % 255, ($i % 254) + 1),
            'status' => 'Registered', 'model' => 'Cisco 8841',
            'descr' => "Synthetic $i", 'http' => 'Yes', 'behaviour' => 'clean',
            'node' => 'CUCM-SUB' . (($i % 3) + 1),
        ];
    }
    return $out;
}

function gen(): int {
    return is_readable(GEN_FILE) ? max(1, (int)file_get_contents(GEN_FILE)) : 1;
}

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

if (preg_match('#^/_modelenum/([01])$#', $uri, $m)) {
    file_put_contents(ENUM_FILE, $m[1]);
    echo "model enums {$m[1]}\n";
    return;
}
if (preg_match('#^/_axlver/(.*)$#', $uri, $m)) {
    file_put_contents(AXLVER_FILE, urldecode($m[1]));
    echo "axl versions {$m[1]}\n";
    return;
}
if ($uri === '/_ris/seen') {
    header('Content-Type: application/json');
    echo is_readable(RISSEEN_FILE) ? (string)file_get_contents(RISSEEN_FILE) : '{}';
    return;
}
if ($uri === '/_ris/reset') {
    @unlink(RISSEEN_FILE);
    echo "reset\n";
    return;
}
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
if (preg_match('#^/_bigcap/([01])$#', $uri, $m)) {
    file_put_contents(BIGCAP_FILE, $m[1]);
    echo "bigcap {$m[1]}\n";
    return;
}
if (preg_match('#^/_edge/([01])$#', $uri, $m)) {
    file_put_contents(EDGE_FILE, $m[1]);
    echo "edge cases {$m[1]}\n";
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
// The fixture estate. Each phone produces one checkable outcome.
// ---------------------------------------------------------------------------
function estate(): array {
    return edgeClients() + softClients() + [
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
        // Basic auth is mandatory.
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="mock"');
        echo 'auth required';
        return;
    }
    if (preg_match('#AXL/API/([0-9.]+)#', $body, $m) && !in_array($m[1], axlVersions(), true)) {
        // CUCM refuses an unknown schema with HTTP 599 and a bare <axlError>.
        http_response_code(599);
        header('Content-Type: text/xml; charset=utf-8');
        echo '<axlError><axlcode>-1</axlcode>'
           . '<axlmessage>Wrong AXL Version</axlmessage>'
           . '<request>getCCMVersion</request></axlError>';
        return;
    }

    if (str_contains($body, 'getCCMVersion')) {
        soap_out('<ns:getCCMVersionResponse xmlns:ns="http://www.cisco.com/AXL/API/14.0"><return>'
               . '<componentVersion><version>14.0.1.13900-155</version></componentVersion>'
               . '</return></ns:getCCMVersionResponse>');
        return;
    }

    if (axl_profile_methods($body)) { return; }

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
        // Same estate RisPort sees.
        $src   = bigCount() > 0 ? bigEstate() : estate();
        preg_match('#<name>([^<]*)</name>#', $body, $nm);
        $like  = trim($nm[1] ?? '%');
        $all   = array_keys($src);
        if ($like !== '' && $like !== '%') {
            $re  = '/^' . str_replace('%', '.*', preg_quote(strtoupper($like), '/')) . '$/i';
            $all = array_values(array_filter($all, fn($n) => preg_match($re, $n)));
        }
        $page  = array_slice($all, $skip, $first);
        $xml   = '';
        foreach ($page as $name) {
            $e = $src[$name];
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

/** Common Phone Profile assignment; see CPP_FILE. */
function cppOf(string $name): string {
    $set = is_file(CPP_FILE) ? (json_decode((string)file_get_contents(CPP_FILE), true) ?: []) : [];
    return $set[$name] ?? ($name === 'SEP001122334403'
        ? 'ac243d17-98b4-4118-8feb-5ff2e1b781ac' : '1f5ae3f8-6f07-4e4b-91c5-1d4b6b1b0a01');
}
function axl_profile_methods(string $body): bool {
    $uuidIn = fn(string $s) => strtolower(trim($s, '{} '));
    if (str_contains($body, 'getCommonPhoneConfig>')) {
        preg_match('#<uuid>([^<]*)</uuid>#', $body, $m);
        $u = $uuidIn($m[1] ?? '');
        if (!isset(CPP_PROFILES[$u])) { soap_fault('Item not valid: The specified CommonPhoneConfig was not found', '5007'); return true; }
        soap_out('<ns:getCommonPhoneConfigResponse xmlns:ns="http://www.cisco.com/AXL/API/14.0"><return>'
               . '<commonPhoneConfig uuid="{' . strtoupper($u) . '}"><name>' . CPP_PROFILES[$u] . '</name></commonPhoneConfig>'
               . '</return></ns:getCommonPhoneConfigResponse>');
        return true;
    }
    if (!preg_match('#<ns:(getPhone|updatePhone|applyPhone)>#', $body, $op)) { return false; }
    preg_match('#<name>([^<]*)</name>#', $body, $n);
    $name = $n[1] ?? '';
    if (!isset(estate()[$name])) { soap_fault("Item not valid: The specified $name was not found", '5007'); return true; }
    if ($op[1] === 'updatePhone') {
        preg_match('#<commonPhoneConfigName uuid="([^"]*)"#', $body, $m);
        $u = $uuidIn($m[1] ?? '');
        if (!isset(CPP_PROFILES[$u])) { soap_fault('Item not valid: CommonPhoneConfig not found', '5007'); return true; }
        $set = is_file(CPP_FILE) ? (json_decode((string)file_get_contents(CPP_FILE), true) ?: []) : [];
        $set[$name] = $u;
        file_put_contents(CPP_FILE, json_encode($set));
    }
    $inner = $op[1] === 'getPhone'
        ? '<phone><commonPhoneConfigName uuid="{' . strtoupper(cppOf($name)) . '}">' . CPP_PROFILES[cppOf($name)] . '</commonPhoneConfigName></phone>'
        : '{' . strtoupper(md5($name)) . '}';
    soap_out("<ns:{$op[1]}Response xmlns:ns=\"http://www.cisco.com/AXL/API/14.0\"><return>$inner</return></ns:{$op[1]}Response>");
    return true;
}

function axl_sql(string $sql): void {
    $l = strtolower($sql);

    // Informix: LIMIT is a syntax error.
    if (preg_match('/\blimit\b/', $l)) {
        soap_fault('A syntax error has occurred near LIMIT', '217');
        return;
    }
    if (str_contains($l, 'count(*)')) {
        soap_out(axl_rows([['c' => (string)count(estate())]]));
        return;
    }
    if (str_contains($l, 'from registrationdynamic')) {
        // Unknown columns fault, as Informix does.
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
        $rows = [];
        foreach (modelTable() as $enum => $name) { $rows[] = ['enum' => (string)$enum, 'name' => $name]; }
        if (preg_match('/select\s+first\s+(\d+)/', $l, $m)) { $rows = array_slice($rows, 0, (int)$m[1]); }
        soap_out(axl_rows($rows));
        return;
    }
    if (str_contains($l, 'commonphoneconfig')) {
        // pkids are lowercase, no braces.
        $rows = [];
        foreach (estate() as $name => $e) {
            $u = cppOf($name);
            $rows[] = ['devicename' => $name, 'cppuuid' => $u, 'cppname' => CPP_PROFILES[$u]];
        }
        soap_out(axl_rows($rows));
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
    preg_match('#<[^>]*NodeName>([^<]*)<#', $body, $nn);
    $wantNode = trim($nn[1] ?? '');

    preg_match_all('#<[^>]*Item>([^<]*)<#', $body, $im);
    $patterns = array_values(array_filter($im[1] ?? [], fn($x) => trim($x) !== ''));
    if (!$patterns) { $patterns = ['*']; }
    $narrowed = !(count($patterns) === 1 && $patterns[0] === '*');

    $source = bigCount() > 0 ? bigEstate() : estate();
    $all = [];
    foreach ($source as $name => $e) {
        if ($want !== 'Any' && strcasecmp($e['status'], $want) !== 0) { continue; }
        if ($wantNode !== '' && ($e['node'] ?? 'CUCM-SUB1') !== $wantNode) { continue; }
        foreach ($patterns as $pat) {
            $re = '/^' . str_replace('\*', '.*', preg_quote($pat, '/')) . '$/i';
            if (preg_match($re, $name)) { $all[$name] = $e; break; }
        }
    }

    $trueTotal = count($all);
    // The cap applies to every query, narrowed or not.
    $capped    = bigCount() > 0 ? BIG_CAP : PHP_INT_MAX;
    if (capHidesTotal() && $trueTotal > $capped) { $trueTotal = $capped; }

    // Small pages so StateInfo paging is exercised.
    $pageSize = min($max, bigCount() > 0 ? 50 : 3);
    $offset   = $state === '' ? 0 : (int)$state;
    $reachable = min(count($all), $capped);
    $slice    = array_slice($all, $offset, min($pageSize, max(0, $reachable - $offset)), true);
    // StateInfo stops silently at the cap.
    $next     = ($offset + $pageSize) < $reachable ? (string)($offset + $pageSize) : '';

    noteReturnedPrefixes($slice);

    $byNode = [];
    foreach ($slice as $name => $e) { $byNode[$e['node'] ?? 'CUCM-SUB1'][$name] = $e; }
    // Even a truncated response lists every node.
    if (bigCount() > 0) {
        foreach (['CUCM-SUB1', 'CUCM-SUB2', 'CUCM-SUB3'] as $n) { $byNode[$n] ??= []; }
    }

    $nodesXml = '';
    foreach ($byNode as $nodeName => $devices) {
    $xml = '';
    foreach ($devices as $name => $e) {
        $xml .= '<item><Name>' . $name . '</Name>'
              . '<DirNumber>' . htmlspecialchars((string)($e['dn'] ?? (2000 + (int)substr($name, -2)))) . '</DirNumber>'
              . '<Class>Phone</Class><Model>'
              . htmlspecialchars(modelEnums() ? modelEnumFor($e['model']) : $e['model']) . '</Model>'
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
        $nodesXml .= '<item><Name>' . htmlspecialchars((string)$nodeName) . '</Name>'
                   . '<NoChange>false</NoChange><CmDevices>' . $xml . '</CmDevices></item>';
    }
    soap_out('<ns:selectCmDeviceResponse xmlns:ns="http://schemas.cisco.com/ast/soap">'
           . '<selectCmDeviceReturn><SelectCmDeviceResult>'
           . '<TotalDevicesFound>' . $trueTotal . '</TotalDevicesFound>'
           . '<CmNodes>' . ($nodesXml ?: '<item><Name>CUCM-SUB1</Name><CmDevices></CmDevices></item>') . '</CmNodes>'
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

    if ($e['behaviour'] === 'empty') { header('Content-Type: text/html'); echo ''; return; }
    if ($e['behaviour'] === 'blank') { header('Content-Type: text/html'); echo "   \n\t  \n"; return; }
    if ($e['behaviour'] === 'notable') {
        header('Content-Type: text/html');
        echo '<html><body><h1>Service temporarily unavailable</h1></body></html>';
        return;
    }

    header('Content-Type: text/html');
    echo phone_page($e['behaviour'], $adapter, $name, gen());
}

/** Render a phone page. Label spellings and table layouts vary on purpose. */
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
        $kv = [
            'DHCP Server'   => '10.0.0.1',
            'Domain Name'   => 'example.local',
            'TFTP Server 1' => '10.0.0.10',
        ];
        // Neighbour reporting varies:
        //  - the clean phone reports CDP only
        //  - the errors phone reports LLDP only, plus a CDP placeholder
        //  - the duplex-mismatch phone reports both, so CDP must win
        //  - access-down reports neither
        switch ($behaviour) {
            case 'errors':
                $kv += [
                    'CDP Neighbor Device ID'      => 'not available',
                    'LLDP-MED Neighbor Device ID' => 'sw-edge-07.example.local',
                    'LLDP-MED Neighbor Port'      => 'Gi1/0/33',
                ];
                break;
            case 'duplex-mismatch':
                $kv += [
                    'CDP Neighbor Device ID'  => 'sw-access-02',
                    'CDP Neighbor Port'       => 'GigabitEthernet1/0/7',
                    'CDP Neighbor IP Address' => '10.0.0.202',
                    'LLDP Neighbor Device ID' => '00:11:22:33:44:55',
                    'LLDP Neighbor Port'      => '7',
                ];
                break;
            case 'access-down':
                break;   // no neighbour info at all
            default:
                $kv += [
                    'CDP Neighbor Device ID'  => 'sw-access-01',
                    'CDP Neighbor Port'       => 'GigabitEthernet1/0/12',
                    'CDP Neighbor IP Address' => '10.0.0.201',
                ];
        }
        return table3($kv);
    }

    // Counters grow per generation; generation 3 models a reboot.
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
            // CamelCase labels, padded cells, and one unmapped label.
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
