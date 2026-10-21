#!/usr/bin/env php
<?php
/**
 * cucm-sweep.php - discover phones from CUCM, poll their port statistics, store them in MySQL.
 *
 * Usage:
 *   php bin/cucm-sweep.php [--config FILE] [--status Registered] [--source risport|axl-sql]
 *                          [--adapters network,access,ethernet,device]
 *                          [--sample N] [--limit N] [--match TEXT] [--exclude-subnet CIDR]
 *                          [--phones-from FILE] [--only-problems [SWEEP]]
 *                          [--no-db] [--json] [--skip-no-http] [--verbose]
 *
 * Examples:
 *   php bin/cucm-sweep.php                            # full sweep into the database
 *   php bin/cucm-sweep.php --sample 20 --no-db        # 20 random phones, nothing stored
 *   php bin/cucm-sweep.php --match name:SEP0011 --sample 5
 *   php bin/cucm-sweep.php --phones-from phones.csv   # poll a list, skip CUCM
 *   php bin/cucm-sweep.php --only-problems            # re-check what the last sweep flagged
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/discover.php';
require_once dirname(__DIR__) . '/lib/db.php';

$p    = cps_parse_args($argv, ['json', 'no-db', 'verbose', 'quiet', 'debug', 'skip-no-http', 'all-devices', 'help']);
$opts = $p['opts'];

if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-sweep.php [options]\n"
                 . "  --config FILE                 config file\n"
                 . "  --status S                    registration status to discover (default Registered)\n"
                 . "  --source risport|axl-sql      discovery source (default risport)\n"
                 . "  --adapters a,b,c              pages to fetch: network,access,ethernet,device\n"
                 . "  --sample N                    N random phones\n"
                 . "  --limit N                     the first N phones\n"
                 . "  --match TEXT                  substring on name/description/model/ip; field:value for one\n"
                 . "  --exclude-subnet CIDR[,CIDR]  leave these subnets out\n"
                 . "  --device-prefixes SEP[,CSF]   device name prefixes (default SEP)\n"
                 . "  --all-devices                 every device type, soft clients included\n"
                 . "  --parallel N                  phones in flight at once (default 10)\n"
                 . "  --phones-from FILE            poll phones from a cucm-phones CSV/JSON\n"
                 . "  --only-problems [SWEEP]       only phones the last (or given) sweep flagged\n"
                 . "  --problems LIST               mismatch,errors,growing,switch (default mismatch,errors,switch)\n"
                 . "  --min-errors N                with 'errors': ports with >= N errors\n"
                 . "  --skip-no-http                skip phones CUCM says have no web server\n"
                 . "  --no-db                       do not store results\n"
                 . "  --json                        JSON output\n"
                 . "  --verbose / --quiet           more / less on stderr\n");
    exit(0);
}

$cfg      = cps_load_config(cps_common_opts($opts));
$status   = is_string($opts['status'] ?? null) ? $opts['status'] : 'Registered';
$source   = is_string($opts['source'] ?? null) ? $opts['source'] : 'risport';
$limit    = isset($opts['limit']) && is_string($opts['limit']) ? (int)$opts['limit'] : 0;
$sample   = isset($opts['sample']) && is_string($opts['sample']) ? (int)$opts['sample'] : 0;
$match    = is_string($opts['match'] ?? null) ? $opts['match'] : null;
$useDb    = empty($opts['no-db']) && cps_cfg_bool($cfg, 'db_enabled', true);
$adapters = is_string($opts['adapters'] ?? null)
    ? preg_split('/[\s,]+/', $opts['adapters'], -1, PREG_SPLIT_NO_EMPTY)
    : preg_split('/[\s,]+/', (string)$cfg['adapters'], -1, PREG_SPLIT_NO_EMPTY);

$problemNames = null;
if (!isset($opts['only-problems']) && (isset($opts['problems']) || isset($opts['min-errors']))) {
    cps_die('--problems and --min-errors only apply with --only-problems');
}
if (isset($opts['only-problems'])) {
    $pdb  = new CpsDb($cfg);
    if (is_string($opts['only-problems']) && !ctype_digit($opts['only-problems'])) {
        cps_die("--only-problems takes a sweep number, got '{$opts['only-problems']}'");
    }
    $from = is_string($opts['only-problems']) ? (int)$opts['only-problems'] : $pdb->latestSweepId();
    if (!$from) { cps_die('--only-problems needs a completed sweep to start from'); }
    if (!$pdb->query('SELECT 1 FROM sweeps WHERE id = ? AND finished_at IS NOT NULL', [$from])) {
        cps_die("sweep #$from does not exist or did not complete");
    }
    $why = [];
    $reasons = is_string($opts['problems'] ?? null)
        ? preg_split('/[\s,]+/', strtolower($opts['problems']), -1, PREG_SPLIT_NO_EMPTY) : null;
    $minErr  = isset($opts['min-errors']) && is_string($opts['min-errors']) ? max(1, (int)$opts['min-errors']) : 1;
    try {
        $problemNames = array_map('strval', $pdb->problemPhones($from, $why, $reasons, $minErr));
    } catch (InvalidArgumentException $e) {
        cps_die($e->getMessage());
    }
    cps_log(sprintf('%d phones flagged in sweep #%d (%s)', count($problemNames), $from,
        implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($why), $why))));
    if (!$problemNames) {
        cps_log("nothing to re-check - sweep #$from flagged no phones");
        exit(0);
    }
}

// ---------------------------------------------------------------- discovery
$t0 = microtime(true);
if (isset($opts['phones-from']) && is_string($opts['phones-from'])) {
    $phones = cps_phones_from_file($opts['phones-from']);
    $source = 'file:' . basename($opts['phones-from']);
    cps_log(count($phones) . ' phones loaded from ' . $opts['phones-from'], 2);
    if ($problemNames !== null) {
        $want   = array_flip($problemNames);
        $phones = array_values(array_filter($phones, fn($r) => isset($want[$r['name']])));
        $source = "problems:#$from";
    }
} elseif ($problemNames !== null) {
    // Ask RisPort for these names only.
    if (!cps_server_urls($cfg)) { cps_die('no cucm_servers configured (see cucm.conf.example)'); }
    $phones = cps_discover_named_phones($cfg, $problemNames, $status);
    $source = "problems:#$from";
    cps_log(sprintf('%d of %d flagged phones are currently %s', count($phones), count($problemNames), $status), 2);
} else {
    if (!cps_server_urls($cfg)) { cps_die('no cucm_servers configured (see cucm.conf.example)'); }
    $phones = cps_discover_phones($cfg, $source, $status, $opts);
    cps_log(count($phones) . " phones discovered from CUCM (status=$status, source=$source)", 2);
}
$phones     = cps_apply_device_prefixes($phones, $cfg, $opts);
$phones     = cps_apply_subnet_exclusions($phones, $cfg, $opts);
$discovered = count($phones);

$pollable = array_values(array_filter($phones, function ($r) use ($opts) {
    if (empty($r['ip'])) { return false; }
    if (!empty($opts['skip-no-http']) && cps_tri_bool($r['http_supported'] ?? '') === 0) { return false; }
    return true;
}));
// --match filters before --sample/--limit.
if ($match !== null && $match !== '') {
    $fields = ['name', 'description', 'model', 'ip'];
    $needle = mb_strtolower($match);
    $scope  = $fields;
    if (($c = strpos($match, ':')) !== false) {
        $maybe = mb_strtolower(substr($match, 0, $c));
        if (in_array($maybe, $fields, true)) {
            $scope  = [$maybe];
            $needle = mb_strtolower(substr($match, $c + 1));
        }
    }
    $pollable = array_values(array_filter($pollable, function ($r) use ($needle, $scope) {
        foreach ($scope as $f) {
            if (str_contains(mb_strtolower((string)($r[$f] ?? '')), $needle)) { return true; }
        }
        return false;
    }));
    cps_log(sprintf("%d phones match '%s' (%s)", count($pollable), $match,
        count($scope) === 1 ? $scope[0] . ' only' : 'substring across ' . implode('/', $fields)), 2);
}

if ($sample > 0 && $sample < count($pollable)) {
    $keys = array_rand($pollable, $sample);
    $picked = [];
    foreach ((array)$keys as $k) { $picked[] = $pollable[$k]; }
    $pollable = $picked;
    cps_log("randomly sampled $sample phones from the pollable set", 2);
}
if ($limit > 0) { $pollable = array_slice($pollable, 0, $limit); }

cps_log(sprintf('%d of %d phones are pollable%s', count($pollable), $discovered,
    $sample > 0 ? " (random sample of $sample)" : ($limit > 0 ? " (first $limit)" : '')), 2);
if (!$pollable) { cps_die('no phones with an IP address to poll'); }

// ------------------------------------------------------------------ polling
$db      = null;
$sweepId = null;
if (isset($opts['parallel']) && is_string($opts['parallel'])) {
    $cfg['phone_parallel'] = max(1, (int)$opts['parallel']);
}
$fetcher = cps_phone_fetcher($cfg, $adapters);   // validates adapters before a sweep row exists
if ($useDb) {
    $db      = new CpsDb($cfg);
    $sweepId = $db->startSweep($source, cps_server_urls($cfg), CPS_VERSION);
    cps_log("sweep #$sweepId opened", 2);

    // Record why an unfinished sweep stopped; finished_at stays NULL.
    register_shutdown_function(function () use ($db, $sweepId) {
        if (!isset($GLOBALS['CPS_SWEEP_FINISHED'])) {
            $e = error_get_last();
            $why = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
                ? sprintf('aborted: %s in %s:%d', $e['message'], basename((string)$e['file']), $e['line'])
                : 'aborted before completion';
            try {
                $st = $db->pdo()->prepare('UPDATE sweeps SET notes = ? WHERE id = ? AND finished_at IS NULL');
                $st->execute([substr($why, 0, 2000), $sweepId]);
            } catch (Throwable) { /* nothing useful left to do at shutdown */ }
            fwrite(STDERR, "sweep #$sweepId did not complete: $why\n");
        }
    });
}

