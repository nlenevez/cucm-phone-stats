<?php
/**
 * db.php - MySQL persistence for sweeps, phones, port stats and switch captures.
 * Writes are one transaction per phone; finished_at stays NULL on a partial sweep.
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/switch.php';
require_once __DIR__ . '/axl.php';

/** Counter columns written to port_stats, in schema order. */
const CPS_PORT_COLUMNS = [
    'rx_total_pkt', 'rx_total_bytes', 'rx_crc_err', 'rx_align_err', 'rx_short_err',
    'rx_long_err', 'rx_jabber', 'rx_overrun', 'rx_multicast', 'rx_broadcast', 'rx_unicast',
    'tx_total_pkt', 'tx_total_bytes', 'tx_collisions', 'tx_late_collision',
    'tx_excess_collision', 'tx_excess_defer', 'tx_excess_length', 'tx_deferred',
    'tx_multicast', 'tx_broadcast', 'tx_unicast',
];

class CpsDb
{
    private PDO $pdo;

    public function __construct(array $cfg)
    {
        $host = $cfg['db_host'] ?? '127.0.0.1';
        $port = (int)($cfg['db_port'] ?? 3306);
        $name = $cfg['db_name'] ?? 'cucm_phone_stats';
        $dsn  = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        try {
            $this->pdo = new PDO($dsn, $cfg['db_user'] ?? 'root', (string)($cfg['db_pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("MySQL connect failed ($host:$port/$name): " . $e->getMessage(), 0, $e);
        }
        // Server-default collation, as schema.sql uses: views' literals then compare cleanly with ours.
        $this->pdo->exec('SET NAMES utf8mb4');
    }

    public function pdo(): PDO { return $this->pdo; }

    /**
     * Apply schema.sql; returns the number of statements run.
     * Strip "--" lines before splitting on ";" at end of line.
     */
    public function applySchema(string $file): int
    {
        $sql = file_get_contents($file);
        if ($sql === false) { throw new RuntimeException("cannot read $file"); }

        $lines = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (str_starts_with(ltrim($line), '--')) { continue; }
            $lines[] = $line;
        }
        $n = 0;
        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') { continue; }
            try {
                $this->pdo->exec($stmt);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    'schema statement failed: ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 120)
                    . ' -- ' . $e->getMessage(), 0, $e
                );
            }
            $n++;
        }
        return $n;
    }

    /** Minimum column widths enforced by migrate(); only ever widens. */
    private const MIN_WIDTHS = [
        ['phones', 'dir_number', 'VARCHAR(512) NULL', 512],
    ];

