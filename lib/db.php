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
        $st->execute([
            $p['device_name'],
            $p['mac_address']       ?? cps_mac_from_device_name($p['device_name']),
            $p['description']       ?? null,
            $p['model']             ?? null,
            $p['product']           ?? null,
            $p['device_class']      ?? null,
            $p['dir_number']        ?? null,
            $p['serial_number']     ?? null,
            $p['model_number']      ?? null,
            $p['hardware_revision'] ?? null,
            $p['ip']                ?? null,
        ]);
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
        $st->execute([
            $sweepId, $phoneId,
            $r['ip'] ?: null,
            isset($r['ip_addresses']) ? json_encode($r['ip_addresses']) : null,
            $r['status'] ?? null,
            $r['status_reason'] ?? null,
            $r['node_name'] ?? null,
            $r['ris_server'] ?? null,
            $r['protocol'] ?? null,
            $r['active_load'] ?? null,
            $r['inactive_load'] ?? null,
            $http,
            $r['ris_timestamp'] ?? null,
            $r['source'] ?? 'risport',
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
        $st->execute([
            $sweepId, $phoneId,
            $n['cdp_neighbour'] ?? null,  $n['cdp_neighbour_port'] ?? null,
            $n['cdp_neighbour_ip'] ?? null, $n['cdp_neighbour_platform'] ?? null,
            $n['lldp_neighbour'] ?? null, $n['lldp_neighbour_port'] ?? null,
            $n['lldp_neighbour_ip'] ?? null,
            $summary['switch_name'] ?? null, $summary['switch_port'] ?? null,
            $summary['switch_ip'] ?? null,   $summary['source'] ?? null,
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
