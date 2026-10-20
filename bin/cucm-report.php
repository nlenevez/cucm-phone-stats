#!/usr/bin/env php
<?php
/**
 * cucm-report.php - reports over the collected sweeps (default: latest completed sweep).
 *
 * Usage:
 *   php bin/cucm-report.php <report> [--sweep N|--all] [--json|--csv|--table] [--config FILE]
 *
 * Reports: speed-mismatch, errors, error-trend, switch-mismatch, switch-compare,
 *          switch-captures, phones, sweeps, uplinks, labels
 *
 * Examples:
 *   php bin/cucm-report.php speed-mismatch
 *   php bin/cucm-report.php errors --min 100 --csv > errors.csv
 *   php bin/cucm-report.php error-trend --since 2026-09-01
 *   php bin/cucm-report.php labels --unmapped-only
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';

$p      = cps_parse_args($argv, ['json', 'csv', 'table', 'all', 'verbose', 'quiet', 'debug', 'help',
                                'include-resets', 'unmapped-only', 'history']);
$opts   = $p['opts'];
$report = $p['args'][0] ?? null;

$known = ['speed-mismatch', 'errors', 'error-trend', 'switch-mismatch', 'switch-compare', 'switch-captures',
          'phones', 'sweeps', 'uplinks', 'labels'];
if (!empty($opts['help']) || $report === null) {
    fwrite(STDERR, "Usage: php bin/cucm-report.php <" . implode('|', $known) . "> [options]\n"
                 . "  --sweep N | --all     one sweep, or every sweep (default latest)\n"
                 . "  --json | --csv | --table\n"
                 . "  --min N               errors: minimum error count\n"
                 . "  --since YYYY-MM-DD    error-trend: start date\n"
                 . "  --history             error-trend: every transition, not one row per port\n"
                 . "  --include-resets      error-trend: include counter resets\n"
                 . "  --result R            switch-compare: filter by result\n"
                 . "  --grep TEXT           labels: filter by label\n"
                 . "  --unmapped-only       labels: only labels not mapped\n");
    exit($report === null ? 1 : 0);
}
if (!in_array($report, $known, true)) {
    cps_die("unknown report '$report'; known: " . implode(', ', $known));
}

$cfg = cps_load_config(cps_common_opts($opts));
$db  = new CpsDb($cfg);

$sweepId = null;
if (!empty($opts['all'])) {
    $sweepId = null;
} elseif (isset($opts['sweep']) && is_string($opts['sweep'])) {
    $sweepId = (int)$opts['sweep'];
} else {
    $sweepId = $db->latestSweepId();
    if ($sweepId === null && $report !== 'sweeps' && $report !== 'switch-captures') {
        cps_die('no completed sweep in the database yet - run bin/cucm-sweep.php first');
    }
}

// 'labels' decodes stored label/value pairs rather than querying a view.
if ($report === 'labels') {
    cps_report_labels($db, $sweepId, $opts);
    exit(0);
}

[$sql, $params, $cols] = cps_build_report($report, $sweepId, $opts);
$res     = $db->queryWithColumns($sql, $params);
$rows    = $res['rows'];
$sqlCols = $res['columns'];

if (!empty($opts['json']))      { echo cps_json($rows); }
// CSV always has a header row, even with no data.
elseif (!empty($opts['csv']))   { echo cps_render_csv($rows, $sqlCols); }
else {
    echo cps_render_table($rows, $cols);
    fwrite(STDERR, sprintf("\n%d row(s)%s\n", count($rows),
        $sweepId !== null && !in_array($report, ['error-trend', 'sweeps', 'switch-captures'], true) ? " (sweep #$sweepId)" : ''));
}

/** Every label the phones returned, with how each is mapped. */
function cps_report_labels(CpsDb $db, ?int $sweepId, array $opts): void
{
    $sql = 'SELECT adapter, pairs FROM page_fetches WHERE ok = 1 AND pairs IS NOT NULL';
    $params = [];
    if ($sweepId !== null) { $sql .= ' AND sweep_id = ?'; $params[] = $sweepId; }
    $rows = $db->query($sql, $params);

    $seen = [];
    foreach ($rows as $r) {
        $pairs = json_decode((string)$r['pairs'], true);
        if (!is_array($pairs)) { continue; }
        foreach ($pairs as $label => $value) {
            $key = $r['adapter'] . "\0" . $label;
            if (!isset($seen[$key])) {
                $seen[$key] = ['adapter' => $r['adapter'], 'label' => (string)$label,
                               'count' => 0, 'example' => (string)$value];
            }
            $seen[$key]['count']++;
        }
    }

    $filter = is_string($opts['grep'] ?? null) ? mb_strtolower($opts['grep']) : null;
    $out = [];
    foreach ($seen as $e) {
        $k = cps_norm_key($e['label']);
        $mapped = CPS_COUNTER_MAP[$k]
            ?? CPS_NEIGHBOUR_MAP[$k]
            ?? CPS_DEVICE_MAP[$k]
            ?? (in_array($k, CPS_LINK_LABELS, true) ? 'link state' : null);
        if ($mapped === null && preg_match('/^(cdp|lldp)/', $k)) {
            $mapped = 'neighbour (matched by fallback)';
        }
        if (!empty($opts['unmapped-only']) && $mapped !== null) { continue; }
        if ($filter !== null && !str_contains(mb_strtolower($e['label']), $filter)) { continue; }
        $out[] = [
            'adapter'   => $e['adapter'],
            'label'     => $e['label'],
            'seen_on'   => $e['count'],
            'mapped_to' => $mapped ?? '- NOT MAPPED -',
            'example'   => mb_substr($e['example'], 0, 40),
        ];
    }
    usort($out, fn($a, $b) => [$a['adapter'], -$a['seen_on']] <=> [$b['adapter'], -$b['seen_on']]);

    if (!empty($opts['json']))    { echo cps_json($out); }
    elseif (!empty($opts['csv'])) { echo cps_render_csv($out, ['adapter','label','seen_on','mapped_to','example']); }
    else {
        echo cps_render_table($out, ['adapter', 'label', 'seen_on', 'mapped_to', 'example']);
        $un = count(array_filter($out, fn($r) => $r['mapped_to'] === '- NOT MAPPED -'));
        fwrite(STDERR, sprintf("\n%d distinct labels, %d not mapped\n", count($out), $un));
    }
}

