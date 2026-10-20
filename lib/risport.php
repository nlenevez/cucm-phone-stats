<?php
/**
 * risport.php - CUCM RisPort70 client: registered devices and their addresses.
 *
 * Responses are capped (1000) and paged via StateInfo; requests are rate
 * limited per user per node.
 */

require_once __DIR__ . '/soap.php';

const CPS_RIS_NS   = 'http://schemas.cisco.com/ast/soap';
const CPS_RIS_PATH = '/realtimeservice2/services/RISService70';

class CpsRisportClient
{
    /** Diagnostics from the most recent selectCmDevice call. */
    private array $lastDiag = [];

    /** Request timestamps per server URL; static so all clients share the limit. */
    private static array $requestLog = [];

    public function __construct(
        private CpsSoapClient $soap,
        private int $maxReturnedDevices = 1000,
        private int $requestGapMs = 4000,
        private int $ratePerMin = 12,
        private int $rateRetries = 4,
    ) {}

    /** Block until another request fits the sliding one-minute window. */
    private function rateLimit(): void
    {
        $key = $this->soap->baseUrl();
        $now = microtime(true);
        self::$requestLog[$key] = array_values(array_filter(
            self::$requestLog[$key] ?? [], fn($t) => $now - $t < 60.0
        ));

        if ($this->ratePerMin > 0 && count(self::$requestLog[$key]) >= $this->ratePerMin) {
            $oldest = self::$requestLog[$key][0];
            $wait   = 60.0 - ($now - $oldest) + 0.25;
            if ($wait > 0) {
                cps_log(sprintf('%s: rate limit reached (%d/min), waiting %.1fs',
                    $key, $this->ratePerMin, $wait), 2);
                usleep((int)($wait * 1_000_000));
            }
        }
        if ($this->requestGapMs > 0) {
            $last = end(self::$requestLog[$key]);
            if ($last !== false) {
                $since = (microtime(true) - $last) * 1000;
                if ($since < $this->requestGapMs) {
                    usleep((int)(($this->requestGapMs - $since) * 1000));
                }
            }
        }
        self::$requestLog[$key][] = microtime(true);
    }

