<?php

declare(strict_types=1);

$CONFIG = [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'cucm_phone_stats',
    'db_user' => 'cucm',
    'db_pass' => '',
];

foreach ([getenv('CPS_CONF') ?: '', __DIR__ . '/cucm.conf', dirname(__DIR__) . '/cucm.conf'] as $candidate) {
    if ($candidate !== '' && is_readable($candidate)) {

        $ini = parse_ini_file($candidate, false, INI_SCANNER_RAW);
        if (is_array($ini)) {
            foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'] as $k) {
                if (isset($ini[$k]) && $ini[$k] !== '') { $CONFIG[$k] = trim((string)$ini[$k], " \t\"'"); }
            }
        }
        break;
    }
}
foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'] as $k) {
    $env = getenv('CPS_' . strtoupper($k));
    if ($env !== false && $env !== '') { $CONFIG[$k] = $env; }
}

$PARAMS = $_GET;
$QUERY_STRING_LOST = false;   

$CGI_ENV_MISSING   = false;   

if (!$PARAMS) {
    $rawQuery = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if (is_string($rawQuery) && $rawQuery !== '') {
        parse_str($rawQuery, $PARAMS);
        $QUERY_STRING_LOST = (bool)$PARAMS;
    } elseif (!array_key_exists('REQUEST_URI', $_SERVER)) {

        $CGI_ENV_MISSING = true;
    }
}

