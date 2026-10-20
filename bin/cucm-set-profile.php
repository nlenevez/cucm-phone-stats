#!/usr/bin/env php
<?php
/**
 * cucm-set-profile.php - move switch-mismatched phones to another Common Phone Profile.
 *
 * Usage:
 *   php bin/cucm-set-profile.php --from UUID --to UUID [--sweep N]
 *       [--result speed|duplex|speed+duplex] [--device NAME[,NAME]] [--limit N]
 *       [--apply [--apply-config]] [--json] [--config FILE]
 *   php bin/cucm-set-profile.php --undo BATCH [--apply [--apply-config]]
 *   php bin/cucm-set-profile.php --apply-config BATCH [--apply]
 *
 * Dry run unless --apply. Only phones currently on --from (checked live via AXL) are changed.
 *
 * Examples:
 *   php bin/cucm-set-profile.php --from UUID1 --to UUID2
 *   php bin/cucm-set-profile.php --from UUID1 --to UUID2 --apply --apply-config
 *   php bin/cucm-set-profile.php --apply-config latest --apply
 *   php bin/cucm-set-profile.php --undo 20261021-101500 --apply
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/axl.php';

// apply-config is undeclared so it can be bare or take a BATCH value.
$p    = cps_parse_args($argv, ['apply', 'json', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-set-profile.php --from UUID --to UUID [options]\n"
                 . "       php bin/cucm-set-profile.php --undo BATCH [--apply [--apply-config]]\n"
                 . "       php bin/cucm-set-profile.php --apply-config BATCH|latest [--apply]\n"
                 . "  --sweep N                       mismatches from this sweep (default latest)\n"
                 . "  --result speed|duplex|speed+duplex  only this mismatch kind\n"
                 . "  --device NAME[,NAME]            only these phones\n"
                 . "  --limit N                       at most N phones\n"
                 . "  --apply                         make the change (default dry run)\n"
                 . "  --apply-config                  send Apply Config after each change\n"
                 . "  --json                          JSON output\n");
    exit(0);
}

$cfg   = cps_load_config(cps_common_opts($opts));
$db    = new CpsDb($cfg);
$apply = !empty($opts['apply']);
$undo  = is_string($opts['undo'] ?? null) ? $opts['undo'] : null;
if ((string)($cfg['axl_user'] ?? '') === '') { cps_die('axl_user is not configured'); }

$clients = [];
$axlFor = function (?string $server) use (&$clients, $cfg): CpsAxlClient {
    $key = $server ?? '';
    return $clients[$key] ??= cps_axl_client($cfg, $server ?: null);
};
// --device and --limit apply in every mode.
$devices = is_string($opts['device'] ?? null)
    ? array_flip(array_map('strtoupper', preg_split('/[\s,]+/', $opts['device'], -1, PREG_SPLIT_NO_EMPTY)))
    : null;
$narrow = function (array $rows) use ($devices, $opts): array {
    if ($devices !== null) {
        $rows = array_values(array_filter($rows, fn($r) => isset($devices[strtoupper($r['device_name'])])));
    }
    if (isset($opts['limit']) && is_string($opts['limit'])) { $rows = array_slice($rows, 0, max(0, (int)$opts['limit'])); }
    return $rows;
};

// ------------------------------------------- Apply Config for a past batch
if (is_string($opts['apply-config'] ?? null)) {
    $b    = $opts['apply-config'];
    if ($b === 'latest') {
        $last = $db->query('SELECT batch FROM profile_changes WHERE ok = 1 ORDER BY id DESC LIMIT 1');
        if (!$last) { cps_die('no profile changes recorded yet'); }
        $b = $last[0]['batch'];
    }
    $rows = $narrow($db->query('SELECT id, device_name, axl_server, applied FROM profile_changes
                                WHERE batch = ? AND ok = 1 ORDER BY id', [$b]));
    if (!$rows) { cps_die("batch '$b' has no matching successful changes"); }
    $mark = $db->pdo()->prepare('UPDATE profile_changes SET applied = 1 WHERE id = ?');
    $out = [];
    $failed = 0;
    foreach ($rows as $r) {
        $row = ['device_name' => $r['device_name'], 'previously_applied' => (int)$r['applied'], 'action' => '', 'note' => ''];
        if (!$apply) {
            $row['action'] = 'would apply config';
        } else {
            try {
                $axlFor($r['axl_server'] ?: null)->applyPhone($r['device_name']);
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
    foreach ($narrow($db->query('SELECT phone_id, device_name, from_uuid, to_uuid, axl_server
                                 FROM profile_changes WHERE batch = ? AND ok = 1 ORDER BY id', [$undo])) as $r) {
        $cands[] = ['phone_id' => (int)$r['phone_id'], 'device_name' => $r['device_name'],
                    'from' => $r['to_uuid'], 'to' => $r['from_uuid'], 'server' => $r['axl_server'],
                    'why' => "undo $undo"];
    }
    if (!$cands) { cps_die("batch '$undo' has no matching successful changes to undo"); }
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

    $other = $unknown = 0;
    foreach ($rows as $r) {
        if ($devices !== null && !isset($devices[strtoupper($r['device_name'])])) { continue; }
        // No stored profile (not collected yet): the live check below decides.
        if ($r['cur'] === null) { $unknown++; }
        elseif ($r['cur'] !== $from) { $other++; continue; }
        $cands[] = ['phone_id' => (int)$r['phone_id'], 'device_name' => $r['device_name'],
                    'from' => $from, 'to' => $to, 'server' => $r['axl_server'], 'why' => $r['why']];
    }
    cps_log(sprintf('sweep #%d: %d switch-mismatched, %d to check (%d with no stored profile), %d on another profile',
        $sweepId, count($rows), count($cands), $unknown, $other));
    $cands = $narrow($cands);
    if (!$cands) { cps_log('nothing to change'); exit(0); }
}

// Check the target profile exists on every cluster before changing anything.
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

$batch = $apply ? date('Ymd-His') . '-' . bin2hex(random_bytes(2)) : null;
$log = $db->pdo()->prepare(
    'INSERT INTO profile_changes (batch, phone_id, device_name, from_uuid, to_uuid, axl_server, sweep_id,
                                  ok, applied, error, changed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?, NOW())'
);
$setApplied = $db->pdo()->prepare('UPDATE profile_changes SET applied = 1 WHERE id = ?');
$setCfg = $db->pdo()->prepare('UPDATE phone_config SET common_phone_profile_uuid = ?, common_phone_profile_name = ?,
                               updated_at = NOW() WHERE phone_id = ?');
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
            $axl->setPhoneCommonProfile($c['device_name'], $c['to'], $toNames[$c['to']] ?? '');
            // From here the phone IS changed: log it before anything else can fail.
            $row['action'] = 'changed';
            try {
                $log->execute([$batch, $c['phone_id'], $c['device_name'], $c['from'], $c['to'], $c['server'],
                               $sweepId, 1, 0, null]);
                $logId = (int)$db->pdo()->lastInsertId();
            } catch (Throwable $e) {
                $logId = 0;
                $failed++;
                $row['note'] = 'CHANGED but not logged (undo by hand): ' . $e->getMessage();
                cps_warn("{$c['device_name']}: changed to {$c['to']} but not logged: " . $e->getMessage());
            }
            try { $setCfg->execute([$c['to'], $toNames[$c['to']] ?? null, $c['phone_id']]); }
            catch (Throwable $e) { cps_warn("{$c['device_name']}: phone_config not updated: " . $e->getMessage()); }
            if (!empty($opts['apply-config'])) {
                try {
                    $axl->applyPhone($c['device_name']);
                    if ($logId) { $setApplied->execute([$logId]); }
                    $row['action'] = 'changed + applied';
                } catch (Throwable $e) {
                    $failed++;
                    $row['note'] = 'apply config failed (retry with --apply-config BATCH): ' . $e->getMessage();
                    cps_warn("{$c['device_name']}: apply config failed: " . $e->getMessage());
                }
            }
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
          . (empty($opts['apply-config']) && $changed ? "Takes effect on Apply Config or reset.\n" : '')
        : "\nDRY RUN: $would phone(s) would change. Re-run with --apply to make the change.\n");
}
exit($failed ? 2 : 0);
