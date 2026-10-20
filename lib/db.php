<?php
/**
 * db.php - MySQL persistence for sweeps, phones and port statistics.
 *
 * One sweep is one transaction-per-phone rather than one giant transaction:
 * a sweep of a few thousand handsets takes long enough that holding a single
 * transaction open across all of it turns a mid-run failure into a total
 * loss of the run. Per-phone commits mean an interrupted sweep still leaves
 * usable data, and the sweep row's finished_at (NULL until the end) marks it
 * as partial so the reporting views can exclude it.
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/switch.php';

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
    }

    public function pdo(): PDO { return $this->pdo; }

    /**
     * Apply schema.sql.
     *
     * Comment lines are stripped BEFORE splitting, not after. Splitting
     * first and then skipping any chunk that begins with "--" throws away
     * every statement that happens to be preceded by a comment block - which
     * silently cost this schema its page_fetches table and all five views,
     * while still reporting success. Statements are separated by ";" at end
     * of line, which is safe here because the schema has no stored programs
     * and no semicolons inside string literals.
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

    /**
     * Column widths that must be at least this wide, applied on every
     * initdb run.
     *
     * CREATE TABLE IF NOT EXISTS cannot widen a column that already exists,
     * so a schema change to a live database needs an explicit, idempotent
     * ALTER. Only widening is ever attempted - narrowing would truncate real
     * data - and a column already wide enough is left alone.
     */
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

    /**
     * Clip over-long strings to what the column can hold.
     *
     * A single over-long value used to fail the whole INSERT, and because
     * writes are one transaction per phone that cost the entire handset -
     * its registration, its port statistics, everything - over one field.
     * Losing the tail of a field is a far better outcome, and it is
     * announced rather than silent.
     *
     * Driven by the live column widths, so it also covers the fields nobody
     * predicted would overflow. That is the point: dir_number was widened
     * because it was the one that broke, but it will not be the last.
     */
    private function clipRow(string $table, array $row): array
    {
        $w = $this->widths($table);
        foreach ($row as $col => $v) {
            if (!is_string($v) || !isset($w[$col])) { continue; }
            if (mb_strlen($v) <= $w[$col]) { continue; }
            $key = "$table.$col";
            if (!isset($this->clipWarned[$key])) {
                $this->clipWarned[$key] = true;
                cps_warn(sprintf('%s: value of %d chars clipped to %d (e.g. "%s") - widen the column if this matters',
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
        $st->execute([$source, implode(', ', $servers), $toolVersion]);
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

    /**
     * Insert or update the inventory row for a device and return its id.
     *
     * COALESCE on update so a sweep that could not reach the phone (and so
     * learned no serial number) does not wipe the serial a previous sweep
     * did learn. Losing known-good inventory to a failed poll is a quiet,
     * cumulative kind of damage.
     */
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
               description       = COALESCE(NULLIF(VALUES(description),  ""), description),
               model             = COALESCE(NULLIF(VALUES(model),        ""), model),
               product           = COALESCE(NULLIF(VALUES(product),      ""), product),
               device_class      = COALESCE(NULLIF(VALUES(device_class), ""), device_class),
               dir_number        = COALESCE(NULLIF(VALUES(dir_number),   ""), dir_number),
               serial_number     = COALESCE(NULLIF(VALUES(serial_number),""), serial_number),
               model_number      = COALESCE(NULLIF(VALUES(model_number), ""), model_number),
               hardware_revision = COALESCE(NULLIF(VALUES(hardware_revision), ""), hardware_revision),
               last_seen         = NOW(),
               last_ip           = COALESCE(NULLIF(VALUES(last_ip), ""), last_ip),
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
            isset($r['ip_addresses']) ? json_encode($r['ip_addresses']) : null,
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
     * Store what the phone says it is plugged into for this sweep.
     *
     * @param array $n       raw cdp_ and lldp_ fields
     * @param array $summary collapsed switch_name/switch_port/switch_ip/source
     */
    /**
     * Mirror the cluster's model-enum table.
     *
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

    /**
     * Replace numeric models already stored with their names.
     *
     * A sweep fixes every phone it sees, but a handset that is not currently
     * registered would keep its enum indefinitely - so this catches the rest
     * without needing them to come back online.
     */
    public function backfillModelNames(): int
    {
        return (int)$this->pdo->exec(
            'UPDATE phones p JOIN device_models dm ON dm.enum = CAST(p.model AS UNSIGNED)
             SET p.model = dm.name
             WHERE p.model REGEXP \'^[0-9]+$\''
        );
    }

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
            isset($page['pairs']) ? json_encode($page['pairs'], JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /**
     * Store one switch's "show interface status" output and its parsed rows.
     *
     * Identical output for the same switch is not stored twice: it only
     * moves captured_at forward, since a re-run that saw the same thing is
     * still fresher evidence. One transaction per capture, so a failure
     * never leaves a capture with half its ports.
     *
     * $capturedAt is a unix time (a file mtime) or a DATETIME string. The
     * conversion is done by MySQL, so it lands in the same zone as every
     * NOW() in this schema even when the CLI's PHP zone differs.
     *
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
            // A port listed twice (paged output captured with a repeat) keeps
            // its last appearance rather than failing the whole capture.
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
     * Run a query and return both the rows and the SELECT's column names.
     *
     * The column list comes from the statement rather than from the first
     * row, so a result with no rows still knows its shape. CSV output needs
     * that: a report with nothing to say should be a header and no data
     * rows, not a zero-byte file, which is indistinguishable from the
     * command having failed.
     *
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
        // Drivers are allowed to refuse metadata; fall back to the keys of
        // the first row, and to nothing if there is not one.
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

    public function latestSweepId(): ?int
    {
        $r = $this->query('SELECT id FROM sweeps WHERE finished_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 1');
        return $r ? (int)$r[0]['id'] : null;
    }
}