if ($db && !empty($GLOBALS['CPS_MODEL_NAMES'])) {
    try {
        $db->upsertDeviceModels($GLOBALS['CPS_MODEL_NAMES']);
        $fixed = $db->backfillModelNames();
        if ($fixed > 0) { cps_log("resolved $fixed stored model enum(s) to names", 2); }
    } catch (Throwable $e) {
        cps_warn('could not store model names: ' . $e->getMessage());
    }
}

$results = $fetcher->fetchMany(array_map(fn($r) => ['name' => $r['name'], 'ip' => $r['ip']], $pollable));
cps_log(sprintf('polled %d phones in %.1fs', count($results), microtime(true) - $t0), 2);

// ------------------------------------------------------------------ storing
$byName  = [];
foreach ($pollable as $r) { $byName[$r['name']] = $r; }

$ok = $failed = 0;
$dbErrors = 0;
$phoneErrors = 0;
$summary = [];
foreach ($results as $name => $res) {
  // One bad phone must not end the sweep.
  try {
    $disc  = $byName[$name] ?? ['name' => $name];
    $pages = $res['pages'] ?? [];

    // OK = at least one port page parsed.
    $gotPort = false;
    foreach (CPS_PORT_ADAPTERS as $a) {
        if (!empty($pages[$a]['ok'])) { $gotPort = true; break; }
    }
    $gotPort ? $ok++ : $failed++;

    $deviceFields = [];
    foreach (['device', 'config'] as $a) {
        if (!empty($pages[$a]['ok'])) {
            $deviceFields += cps_normalise_device($pages[$a]['pairs']);
        }
    }

    // CDP/LLDP can appear on any page depending on firmware. Known labels from
    // every page first, so a guess from one page never beats a match on another.
    $neighbour = [];
    foreach ([false, true] as $fallback) {
        foreach ($pages as $page) {
            if (!empty($page['ok'])) { $neighbour += cps_normalise_neighbour($page['pairs'], $fallback); }
        }
    }
    $uplink = cps_neighbour_summary($neighbour);

    $row = [
        'name'   => $name,
        'ip'     => $disc['ip'] ?? null,
        'status' => $disc['status'] ?? null,
        'ok'     => $gotPort,
        'ports'  => [],
        'errors' => [],
        'switch' => null,
    ];
    if ($uplink['switch_name'] !== null || $uplink['switch_port'] !== null) {
        $row['switch'] = trim(($uplink['switch_name'] ?? '?') . ' ' . ($uplink['switch_port'] ?? ''));
    }
    foreach ($pages as $a => $page) {
        if (empty($page['ok'])) {
            $row['errors'][$a] = $page['error'] ?? 'failed';
            continue;
        }
        if (in_array($a, CPS_PORT_ADAPTERS, true)) {
            $n = cps_normalise_port($page['pairs']);
            $row['ports'][$a] = [
                'link_up'      => $n['link']['link_up'],
                'speed_mbps'   => $n['link']['speed_mbps'],
                'duplex'       => $n['link']['duplex'],
                'errors_total' => $n['errors_total'],
            ];
        }
    }
    $summary[] = $row;

    if (!$db) { continue; }

    // One transaction per phone.
    try {
        $db->begin();
        $phoneId = $db->upsertPhone([
            'device_name'       => $name,
            'description'       => $disc['description'] ?? null,
            'model'             => $disc['model'] ?? null,
            'product'           => $disc['product'] ?? null,
            'device_class'      => $disc['device_class'] ?? null,
            'dir_number'        => $disc['dir_number'] ?? null,
            'ip'                => $disc['ip'] ?? null,
            'serial_number'     => $deviceFields['serial_number'] ?? null,
            'model_number'      => $deviceFields['model_number'] ?? null,
            'hardware_revision' => $deviceFields['hardware_revision'] ?? null,
            'mac_address'       => isset($deviceFields['mac_address'])
                ? strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $deviceFields['mac_address'])) ?: null
                : null,
        ]);
        $db->insertRegistration($sweepId, $phoneId, $disc + ['source' => str_starts_with($source, 'file:') ? 'file' : $source]);
        if ($neighbour || $uplink['switch_name'] !== null) {
            $db->insertUplink($sweepId, $phoneId, $neighbour, $uplink);
        }
        foreach ($pages as $a => $page) {
            $db->insertPageFetch($sweepId, $phoneId, CPS_ADAPTERS[$a] ?? $a, $page);
            if (!empty($page['ok']) && in_array($a, CPS_PORT_ADAPTERS, true)) {
                $db->insertPortStats($sweepId, $phoneId, $a, cps_normalise_port($page['pairs']));
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        $dbErrors++;
        cps_warn("$name: database write failed: " . $e->getMessage());
    }
  } catch (Throwable $e) {
    $failed++;
    $phoneErrors++;
    cps_warn("$name: skipped after an unexpected error: " . get_class($e) . ': ' . $e->getMessage());
  }
}

