<?php
/**
 * discover.php - build the list of phones to poll.
 *
 * Shared by cucm-phones.php (report the list) and cucm-sweep.php (poll it),
 * so the two can never disagree about what "a registered phone" means.
 */

require_once __DIR__ . '/config.php';

/**
 * Discover phones from RisPort70 (default) or, as a fallback, from the AXL
 * configuration database.
 *
 * @return array<int,array<string,mixed>>
 */
function cps_discover_phones(array $cfg, string $source, string $status, array $opts = []): array
{
    if ($source === 'risport') {
        $parts = (string)($cfg['ris_partitions'] ?? 'auto');
        $mode  = in_array(strtolower(trim($parts)), ['auto', 'off', 'always'], true)
            ? strtolower(trim($parts))
            : preg_split('/[\s,]+/', $parts, -1, PREG_SPLIT_NO_EMPTY);
        // Enumerate only the device types that will actually be polled.
        // Splitting the estate to recover CSF/TCT/BOT rows that the prefix
        // filter then discards spends the rate-limit budget on nothing.
        $prefixes = [];
        if (empty($opts['all-devices'])) {
            $raw = is_string($opts['device-prefixes'] ?? null)
                ? $opts['device-prefixes']
                : (string)($cfg['poll_device_prefixes'] ?? '');
            $prefixes = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        }
        if ($prefixes) {
            // Say what is being asked for. The "N skipped as not SEP" line
            // no longer appears once the query itself is scoped, and a
            // quietly smaller number is exactly what makes an operator
            // wonder whether devices went missing.
            cps_log('querying only ' . implode('*, ', $prefixes) . '* device names');
        }
        $res = CpsRisportClient::discover(cps_risport_clients($cfg), $status, 'Phone', $mode, $prefixes);
        foreach ($res['per_server'] ?? [] as $srv => $st) {
            $enum = $st['enumeration'] ?? null;
            if ($enum && !empty($enum['truncated'])) {
                cps_warn(sprintf('%s: still short after partitioning - %d returned, %s claimed',
                    $srv, $enum['returned'], $enum['total_devices_found'] ?? '?'));
            }
        }
        foreach ($res['errors'] as $e) {
            cps_warn("RisPort {$e['server']}: {$e['error']}");
        }
        if (!$res['devices'] && $res['errors']) {
            cps_die('every RisPort server failed; nothing discovered');
        }
        $rows = array_values($res['devices']);
        foreach ($rows as &$r) { $r['source'] = 'risport'; }
        return $rows;
    }

    if ($source === 'axl-sql') {
        $axl  = cps_axl_client($cfg);
        $rows = [];
        foreach ($axl->registrationDynamic() as $r) {
            $rows[] = [
                'name'          => $r['devicename'] ?? '',
                'description'   => $r['description'] ?? '',
                'model'         => $r['model'] ?? '',
                'ip'            => $r['lastknownipaddress'] ?? '',
                'node_name'     => $r['lastknownucm'] ?? '',
                // RegistrationDynamic records where a device last was, not
                // that it is there now, so the status is explicitly not
                // claimed to be "Registered".
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
 * Apply the configured/CLI subnet exclusions to a discovered phone list.
 *
 * Shared by cucm-phones.php and cucm-sweep.php on purpose: if the listing
 * and the sweep disagreed about what is in scope, a CSV carried between
 * them would quietly re-introduce the phones the operator excluded.
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
        // Report every prefix, including the ones that matched nothing. A
        // zero is the signal that a CIDR is wrong or the estate moved, and
        // it is invisible if only non-zero counts are printed.
        cps_log(sprintf('exclude %-18s %d phone(s)%s', $cidr, $n,
            $n === 0 ? '  <- matched nothing, check this prefix' : ''));
    }
    cps_log(sprintf('%d phone(s) excluded by subnet, %d remain',
        count($res['excluded']), count($res['kept'])));
    return $res['kept'];
}

/**
 * Restrict a discovered list to pollable device types.
 *
 * Reports what it dropped, broken down by prefix. A filter that shrinks the
 * estate without saying so is how a partial sweep gets mistaken for a
 * complete one - and here it is also the answer to "why do I see 2,000
 * phones when CUCM says 3,000".
 *
 * @return array<int,array<string,mixed>>
 */
function cps_apply_device_prefixes(array $phones, array $cfg, array $opts): array
{
    if (!empty($opts['all-devices'])) {
        cps_log('--all-devices: device-prefix filtering disabled');
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
