#!/usr/bin/env php
<?php
/**
 * cucm-report.php - reports over the collected sweeps.
 *
 * Usage:
 *   php bin/cucm-report.php <report> [--sweep N|--all] [--json|--csv|--table] [--config FILE]
 *
 * Reports:
 *   speed-mismatch   phones whose access port speed/duplex differs from the
 *                    network port (both ports up)
 *   errors           ports with any non-zero error counter
 *   error-trend      ports whose error counters grew between sweeps
 *   phones           current inventory
 *   sweeps           collection history
 *
 * By default each report covers the most recent completed sweep;
 * --all covers every sweep, --sweep N a specific one. error-trend ignores
 * --sweep, since a trend needs at least two.
 *
 * Examples:
 *   php bin/cucm-report.php speed-mismatch
 *   php bin/cucm-report.php errors --min 100 --csv > errors.csv
 *   php bin/cucm-report.php error-trend --since 2026-09-01
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';

$p      = cps_parse_args($argv, ['json', 'csv', 'table', 'all', 'verbose', 'quiet', 'debug', 'help', 'include-resets']);
$opts   = $p['opts'];
$report = $p['args'][0] ?? null;

$known = ['speed-mismatch', 'errors', 'error-trend', 'phones', 'sweeps'];
if (!empty($opts['help']) || $report === null) {
    fwrite(STDERR, "Usage: php bin/cucm-report.php <" . implode('|', $known) . "> [--sweep N|--all]\n"
                 . "       [--min N] [--since YYYY-MM-DD] [--json|--csv|--table]\n");
    exit($report === null ? 1 : 0);
}
if (!in_array($report, $known, true)) {
    cps_die("unknown report '$report'; known: " . implode(', ', $known));
}

$cfg = cps_load_config(cps_common_opts($opts));
$db  = new CpsDb($cfg);

// Resolve the sweep scope once, so every report answers "which run?" the
// same way and a report can never quietly mix sweeps.
$sweepId = null;
if (!empty($opts['all'])) {
    $sweepId = null;
} elseif (isset($opts['sweep']) && is_string($opts['sweep'])) {
    $sweepId = (int)$opts['sweep'];
} else {
    $sweepId = $db->latestSweepId();
    if ($sweepId === null && $report !== 'sweeps') {
        cps_die('no completed sweep in the database yet - run bin/cucm-sweep.php first');
    }
}

[$sql, $params, $cols] = cps_build_report($report, $sweepId, $opts);
$rows = $db->query($sql, $params);

if (!empty($opts['json']))      { echo cps_json($rows); }
elseif (!empty($opts['csv']))   { echo cps_render_csv($rows); }
else {
    echo cps_render_table($rows, $cols);
    fwrite(STDERR, sprintf("\n%d row(s)%s\n", count($rows),
        $sweepId !== null && $report !== 'error-trend' && $report !== 'sweeps' ? " (sweep #$sweepId)" : ''));
}

/** @return array{0:string,1:array,2:array} sql, params, preferred columns */
function cps_build_report(string $report, ?int $sweepId, array $opts): array
{
    $params = [];
    switch ($report) {
        case 'speed-mismatch':
            $sql = 'SELECT device_name, description, model, ip_address,
                           network_speed_mbps, network_duplex,
                           access_speed_mbps, access_duplex, mismatch_kind, started_at
                    FROM v_port_speed_mismatch';
            if ($sweepId !== null) { $sql .= ' WHERE sweep_id = ?'; $params[] = $sweepId; }
            $sql .= ' ORDER BY device_name';
            return [$sql, $params, ['device_name', 'ip_address', 'model',
                'network_speed_mbps', 'network_duplex', 'access_speed_mbps', 'access_duplex', 'mismatch_kind']];

        case 'errors':
            $min = isset($opts['min']) && is_string($opts['min']) ? (int)$opts['min'] : 1;
            $sql = 'SELECT device_name, description, ip_address, port, speed_mbps, duplex,
                           errors_total, rx_crc_err, rx_align_err, rx_short_err, rx_long_err,
                           tx_collisions, tx_late_collision, tx_excess_collision,
                           rx_total_pkt, tx_total_pkt, started_at
                    FROM v_port_errors WHERE errors_total >= ?';
            $params[] = $min;
            if ($sweepId !== null) { $sql .= ' AND sweep_id = ?'; $params[] = $sweepId; }
            $sql .= ' ORDER BY errors_total DESC, device_name';
            return [$sql, $params, ['device_name', 'ip_address', 'port', 'speed_mbps', 'duplex',
                'errors_total', 'rx_crc_err', 'tx_collisions', 'tx_late_collision', 'rx_total_pkt']];

        case 'error-trend':
            // Deliberately not scoped to one sweep: a delta is between two.
            // --include-resets shows the reboot rows too, which is what you
            // want when a phone's counters keep clearing and you are trying
            // to work out why.
            $where = ['1=1'];
            if (empty($opts['include-resets'])) { $where[] = 'errors_delta > 0'; }
            else { $where[] = '(errors_delta > 0 OR counter_reset = 1)'; }
            if (isset($opts['since']) && is_string($opts['since'])) {
                $where[] = 'started_at >= ?';
                $params[] = $opts['since'];
            }
            $sql = 'SELECT device_name, description, port, prev_started_at, started_at,
                           prev_errors_total, errors_total, errors_delta, counter_reset
                    FROM v_port_error_deltas WHERE ' . implode(' AND ', $where)
                 . ' ORDER BY errors_delta DESC, started_at DESC, device_name';
            return [$sql, $params, ['device_name', 'port', 'prev_started_at', 'started_at',
                'prev_errors_total', 'errors_total', 'errors_delta', 'counter_reset']];

        case 'phones':
            $sql = 'SELECT device_name, mac_address, description, model, dir_number,
                           serial_number, last_ip, first_seen, last_seen
                    FROM phones ORDER BY device_name';
            return [$sql, [], ['device_name', 'last_ip', 'model', 'dir_number', 'description', 'last_seen']];

        case 'sweeps':
            $sql = 'SELECT id, started_at, finished_at, source, phones_discovered,
                           phones_polled, phones_ok, phones_failed
                    FROM sweeps ORDER BY started_at DESC LIMIT 50';
            return [$sql, [], ['id', 'started_at', 'finished_at', 'source',
                'phones_discovered', 'phones_polled', 'phones_ok', 'phones_failed']];
    }
    cps_die("unhandled report '$report'");
}