// Common Phone Profile per phone; a failure only warns.
if ($db && (string)($cfg['axl_user'] ?? '') !== '' && cps_cfg_bool($cfg, 'collect_phone_profiles', true)) {
    try {
        $profiles = cps_common_phone_profiles($cfg);
        $stored   = $profiles ? $db->upsertPhoneConfig($profiles) : 0;
        cps_log(sprintf('common phone profile: %d known to AXL, %d stored', count($profiles), $stored), 2);
    } catch (Throwable $e) {
        cps_warn('could not store common phone profiles: ' . $e->getMessage());
    }
}

if ($db) {
    $db->finishSweep($sweepId, [
        'discovered' => $discovered, 'polled' => count($results),
        'ok' => $ok, 'failed' => $failed,
    ], cps_sweep_notes($dbErrors, $phoneErrors));
}
$GLOBALS['CPS_SWEEP_FINISHED'] = true;
if ($db) {
    cps_log("sweep #$sweepId closed", 2);
    if ($dbErrors > 0) {
        cps_warn("$dbErrors of " . count($results) . ' phones could not be stored');
    }
}

// ------------------------------------------------------------------- output
if (!empty($opts['json'])) {
    echo cps_json([
        'sweep_id'   => $sweepId,
        'discovered' => $discovered,
        'polled'     => count($results),
        'ok'         => $ok,
        'failed'     => $failed,
        'elapsed_s'  => round(microtime(true) - $t0, 1),
        'db_errors'  => $dbErrors,
        'phone_errors' => $phoneErrors,
        'phones'     => $summary,
    ]);
} else {
    $rows = [];
    foreach ($summary as $s) {
        $rows[] = [
            'name'       => $s['name'],
            'ip'         => $s['ip'] ?? '',
            'net_speed'  => cps_fmt_link($s['ports']['network'] ?? null),
            'acc_speed'  => cps_fmt_link($s['ports']['access'] ?? null),
            'net_errs'   => (string)($s['ports']['network']['errors_total'] ?? ''),
            'acc_errs'   => (string)($s['ports']['access']['errors_total'] ?? ''),
            'switch'     => (string)($s['switch'] ?? ''),
            'result'     => $s['ok'] ? 'ok' : ('FAILED: ' . implode('; ', array_slice($s['errors'], 0, 1))),
        ];
    }
    echo cps_render_table($rows);
    printf("\n%d discovered, %d polled, %d ok, %d failed, %.1fs%s\n",
        $discovered, count($results), $ok, $failed, microtime(true) - $t0,
        $sweepId ? " (sweep #$sweepId)" : ' (not stored)');
}
// Exit 2: no phone answered, or every database write failed.
exit((($failed > 0 && $ok === 0) || ($db && $dbErrors > 0 && $dbErrors === count($results))) ? 2 : 0);

