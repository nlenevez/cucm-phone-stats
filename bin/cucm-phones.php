#!/usr/bin/env php
<?php
/**
 * cucm-phones.php - list registered IP phones and their current addresses.
 *
 * Usage:
 *   php bin/cucm-phones.php [--config FILE] [--status Registered|UnRegistered|Any]
 *                           [--source risport|axl-sql] [--json|--csv|--table]
 *                           [--with-ip-only] [--exclude-subnet CIDR] [--all-devices]
 *
 * Examples:
 *   php bin/cucm-phones.php --csv > phones.csv
 *   php bin/cucm-phones.php --status Any --json
 *   php bin/cucm-phones.php --source axl-sql        # without RisPort
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/discover.php';

$p    = cps_parse_args($argv, ['json', 'csv', 'table', 'verbose', 'quiet', 'debug', 'with-ip-only', 'all-devices', 'help']);
$opts = $p['opts'];

if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-phones.php [options]\n"
                 . "  --config FILE                 config file\n"
                 . "  --status S                    Registered (default), UnRegistered or Any\n"
                 . "  --source risport|axl-sql      discovery source (default risport)\n"
                 . "  --json | --csv | --table      output format (default table)\n"
                 . "  --with-ip-only                drop devices without an IP\n"
                 . "  --exclude-subnet CIDR[,CIDR]  leave these subnets out\n"
                 . "  --device-prefixes SEP[,CSF]   device name prefixes (default SEP)\n"
                 . "  --all-devices                 every device type, soft clients included\n"
                 . "  --verbose                     progress on stderr\n");
    exit(0);
}

$cfg    = cps_load_config(cps_common_opts($opts));
$status = is_string($opts['status'] ?? null) ? $opts['status'] : 'Registered';
$source = is_string($opts['source'] ?? null) ? $opts['source'] : 'risport';

if (!cps_server_urls($cfg)) { cps_die('no cucm_servers configured (see cucm.conf.example)'); }

$rows = cps_discover_phones($cfg, $source, $status, $opts);
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
