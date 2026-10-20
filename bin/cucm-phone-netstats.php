#!/usr/bin/env php
<?php
/**
 * cucm-phone-netstats.php - port statistics from a single phone, by IP address.
 *
 * Usage:
 *   php bin/cucm-phone-netstats.php <phone-ip> [--adapters network,access]
 *                                   [--json] [--raw] [--normalised] [--config FILE]
 *
 * Examples:
 *   php bin/cucm-phone-netstats.php 10.0.0.50
 *   php bin/cucm-phone-netstats.php 10.0.0.50 --json
 *   php bin/cucm-phone-netstats.php 10.0.0.50 --adapters network --raw
 */

require_once dirname(__DIR__) . '/lib/config.php';

$p    = cps_parse_args($argv, ['json', 'raw', 'normalised', 'normalized', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
$ip   = $p['args'][0] ?? null;

if (!empty($opts['help']) || $ip === null) {
    fwrite(STDERR, "Usage: php bin/cucm-phone-netstats.php <phone-ip> [--adapters a,b] [--json] [--raw] [--normalised]\n");
    exit($ip === null ? 1 : 0);
}

$cfg      = cps_load_config(cps_common_opts($opts));
$adapters = is_string($opts['adapters'] ?? null)
    ? preg_split('/[\s,]+/', $opts['adapters'], -1, PREG_SPLIT_NO_EMPTY)
    : ['network', 'access'];

$fetcher = cps_phone_fetcher($cfg, $adapters);
$showNorm = !empty($opts['normalised']) || !empty($opts['normalized']);

// --raw: page bodies untouched, no parsing.
if (!empty($opts['raw'])) {
    foreach ($adapters as $a) {
        $url = cps_phone_url($ip, CPS_ADAPTERS[$a], (string)$cfg['phone_url_base']);
        echo "===== $a  ($url) =====\n";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => (int)$cfg['phone_timeout'],
            CURLOPT_FOLLOWLOCATION => true,
        ] + cps_curl_scheme_limits((string)$cfg['phone_url_base']));
        if (((string)$cfg['phone_user']) !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $cfg['phone_user'] . ':' . $cfg['phone_pass']);
        }
        $body = curl_exec($ch);
        if ($body === false) { echo 'curl error: ' . curl_error($ch) . "\n"; }
        else { echo $body . "\n"; }
        curl_close($ch);
    }
    exit(0);
}

$out      = [];
$anyOk    = false;
foreach ($adapters as $a) {
    $page = $fetcher->fetchOne($ip, CPS_ADAPTERS[$a]);
    if (!empty($page['ok'])) { $anyOk = true; }
    $entry = [
        'adapter'   => CPS_ADAPTERS[$a],
        'ok'        => (bool)($page['ok'] ?? false),
        'http_code' => $page['http_code'] ?? null,
    ];
    if (!empty($page['ok'])) {
        $entry['stats'] = $page['pairs'];
        if ($showNorm || !empty($opts['json'])) {
            if (in_array($a, CPS_PORT_ADAPTERS, true)) {
                $n = cps_normalise_port($page['pairs']);
                $entry['normalised'] = [
                    'link_up'      => $n['link']['link_up'],
                    'speed_mbps'   => $n['link']['speed_mbps'],
                    'duplex'       => $n['link']['duplex'],
                    'errors_total' => $n['errors_total'],
                    'counters'     => $n['counters'],
                    'unmapped_labels' => $n['unmapped'],
                ];
            } else {
                $entry['normalised'] = cps_normalise_device($page['pairs']);
            }
        }
    } else {
        $entry['error']      = $page['error'] ?? 'unknown error';
        $entry['error_kind'] = $page['error_kind'] ?? null;
    }
    $out[$a] = $entry;
}

if (!empty($opts['json'])) {
    echo cps_json(['ip' => $ip, 'adapters' => $out]);
    exit($anyOk ? 0 : 2);
}

foreach ($out as $a => $entry) {
    echo "\n=== $a  ({$entry['adapter']}) ===\n";
    if (!$entry['ok']) {
        echo "  FAILED: {$entry['error']}\n";
        continue;
    }
    if ($showNorm && isset($entry['normalised'])) {
        $n = $entry['normalised'];
        if (array_key_exists('speed_mbps', $n)) {
            printf("  link: %s, speed %s, duplex %s, errors %d\n",
                $n['link_up'] === null ? 'unknown' : ($n['link_up'] ? 'up' : 'down'),
                $n['speed_mbps'] ?? '?', $n['duplex'] ?? '?', $n['errors_total']);
        } else {
            echo cps_render_kv($n);
        }
        echo "\n";
    }
    echo cps_render_kv($entry['stats']);
}
exit($anyOk ? 0 : 2);
