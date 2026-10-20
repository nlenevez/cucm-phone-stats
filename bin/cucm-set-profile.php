#!/usr/bin/env php
<?php
/**
 * cucm-set-profile.php - move switch-mismatched phones to another Common
 * Phone Profile.
 *
 * Usage:
 *   php bin/cucm-set-profile.php --from UUID --to UUID [--sweep N]
 *       [--result speed|duplex|speed+duplex] [--device NAME[,NAME]] [--limit N]
 *       [--apply [--apply-config]] [--json] [--config FILE]
 *   php bin/cucm-set-profile.php --undo BATCH [--apply [--apply-config]]
 *   php bin/cucm-set-profile.php --apply-config BATCH [--apply]
 *
 * Candidates are the phones in the switch-mismatch report for the sweep
 * (default: the latest) whose stored profile is --from. Nothing is changed
 * without --apply; a dry run still asks AXL for each phone's CURRENT
 * profile, because the stored one is only as fresh as the last sweep, and
 * a phone is only ever changed if it is on --from right now.
 *
 * The profile change takes effect when the phone picks up its config:
 * --apply-config sends AXL applyPhone (Apply Config) after each update;
 * without it, use Apply Config in CUCM or wait for the next reset.
 * --apply-config BATCH sends it later, to every phone a batch changed
 * (e.g. change now, apply out of hours); listed only, unless --apply.
 *
 * Every attempt is logged to profile_changes under a batch id, printed at
 * the end. --undo BATCH puts that batch's phones back on their old profile
 * (again only those still on the profile the batch set).
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/axl.php';

// apply-config is not a declared flag: bare it is true, "--apply-config BATCH" takes a value.
$p    = cps_parse_args($argv, ['apply', 'json', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-set-profile.php --from UUID --to UUID [--sweep N]\n"
                 . "       [--result speed|duplex|speed+duplex] [--device NAME[,NAME]] [--limit N]\n"
                 . "       [--apply [--apply-config]] [--json]\n"
                 . "       php bin/cucm-set-profile.php --undo BATCH [--apply [--apply-config]]\n"
                 . "       php bin/cucm-set-profile.php --apply-config BATCH [--apply]\n"
                 . "\n  Dry run unless --apply.\n");
    exit(0);
}

$cfg   = cps_load_config(cps_common_opts($opts));
$db    = new CpsDb($cfg);
$apply = !empty($opts['apply']);
$undo  = is_string($opts['undo'] ?? null) ? $opts['undo'] : null;
if ((string)($cfg['axl_user'] ?? '') === '') { cps_die('axl_user is not configured'); }

// ------------------------------------------- Apply Config for a past batch
if (is_string($opts['apply-config'] ?? null)) {
    $b    = $opts['apply-config'];
    $rows = $db->query('SELECT id, device_name, axl_server, applied FROM profile_changes
                        WHERE batch = ? AND ok = 1 ORDER BY id', [$b]);
    if (!$rows) { cps_die("batch '$b' has no successful changes"); }
    $mark = $db->pdo()->prepare('UPDATE profile_changes SET applied = 1 WHERE id = ?');
    $out = [];
    $failed = 0;
    foreach ($rows as $r) {
        $row = ['device_name' => $r['device_name'], 'previously_applied' => (int)$r['applied'], 'action' => '', 'note' => ''];
        if (!$apply) {
            $row['action'] = 'would apply config';
        } else {
            try {
                cps_axl_client($cfg, $r['axl_server'] ?: null)->applyPhone($r['device_name']);
                $mark->execute([$r['id']]);
                $row['action'] = 'applied';
            } catch (Throwable $e) {
                $failed++;
                $row['action'] = 'failed';
                $row['note']   = $e->getMessage();
                cps_warn("{$r['device_name']}: " . $e->getMessage());
            }
        }
        $out[] = $row;
    }
    if (!empty($opts['json'])) {
        echo cps_json(['batch' => $b, 'dry_run' => !$apply, 'phones' => $out]);
    } else {
        echo cps_render_table($out, ['device_name', 'previously_applied', 'action', 'note']);
        fwrite(STDERR, $apply
            ? sprintf("\nbatch %s: Apply Config sent to %d phone(s), %d failed\n", $b, count($out) - $failed, $failed)
            : sprintf("\nDRY RUN: %d phone(s) would get Apply Config. Re-run with --apply.\n", count($out)));
    }
    exit($failed ? 2 : 0);
}

// ------------------------------------------------------------ candidates
// Each candidate: phone_id, device_name, from, to, server, why.
$cands  = [];
$sweepId = null;
if ($undo !== null) {
    foreach ($db->query('SELECT phone_id, device_name, from_uuid, to_uuid, axl_server
                         FROM profile_changes WHERE batch = ? AND ok = 1 ORDER BY id', [$undo]) as $r) {
        $cands[] = ['phone_id' => (int)$r['phone_id'], 'device_name' => $r['device_name'],
                    'from' => $r['to_uuid'], 'to' => $r['from_uuid'], 'server' => $r['axl_server'],
                    'why' => "undo $undo"];
    }
    if (!$cands) { cps_die("batch '$undo' has no successful changes to undo"); }
} else {
    $from = CpsAxlClient::normUuid((string)($opts['from'] ?? ''));
    $to   = CpsAxlClient::normUuid((string)($opts['to'] ?? ''));
    if ($from === null || $to === null) { cps_die('--from and --to must both be Common Phone Profile uuids'); }
    if ($from === $to) { cps_die('--from and --to are the same profile'); }

    $sweepId = isset($opts['sweep']) && is_string($opts['sweep']) ? (int)$opts['sweep'] : $db->latestSweepId();
    if (!$sweepId) { cps_die('no completed sweep to take mismatches from'); }

    $sql = 'SELECT m.phone_id, m.device_name, GROUP_CONCAT(DISTINCT m.result) AS why,
                   pc.common_phone_profile_uuid AS cur, pc.axl_server
            FROM v_switch_port_mismatch m
            LEFT JOIN phone_config pc ON pc.phone_id = m.phone_id
            WHERE m.sweep_id = ?';
    $params = [$sweepId];
    if (is_string($opts['result'] ?? null)) { $sql .= ' AND m.result = ?'; $params[] = $opts['result']; }
    $sql .= ' GROUP BY m.phone_id, m.device_name, pc.common_phone_profile_uuid, pc.axl_server ORDER BY m.device_name';
    $rows = $db->query($sql, $params);

    $devices = is_string($opts['device'] ?? null)
        ? array_flip(array_map('strtoupper', preg_split('/[\s,]+/', $opts['device'], -1, PREG_SPLIT_NO_EMPTY)))
        : null;
    $other = 0;
    foreach ($rows as $r) {
        if ($devices !== null && !isset($devices[strtoupper($r['device_name'])])) { continue; }
        if ($r['cur'] !== $from) { $other++; continue; }
        $cands[] = ['phone_id' => (int)$r['phone_id'], 'device_name' => $r['device_name'],
                    'from' => $from, 'to' => $to, 'server' => $r['axl_server'], 'why' => $r['why']];
    }
    cps_log(sprintf('sweep #%d: %d switch-mismatched phone(s), %d on the --from profile, %d on another',
        $sweepId, count($rows), count($cands), $other));
    if (isset($opts['limit']) && is_string($opts['limit'])) { $cands = array_slice($cands, 0, (int)$opts['limit']); }
    if (!$cands) { cps_log('nothing to change'); exit(0); }
}

// --------------------------------------------------------------- AXL side
$clients = [];
$axlFor = function (?string $server) use (&$clients, $cfg): CpsAxlClient {
    $key = $server ?? '';
    return $clients[$key] ??= cps_axl_client($cfg, $server ?: null);
};

// A profile uuid belongs to one cluster. Check the target exists on every
// cluster about to be written to, before anything is touched.
$targets = [];
foreach ($cands as $c) { $targets[($c['server'] ?? '') . '|' . $c['to']] = $c; }
$toNames = [];
foreach ($targets as $c) {
    try {
        $toNames[$c['to']] = $axlFor($c['server'])->commonPhoneProfileName($c['to']);
    } catch (Throwable $e) {
        cps_die(sprintf('target profile %s not found on %s: %s', $c['to'], $c['server'] ?: 'default server', $e->getMessage()));
    }
}

$batch = $apply ? date('Ymd-His') : null;
$log = $db->pdo()->prepare(
    'INSERT INTO profile_changes (batch, phone_id, device_name, from_uuid, to_uuid, axl_server, sweep_id,
                                  ok, applied, error, changed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?, NOW())'
);
$out = [];
$failed = 0;
foreach ($cands as $c) {
    $row = ['device_name' => $c['device_name'], 'why' => $c['why'], 'to' => $toNames[$c['to']] ?? $c['to'],
            'action' => '', 'note' => ''];
    try {
        $axl  = $axlFor($c['server']);
        $live = $axl->phoneCommonProfileUuid($c['device_name']);
        if ($live !== $c['from']) {
            $row['action'] = 'skipped';
            $row['note']   = 'now on ' . ($live ?? 'none') . ', not ' . $c['from'];
        } elseif (!$apply) {
            $row['action'] = 'would change';
        } else {
            $applied = 0;
            $axl->setPhoneCommonProfile($c['device_name'], $c['to']);
            if (!empty($opts['apply-config'])) { $axl->applyPhone($c['device_name']); $applied = 1; }
            $log->execute([$batch, $c['phone_id'], $c['device_name'], $c['from'], $c['to'], $c['server'],
                           $sweepId, 1, $applied, null]);
            $db->pdo()->prepare('UPDATE phone_config SET common_phone_profile_uuid = ?, common_phone_profile_name = ?,
                                 updated_at = NOW() WHERE phone_id = ?')
                ->execute([$c['to'], $toNames[$c['to']] ?? null, $c['phone_id']]);
            $row['action'] = $applied ? 'changed + applied' : 'changed';
        }
    } catch (Throwable $e) {
        $failed++;
        $row['action'] = 'failed';
        $row['note']   = $e->getMessage();
        if ($apply) {
            try {
                $log->execute([$batch, $c['phone_id'], $c['device_name'], $c['from'], $c['to'], $c['server'],
                               $sweepId, 0, 0, mb_substr($e->getMessage(), 0, 2000)]);
            } catch (Throwable) { /* the warning below still says it failed */ }
        }
        cps_warn("{$c['device_name']}: " . $e->getMessage());
    }
    $out[] = $row;
}

if (!empty($opts['json'])) {
    echo cps_json(['batch' => $batch, 'dry_run' => !$apply, 'phones' => $out]);
} else {
    echo cps_render_table($out, ['device_name', 'why', 'to', 'action', 'note']);
    $changed = count(array_filter($out, fn($r) => str_starts_with($r['action'], 'changed')));
    $would   = count(array_filter($out, fn($r) => $r['action'] === 'would change'));
    fwrite(STDERR, $apply
        ? "\nbatch $batch: $changed changed, $failed failed. Undo with: php bin/cucm-set-profile.php --undo $batch --apply\n"
          . (empty($opts['apply-config']) && $changed ? "Phones pick up the new profile on Apply Config or reset (--apply-config does it).\n" : '')
        : "\nDRY RUN: $would phone(s) would change. Re-run with --apply to make the change.\n");
}
exit($failed ? 2 : 0);
