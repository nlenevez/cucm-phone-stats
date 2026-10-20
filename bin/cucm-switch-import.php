#!/usr/bin/env php
<?php
/**
 * cucm-switch-import.php - load "show interface status" captures.
 *
 * Usage:
 *   php bin/cucm-switch-import.php [--config FILE] [--switch NAME]
 *       [--captured-at "YYYY-MM-DD HH:MM:SS"] [--dry-run] [--json] PATH...
 *
 * PATH is a file or a directory (every non-hidden file in it). The switch
 * is named by a "HOST#show interface status" prompt in the text if there is
 * one, else by the file name minus .txt/.log/.out/.json - which is what an
 * Ansible playbook writing switch-outputs/<inventory_hostname>.txt gives.
 * --switch overrides that for a single file.
 *
 * The capture time is the file's mtime (tar keeps it across the airgap)
 * unless --captured-at is given. Identical output already imported for a
 * switch is not stored again; its capture time is moved forward.
 *
 * --dry-run parses and prints without touching the database, so a new
 * capture format can be checked on a box with no pdo_mysql.
 *
 * Examples:
 *   php bin/cucm-switch-import.php switch-outputs/
 *   php bin/cucm-switch-import.php --dry-run --json switch-outputs/sw01.txt
 *   php bin/cucm-report.php switch-mismatch
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/switch.php';

$p    = cps_parse_args($argv, ['dry-run', 'json', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help']) || !$p['args']) {
    fwrite(STDERR, "Usage: php bin/cucm-switch-import.php [--config FILE] [--switch NAME]\n"
                 . "       [--captured-at 'YYYY-MM-DD HH:MM:SS'] [--dry-run] [--json] PATH...\n");
    exit($p['args'] ? 0 : 1);
}

$files = [];
foreach ($p['args'] as $path) {
    if (is_dir($path)) {
        $list = glob(rtrim($path, '/') . '/*') ?: [];
        sort($list);
        foreach ($list as $f) {
            if (is_file($f) && !str_starts_with(basename($f), '.')) { $files[] = $f; }
        }
    } elseif (is_file($path)) {
        $files[] = $path;
    } else {
        cps_die("no such file or directory: $path");
    }
}
if (!$files) { cps_die('no capture files found'); }

$override = is_string($opts['switch'] ?? null) ? $opts['switch'] : null;
if ($override !== null && count($files) > 1) {
    cps_die('--switch names one switch, so it needs exactly one file');
}
$at = is_string($opts['captured-at'] ?? null) ? $opts['captured-at'] : null;
if ($at !== null && !preg_match('/^\d{4}-\d\d-\d\d( \d\d:\d\d(:\d\d)?)?$/', $at)) {
    cps_die("--captured-at wants 'YYYY-MM-DD HH:MM:SS', got '$at'");
}

$dry = !empty($opts['dry-run']);
$db  = null;
if (!$dry) {
    require_once dirname(__DIR__) . '/lib/db.php';
    $db = new CpsDb(cps_load_config(cps_common_opts($opts)));
} else {
    cps_common_opts($opts);
}

$summary = [];
$parsedOut = [];
$skipped = 0;
foreach ($files as $file) {
    $raw = file_get_contents($file);
    if ($raw === false) { cps_warn("$file: unreadable"); $skipped++; continue; }
    $text = cps_switch_unwrap($raw);
    $fromFile = $override ?? (string)preg_replace('/\.(txt|log|out|json)$/i', '', basename($file));

    foreach (cps_switch_split($text, $fromFile) as $block) {
        $name   = $override ?? $block['switch'];
        $parsed = cps_parse_interface_status($block['text']);
        $row = ['switch' => $name, 'file' => $file, 'ports' => count($parsed['ports']),
                'unparsed' => $parsed['unparsed'], 'status' => $dry ? 'parsed' : null, 'capture_id' => null];

        // A block with no ports is not a capture of an idle switch - it is
        // the wrong command, an error message, or a login banner. Storing it
        // would make it the newest capture and hide the last good one.
        if (!$parsed['ports']) {
            cps_warn("$file ($name): no interface rows found - not imported");
            $row['status'] = 'skipped';
            $skipped++;
        } elseif (!$dry) {
            try {
                $res = $db->importSwitchCapture($name, $block['text'], $parsed, $file,
                                                $at ?? (int)filemtime($file));
                $row['status'] = $res['status'];
                $row['capture_id'] = $res['id'];
            } catch (Throwable $e) {
                cps_warn("$file ($name): " . $e->getMessage());
                $row['status'] = 'failed';
                $skipped++;
            }
        }
        if ($parsed['unparsed'] > 0) {
            cps_warn("$file ($name): {$parsed['unparsed']} line(s) looked like ports but did not parse");
        }
        $summary[] = $row;
        if ($dry) { $parsedOut[] = ['switch' => $name, 'file' => $file] + $parsed; }
    }
}

if (!empty($opts['json'])) {
    echo cps_json($dry ? $parsedOut : $summary);
} else {
    echo cps_render_table($summary, ['switch', 'file', 'ports', 'unparsed', 'status', 'capture_id']);
    $new = count(array_filter($summary, fn($r) => $r['status'] === 'new'));
    fwrite(STDERR, sprintf("\n%d capture(s): %d new, %d unchanged, %d skipped\n", count($summary), $new,
        count(array_filter($summary, fn($r) => $r['status'] === 'unchanged')), $skipped));
}
exit($skipped ? 2 : 0);
