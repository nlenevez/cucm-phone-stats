#!/usr/bin/env php
<?php
/**
 * cucm-prune.php - delete sweeps and switch captures older than N days.
 *
 * Usage:
 *   php bin/cucm-prune.php --keep-days N [--apply] [--config FILE]
 *
 * Dry run unless --apply. The account needs DELETE (only this script uses
 * it). Never deletes the newest completed sweep or the newest capture of a
 * switch. Phone inventory and the profile_changes log are kept.
 *
 * Examples:
 *   php bin/cucm-prune.php --keep-days 7
 *   php bin/cucm-prune.php --keep-days 7 --apply
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';

$p    = cps_parse_args($argv, ['apply', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-prune.php --keep-days N [--apply] [--config FILE]\n");
    exit(0);
}
$days = is_string($opts['keep-days'] ?? null) && ctype_digit($opts['keep-days']) ? (int)$opts['keep-days'] : 0;
if ($days < 1) { cps_die('--keep-days N is required (N >= 1)'); }

$db    = new CpsDb(cps_load_config(cps_common_opts($opts)));
$apply = !empty($opts['apply']);
$keep  = $db->latestSweepId() ?? 0;

$sweeps = array_map('intval', array_column($db->query(
    'SELECT id FROM sweeps WHERE started_at < NOW() - INTERVAL ? DAY AND id <> ? ORDER BY id',
    [$days, $keep]), 'id'));
$captures = array_map('intval', array_column($db->query(
    'SELECT c.id FROM switch_captures c
     LEFT JOIN v_switch_latest_capture l ON l.id = c.id
     WHERE c.captured_at < NOW() - INTERVAL ? DAY AND l.id IS NULL ORDER BY c.id',
    [$days]), 'id'));

if (!$apply) {
    printf("DRY RUN: would delete %d sweep(s) and %d switch capture(s) older than %d days. Re-run with --apply.\n",
        count($sweeps), count($captures), $days);
    exit(0);
}

// One sweep per statement: each cascades into thousands of child rows.
$delSweep = $db->pdo()->prepare('DELETE FROM sweeps WHERE id = ?');
foreach ($sweeps as $id) {
    $delSweep->execute([$id]);
    cps_log("deleted sweep #$id", 2);
}
$delCap = $db->pdo()->prepare('DELETE FROM switch_captures WHERE id = ?');
foreach ($captures as $id) { $delCap->execute([$id]); }

printf("deleted %d sweep(s) and %d switch capture(s) older than %d days\n", count($sweeps), count($captures), $days);
