#!/usr/bin/env php
<?php
/**
 * cucm-probe.php - verify, one assumption at a time, that this tool's
 * assumptions hold against a real cluster.
 *
 * Usage:
 *   php bin/cucm-probe.php [--config FILE] [--phone IP] [--out DIR] [--verbose]
 *
 * WHY THIS EXISTS
 * ---------------
 * The target cluster is on an airgapped network, so this code was written
 * against Cisco's documentation rather than against the instance - and
 * documentation is not the instance. The failure mode to avoid is a round
 * trip per wrong guess.
 *
 * So every assumption gets its OWN request, and each one states what it
 * expects before it runs. A single query selecting six columns fails as a
 * unit and never tells you which column name was wrong; six queries tell you
 * exactly which one. The cost is a few extra seconds, once.
 *
 * Output: a PASS/FAIL/SKIP summary on stdout, and JSON evidence per probe
 * under --out (default ./probe-evidence). Carry that directory back.
 */

require_once dirname(__DIR__) . '/lib/config.php';

$p    = cps_parse_args($argv, ['verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-probe.php [--config FILE] [--phone IP] [--out DIR]\n");
    exit(0);
}
$cfg     = cps_load_config(cps_common_opts($opts));
$outDir  = is_string($opts['out'] ?? null) ? $opts['out'] : './probe-evidence';
$phoneIp = is_string($opts['phone'] ?? null) ? $opts['phone'] : null;

if (!is_dir($outDir) && !@mkdir($outDir, 0700, true)) {
    cps_die("cannot create evidence directory: $outDir");
}

$servers = cps_server_urls($cfg);
if (!$servers) { cps_die('no cucm_servers configured (see cucm.conf.example)'); }

$probes = [];

/**
 * Run one probe. $expect is printed BEFORE $fn runs, so the record shows
 * what was predicted rather than a rationalisation of whatever came back.
 */
function probe(array &$probes, string $id, string $expect, callable $fn): array
{
    fwrite(STDOUT, sprintf("%-38s expect: %s\n", $id, $expect));
    $t0 = microtime(true);
    try {
        $res = $fn();
        $status = $res['status'] ?? 'PASS';
        $rec = ['id' => $id, 'expect' => $expect, 'status' => $status] + $res;
    } catch (Throwable $e) {
        $rec = ['id' => $id, 'expect' => $expect, 'status' => 'FAIL',
                'error' => $e->getMessage(), 'error_class' => get_class($e)];
    }
    $rec['elapsed_s'] = round(microtime(true) - $t0, 2);
    $probes[] = $rec;
    fwrite(STDOUT, sprintf("%-38s %-5s %s\n\n", '', $rec['status'],
        $rec['error'] ?? ($rec['note'] ?? '')));
    return $rec;
}

// -------------------------------------------------------- A. reachability
foreach ($servers as $srv) {
    probe($probes, "reach:$srv", 'TCP+TLS connect succeeds', function () use ($srv) {
        $ch = curl_init($srv . '/axl/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            // timeout vs refused is the discriminator: a silent drop in the
            // path times out, a host that is not listening refuses.
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout (silent drop in the path - firewall/ACL)'
                  : ($errno === CURLE_COULDNT_CONNECT ? 'refused (nothing listening, or host firewall rejecting)'
                  : "curl $errno");
            return ['status' => 'FAIL', 'error' => "$kind: $err"];
        }
        return ['status' => 'PASS', 'note' => "HTTP $code (any code proves the path and TLS)", 'http_code' => $code];
    });
}

// ------------------------------------------------------------- B. AXL auth
$axlServer = $servers[0];
$soap      = cps_soap_for($cfg, $axlServer);
$axlVer    = null;

probe($probes, 'axl:auth+schema', 'getCCMVersion accepted on one candidate schema version',
    function () use ($soap, &$axlVer, $cfg) {
        $tried = [];
        $versions = ((string)$cfg['axl_version']) !== ''
            ? [(string)$cfg['axl_version']] : CpsAxlClient::KNOWN_VERSIONS;
        foreach ($versions as $v) {
            $env = CpsSoapClient::envelope('<ns:getCCMVersion></ns:getCCMVersion>',
                ['ns' => "http://www.cisco.com/AXL/API/$v"]);
            try {
                $doc = $soap->post('/axl/', $env, "\"CUCM:DB ver=$v getCCMVersion\"");
                $axlVer = $v;
                return ['status' => 'PASS', 'schema_version' => $v,
                        'ccm_version' => cps_xml_text($doc, 'version'),
                        'tried' => $tried,
                        'note' => "schema $v, cluster " . (cps_xml_text($doc, 'version') ?? '?')];
            } catch (CpsSoapFault $e) {
                $tried[$v] = $e->getMessage();
            } catch (CpsHttpError $e) {
                // 401/403 will not improve with another schema version.
                return ['status' => 'FAIL', 'error' => $e->getMessage(),
                        'http_code' => $e->httpCode, 'tried' => $tried];
            }
        }
        return ['status' => 'FAIL', 'error' => 'no schema version accepted', 'tried' => $tried];
    });

$axl = new CpsAxlClient($soap, $axlVer);
$axlUsable = $axlVer !== null;

/** Probe one SQL statement in isolation. */
$sqlProbe = function (string $id, string $expect, string $sql) use (&$probes, $axl, $axlUsable) {
    return probe($probes, $id, $expect, function () use ($axl, $sql, $axlUsable) {
        if (!$axlUsable) { return ['status' => 'SKIP', 'note' => 'AXL not usable', 'sql' => $sql]; }
        $rows = $axl->executeSqlQuery($sql);
        return ['status' => 'PASS', 'sql' => $sql, 'row_count' => count($rows),
                'sample' => array_slice($rows, 0, 2),
                'note' => count($rows) . ' row(s)'];
    });
};

// --------------------------------------------- C/D. SQL access and dialect
$sqlProbe('axl:sql:permitted', 'executeSQLQuery is allowed for this account',
    'SELECT COUNT(*) AS c FROM device');
$sqlProbe('axl:sql:informix-first', 'Informix "SELECT FIRST n" works (there is no LIMIT)',
    'SELECT FIRST 1 name FROM device');

// -------------------- E. RegistrationDynamic, one column per request ------
// These column names are the least certain thing in the tool. Probed
// separately so the answer names the wrong column instead of just failing.
foreach ([
    'fkdevice'           => 'RegistrationDynamic.fkdevice exists',
    'lastknownipaddress' => 'RegistrationDynamic.lastknownipaddress exists (the phone IP)',
    'lastknownucm'       => 'RegistrationDynamic.lastknownucm exists',
    'lastseen'           => 'RegistrationDynamic.lastseen exists',
] as $col => $expect) {
    $sqlProbe("axl:sql:regdyn.$col", $expect, "SELECT FIRST 1 $col FROM registrationdynamic");
}
$sqlProbe('axl:sql:typemodel', 'typemodel(enum,name) exists for the model join',
    'SELECT FIRST 1 enum, name FROM typemodel');
$sqlProbe('axl:sql:regdyn-join', 'the full registration query runs as one statement',
    'SELECT FIRST 3 d.name AS devicename, d.description, tm.name AS model, '
  . 'rd.lastknownipaddress, rd.lastknownucm, rd.lastseen '
  . 'FROM device d INNER JOIN registrationdynamic rd ON rd.fkdevice = d.pkid '
  . 'LEFT JOIN typemodel tm ON tm.enum = d.tkmodel');

// ---------------------------------------------------------- F. listPhone --
probe($probes, 'axl:listPhone', 'listPhone returns rows with skip/first paging',
    function () use ($axl, $axlUsable) {
        if (!$axlUsable) { return ['status' => 'SKIP', 'note' => 'AXL not usable']; }
        $rows = $axl->listPhones(['name', 'description', 'model'], 3);
        return ['status' => 'PASS', 'row_count' => count($rows),
                'sample' => array_slice($rows, 0, 2), 'note' => count($rows) . ' phones'];
    });

// ---------------------------------------------------------- G. RisPort70 --
foreach ($servers as $srv) {
    probe($probes, "ris:selectCmDevice:$srv", 'RisPort70 returns registered phones with IP addresses',
        function () use ($cfg, $srv) {
            $ris  = new CpsRisportClient(cps_soap_for($cfg, $srv), 200, 0);
            $rows = $ris->selectCmDevice('Registered', 'Phone');
            $withIp   = count(array_filter($rows, fn($r) => !empty($r['ip'])));
            $withHttp = count(array_filter($rows, fn($r) => cps_tri_bool($r['http_supported']) !== null));
            $status = $rows ? 'PASS' : 'FAIL';
            return ['status' => $status,
                    'device_count' => count($rows),
                    'with_ip' => $withIp,
                    'with_http_supported' => $withHttp,
                    'sample' => array_slice($rows, 0, 3),
                    'error' => $rows ? null : 'no devices returned',
                    'note' => count($rows) . " devices, $withIp with an IP, $withHttp reporting HttpSupported"];
        });
}

// ------------------------------------------------------------- H. a phone --
if ($phoneIp !== null) {
    // Probe the CONFIGURED base first, then the two defaults. The probe used
    // to try only https://{ip} and http://{ip}, which meant an estate serving
    // the phone pages on a non-standard base saw every phone probe fail while
    // the sweep worked perfectly - the probe contradicting the tool it exists
    // to validate. Duplicates are dropped so the common case still runs twice,
    // not three times.
    $bases = array_values(array_unique(array_filter([
        (string)$cfg['phone_url_base'], 'https://{ip}', 'http://{ip}',
    ])));
    foreach ($bases as $base) {
        foreach (['network', 'access'] as $a) {
            $scheme = parse_url(str_replace('{ip}', '0.0.0.0', $base), PHP_URL_SCHEME) ?: 'http';
            $label  = $base === 'https://{ip}' || $base === 'http://{ip}' ? $scheme : "configured($scheme)";
            probe($probes, "phone:$label:$a", "phone $phoneIp serves $a stats via $base",
                function () use ($cfg, $phoneIp, $a, $base) {
                    $f    = new CpsPhoneFetcher($base,
                        ((string)$cfg['phone_user']) !== '' ? (string)$cfg['phone_user'] : null,
                        (string)$cfg['phone_pass'], false, 10, 5, 1, [$a]);
                    $page = $f->fetchOne($phoneIp, CPS_ADAPTERS[$a]);
                    if (empty($page['ok'])) {
                        return ['status' => 'FAIL', 'error' => $page['error'],
                                'error_kind' => $page['error_kind'] ?? null,
                                'http_code' => $page['http_code'] ?? null];
                    }
                    $n = cps_normalise_port($page['pairs']);
                    return ['status' => 'PASS',
                            'label_count' => count($page['pairs']),
                            'mapped_counters' => count($n['counters']),
                            'unmapped_labels' => $n['unmapped'],
                            'link' => $n['link'],
                            'errors_total' => $n['errors_total'],
                            'pairs' => $page['pairs'],
                            'note' => sprintf('%d labels, %d mapped, %d unmapped, link %s/%s',
                                count($page['pairs']), count($n['counters']), $n['unmapped'],
                                $n['link']['speed_mbps'] ?? '?', $n['link']['duplex'] ?? '?')];
                });
        }
    }
} else {
    fwrite(STDOUT, "phone probes skipped (pass --phone <ip> to include them)\n\n");
}

// ------------------------------------------------------------- summary ----
$stamp = date('Ymd-His');
$file  = rtrim($outDir, '/') . "/probe-$stamp.json";
file_put_contents($file, cps_json([
    'generated_at' => date('c'),
    'tool_version' => CPS_VERSION,
    'servers'      => $servers,
    'phone'        => $phoneIp,
    'probes'       => $probes,
]));

$counts = array_count_values(array_column($probes, 'status'));
echo str_repeat('=', 72) . "\n";
echo cps_render_table(array_map(fn($r) => [
    'probe'  => $r['id'],
    'status' => $r['status'],
    'detail' => substr((string)($r['error'] ?? $r['note'] ?? ''), 0, 70),
], $probes), ['probe', 'status', 'detail']);
printf("\nPASS %d, FAIL %d, SKIP %d\nEvidence: %s\n",
    $counts['PASS'] ?? 0, $counts['FAIL'] ?? 0, $counts['SKIP'] ?? 0, $file);

exit(($counts['FAIL'] ?? 0) > 0 ? 1 : 0);
