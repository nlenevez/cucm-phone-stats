#!/usr/bin/env php
<?php
/**
 * cucm-sweep.php - the whole job: discover registered phones from CUCM,
 * pull each phone's port statistics, and store the lot in MySQL.
 *
 * Usage:
 *   php bin/cucm-sweep.php [--config FILE] [--status Registered] [--source risport|axl-sql]
 *                          [--adapters network,access,ethernet,device]
 *                          [--sample N] [--limit N] [--match TEXT] [--exclude-subnet CIDR]
 *                          [--no-db] [--json] [--skip-no-http] [--verbose]
 *
 * Examples:
 *   php bin/cucm-sweep.php                            # full sweep into the database
 *   php bin/cucm-sweep.php --sample 20 --no-db        # 20 random phones, nothing stored
 *   php bin/cucm-sweep.php --match Warehouse --sample 5     # by description
 *   php bin/cucm-sweep.php --match name:SEP0011            # scoped to one field
 *   php bin/cucm-sweep.php --limit 5 --no-db --json   # the FIRST five (one node, one site)
 *   php bin/cucm-sweep.php --phones-from phones.csv   # poll a list, skip CUCM
 *
 * On a large estate reach for --sample, not --limit: RisPort returns devices
 * grouped by node, so the first N are usually all from one subscriber.
 *
 * --phones-from exists for the airgapped case where discovery and polling
 * may not happen from the same host or at the same time: cucm-phones.php
 * --csv on one box, this on another.
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/discover.php';
require_once dirname(__DIR__) . '/lib/db.php';

$p    = cps_parse_args($argv, ['json', 'no-db', 'verbose', 'quiet', 'debug', 'skip-no-http', 'all-devices', 'help']);
$opts = $p['opts'];

if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-sweep.php [--config FILE] [--status S] [--source risport|axl-sql]\n"
                 . "       [--adapters a,b,c] [--sample N] [--limit N] [--match TEXT]\n"
                 . "       [--phones-from FILE] [--no-db] [--json] [--skip-no-http] [--verbose]\n"
                 . "\n  --sample N   N random phones (use this on a large estate)\n"
                 . "  --limit N    the first N discovered (usually all one node)\n"
                 . "  --match TEXT substring on name/description/model/ip; 'field:value' scopes it\n"
                 . "               (for device TYPES use --device-prefixes: --match SEP also\n"
                 . "                hits CSFJOSEPH, since JO-SEP-H contains it)\n"
                 . "  --exclude-subnet CIDR[,CIDR]  leave these subnets alone\n"
                 . "  --device-prefixes SEP[,CSF]   device types to poll (default SEP)\n"
                 . "  --all-devices                 poll every registered device, soft clients included\n"
                 . "  --parallel N                  phones in flight at once (default 10)\n");
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

// ---------------------------------------------------------------- discovery
$t0 = microtime(true);
if (isset($opts['phones-from']) && is_string($opts['phones-from'])) {
    $phones = cps_phones_from_file($opts['phones-from']);
    $source = 'file:' . basename($opts['phones-from']);
    cps_log(count($phones) . ' phones loaded from ' . $opts['phones-from']);
} else {
    if (!cps_server_urls($cfg)) { cps_die('no cucm_servers configured (see cucm.conf.example)'); }
    $phones = cps_discover_phones($cfg, $source, $status, $opts);
    cps_log(count($phones) . " phones discovered from CUCM (status=$status, source=$source)");
}
$phones     = cps_apply_device_prefixes($phones, $cfg, $opts);
$phones     = cps_apply_subnet_exclusions($phones, $cfg, $opts);
$discovered = count($phones);

$pollable = array_values(array_filter($phones, function ($r) use ($opts) {
    if (empty($r['ip'])) { return false; }
    // HttpSupported=0 means CUCM believes the phone has no web server; with
    // --skip-no-http we take its word for it rather than spending a timeout
    // per handset finding out. Off by default: CUCM is sometimes wrong, and
    // a wrong skip is invisible in the results.
    if (!empty($opts['skip-no-http']) && cps_tri_bool($r['http_supported'] ?? '') === 0) { return false; }
    return true;
}));
// --match narrows to a site or a model before any sampling, so
// "--match SEP0011 --sample 20" means twenty of THAT set, not twenty of the
// estate that happen to match.
if ($match !== null && $match !== '') {
    // --match is a SUBSTRING search, and across several fields. That is
    // useful for "everything at the Warehouse" and a trap for device types:
    // "--match SEP" also matches CSFJOSEPH, because JO-SEP-H contains it.
    // Use --device-prefixes for device types; it anchors to the name.
    //
    // "field:value" scopes the search to one field for the cases where the
    // broad form is too broad.
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
        count($scope) === 1 ? $scope[0] . ' only' : 'substring across ' . implode('/', $fields)));
}

// --sample takes a random spread; --limit takes the first N.
//
// They are not the same test. RisPort returns devices grouped by node, so
// the first N are typically all from one subscriber, one site and often one
// switch - exactly the population least likely to expose a problem that only
// some of the estate has. For a smoke test across thousands of handsets,
// --sample is the honest one.
if ($sample > 0 && $sample < count($pollable)) {
    $keys = array_rand($pollable, $sample);
    $picked = [];
    foreach ((array)$keys as $k) { $picked[] = $pollable[$k]; }
    $pollable = $picked;
    cps_log("randomly sampled $sample phones from the pollable set");
}
if ($limit > 0) { $pollable = array_slice($pollable, 0, $limit); }

cps_log(sprintf('%d of %d phones are pollable%s', count($pollable), $discovered,
    $sample > 0 ? " (random sample of $sample)" : ($limit > 0 ? " (first $limit)" : '')));
if (!$pollable) { cps_die('no phones with an IP address to poll'); }

// ------------------------------------------------------------------ polling
$db      = null;
$sweepId = null;
if ($useDb) {
    $db      = new CpsDb($cfg);
    $sweepId = $db->startSweep($source, cps_server_urls($cfg), CPS_VERSION);
    cps_log("sweep #$sweepId opened");

    // If the run dies anyway, say so on the sweep row. finished_at stays
    // NULL so the reporting views keep excluding a partial run, but the
    // reason is recorded rather than the sweep just sitting there open with
    // no explanation months later.
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

if (isset($opts['parallel']) && is_string($opts['parallel'])) {
    $cfg['phone_parallel'] = max(1, (int)$opts['parallel']);
}
// Mirror the model-enum table so past sweeps can be made readable too.
if ($db && !empty($GLOBALS['CPS_MODEL_NAMES'])) {
    try {
        $db->upsertDeviceModels($GLOBALS['CPS_MODEL_NAMES']);
        $fixed = $db->backfillModelNames();
        if ($fixed > 0) { cps_log("resolved $fixed stored model enum(s) to names"); }
    } catch (Throwable $e) {
        cps_warn('could not store model names: ' . $e->getMessage());
    }
}

$fetcher = cps_phone_fetcher($cfg, $adapters);
$results = $fetcher->fetchMany(array_map(fn($r) => ['name' => $r['name'], 'ip' => $r['ip']], $pollable));
cps_log(sprintf('polled %d phones in %.1fs', count($results), microtime(true) - $t0));

// ------------------------------------------------------------------ storing
$byName  = [];
foreach ($pollable as $r) { $byName[$r['name']] = $r; }

$ok = $failed = 0;
$dbErrors = 0;
$phoneErrors = 0;
$summary = [];
foreach ($results as $name => $res) {
  // Everything for one phone is contained. A sweep of thousands must never
  // be brought down by one handset: an unhandled throw here previously took
  // the whole run with it, losing every phone after the bad one.
  try {
    $disc  = $byName[$name] ?? ['name' => $name];
    $pages = $res['pages'] ?? [];

    // A phone counts as OK when at least one port page parsed. Requiring
    // every adapter would mark a perfectly healthy phone failed just because
    // it does not serve, say, the ethernet page on its firmware.
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

    // Neighbour info is harvested from EVERY page, not just the config one.
    // Firmware disagrees about where CDP/LLDP appears - some builds put it on
    // the network port page - and looking in only one place means the field
    // is there, visible in the raw pairs, and silently not collected.
    $neighbour = [];
    foreach ($pages as $page) {
        if (!empty($page['ok'])) { $neighbour += cps_normalise_neighbour($page['pairs']); }
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

    // One transaction per phone: a failure part-way through a long sweep
    // leaves every phone before it intact and committed.
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

$GLOBALS['CPS_SWEEP_FINISHED'] = true;
if ($db) {
    $db->finishSweep($sweepId, [
        'discovered' => $discovered, 'polled' => count($results),
        'ok' => $ok, 'failed' => $failed,
    ], cps_sweep_notes($dbErrors, $phoneErrors));
    cps_log("sweep #$sweepId closed");
    // A sweep that reached every phone but stored none of it must not look
    // like a success: the polling worked and the run is still worthless.
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
// Exit 2 when nothing usable came out of the run: no phone answered, or the
// database rejected every write.
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

/**
 * Read a phone list produced by cucm-phones.php (--csv or --json).
 * Accepts either, sniffed by first character, so whichever the operator
 * carried across the airgap works without a flag.
 */
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