function cps_sweep_notes(int $dbErrors, int $phoneErrors): ?string
{
    $n = [];
    if ($dbErrors > 0)    { $n[] = "$dbErrors phone(s) failed to store"; }
    if ($phoneErrors > 0) { $n[] = "$phoneErrors phone(s) skipped after an unexpected error"; }
    return $n ? implode('; ', $n) : null;
}

function cps_fmt_link(?array $p): string
{
    if ($p === null) { return ''; }
    if ($p['link_up'] === 0) { return 'down'; }
    if ($p['speed_mbps'] === null && $p['duplex'] === null) { return '?'; }
    return ($p['speed_mbps'] ?? '?') . '/' . ($p['duplex'] ?? '?');
}

/** Read a phone list from cucm-phones.php --csv or --json (sniffed). */
function cps_phones_from_file(string $file): array
{
    if (!is_readable($file)) { cps_die("phones file not readable: $file"); }
    $text = trim((string)file_get_contents($file));
    if ($text === '') { cps_die("phones file is empty: $file"); }

    if (str_starts_with($text, '[') || str_starts_with($text, '{')) {
        $j = json_decode($text, true);
        if (!is_array($j)) { cps_die("phones file is not valid JSON: $file"); }
        return array_values(array_filter($j, fn($r) => is_array($r) && !empty($r['name'])));
    }

    $fh   = fopen($file, 'r');
    $head = fgetcsv($fh, 0, ',', '"', '\\');
    if ($head === false) { cps_die("phones file has no header row: $file"); }
    $head = array_map(fn($h) => strtolower(trim((string)$h)), $head);
    if (!in_array('name', $head, true) || !in_array('ip', $head, true)) {
        cps_die("phones CSV needs at least 'name' and 'ip' columns; got: " . implode(', ', $head));
    }
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($r) === 1 && trim((string)$r[0]) === '') { continue; }
        $row = @array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
        if ($row && $row['name'] !== '') { $rows[] = $row; }
    }
    fclose($fh);
    return $rows;
}
