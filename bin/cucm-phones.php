#!/usr/bin/env php
<?php
/**
 * cucm-phones.php - list registered IP phones and their current addresses.
 *
 * Usage:
 *   php bin/cucm-phones.php [--config FILE] [--status Registered|UnRegistered|Any]
 *                           [--source risport|axl-sql] [--json|--csv|--table]
 *                           [--with-ip-only] [--verbose]
 *
 * Examples:
 *   php bin/cucm-phones.php --csv > phones.csv
 *   php bin/cucm-phones.php --status Any --json
 *   php bin/cucm-phones.php --source axl-sql        # when RisPort is unavailable
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/discover.php';

$p    = cps_parse_args($argv, ['json', 'csv', 'table', 'verbose', 'quiet', 'debug', 'with-ip-only', 'all-devices', 'help']);
$opts = $p['opts'];

if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-phones.php [--config FILE] [--status S] [--source risport|axl-sql]\n"
                 . "       [--json|--csv|--table] [--with-ip-only] [--exclude-subnet CIDR[,CIDR]]\n"
                 . "       [--device-prefixes SEP] [--all-devices] [--verbose]\n");
    exit(0);
}

$cfg    = cps_load_config(cps_common_opts($opts));
$status = is_string($opts['status'] ?? null) ? $opts['status'] : 'Registered';
$source = is_string($opts['source'] ?? null) ? $opts['source'] : 'risport';

if (!cps_server_urls($cfg)) { cps_die('no cucm_servers configured (see cucm.conf.example)'); }

$rows = cps_discover_phones($cfg, $source, $status);
$rows = cps_apply_device_prefixes($rows, $cfg, $opts);
$rows = cps_apply_subnet_exclusions($rows, $cfg, $opts);

if (!empty($opts['with-ip-only'])) {
    $rows = array_values(array_filter($rows, fn($r) => !empty($r['ip'])));
}

usort($rows, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));

if (!empty($opts['json'])) {
    echo cps_json($rows);
} elseif (!empty($opts['csv'])) {
    echo cps_render_csv(array_map(fn($r) => array_diff_key($r, ['ip_addresses' => 1]), $rows));
} else {
    echo cps_render_table(
        array_map(fn($r) => [
            'name'    => $r['name'],
            'ip'      => $r['ip'] ?? '',
            'status'  => $r['status'] ?? '',
            'model'   => $r['model'] ?? '',
            'node'    => $r['node_name'] ?? '',
            'http'    => (string)($r['http_supported'] ?? ''),
            'descr'   => $r['description'] ?? '',
        ], $rows)
    );
    fwrite(STDERR, sprintf("\n%d phones (%d with an IP address)\n",
        count($rows), count(array_filter($rows, fn($r) => !empty($r['ip'])))));
}
