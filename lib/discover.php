<?php
/**
 * discover.php - build the list of phones to poll (shared by cucm-phones.php
 * and cucm-sweep.php).
 */

require_once __DIR__ . '/config.php';

/**
 * Common Phone Profile per device name, merged across all servers.
 * Failing servers are warned about and skipped.
 *
 * @return array<string,array{uuid:?string,name:?string,server:string}>
 */
function cps_common_phone_profiles(array $cfg): array
{
    $out = [];
    foreach (cps_server_urls($cfg) as $url) {
        try {
            foreach (cps_axl_client($cfg, $url)->commonPhoneProfiles() as $name => $p) {
                if (!isset($out[$name]) || ($out[$name]['uuid'] === null && $p['uuid'] !== null)) {
                    $out[$name] = $p + ['server' => $url];
                }
            }
        } catch (Throwable $e) {
            cps_warn("$url: could not read common phone profiles: " . $e->getMessage());
        }
    }
    return $out;
}

/** Discover only the named phones via RisPort (--only-problems). */
function cps_discover_named_phones(array $cfg, array $names, string $status): array
{
    $res = CpsRisportClient::discoverNames(cps_risport_clients($cfg), $names, $status);
    if (!$res['devices'] && $res['errors']) {
        cps_die('every RisPort server failed; nothing discovered');
    }
    $rows = array_values($res['devices']);
    foreach ($rows as &$r) { $r['source'] = 'risport'; }
    unset($r);
    return cps_resolve_model_names($rows, $cfg);
}

/**
 * Discover phones from RisPort70 or the AXL database (axl-sql).
 *
 * @return array<int,array<string,mixed>>
 */
function cps_discover_phones(array $cfg, string $source, string $status, array $opts = []): array
{
    if ($source === 'risport') {
        $mode = strtolower(trim((string)($cfg['ris_partitions'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'off', 'always'], true)) {
            cps_warn("ris_partitions = '$mode' is not auto|always|off; using auto");
            $mode = 'auto';
        }
        $res = CpsRisportClient::discover(cps_risport_clients($cfg), $status, 'Phone', $mode);
        foreach ($res['errors'] as $e) {
            cps_warn("RisPort {$e['server']}: {$e['error']}");
        }
        if (!$res['devices'] && $res['errors']) {
            cps_die('every RisPort server failed; nothing discovered');
        }
        $rows = array_values($res['devices']);
        foreach ($rows as &$r) { $r['source'] = 'risport'; }
        unset($r);
        return cps_resolve_model_names($rows, $cfg);
    }

    if ($source === 'axl-sql') {
        try {
            $axl = cps_axl_client($cfg);
            $axl->discoverVersion();
        } catch (Throwable $e) {
            cps_die('AXL is not usable on ' . implode(', ', cps_server_urls($cfg)) . ': ' . $e->getMessage());
        }
        $rows = [];
        foreach ($axl->registrationDynamic() as $r) {
            $rows[] = [
                'name'          => $r['devicename'] ?? '',
                'description'   => $r['description'] ?? '',
                'model'         => $r['model'] ?? '',
                'ip'            => $r['lastknownipaddress'] ?? '',
                'node_name'     => $r['lastknownucm'] ?? '',
                // Last known location, not current registration.
                'status'        => 'LastKnown',
                'ris_timestamp' => $r['lastseen'] ?? '',
                'source'        => 'axl-sql',
            ];
        }
        return $rows;
    }

    cps_die("unknown --source '$source' (use risport or axl-sql)");
}

/**
 * Drop phones in the configured/CLI excluded subnets.
 *
 * @return array<int,array<string,mixed>> the phones still in scope
 */
function cps_apply_subnet_exclusions(array $phones, array $cfg, array $opts): array
{
    $raw = is_string($opts['exclude-subnet'] ?? null)
        ? $opts['exclude-subnet']
        : (string)($cfg['exclude_subnets'] ?? '');
    $cidrs = cps_parse_cidr_list($raw, 'exclude_subnets');
    if (!$cidrs) { return $phones; }

    $res = cps_filter_by_cidr($phones, $cidrs);
    foreach ($res['by_cidr'] as $cidr => $n) {
        // A prefix matching nothing is shown at default verbosity.
        cps_log(sprintf('exclude %-18s %d phone(s)%s', $cidr, $n,
            $n === 0 ? '  <- matched nothing, check this prefix' : ''), $n === 0 ? 1 : 2);
    }
    cps_log(sprintf('%d phone(s) excluded by subnet, %d remain',
        count($res['excluded']), count($res['kept'])));
    return $res['kept'];
}

/**
 * Keep only devices whose name starts with a pollable prefix; logs the drops.
 *
 * @return array<int,array<string,mixed>>
 */
function cps_apply_device_prefixes(array $phones, array $cfg, array $opts): array
{
    if (!empty($opts['all-devices'])) {
        cps_log('--all-devices: device-prefix filtering disabled', 2);
        return $phones;
    }
    $raw = is_string($opts['device-prefixes'] ?? null)
        ? $opts['device-prefixes']
        : (string)($cfg['poll_device_prefixes'] ?? '');
    $prefixes = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    if (!$prefixes) { return $phones; }

    $res = cps_filter_by_device_prefix($phones, $prefixes);
    if ($res['excluded']) {
        $parts = [];
        foreach ($res['by_prefix'] as $p => $n) { $parts[] = "$p=$n"; }
        cps_log(sprintf('%d device(s) skipped as not %s (%s) - no ports to poll',
            count($res['excluded']), implode('/', $prefixes), implode(', ', $parts)));
    }
    return $res['kept'];
}

/**
 * Replace numeric model enums with names from AXL's typemodel table.
 * On AXL failure the enums are kept.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function cps_resolve_model_names(array $rows, array $cfg): array
{
    $needed = false;
    foreach ($rows as $r) {
        if (isset($r['model']) && $r['model'] !== '' && ctype_digit((string)$r['model'])) { $needed = true; break; }
    }
    if (!$needed || ((string)($cfg['axl_user'] ?? '')) === '') { return $rows; }

    try {
        $names = cps_axl_client($cfg)->modelNames();
    } catch (Throwable $e) {
        cps_warn('could not read model names from AXL (models stay numeric): ' . $e->getMessage());
        return $rows;
    }
    if (!$names) { return $rows; }

    $GLOBALS['CPS_MODEL_NAMES'] = $names;
    $hit = 0;
    foreach ($rows as &$r) {
        $m = (string)($r['model'] ?? '');
        if ($m !== '' && ctype_digit($m) && isset($names[(int)$m])) {
            $r['model'] = $names[(int)$m];
            $hit++;
        }
    }
    unset($r);
    cps_log("resolved $hit model enum(s) to names (" . count($names) . ' known)', 2);
    return $rows;
}
