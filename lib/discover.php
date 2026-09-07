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
function cps_discover_phones(array $cfg, string $source, string $status): array
{
    if ($source === 'risport') {
        $res = CpsRisportClient::discover(cps_risport_clients($cfg), $status, 'Phone');
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