/** @return array{0:string,1:array,2:array} sql, params, preferred columns */
function cps_build_report(string $report, ?int $sweepId, array $opts): array
{
    $params = [];
    switch ($report) {
        case 'speed-mismatch':
            $sql = 'SELECT device_name, description, model, ip_address,
                           switch_name, switch_port, neighbour_source,
                           network_speed_mbps, network_duplex,
                           access_speed_mbps, access_duplex, mismatch_kind, started_at
                    FROM v_port_speed_mismatch';
            if ($sweepId !== null) { $sql .= ' WHERE sweep_id = ?'; $params[] = $sweepId; }
            $sql .= ' ORDER BY device_name';
            return [$sql, $params, ['device_name', 'ip_address', 'switch_name', 'switch_port',
                'network_speed_mbps', 'network_duplex', 'access_speed_mbps', 'access_duplex', 'mismatch_kind']];

        case 'errors':
            $min = isset($opts['min']) && is_string($opts['min']) ? (int)$opts['min'] : 1;
            $sql = 'SELECT device_name, description, ip_address,
                           switch_name, switch_port, neighbour_source,
                           port, speed_mbps, duplex,
                           errors_total, rx_crc_err, rx_align_err, rx_short_err, rx_long_err,
                           tx_collisions, tx_late_collision, tx_excess_collision,
                           rx_total_pkt, tx_total_pkt, started_at
                    FROM v_port_errors WHERE errors_total >= ?';
            $params[] = $min;
            if ($sweepId !== null) { $sql .= ' AND sweep_id = ?'; $params[] = $sweepId; }
            $sql .= ' ORDER BY errors_total DESC, device_name';
            return [$sql, $params, ['device_name', 'ip_address', 'switch_name', 'switch_port',
                'port', 'speed_mbps', 'duplex', 'errors_total', 'rx_crc_err', 'tx_collisions']];

        case 'error-trend':
            // Not sweep-scoped: a delta spans two sweeps.
            $where = ['1=1'];
            if (empty($opts['include-resets'])) { $where[] = 'errors_delta > 0'; }
            else { $where[] = '(errors_delta > 0 OR counter_reset = 1)'; }
            if (isset($opts['since']) && is_string($opts['since'])) {
                $where[] = 'started_at >= ?';
                $params[] = $opts['since'];
            }
            $inner = 'SELECT device_name, description, switch_name, switch_port, phone_id, port,
                             prev_started_at, started_at,
                             prev_errors_total, errors_total, errors_delta, counter_reset
                      FROM v_port_error_deltas WHERE ' . implode(' AND ', $where);

            if (!empty($opts['history'])) {
                return [$inner . ' ORDER BY device_name, port, started_at DESC',
                    $params,
                    ['device_name', 'switch_name', 'switch_port', 'port', 'started_at',
                     'prev_errors_total', 'errors_total', 'errors_delta', 'counter_reset']];
            }

            // Default: one row per phone and port, the most recent growth.
            $sql = 'SELECT device_name, description, switch_name, switch_port, port,
                           prev_started_at, started_at,
                           prev_errors_total, errors_total, errors_delta, counter_reset,
                           times_grown, total_growth
                    FROM (
                      SELECT t.*,
                             ROW_NUMBER() OVER (PARTITION BY t.phone_id, t.port
                                                ORDER BY t.started_at DESC) AS rn,
                             COUNT(*)      OVER (PARTITION BY t.phone_id, t.port) AS times_grown,
                             SUM(t.errors_delta) OVER (PARTITION BY t.phone_id, t.port) AS total_growth
                      FROM (' . $inner . ') t
                    ) x
                    WHERE x.rn = 1
                    ORDER BY x.errors_delta DESC, x.device_name';
            return [$sql, $params, ['device_name', 'switch_name', 'switch_port', 'port',
                'prev_errors_total', 'errors_total', 'errors_delta', 'times_grown', 'total_growth']];

        case 'switch-mismatch':
        case 'switch-compare':
            $sql = 'SELECT device_name, description, ip_address, switch_name, switch_port,
                           switch_key, port_key, switch_if, switch_if_name, switch_status, switch_vlan,
                           phone_speed_mbps, phone_duplex, switch_speed, switch_duplex,
                           result, captured_at, started_at
                    FROM ' . ($report === 'switch-mismatch' ? 'v_switch_port_mismatch' : 'v_switch_port_compare')
                 . ' WHERE 1=1';
            if ($sweepId !== null) { $sql .= ' AND sweep_id = ?'; $params[] = $sweepId; }
            if ($report === 'switch-compare' && isset($opts['result']) && is_string($opts['result'])) {
                $sql .= ' AND result = ?';
                $params[] = $opts['result'];
            }
            $sql .= ' ORDER BY result, switch_name, switch_port, device_name';
            return [$sql, $params, ['device_name', 'switch_name', 'switch_key', 'switch_port', 'switch_status',
                'phone_speed_mbps', 'phone_duplex', 'switch_speed', 'switch_duplex', 'result']];

        case 'switch-captures':
            $sql = 'SELECT c.id, c.switch_name, c.switch_key, c.switch_ip, c.source_file, c.captured_at, c.imported_at,
                           c.ports_parsed, c.lines_unparsed,
                           (l.id IS NOT NULL) AS is_latest
                    FROM switch_captures c
                    LEFT JOIN v_switch_latest_capture l ON l.id = c.id
                    ORDER BY c.switch_key, c.captured_at DESC, c.id DESC';
            return [$sql, [], ['id', 'switch_name', 'switch_key', 'captured_at', 'ports_parsed', 'lines_unparsed',
                'is_latest', 'source_file']];

        case 'phones':
            $sql = 'SELECT p.device_name, p.mac_address, p.description, p.model, p.dir_number,
                           p.serial_number, p.last_ip, p.first_seen, p.last_seen,
                           pc.common_phone_profile_name, pc.common_phone_profile_uuid
                    FROM phones p LEFT JOIN phone_config pc ON pc.phone_id = p.id
                    ORDER BY p.device_name';
            return [$sql, [], ['device_name', 'last_ip', 'model', 'dir_number', 'description',
                'common_phone_profile_name', 'common_phone_profile_uuid', 'last_seen']];

        case 'uplinks':
            $sql = 'SELECT p.device_name, p.description, r.ip_address,
                           u.switch_name, u.switch_port, u.switch_ip, u.neighbour_source,
                           u.cdp_neighbour, u.cdp_neighbour_port,
                           u.lldp_neighbour, u.lldp_neighbour_port, u.collected_at
                    FROM uplinks u
                    JOIN phones p ON p.id = u.phone_id
                    LEFT JOIN registrations r ON r.sweep_id = u.sweep_id AND r.phone_id = u.phone_id';
            if ($sweepId !== null) { $sql .= ' WHERE u.sweep_id = ?'; $params[] = $sweepId; }
            $sql .= ' ORDER BY u.switch_name, u.switch_port, p.device_name';
            return [$sql, $params, ['device_name', 'ip_address', 'switch_name', 'switch_port',
                'neighbour_source', 'description']];

        case 'sweeps':
            $sql = 'SELECT id, started_at, finished_at, source, phones_discovered,
                           phones_polled, phones_ok, phones_failed
                    FROM sweeps ORDER BY started_at DESC LIMIT 50';
            return [$sql, [], ['id', 'started_at', 'finished_at', 'source',
                'phones_discovered', 'phones_polled', 'phones_ok', 'phones_failed']];
    }
    cps_die("unhandled report '$report'");
}
