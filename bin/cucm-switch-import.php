#!/usr/bin/env php
<?php
/**
 * cucm-switch-import.php - load "show interface status" captures from files or directories.
 *
 * Usage:
 *   php bin/cucm-switch-import.php [--config FILE] [--switch NAME] [--name-regex RE]
 *       [--captured-at "YYYY-MM-DD HH:MM:SS"] [--dry-run] [--json] PATH...
 *
 * Switch name: prompt in the text, else file name minus extension and -status suffix.
 * Capture time: file mtime unless --captured-at.
 *
 * Examples:
 *   php bin/cucm-switch-import.php switch-outputs/
 *   php bin/cucm-switch-import.php --dry-run --json switch-outputs/sw01.txt
 *   php bin/cucm-switch-import.php --name-regex '/^show_(.+)\.txt$/' captures/
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/switch.php';

$p    = cps_parse_args($argv, ['dry-run', 'json', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help']) || !$p['args']) {
    fwrite(STDERR, "Usage: php bin/cucm-switch-import.php [options] PATH...\n"
                 . "  --config FILE           config file\n"
                 . "  --switch NAME           switch name (single file only)\n"
                 . "  --name-regex RE         switch name from capture group 1 of RE (default: file name minus extension and -status)\n"
                 . "  --captured-at 'YYYY-MM-DD HH:MM:SS'  capture time (default file mtime)\n"
                 . "  --dry-run               parse only, no database\n"
                 . "  --json                  JSON output\n");
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
$nameRe = is_string($opts['name-regex'] ?? null) ? $opts['name-regex'] : null;
if ($nameRe !== null && @preg_match($nameRe, '') === false) {
    cps_die("--name-regex is not a valid PCRE pattern: $nameRe");
}

/** The switch a capture file names, before any prompt in its text overrides it. */
function cps_switch_name_from_file(string $file, ?string $re): ?string
{
    $base = basename($file);
    if ($re !== null) {
        if (!preg_match($re, $base, $m)) { return null; }
        if (!array_key_exists(1, $m)) { cps_die("--name-regex needs a capture group for the switch name: $re"); }
        return $m[1] !== '' ? $m[1] : null;
    }
    $n = (string)preg_replace('/\.(txt|log|out|json)$/i', '', $base);
    return (string)preg_replace('/[-_](?:sh(?:ow)?[-_])?(?:int(?:erfaces?)?[-_])?stat(?:us)?$/i', '', $n);
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
    $fromFile = $override ?? cps_switch_name_from_file($file, $nameRe);
    if ($fromFile === null) {
        cps_warn("$file: does not match --name-regex - not imported");
        $skipped++;
        continue;
    }

    $blocks = [];
    foreach (cps_switch_split($text, $fromFile) as $block) {
        $blocks[] = $block + ['parsed' => cps_parse_interface_status($block['text'])];
    }
    $anyPorts = (bool)array_filter($blocks, fn($b) => $b['parsed']['ports']);
    $isCapture = (bool)preg_match('/^Port\s+Name\s+Status/mi', $text);

    foreach ($blocks as $block) {
        $name   = $override ?? $block['switch'];
        $parsed = $block['parsed'];
        $row = ['switch' => $name, 'file' => $file, 'ports' => count($parsed['ports']),
                'unparsed' => $parsed['unparsed'], 'status' => $dry ? 'parsed' : null, 'capture_id' => null];

        // No ports = wrong command, an error page, or text before the prompt; never store it.
        if (!$parsed['ports']) {
            if ($anyPorts) { cps_log("$file ($name): block without interface rows ignored", 2); continue; }
            $row['status'] = 'skipped';
            if ($isCapture) {
                cps_warn("$file ($name): no interface rows found - not imported");
                $skipped++;
            } else {
                cps_warn("$file: not a 'show interface status' capture - ignored");
            }
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