const REPORTS = [
    'speed-mismatch' => [
        'title' => 'Speed mismatch',
        'blurb' => 'Phones whose access (PC) port is running at a different speed or duplex from the network (switch) port. Only phones with both ports up are compared.',
        'view'  => 'v_port_speed_mismatch',
        'sweep_scoped' => true,
        'columns' => [
            'device_name' => 'Device', 'description' => 'Description', 'model' => 'Model',
            'ip_address' => 'IP', 'switch_name' => 'Switch', 'switch_port' => 'Switch port',
            'network_speed_mbps' => 'Net Mbps', 'network_duplex' => 'Net duplex',
            'access_speed_mbps' => 'PC Mbps', 'access_duplex' => 'PC duplex',
            'mismatch_kind' => 'Mismatch',
        ],
        'numeric' => ['network_speed_mbps', 'access_speed_mbps'],
        'sql' => 'SELECT device_name, description, model, ip_address, switch_name, switch_port,
                         network_speed_mbps, network_duplex, access_speed_mbps, access_duplex,
                         mismatch_kind
                  FROM v_port_speed_mismatch WHERE sweep_id = :sweep
                  ORDER BY device_name',
    ],
    'errors' => [
        'title' => 'Errors',
        'blurb' => 'Ports reporting a non-zero error counter. Traffic counters (broadcast, multicast, unicast) are not errors and are excluded.',
        'view'  => 'v_port_errors',
        'sweep_scoped' => true,
        'columns' => [
            'device_name' => 'Device', 'description' => 'Description', 'ip_address' => 'IP',
            'switch_name' => 'Switch', 'switch_port' => 'Switch port', 'port' => 'Port',
            'speed_mbps' => 'Mbps', 'duplex' => 'Duplex', 'errors_total' => 'Errors',
            'rx_crc_err' => 'RX CRC', 'rx_align_err' => 'RX align',
            'tx_collisions' => 'TX coll', 'tx_late_collision' => 'TX late coll',
            'rx_total_pkt' => 'RX pkts', 'tx_total_pkt' => 'TX pkts',
        ],
        'numeric' => ['speed_mbps', 'errors_total', 'rx_crc_err', 'rx_align_err',
                      'tx_collisions', 'tx_late_collision', 'rx_total_pkt', 'tx_total_pkt'],
        'sql' => 'SELECT device_name, description, ip_address, switch_name, switch_port, port,
                         speed_mbps, duplex, errors_total, rx_crc_err, rx_align_err,
                         tx_collisions, tx_late_collision, rx_total_pkt, tx_total_pkt
                  FROM v_port_errors WHERE sweep_id = :sweep AND errors_total >= :min
                  ORDER BY errors_total DESC, device_name',
    ],
    'error-trend' => [
        'title' => 'Increasing errors',
        'blurb' => 'Ports whose error counters grew between sweeps. One row per phone and port - the most recent growth - with how many sweeps it grew in and the total across them. A counter that drops is a handset reboot, not an improvement, and is excluded.',
        'view'  => 'v_port_error_deltas',
        'sweep_scoped' => false,
        'columns' => [
            'device_name' => 'Device', 'description' => 'Description',
            'switch_name' => 'Switch', 'switch_port' => 'Switch port', 'port' => 'Port',
            'prev_errors_total' => 'Was', 'errors_total' => 'Now', 'errors_delta' => 'Growth',
            'times_grown' => 'Sweeps grown', 'total_growth' => 'Total growth',
            'started_at' => 'Last seen',
        ],
        'numeric' => ['prev_errors_total', 'errors_total', 'errors_delta', 'times_grown', 'total_growth'],
        'sql' => 'SELECT device_name, description, switch_name, switch_port, port,
                         prev_errors_total, errors_total, errors_delta,
                         times_grown, total_growth, started_at
                  FROM (
                    SELECT t.*,
                           ROW_NUMBER() OVER (PARTITION BY t.phone_id, t.port
                                              ORDER BY t.started_at DESC) AS rn,
                           COUNT(*)            OVER (PARTITION BY t.phone_id, t.port) AS times_grown,
                           SUM(t.errors_delta) OVER (PARTITION BY t.phone_id, t.port) AS total_growth
                    FROM (SELECT * FROM v_port_error_deltas WHERE errors_delta > 0) t
                  ) x
                  WHERE x.rn = 1
                  ORDER BY x.errors_delta DESC, x.device_name',
    ],
    'switch-mismatch' => [
        'title' => 'Switch mismatch',
        'blurb' => 'Phones whose network port speed or duplex differs from the switch port, per the newest imported show interface status of that switch.',
        'view'  => 'v_switch_port_mismatch',
        'sweep_scoped' => true,
        'columns' => [
            'device_name' => 'Device', 'description' => 'Description', 'ip_address' => 'IP',
            'switch_name' => 'Switch', 'switch_if' => 'Interface', 'switch_if_name' => 'Port name',
            'switch_vlan' => 'VLAN',
            'phone_speed_mbps' => 'Phone Mbps', 'phone_duplex' => 'Phone duplex',
            'switch_speed' => 'Switch speed', 'switch_duplex' => 'Switch duplex',
            'result' => 'Mismatch', 'captured_at' => 'Captured',
        ],
        'numeric' => ['phone_speed_mbps'],
        'sql' => 'SELECT device_name, description, ip_address, switch_name, switch_if, switch_if_name,
                         switch_vlan, phone_speed_mbps, phone_duplex, switch_speed, switch_duplex,
                         result, captured_at
                  FROM v_switch_port_mismatch WHERE sweep_id = :sweep
                  ORDER BY switch_name, switch_if, device_name',
    ],
];

const ROW_CAP = 20000;   

$report = $PARAMS['r'] ?? 'speed-mismatch';
if (!isset(REPORTS[$report])) { $report = 'speed-mismatch'; }
$spec = REPORTS[$report];

$minErrors = isset($PARAMS['min']) && ctype_digit((string)$PARAMS['min']) ? (int)$PARAMS['min'] : 1;