    /** Widen any column that is narrower than the schema now requires. */
    public function migrate(string $dbName): array
    {
        $applied = [];
        foreach (self::MIN_WIDTHS as [$table, $col, $definition, $want]) {
            $cur = $this->query(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $col]
            );
            if (!$cur) { continue; }              // table/column not there yet
            if ((int)$cur[0]['len'] >= $want) { continue; }
            $this->pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$col` $definition");
            $applied[] = "$table.$col -> $definition (was " . $cur[0]['len'] . ')';
        }
        return $applied;
    }

    /** Cached CHARACTER_MAXIMUM_LENGTH per table.column. */
    private array $widths = [];

    private function widths(string $table): array
    {
        if (!isset($this->widths[$table])) {
            $this->widths[$table] = [];
            foreach ($this->query(
                'SELECT COLUMN_NAME AS c, CHARACTER_MAXIMUM_LENGTH AS len
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL', [$table]
            ) as $r) {
                $this->widths[$table][$r['c']] = (int)$r['len'];
            }
        }
        return $this->widths[$table];
    }

    /** Clip over-long strings to the live column width, warning once per column. */
    private function clipRow(string $table, array $row): array
    {
        $w = $this->widths($table);
        foreach ($row as $col => $v) {
            if (!is_string($v) || !isset($w[$col])) { continue; }
            if (mb_strlen($v) <= $w[$col]) { continue; }
            $key = "$table.$col";
            if (!isset($this->clipWarned[$key])) {
                $this->clipWarned[$key] = true;
                cps_warn(sprintf('%s: %d chars clipped to %d ("%s")',
                    $key, mb_strlen($v), $w[$col], mb_substr($v, 0, 40)));
            }
            $row[$col] = mb_substr($v, 0, $w[$col]);
        }
        return $row;
    }

    private array $clipWarned = [];

    public function startSweep(string $source, array $servers, string $toolVersion): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO sweeps (started_at, source, cucm_servers, tool_version) VALUES (NOW(), ?, ?, ?)'
        );
        $row = $this->clipRow('sweeps', ['source' => $source, 'cucm_servers' => implode(', ', $servers),
                                         'tool_version' => $toolVersion]);
        $st->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    public function finishSweep(int $sweepId, array $counts, ?string $notes = null): void
    {
        $st = $this->pdo->prepare(
            'UPDATE sweeps SET finished_at = NOW(), phones_discovered = ?, phones_polled = ?,
             phones_ok = ?, phones_failed = ?, notes = ? WHERE id = ?'
        );
        $st->execute([
            $counts['discovered'] ?? 0, $counts['polled'] ?? 0,
            $counts['ok'] ?? 0, $counts['failed'] ?? 0, $notes, $sweepId,
        ]);
    }

    /** Upsert a phone and return its id; empty values never overwrite known ones. */
    public function upsertPhone(array $p): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO phones
               (device_name, mac_address, description, model, product, device_class,
                dir_number, serial_number, model_number, hardware_revision,
                first_seen, last_seen, last_ip)
             VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE
               mac_address       = COALESCE(VALUES(mac_address), mac_address),
               description       = COALESCE(NULLIF(VALUES(description),  \'\'), description),
               model             = COALESCE(NULLIF(VALUES(model),        \'\'), model),
               product           = COALESCE(NULLIF(VALUES(product),      \'\'), product),
               device_class      = COALESCE(NULLIF(VALUES(device_class), \'\'), device_class),
               dir_number        = COALESCE(NULLIF(VALUES(dir_number),   \'\'), dir_number),
               serial_number     = COALESCE(NULLIF(VALUES(serial_number),\'\'), serial_number),
               model_number      = COALESCE(NULLIF(VALUES(model_number), \'\'), model_number),
               hardware_revision = COALESCE(NULLIF(VALUES(hardware_revision), \'\'), hardware_revision),
               last_seen         = NOW(),
               last_ip           = COALESCE(NULLIF(VALUES(last_ip), \'\'), last_ip),
               id                = LAST_INSERT_ID(id)'
        );
        $row = $this->clipRow('phones', [
            'device_name'       => $p['device_name'],
            'mac_address'       => $p['mac_address'] ?? cps_mac_from_device_name($p['device_name']),
            'description'       => $p['description'] ?? null,
            'model'             => $p['model'] ?? null,
            'product'           => $p['product'] ?? null,
            'device_class'      => $p['device_class'] ?? null,
            'dir_number'        => $p['dir_number'] ?? null,
            'serial_number'     => $p['serial_number'] ?? null,
            'model_number'      => $p['model_number'] ?? null,
            'hardware_revision' => $p['hardware_revision'] ?? null,
            'last_ip'           => $p['ip'] ?? null,
        ]);
        $st->execute(array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    public function insertRegistration(int $sweepId, int $phoneId, array $r): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO registrations
               (sweep_id, phone_id, ip_address, ip_addresses, status, status_reason,
                node_name, ris_server, protocol, active_load, inactive_load,
                http_supported, ris_timestamp, source)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               ip_address = VALUES(ip_address), status = VALUES(status)'
        );
        $http = cps_tri_bool($r['http_supported'] ?? '');
        $row = $this->clipRow('registrations', [
            'ip_address'    => $r['ip'] ?: null,
            'status'        => $r['status'] ?? null,
            'status_reason' => $r['status_reason'] ?? null,
            'node_name'     => $r['node_name'] ?? null,
            'ris_server'    => $r['ris_server'] ?? null,
            'protocol'      => $r['protocol'] ?? null,
            'active_load'   => $r['active_load'] ?? null,
            'inactive_load' => $r['inactive_load'] ?? null,
            'ris_timestamp' => $r['ris_timestamp'] ?? null,
            'source'        => $r['source'] ?? 'risport',
        ]);
        $st->execute([
            $sweepId, $phoneId,
            $row['ip_address'],
            isset($r['ip_addresses']) ? (json_encode($r['ip_addresses'], JSON_INVALID_UTF8_SUBSTITUTE) ?: null) : null,
            $row['status'], $row['status_reason'], $row['node_name'], $row['ris_server'],
            $row['protocol'], $row['active_load'], $row['inactive_load'],
            $http, $row['ris_timestamp'], $row['source'],
        ]);
    }

    public function insertPortStats(int $sweepId, int $phoneId, string $port, array $norm): void
    {
        $cols = array_merge(
            ['sweep_id', 'phone_id', 'port', 'collected_at', 'link_up', 'speed_mbps', 'duplex'],
            CPS_PORT_COLUMNS,
            ['errors_total', 'unmapped_labels']
        );
        $place = [];
        $vals  = [];
        foreach ($cols as $c) {
            if ($c === 'collected_at') { $place[] = 'NOW()'; continue; }
            $place[] = '?';
            $vals[]  = match ($c) {
                'sweep_id'        => $sweepId,
                'phone_id'        => $phoneId,
                'port'            => $port,
                'link_up'         => $norm['link']['link_up'],
                'speed_mbps'      => $norm['link']['speed_mbps'],
                'duplex'          => $norm['link']['duplex'],
                'errors_total'    => $norm['errors_total'],
                'unmapped_labels' => $norm['unmapped'],
                default           => $norm['counters'][$c] ?? null,
            };
        }
        $upd = [];
        foreach ($cols as $c) {
            if (in_array($c, ['sweep_id', 'phone_id', 'port'], true)) { continue; }
            $upd[] = "$c = VALUES($c)";
        }
        $sql = 'INSERT INTO port_stats (' . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')'
             . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $upd);
        $this->pdo->prepare($sql)->execute($vals);
    }

    /**
     * Mirror the cluster's model-enum table.
     * @param array<int,string> $names
     */
    public function upsertDeviceModels(array $names): int
    {
        if (!$names) { return 0; }
        $st = $this->pdo->prepare(
            'INSERT INTO device_models (enum, name, updated_at) VALUES (?,?,NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = NOW()'
        );
        $n = 0;
        foreach ($names as $enum => $name) {
            $st->execute([(int)$enum, mb_substr((string)$name, 0, 128)]);
            $n++;
        }
        return $n;
    }

    /** Replace stored numeric models with their names. */
    public function backfillModelNames(): int
    {
        return (int)$this->pdo->exec(
            'UPDATE phones p JOIN device_models dm ON dm.enum = CAST(p.model AS UNSIGNED)
             SET p.model = dm.name
             WHERE p.model REGEXP \'^[0-9]+$\''
        );
    }

    /**
     * Store the phone's reported uplink for this sweep.
     * @param array $n       raw cdp_ and lldp_ fields
     * @param array $summary collapsed switch_name/switch_port/switch_ip/source
     */
    public function insertUplink(int $sweepId, int $phoneId, array $n, array $summary): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO uplinks
               (sweep_id, phone_id, cdp_neighbour, cdp_neighbour_port, cdp_neighbour_ip,
                cdp_neighbour_platform, lldp_neighbour, lldp_neighbour_port, lldp_neighbour_ip,
                switch_name, switch_port, switch_ip, neighbour_source, collected_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
             ON DUPLICATE KEY UPDATE
               cdp_neighbour = VALUES(cdp_neighbour),
               cdp_neighbour_port = VALUES(cdp_neighbour_port),
               cdp_neighbour_ip = VALUES(cdp_neighbour_ip),
               cdp_neighbour_platform = VALUES(cdp_neighbour_platform),
               lldp_neighbour = VALUES(lldp_neighbour),
               lldp_neighbour_port = VALUES(lldp_neighbour_port),
               lldp_neighbour_ip = VALUES(lldp_neighbour_ip),
               switch_name = VALUES(switch_name),
               switch_port = VALUES(switch_port),
               switch_ip = VALUES(switch_ip),
               neighbour_source = VALUES(neighbour_source),
               collected_at = NOW()'
        );
        $row = $this->clipRow('uplinks', [
            'cdp_neighbour'          => $n['cdp_neighbour'] ?? null,
            'cdp_neighbour_port'     => $n['cdp_neighbour_port'] ?? null,
            'cdp_neighbour_ip'       => $n['cdp_neighbour_ip'] ?? null,
            'cdp_neighbour_platform' => $n['cdp_neighbour_platform'] ?? null,
            'lldp_neighbour'         => $n['lldp_neighbour'] ?? null,
            'lldp_neighbour_port'    => $n['lldp_neighbour_port'] ?? null,
            'lldp_neighbour_ip'      => $n['lldp_neighbour_ip'] ?? null,
            'switch_name'            => $summary['switch_name'] ?? null,
            'switch_port'            => $summary['switch_port'] ?? null,
            'switch_ip'              => $summary['switch_ip'] ?? null,
        ]);
        $st->execute([
            $sweepId, $phoneId,
            $row['cdp_neighbour'], $row['cdp_neighbour_port'], $row['cdp_neighbour_ip'],
            $row['cdp_neighbour_platform'],
            $row['lldp_neighbour'], $row['lldp_neighbour_port'], $row['lldp_neighbour_ip'],
            $row['switch_name'], $row['switch_port'], $row['switch_ip'],
            $summary['source'] ?? null,
        ]);
    }

    /**
     * Upsert each known phone's Common Phone Profile.
     * @param array<string,array{uuid:?string,name:?string,server:string}> $profiles
     */
    public function upsertPhoneConfig(array $profiles): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO phone_config (phone_id, common_phone_profile_uuid, common_phone_profile_name, axl_server, updated_at)
             SELECT id, ?, ?, ?, NOW() FROM phones WHERE device_name = ?
             ON DUPLICATE KEY UPDATE
               common_phone_profile_uuid = VALUES(common_phone_profile_uuid),
               common_phone_profile_name = VALUES(common_phone_profile_name),
               axl_server = VALUES(axl_server), updated_at = NOW()'
        );
        $n = 0;
        foreach ($profiles as $name => $p) {
            $row = $this->clipRow('phone_config', ['common_phone_profile_name' => $p['name'], 'axl_server' => $p['server']]);
            $st->execute([CpsAxlClient::normUuid((string)$p['uuid']), $row['common_phone_profile_name'],
                          $row['axl_server'], (string)$name]);
            $n += $st->rowCount() > 0 ? 1 : 0;
        }
        return $n;
    }

    public function insertPageFetch(int $sweepId, int $phoneId, string $adapter, array $page): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO page_fetches
               (sweep_id, phone_id, adapter, ok, http_code, error_kind, error, pairs, fetched_at)
             VALUES (?,?,?,?,?,?,?,?, NOW())
             ON DUPLICATE KEY UPDATE
               ok = VALUES(ok), http_code = VALUES(http_code), error_kind = VALUES(error_kind),
               error = VALUES(error), pairs = VALUES(pairs), fetched_at = NOW()'
        );
        $st->execute([
            $sweepId, $phoneId, $adapter,
            !empty($page['ok']) ? 1 : 0,
            $page['http_code'] ?? null,
            $page['error_kind'] ?? null,
            $page['error'] ?? null,
            isset($page['pairs']) ? (json_encode($page['pairs'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null) : null,
        ]);
    }

    /**
     * Store one switch capture and its ports; identical output only advances captured_at.
     * $capturedAt (unix time or DATETIME string) is converted by MySQL to match NOW().
     * @return array{id:int, status:string} status is 'new' or 'unchanged'
     */
    public function importSwitchCapture(string $switchName, string $raw, array $parsed,
                                        ?string $sourceFile, int|string $capturedAt): array
    {
        $key = cps_switch_key($switchName);
        $sha = hash('sha256', $raw);
        $ip  = filter_var($key, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $key : null;

        $at = is_int($capturedAt) ? 'FROM_UNIXTIME(?)' : 'CAST(? AS DATETIME)';

        $have = $this->query('SELECT id FROM switch_captures WHERE switch_key = ? AND content_sha256 = ?', [$key, $sha]);
        if ($have) {
            $id = (int)$have[0]['id'];
            $this->pdo->prepare("UPDATE switch_captures SET captured_at = GREATEST(captured_at, $at), imported_at = NOW()
                                 WHERE id = ?")->execute([$capturedAt, $id]);
            return ['id' => $id, 'status' => 'unchanged'];
        }

        $this->begin();
        try {
            $row = $this->clipRow('switch_captures', [
                'switch_name' => $switchName, 'switch_key' => $key, 'switch_ip' => $ip,
                'source_file' => $sourceFile, 'content_sha256' => $sha,
                'ports_parsed' => count($parsed['ports']), 'lines_unparsed' => $parsed['unparsed'],
                'raw_output' => $raw,
            ]);
            $this->pdo->prepare(
                "INSERT INTO switch_captures (switch_name, switch_key, switch_ip, source_file,
                   content_sha256, ports_parsed, lines_unparsed, raw_output, captured_at, imported_at)
                 VALUES (?,?,?,?,?,?,?,?, $at, NOW())"
            )->execute([...array_values($row), $capturedAt]);
            $id = (int)$this->pdo->lastInsertId();

            $cols = ['port', 'port_key', 'name', 'status', 'vlan', 'duplex_raw', 'speed_raw', 'port_type',
                     'duplex', 'speed_mbps', 'duplex_auto', 'speed_auto'];
            // A repeated port keeps its last row.
            $st = $this->pdo->prepare(
                'INSERT INTO switch_ports (capture_id, ' . implode(',', $cols) . ')
                 VALUES (?' . str_repeat(',?', count($cols)) . ')
                 ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols))
            );
            foreach ($parsed['ports'] as $p) {
                $p = $this->clipRow('switch_ports', array_intersect_key($p, array_flip($cols)));
                $st->execute(array_merge([$id], array_map(fn($c) => $p[$c] ?? null, $cols)));
            }
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
        return ['id' => $id, 'status' => 'new'];
    }

    public function begin(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void {
        if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
    }

    /**
     * Run a query and return its rows and column names (known even with no rows).
     * @return array{rows: array<int,array<string,mixed>>, columns: string[]}
     */
    public function queryWithColumns(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        $cols = [];
        for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
            $meta = @$st->getColumnMeta($i);
            if (is_array($meta) && isset($meta['name'])) { $cols[] = (string)$meta['name']; }
        }
        // No driver metadata: fall back to the first row's keys.
        if (!$cols && $rows) { $cols = array_keys($rows[0]); }
        return ['rows' => $rows, 'columns' => $cols];
    }

    /** @return array<int,array<string,mixed>> */
    public function query(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Device names flagged by a sweep. $reasons: mismatch, errors, growing,
     * switch (default all but growing). A missing switch view is skipped.
     * @param array<string,int> $counts filled with phones per reason
     * @return string[]
     */
    public function problemPhones(int $sweepId, array &$counts = [], ?array $reasons = null, int $minErrors = 1): array
    {
        $names = [];
        $all = [
            'mismatch' => ['SELECT device_name FROM v_port_speed_mismatch WHERE sweep_id = ?', [$sweepId]],
            'errors'   => ['SELECT device_name FROM v_port_errors WHERE sweep_id = ? AND errors_total >= ?', [$sweepId, $minErrors]],
            'growing'  => ['SELECT device_name FROM v_port_errors_increasing WHERE sweep_id = ?', [$sweepId]],
            'switch'   => ['SELECT device_name FROM v_switch_port_mismatch WHERE sweep_id = ?', [$sweepId]],
        ];
        $reasons ??= ['mismatch', 'errors', 'switch'];
        foreach ($reasons as $why) {
            if (!isset($all[$why])) {
                throw new InvalidArgumentException("unknown problem '$why'; known: " . implode(', ', array_keys($all)));
            }
            [$sql, $params] = $all[$why];
            try {
                $rows = $this->query($sql, $params);
            } catch (PDOException $e) {
                if ($why !== 'switch') { throw $e; }
                cps_warn('switch mismatches skipped, run cucm-initdb.php: ' . $e->getMessage());
                continue;
            }
            $counts[$why] = count(array_unique(array_column($rows, 'device_name')));
            foreach ($rows as $r) { $names[$r['device_name']] = true; }
        }
        $out = array_keys($names);
        sort($out);
        return $out;
    }

    public function latestSweepId(): ?int
    {
        $r = $this->query('SELECT id FROM sweeps WHERE finished_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 1');
        return $r ? (int)$r[0]['id'] : null;
    }
}