    /** Does this fault mean "you are asking too often"? */
    public static function isRateLimitFault(Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, 'exceeded allowed rate')
            || str_contains($m, 'rate control')
            || str_contains($m, 'throttl')
            || str_contains($m, 'too many requests');
    }

    /** POST one selectCmDevice, backing off and retrying on rate-limit faults. */
    private function postWithRetry(string $envelope): DOMDocument
    {
        $attempt = 0;
        while (true) {
            $this->rateLimit();
            try {
                return $this->soap->post(CPS_RIS_PATH, $envelope, '""');
            } catch (Throwable $e) {
                if (!self::isRateLimitFault($e) || $attempt >= $this->rateRetries) { throw $e; }
                $attempt++;
                $backoff = min(60, 15 * $attempt);
                cps_warn(sprintf('%s: rate limited, retry %d/%d in %ds: %s',
                    $this->soap->baseUrl(), $attempt, $this->rateRetries, $backoff, trim($e->getMessage())));
                sleep($backoff);
            }
        }
    }

    public function baseUrl(): string { return $this->soap->baseUrl(); }

    public function lastDiagnostics(): array { return $this->lastDiag; }

    /**
     * selectCmDevice, following StateInfo until the cluster is exhausted.
     *
     * @param string $status Registered|UnRegistered|Rejected|Any
     * @param string $deviceClass Phone|Gateway|H323|Cti|VoiceMail|MediaResources|Any
     * @return array<int,array<string,mixed>> flattened device rows
     */
    public function selectCmDevice(
        string $status = 'Registered',
        string $deviceClass = 'Phone',
        array  $selectItems = ['*'],
        string $selectBy = 'Name',
        string $nodeName = '',
    ): array {
        $devices   = [];
        $stateInfo = '';
        $seenState = [];
        $seenNames = [];
        $round     = 0;
        $this->lastDiag = [
            'server'        => $this->soap->baseUrl(),
            'status_filter' => $status,
            'device_class'  => $deviceClass,
            'max_returned'  => $this->maxReturnedDevices,
            'rounds'        => [],
            'nodes'         => [],
            'sample_device_xml' => null,
        ];

        while (true) {
            $round++;
            $itemsXml = '';
            foreach ($selectItems as $it) {
                $itemsXml .= '<soap:item><soap:Item>' . cps_xesc($it) . '</soap:Item></soap:item>';
            }
            $body = '<soap:selectCmDevice>'
                  . '<soap:StateInfo>' . cps_xesc($stateInfo) . '</soap:StateInfo>'
                  . '<soap:CmSelectionCriteria>'
                  . '<soap:MaxReturnedDevices>' . $this->maxReturnedDevices . '</soap:MaxReturnedDevices>'
                  . '<soap:DeviceClass>' . cps_xesc($deviceClass) . '</soap:DeviceClass>'
                  . '<soap:Model>255</soap:Model>'          // 255 = any model
                  . '<soap:Status>' . cps_xesc($status) . '</soap:Status>'
                  . '<soap:NodeName>' . cps_xesc($nodeName) . '</soap:NodeName>'
                  . '<soap:SelectBy>' . cps_xesc($selectBy) . '</soap:SelectBy>'
                  . '<soap:SelectItems>' . $itemsXml . '</soap:SelectItems>'
                  . '<soap:Protocol>Any</soap:Protocol>'
                  . '<soap:DownloadStatus>Any</soap:DownloadStatus>'
                  . '</soap:CmSelectionCriteria>'
                  . '</soap:selectCmDevice>';

            $env = CpsSoapClient::envelope($body, ['soap' => CPS_RIS_NS]);
            $doc = $this->postWithRetry($env);

            $found = cps_xml_text($doc, 'TotalDevicesFound');
            $batch = $this->parseDevices($doc);
            $before = count($seenNames);
            foreach ($batch as $d) { $seenNames[$d['name']] = true; }
            $grew = count($seenNames) > $before;
            $devices = array_merge($devices, $batch);

            // Per-node counts.
            foreach (cps_xml_find($doc, 'CmNodes') as $nodesEl) {
                foreach (cps_xml_children($nodesEl) as $nodeItem) {
                    $nn = cps_xml_text($nodeItem, 'Name', '(unnamed)');
                    $cd = cps_xml_first($nodeItem, 'CmDevices');
                    $cnt = $cd === null ? 0 : count(cps_xml_children($cd));
                    $this->lastDiag['nodes'][$nn] = ($this->lastDiag['nodes'][$nn] ?? 0) + $cnt;
                }
            }
            // Keep one raw device element for diagnostics.
            if ($this->lastDiag['sample_device_xml'] === null) {
                foreach (cps_xml_find($doc, 'CmDevices') as $cdEl) {
                    $kids = cps_xml_children($cdEl);
                    if ($kids) {
                        $this->lastDiag['sample_device_xml'] = $doc->saveXML($kids[0]);
                        break;
                    }
                }
            }
            $this->lastDiag['rounds'][] = [
                'round' => $round, 'returned' => count($batch),
                'total_devices_found' => $found, 'state_info_present' => false,
            ];
            cps_log(sprintf(
                'RisPort %s round %d: %d devices (TotalDevicesFound=%s), %d accumulated',
                $this->soap->baseUrl(), $round, count($batch), $found ?? '?', count($devices)
            ), 2);

            $next = cps_xml_text($doc, 'StateInfo', '');
            $this->lastDiag['rounds'][count($this->lastDiag['rounds']) - 1]['state_info_present'] =
                ($next !== null && $next !== '');
            // Stop on no devices, no StateInfo, or a repeated StateInfo.
            if ($next === '' || $next === null || count($batch) === 0) { break; }
            // StateInfo that keeps changing without new devices would page forever.
            if (!$grew || $round >= 50) {
                cps_warn(sprintf('RisPort %s: paging stopped after %d rounds (%s)', $this->soap->baseUrl(), $round,
                    $grew ? 'round limit' : 'no new devices'));
                break;
            }
            if (isset($seenState[$next])) {
                cps_warn('RisPort repeated StateInfo; stopped paging');
                break;
            }
            $seenState[$next] = true;
            $stateInfo = $next;
        }
        $this->lastDiag['returned_total'] = count($devices);
        $statuses = [];
        foreach ($devices as $d) { $statuses[$d['status'] ?: '(empty)'] = ($statuses[$d['status'] ?: '(empty)'] ?? 0) + 1; }
        $this->lastDiag['status_histogram'] = $statuses;
        return $devices;
    }

    /** @return array<int,array<string,mixed>> */
    private function parseDevices(DOMDocument $doc): array
    {
        $out = [];
        foreach (cps_xml_find($doc, 'CmNodes') as $nodes) {
            foreach (cps_xml_children($nodes) as $nodeItem) {
                $nodeName = cps_xml_text($nodeItem, 'Name', '');
                $cmDevices = cps_xml_first($nodeItem, 'CmDevices');
                if ($cmDevices === null) { continue; }
                foreach (cps_xml_children($cmDevices) as $dev) {
                    $out[] = $this->parseDevice($dev, $nodeName);
                }
            }
        }
        return $out;
    }

    private function parseDevice(DOMElement $dev, string $nodeName): array
    {
        $ips = [];
        if ($ipEl = cps_xml_first($dev, 'IPAddress')) {
            foreach (cps_xml_children($ipEl) as $item) {
                $ip = cps_xml_text($item, 'IP');
                if ($ip === null || $ip === '') { continue; }
                $ips[] = [
                    'ip'        => $ip,
                    'type'      => cps_xml_text($item, 'IPAddrType', ''),
                    'attribute' => cps_xml_text($item, 'Attribute', ''),
                ];
            }
        }
        // Prefer IPv4 (IPv6 literals need bracketing in URLs).
        $primary = null;
        foreach ($ips as $c) {
            if (stripos($c['type'], 'v6') === false) { $primary = $c['ip']; break; }
        }
        if ($primary === null && $ips) { $primary = $ips[0]['ip']; }

        $http = cps_xml_text($dev, 'HttpSupported', '');

        return [
            'name'          => cps_xml_text($dev, 'Name', ''),
            'description'   => cps_xml_text($dev, 'Description', ''),
            'dir_number'    => cps_xml_text($dev, 'DirNumber', ''),
            'device_class'  => cps_xml_text($dev, 'Class', ''),
            'model'         => cps_xml_text($dev, 'Model', ''),
            'product'       => cps_xml_text($dev, 'Product', ''),
            'status'        => cps_xml_text($dev, 'Status', ''),
            'status_reason' => cps_xml_text($dev, 'StatusReason', ''),
            'protocol'      => cps_xml_text($dev, 'Protocol', ''),
            'active_load'   => cps_xml_text($dev, 'ActiveLoadID', ''),
            'inactive_load' => cps_xml_text($dev, 'InactiveLoadID', ''),
            'ris_timestamp' => cps_xml_text($dev, 'TimeStamp', cps_xml_text($dev, 'Timestamp', '')),
            'http_supported'=> $http,
            'node_name'     => $nodeName,
            'ip'            => $primary,
            'ip_addresses'  => $ips,
        ];
    }

    /** Most devices one selectCmDevice conversation returns. */
    public const HARD_CAP = 1000;

    /** The most this client can expect back from a single query. */
    private function effectiveCap(): int
    {
        return min(max(1, $this->maxReturnedDevices), self::HARD_CAP);
    }

    /**
     * Every matching device. A result within 90% of the cap or short of
     * TotalDevicesFound is re-queried per node.
     *
     * @param string $mode auto | off | always
     * @return array<int,array<string,mixed>>
     */
    public function selectCmDeviceComplete(string $status = 'Registered', string $deviceClass = 'Phone', string $mode = 'auto'): array
    {
        $cap     = $this->effectiveCap();
        $near    = (int)ceil($cap * 0.9);
        $rows    = $this->selectCmDevice($status, $deviceClass);
        $diag    = $this->lastDiagnostics();
        $totals  = array_map('intval', array_filter(array_column($diag['rounds'] ?? [], 'total_devices_found'),
                                                    fn($v) => $v !== null && $v !== ''));
        $claimed = $totals ? max($totals) : null;
        $capped  = count($rows) >= $near || ($claimed !== null && count($rows) < $claimed);

        $this->lastDiag['enumeration'] = ['mode' => 'single', 'returned' => count($rows),
                                          'total_devices_found' => $claimed, 'truncated' => false];
        if ($mode === 'off' || ($mode !== 'always' && !$capped)) {
            if ($capped) { $this->lastDiag['enumeration']['truncated'] = true; }
            return $rows;
        }

        $nodes = array_values(array_filter(array_keys($diag['nodes'] ?? []), fn($n) => $n !== '' && $n !== '(unnamed)'));
        if (count($nodes) < 2) {
            if ($capped) {
                cps_warn(sprintf('%s: %d devices returned, at the %d cap, and only one node to split by - results incomplete',
                    $this->soap->baseUrl(), count($rows), $cap));
                $this->lastDiag['enumeration']['truncated'] = true;
            }
            return $rows;
        }

        cps_log(sprintf('%s: %d devices returned%s - querying each of %d nodes',
            $this->soap->baseUrl(), count($rows), $capped ? ", at the $cap cap" : '', count($nodes)));
        $byName = [];
        $keep = function (array $r) use (&$byName): void {
            $n = $r['name'];
            if ($n !== '' && (!isset($byName[$n]) || self::isBetter($r, $byName[$n]))) { $byName[$n] = $r; }
        };
        foreach ($rows as $r) { $keep($r); }
        $short = [];
        foreach ($nodes as $node) {
            try {
                $nodeRows = $this->selectCmDevice($status, $deviceClass, ['*'], 'Name', $node);
            } catch (Throwable $e) {
                cps_warn("RisPort node $node failed: " . $e->getMessage());
                $short[] = $node;
                continue;
            }
            foreach ($nodeRows as $r) { $keep($r); }
            cps_log(sprintf('  node %s: %d devices', $node, count($nodeRows)), 2);
            if (count($nodeRows) >= $near) { $short[] = $node; }
        }
        if ($short) {
            cps_warn(sprintf('%s: node(s) %s at or near the %d cap - results may be incomplete',
                $this->soap->baseUrl(), implode(', ', $short), $cap));
        }
        $under = $claimed !== null && count($byName) < $claimed && $claimed < $cap;
        if ($under) {
            cps_warn(sprintf('%s: %d devices after per-node queries, TotalDevicesFound said %d - results incomplete',
                $this->soap->baseUrl(), count($byName), $claimed));
        }
        $this->lastDiag['enumeration'] = ['mode' => 'by node', 'nodes_queried' => $nodes, 'returned' => count($byName),
                                          'total_devices_found' => $claimed, 'truncated' => $short || $under];
        return array_values($byName);
    }

    /**
     * Query several servers and merge, keeping the best row per device
     * (has an IP, then Registered).
     *
     * @param CpsRisportClient[] $clients
     * @return array{devices: array<string,array>, errors: array<int,array>}
     */
    public static function discover(
        array $clients,
        string $status = 'Registered',
        string $deviceClass = 'Phone',
        string $mode = 'auto',
    ): array {
        $byName = [];
        $errors = [];
        $perServer = [];
        foreach ($clients as $c) {
            try {
                $rows = $c->selectCmDeviceComplete($status, $deviceClass, $mode);
            } catch (Throwable $e) {
                $errors[] = ['server' => $c->baseUrl(), 'error' => $e->getMessage()];
                cps_warn($c->baseUrl() . ': ' . $e->getMessage());
                continue;
            }
            $added = 0;
            foreach ($rows as $r) {
                $r['ris_server'] = $c->baseUrl();
                $name = $r['name'];
                if ($name === '') { continue; }
                if (!isset($byName[$name])) { $added++; }
                if (!isset($byName[$name]) || self::isBetter($r, $byName[$name])) {
                    $byName[$name] = $r;
                }
            }
            $diag = $c->lastDiagnostics();
            $perServer[$c->baseUrl()] = [
                'returned'    => count($rows),
                'new_unique'  => $added,
                'nodes'       => $diag['nodes'] ?? [],
                'enumeration' => $diag['enumeration'] ?? null,
            ];
            cps_log(sprintf('%s: %d devices (%d new)', $c->baseUrl(), count($rows), $added));
            cps_log(sprintf('%s: nodes: %s', $c->baseUrl(),
                ($diag['nodes'] ?? []) ? json_encode($diag['nodes']) : 'none reported'), 2);
        }
        return ['devices' => $byName, 'errors' => $errors, 'per_server' => $perServer];
    }

    /**
     * Look up a known list of device names, $chunk names per query.
     *
     * @param CpsRisportClient[] $clients
     * @param string[] $names
     * @return array{devices: array<string,array>, errors: array<int,array>}
     */
    public static function discoverNames(array $clients, array $names, string $status = 'Registered', int $chunk = 200): array
    {
        $byName = [];
        $errors = [];
        foreach ($clients as $c) {
            foreach (array_chunk(array_values(array_unique($names)), $chunk) as $part) {
                try {
                    $rows = $c->selectCmDevice($status, 'Phone', $part);
                } catch (Throwable $e) {
                    $errors[] = ['server' => $c->baseUrl(), 'error' => $e->getMessage()];
                    cps_warn($c->baseUrl() . ': ' . $e->getMessage());
                    continue 2;
                }
                foreach ($rows as $r) {
                    $r['ris_server'] = $c->baseUrl();
                    $name = $r['name'];
                    if ($name === '') { continue; }
                    if (!isset($byName[$name]) || self::isBetter($r, $byName[$name])) {
                        $byName[$name] = $r;
                    }
                }
            }
        }
        return ['devices' => $byName, 'errors' => $errors];
    }

    private static function isBetter(array $new, array $old): bool
    {
        $score = function (array $r): int {
            $s = 0;
            if (!empty($r['ip'])) { $s += 2; }
            if (strcasecmp((string)$r['status'], 'Registered') === 0) { $s += 1; }
            return $s;
        };
        return $score($new) > $score($old);
    }
}