$error  = null;
$rows   = [];
$tabCounts = [];
$sweeps = [];
$sweepId = null;
$coverage = [];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $CONFIG['db_host'], (int)$CONFIG['db_port'], $CONFIG['db_name']),
        $CONFIG['db_user'], (string)$CONFIG['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );

    $sweeps = $pdo->query(
        'SELECT id, started_at, phones_polled, phones_ok, phones_failed
         FROM sweeps WHERE finished_at IS NOT NULL
         ORDER BY started_at DESC, id DESC LIMIT 50'
    )->fetchAll();

    if (!$sweeps) {
        $error = 'No completed sweep in the database yet. Run bin/cucm-sweep.php first.';
    } else {
        $countsPending = true;

        $ids = array_column($sweeps, 'id');
        $want = isset($PARAMS['sweep']) ? (int)$PARAMS['sweep'] : (int)$ids[0];
        $sweepId = in_array($want, array_map('intval', $ids), true) ? $want : (int)$ids[0];

        $st = $pdo->prepare($spec['sql']);
        if ($spec['sweep_scoped']) { $st->bindValue(':sweep', $sweepId, PDO::PARAM_INT); }
        if ($report === 'errors')  { $st->bindValue(':min', $minErrors, PDO::PARAM_INT); }
        $st->execute();
        $rows = $st->fetchAll();

        $tabCounts['speed-mismatch'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM v_port_speed_mismatch WHERE sweep_id = ' . (int)$sweepId)->fetchColumn();
        $tabCounts['errors'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM v_port_errors WHERE sweep_id = ' . (int)$sweepId
            . ' AND errors_total >= ' . (int)$minErrors)->fetchColumn();
        $tabCounts['error-trend'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT phone_id, port FROM v_port_error_deltas
                                   WHERE errors_delta > 0 GROUP BY phone_id, port) c')->fetchColumn();
        try {
            $tabCounts['switch-mismatch'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM v_switch_port_mismatch WHERE sweep_id = ' . (int)$sweepId)->fetchColumn();
            if ($report === 'switch-mismatch') {
                $coverage = $pdo->query(
                    'SELECT result, COUNT(*) AS n FROM v_switch_port_compare WHERE sweep_id = ' . (int)$sweepId
                    . ' GROUP BY result ORDER BY n DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (Throwable $e) {
            if ($report === 'switch-mismatch') { throw new RuntimeException($e->getMessage() . ' - re-run bin/cucm-initdb.php', 0, $e); }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$capped = count($rows) > ROW_CAP;
if ($capped) { $rows = array_slice($rows, 0, ROW_CAP); }

$showAll = ($PARAMS['cols'] ?? '') === 'all';
$hidden  = [];
$visible = $spec['columns'];
if ($rows && !$showAll) {
    foreach ($spec['columns'] as $col => $label) {
        $any = false;
        foreach ($rows as $r) {
            if (isset($r[$col]) && $r[$col] !== '' && $r[$col] !== null) { $any = true; break; }
        }
        if (!$any) { $hidden[$col] = $label; unset($visible[$col]); }
    }
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function qs(array $over = []): string {
    global $PARAMS;
    $q = array_merge(
        ['r' => $PARAMS['r'] ?? 'speed-mismatch'],
        isset($PARAMS['sweep']) ? ['sweep' => (int)$PARAMS['sweep']] : [],
        isset($PARAMS['min'])   ? ['min'   => (int)$PARAMS['min']]   : [],
        isset($PARAMS['cols'])  ? ['cols'  => (string)$PARAMS['cols']] : [],
        $over
    );
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== null));
}

if (isset($PARAMS['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $report . '-sweep' . (int)$sweepId . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_values($visible), ',', '"', '\\');
    foreach ($rows as $r) {
        $line = [];
        foreach (array_keys($visible) as $c) { $line[] = (string)($r[$c] ?? ''); }
        fputcsv($out, $line, ',', '"', '\\');
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CUCM phone port reports</title>
<script>
try {
  var t = localStorage.getItem('cps-theme');
  if (t === 'dark' || t === 'light') { document.documentElement.setAttribute('data-theme', t); }
} catch (e) {}
</script>
<style>
  :root{
    --bg:#f6f7f9; --card:#fff; --ink:#1b1f24; --muted:#5b6672; --line:#e3e7ec;
    --accent:#0b5cad; --accent-soft:#e8f0fa; --warn:#a8410a; --bad:#b3261e;
    --hover:#eef1f5; --chip:#e6eaef; --rowhover:#f9fbfd; --faint:#aeb6c0;
    --warnbg:#fdeee6; --badbg:#fde9e8;
    --errbg:#fde9e8; --errline:#f3c9c6; --errink:#7d1a15;
    color-scheme:light;
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      --bg:#14171a; --card:#1c2024; --ink:#e6e9ec; --muted:#9aa4af; --line:#2b3138;
      --accent:#6aa9e9; --accent-soft:#22303f; --warn:#e8a06a; --bad:#ef7b74;
      --hover:#232830; --chip:#262c33; --rowhover:#20262c; --faint:#6b7681;
      --warnbg:#3a2a1c; --badbg:#3a1f1d;
      --errbg:#3a1f1d; --errline:#5c2f2b; --errink:#f0c6c2;
      color-scheme:dark;
    }
  }
  :root[data-theme="dark"]{
    --bg:#14171a; --card:#1c2024; --ink:#e6e9ec; --muted:#9aa4af; --line:#2b3138;
    --accent:#6aa9e9; --accent-soft:#22303f; --warn:#e8a06a; --bad:#ef7b74;
    --hover:#232830; --chip:#262c33; --rowhover:#20262c; --faint:#6b7681;
    --warnbg:#3a2a1c; --badbg:#3a1f1d;
    --errbg:#3a1f1d; --errline:#5c2f2b; --errink:#f0c6c2;
    color-scheme:dark;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  header{background:var(--card);border-bottom:1px solid var(--line);padding:14px 20px 0;position:relative}
  .themebtn{position:absolute;top:14px;right:20px;display:inline-flex;align-items:center;gap:7px;
            font:inherit;font-size:13px;font-weight:550;color:var(--muted);cursor:pointer;
            background:var(--card);border:1px solid var(--line);border-radius:7px;padding:6px 11px}
  .themebtn:hover{color:var(--accent);background:var(--accent-soft);border-color:var(--accent)}
  .themebtn .ticon{font-size:14px;line-height:1}
  h1{margin:0 0 2px;font-size:17px;font-weight:650;letter-spacing:-.01em}
  .sub{color:var(--muted);font-size:12.5px;margin-bottom:12px}
  nav{display:flex;gap:2px}
  nav a{padding:9px 15px;text-decoration:none;color:var(--muted);font-weight:550;font-size:13.5px;
        border:1px solid transparent;border-bottom:none;border-radius:7px 7px 0 0;position:relative;top:1px}
  nav a:hover{color:var(--ink);background:var(--hover)}
  nav a.on{background:var(--bg);color:var(--accent);border-color:var(--line);}
  nav a .n{display:inline-block;margin-left:7px;padding:1px 7px;border-radius:9px;
           background:var(--chip);color:var(--muted);font-size:11.5px;font-weight:650}
  nav a.on .n{background:var(--accent-soft);color:var(--accent)}
  nav a .n.none{opacity:.55}
  .hint{color:var(--muted);font-size:12px;margin:-4px 0 10px}
  main{padding:18px 20px 40px}
  .blurb{color:var(--muted);max-width:76ch;margin:0 0 14px}
  .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px}
  input[type=search],select,input[type=number]{
    font:inherit;padding:7px 10px;border:1px solid var(--line);border-radius:7px;background:var(--card);color:var(--ink)}
  input[type=search]{min-width:290px}
  input[type=search]:focus,select:focus{outline:2px solid var(--accent-soft);border-color:var(--accent)}
  .grow{flex:1}
  .count{color:var(--muted);font-size:12.5px;white-space:nowrap}
  a.btn{text-decoration:none;color:var(--accent);border:1px solid var(--line);background:var(--card);
        padding:7px 12px;border-radius:7px;font-size:13px;font-weight:550}
  a.btn:hover{background:var(--accent-soft)}
  .wrap{background:var(--card);border:1px solid var(--line);border-radius:10px;overflow:auto;max-height:74vh}
  table{border-collapse:separate;border-spacing:0;width:100%;font-variant-numeric:tabular-nums}
  th,td{padding:8px 12px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}
  th{position:sticky;top:0;background:var(--card);z-index:1;font-size:12px;text-transform:uppercase;
     letter-spacing:.04em;color:var(--muted);font-weight:650;cursor:pointer;user-select:none}
  th:hover{color:var(--accent)}
  th .ar{opacity:.35;margin-left:5px;font-size:10px}
  th.sorted .ar{opacity:1}
  th.sorted{color:var(--accent)}
  td.num,th.num{text-align:right}
  tbody tr:hover{background:var(--rowhover)}
  tbody tr:last-child td{border-bottom:none}
  .dev{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
  .pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11.5px;font-weight:650;
        background:var(--chip);color:var(--muted)}
  .pill.speed{background:var(--warnbg);color:var(--warn)}
  .pill.duplex,.pill\.speedduplex{background:var(--badbg);color:var(--bad)}
  .bad{color:var(--bad);font-weight:650}
  .zero{color:var(--faint)}
  .empty{padding:40px;text-align:center;color:var(--muted)}
  .err{background:var(--errbg);border:1px solid var(--errline);color:var(--errink);padding:12px 14px;border-radius:8px}
  footer{color:var(--muted);font-size:12px;padding:0 20px 30px}

</style>
</head>
<body>
<header>
  <button id="theme" class="themebtn" type="button" title="Light, dark, or follow the browser">
    <span class="ticon">&#9788;</span><span class="tlabel">Theme</span>
  </button>
  <h1>CUCM phone port reports</h1>
  <div class="sub">Network and access port statistics collected from registered handsets</div>
  <nav>
    <?php foreach (REPORTS as $key => $r): ?>
      <a class="<?= $key === $report ? 'on' : '' ?>" href="<?= h(qs(['r' => $key])) ?>"><?= h($r['title']) ?><?php
        if (isset($tabCounts[$key])): ?><span class="n<?= $tabCounts[$key] === 0 ? ' none' : '' ?>"><?= (int)$tabCounts[$key] ?></span><?php
        endif; ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main>
<?php if ($CGI_ENV_MISSING): ?>
  <div class="err" style="margin-bottom:16px">
    <strong>This web server is not passing the CGI environment to PHP, so the tabs cannot work.</strong>
    <p style="margin:8px 0 0">
      <code>REQUEST_URI</code> and <code>QUERY_STRING</code> are both absent, which means the
      <code>location ~ \.php$</code> block is missing <code>include fastcgi_params;</code>
      (or <code>include fastcgi.conf;</code>). Every tab will render the default report until
      that is added. See <code>docs/web-deployment.md</code>.
    </p>
  </div>
<?php endif; ?>
<?php if ($error !== null): ?>
  <div class="err"><strong>Cannot show reports.</strong><br><?= h($error) ?></div>
<?php else: ?>
  <p class="blurb"><?= h($spec['blurb']) ?></p>
  <?php if ($coverage): ?>
    <p class="blurb">Uplinks compared:
      <?php foreach ($coverage as $res => $n): ?><span class="count"><?= h((string)$res) ?> <?= (int)$n ?></span> <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <div class="bar">
    <input type="search" id="q" placeholder="Search any column - device, switch, port, description..." autofocus>
    <?php if ($spec['sweep_scoped']): ?>
      <?php $pinned = isset($PARAMS['sweep']); ?>
      <select onchange="location=this.value">
        <option value="<?= h(qs(['sweep' => null])) ?>" <?= $pinned ? '' : 'selected' ?>>
          Latest sweep (#<?= (int)$sweeps[0]['id'] ?> &mdash; <?= h($sweeps[0]['started_at']) ?>)
        </option>
        <?php foreach ($sweeps as $s): ?>
          <option value="<?= h(qs(['sweep' => $s['id']])) ?>" <?= ($pinned && (int)$s['id'] === $sweepId) ? 'selected' : '' ?>>
            Sweep #<?= (int)$s['id'] ?> &mdash; <?= h($s['started_at']) ?> (<?= (int)$s['phones_ok'] ?> ok, <?= (int)$s['phones_failed'] ?> failed)
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($pinned): ?>
        <a class="btn" href="<?= h(qs(['sweep' => null])) ?>">Back to latest</a>
      <?php endif; ?>
    <?php else: ?>
      <span class="count">across all <?= count($sweeps) ?> sweeps</span>
    <?php endif; ?>
    <?php if ($report === 'errors'): ?>
      <label class="count">min errors
        <input type="number" min="1" value="<?= (int)$minErrors ?>" style="width:90px"
               onchange="location='<?= h(qs(['min' => 0])) ?>'.replace(/min=0/,'min='+Math.max(1,this.value))">
      </label>
    <?php endif; ?>
    <span class="grow"></span>
    <span class="count" id="shown"><?= count($rows) ?> rows</span>
    <a class="btn" href="<?= h(qs(['csv' => 1])) ?>">Download CSV</a>
  </div>

  <?php if ($capped): ?>
    <div class="err" style="margin-bottom:12px">Showing the first <?= ROW_CAP ?> rows only.</div>
  <?php endif; ?>

  <?php if (count($visible) > 12): ?>
    <div class="hint">This table is wider than the window &mdash; scroll it sideways to see the remaining counters.</div>
  <?php endif; ?>
  <div class="wrap">
    <?php if (!$rows): ?>
      <div class="empty">Nothing to report<?= $spec['sweep_scoped'] ? ' in sweep #' . (int)$sweepId : '' ?>. That is good news.</div>
    <?php else: ?>
    <table id="t">
      <thead><tr>
        <?php foreach ($visible as $col => $label):
              $num = in_array($col, $spec['numeric'], true); ?>
          <th class="<?= $num ? 'num' : '' ?>" data-type="<?= $num ? 'n' : 's' ?>"><?= h($label) ?><span class="ar">&#9650;&#9660;</span></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
          <?php foreach ($visible as $col => $label):
                $v = $r[$col] ?? null;
                $num = in_array($col, $spec['numeric'], true);
                $cls = $num ? 'num' : '';
                if ($col === 'device_name') { $cls .= ' dev'; }
                echo '<td class="' . trim($cls) . '">';
                if ($col === 'mismatch_kind' && $v !== null) {
                    echo '<span class="pill ' . h(str_replace('+', '', (string)$v)) . '">' . h((string)$v) . '</span>';
                } elseif (in_array($col, ['errors_total', 'errors_delta', 'total_growth'], true)) {
                    echo $v > 0 ? '<span class="bad">' . h((string)$v) . '</span>' : '<span class="zero">0</span>';
                } elseif ($num && ($v === null || $v === '')) {
                    echo '<span class="zero">&mdash;</span>';
                } elseif ($v === null || $v === '') {
                    echo '<span class="zero">&mdash;</span>';
                } else {
                    echo h((string)$v);
                }
                echo '</td>';
          endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php if ($hidden): ?>
    <p class="hint" style="margin-top:10px">
      <?= count($hidden) ?> empty column<?= count($hidden) === 1 ? '' : 's' ?> hidden:
      <?= h(implode(', ', $hidden)) ?>.
      <a href="<?= h(qs(['cols' => 'all'])) ?>">Show all columns</a>
    </p>
  <?php elseif ($showAll): ?>
    <p class="hint" style="margin-top:10px">
      Showing every column, including empty ones.
      <a href="<?= h(qs(['cols' => null])) ?>">Hide empty columns</a>
    </p>
  <?php endif; ?>
<?php endif; ?>
</main>
<footer>
  <?php if ($QUERY_STRING_LOST): ?>
    <div class="err" style="margin-bottom:12px">
      <strong>This web server is not passing the query string to PHP.</strong>
      The tabs are working here only because the parameters were recovered from
      <code>REQUEST_URI</code>. In nginx this is usually a <code>try_files</code>
      missing <code>?$query_string</code>, or a <code>location ~ \.php$</code>
      block that does not <code>include fastcgi_params</code>. See
      <code>docs/web-deployment.md</code>.
    </div>
  <?php endif; ?>
  Generated <?= h(date('Y-m-d H:i')) ?> from <?= h($CONFIG['db_name']) ?> on <?= h($CONFIG['db_host']) ?>.
  Click a column heading to sort; click again to reverse.
</footer>

<script>
(function () {
  var root = document.documentElement;
  var btn = document.getElementById('theme');
  var icon = btn.querySelector('.ticon');
  var label = btn.querySelector('.tlabel');
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function stored() {
    try { return localStorage.getItem('cps-theme'); } catch (e) { return null; }
  }
  function effective() {
    var s = stored();
    if (s === 'dark' || s === 'light') { return s; }
    return (media && media.matches) ? 'dark' : 'light';
  }
  function paint() {
    var dark = effective() === 'dark';
    icon.innerHTML = dark ? '&#9789;' : '&#9788;';
    label.textContent = stored() ? (dark ? 'Dark' : 'Light') : 'Auto';
    btn.title = stored()
      ? 'Theme set to ' + (dark ? 'dark' : 'light') + '. Click twice more to follow the browser again.'
      : 'Following the browser. Click to choose light or dark.';
  }
  btn.addEventListener('click', function () {
    var s = stored();
    var next = !s ? (effective() === 'dark' ? 'light' : 'dark')
             : (s === 'dark' ? null : 'dark');
    try {
      if (next === null) { localStorage.removeItem('cps-theme'); }
      else { localStorage.setItem('cps-theme', next); }
    } catch (e) {}
    if (next === null) { root.removeAttribute('data-theme'); }
    else { root.setAttribute('data-theme', next); }
    paint();
  });
  if (media && media.addEventListener) {
    media.addEventListener('change', function () { if (!stored()) { paint(); } });
  }
  paint();
})();

(function () {
  var table = document.getElementById('t');
  if (!table) return;
  var tbody = table.tBodies[0];
  var rows  = Array.prototype.slice.call(tbody.rows);
  var heads = Array.prototype.slice.call(table.tHead.rows[0].cells);
  var shown = document.getElementById('shown');
  var sortCol = -1, sortDir = 1;

  function cellText(row, i) { return (row.cells[i].textContent || '').trim(); }
  function cellNum(row, i) {
    var t = cellText(row, i).replace(/[^0-9.\-]/g, '');
    return t === '' || t === '-' ? null : parseFloat(t);
  }

  function sortBy(i) {
    sortDir = (i === sortCol) ? -sortDir : (heads[i].dataset.type === 'n' ? -1 : 1);
    sortCol = i;
    heads.forEach(function (h, j) { h.classList.toggle('sorted', j === i); });
    var numeric = heads[i].dataset.type === 'n';
    rows.sort(function (a, b) {
      if (numeric) {
        var x = cellNum(a, i), y = cellNum(b, i);
        if (x === null && y === null) return 0;
        if (x === null) return 1;
        if (y === null) return -1;
        return (x - y) * sortDir;
      }
      return cellText(a, i).localeCompare(cellText(b, i), undefined, {numeric: true, sensitivity: 'base'}) * sortDir;
    });
    var frag = document.createDocumentFragment();
    rows.forEach(function (r) { frag.appendChild(r); });
    tbody.appendChild(frag);
  }

  heads.forEach(function (h, i) {
    h.addEventListener('click', function () { sortBy(i); });
  });

  var q = document.getElementById('q');
  var timer = null;
  function filter() {
    var terms = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    var n = 0;
    rows.forEach(function (r) {
      var hay = r.textContent.toLowerCase();
      var ok = terms.every(function (t) { return hay.indexOf(t) !== -1; });
      r.style.display = ok ? '' : 'none';
      if (ok) n++;
    });
    shown.textContent = n + (n === rows.length ? ' rows' : ' of ' + rows.length + ' rows');
  }
  q.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(filter, 90);
  });
  q.addEventListener('keydown', function (e) { if (e.key === 'Escape') { q.value = ''; filter(); } });
})();
</script>
</body>
</html>
