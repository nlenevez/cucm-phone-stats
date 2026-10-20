<?php
/**
 * risport.php - CUCM Real-Time Information Port (RisPort70) client.
 *
 * Endpoint:    https://<cucm>:8443/realtimeservice2/services/RISService70
 * Namespace:   http://schemas.cisco.com/ast/soap
 * Role needed: Standard AXL API Access (+ Standard CCM Admin Users)
 *              see docs/cucm-permissions.md
 *
 * This is the authoritative answer to "which phones are registered, and on
 * what address" - the question AXL cannot answer. selectCmDevice returns,
 * per node, the devices that node knows about, including Status
 * (Registered / UnRegistered / Rejected / PartiallyRegistered / Unknown),
 * the current IP address, the active firmware load, and HttpSupported,
 * which says whether the phone's own web server is worth contacting.
 *
 * THREE LIMITS THAT SHAPE THIS CODE
 * ---------------------------------
 * 1. A single response is capped (1000 devices). More than that comes back
 *    across several calls, each seeded with the StateInfo blob returned by
 *    the previous one. Ignoring StateInfo silently truncates a large
 *    cluster to its first 1000 phones, which looks like a working script.
 * 2. RisPort is throttled per user (on the order of fifteen requests a
 *    minute; exceeding it earns an "Exceeded allowed rate" fault, not a
 *    queue). Hence $requestGapMs.
 * 3. A node only reports what it holds. Querying one node usually returns
 *    every node's devices in CmNodes, but a node whose RIS service is
 *    unhappy simply contributes nothing - with no error. That is why
 *    discover() takes a list of servers and merges, and why the result
 *    records which server each device was learned from.
 */

require_once __DIR__ . '/soap.php';

const CPS_RIS_NS   = 'http://schemas.cisco.com/ast/soap';
const CPS_RIS_PATH = '/realtimeservice2/services/RISService70';

class CpsRisportClient
{
    /**
     * Name patterns used to enumerate an estate too large for one query.
     *
     * RisPort caps how many devices a single selectCmDevice conversation
     * will yield, and it does not report that it has truncated - StateInfo
     * simply stops coming and the answer looks complete. Splitting the
     * request by device-name prefix keeps every individual query well under
     * the cap, which is the documented way around it.
     *
     * SEP is split on the first hex digit of the MAC, giving 16 roughly even
     * buckets for hard phones. The soft-client and analog prefixes follow so
     * that a partitioned run is not silently narrower than an unpartitioned
     * one - they have no port statistics to collect, but leaving them out
     * here would make the two paths disagree about what exists.
     */
    public const DEFAULT_PARTITIONS = [
        'SEP0*', 'SEP1*', 'SEP2*', 'SEP3*', 'SEP4*', 'SEP5*', 'SEP6*', 'SEP7*',
        'SEP8*', 'SEP9*', 'SEPA*', 'SEPB*', 'SEPC*', 'SEPD*', 'SEPE*', 'SEPF*',
        'CSF*', 'TCT*', 'BOT*', 'TAB*', 'ATA*', 'CIPC*', 'SIP*',
    ];

    /**
     * Diagnostics from the most recent selectCmDevice call. Populated even
     * when the result looks fine, because the interesting failure here is a
     * quiet undercount - a node that answers cleanly with a fraction of the
     * estate looks identical to a healthy full answer unless you compare the
     * returned count against TotalDevicesFound and the per-node breakdown.
     */
    private array $lastDiag = [];

    /**
     * Request timestamps per server, for the sliding-window rate limit.
     *
     * Static and keyed by base URL because the throttle is enforced per node
     * per user, not per PHP object: the probe builds fresh clients for the
     * same server, and each one starting with an empty history would let the
     * limit be exceeded immediately.
     */
    private static array $requestLog = [];

    public function __construct(
        private CpsSoapClient $soap,
        private int $maxReturnedDevices = 1000,
        private int $requestGapMs = 4000,
        private int $ratePerMin = 12,
        private int $rateRetries = 4,
    ) {}

    /**
     * Block until another request is allowed.
     *
     * A fixed gap between calls is the wrong shape for this limit. RisPort
     * allows a number of requests per minute, so a 4s gap sits exactly on 15
     * a minute with no headroom - and the budget is shared with anything
     * else querying realtime data as the same user. A sliding window spends
     * the allowance as fast as it is safe to and then waits, which is both
     * faster when there is room and safer when there is not.
     */
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

    /**
     * Issue one selectCmDevice, retrying when the cluster says we are asking
     * too often.
     *
     * A throttle fault is transient by definition, so treating it as a
     * failure is simply wrong: previously a throttled partition was warned
     * about and skipped, which dropped exactly the devices the partitioning
     * existed to recover, and left a short answer looking deliberate.
     */
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
                // The window is a minute, so waiting out a good part of one
                // is the only thing that actually clears the condition.
                $backoff = min(60, 15 * $attempt);
                cps_warn(sprintf('%s: %s - backing off %ds (attempt %d of %d)',
                    $this->soap->baseUrl(), trim($e->getMessage()), $backoff, $attempt, $this->rateRetries));
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
            $devices = array_merge($devices, $batch);

            // Per-node counts: RIS answers per node, and a node that is not
            // contributing is invisible in the flat device list.
            foreach (cps_xml_find($doc, 'CmNodes') as $nodesEl) {
                foreach (cps_xml_children($nodesEl) as $nodeItem) {
                    $nn = cps_xml_text($nodeItem, 'Name', '(unnamed)');
                    $cd = cps_xml_first($nodeItem, 'CmDevices');
                    $cnt = $cd === null ? 0 : count(cps_xml_children($cd));
                    $this->lastDiag['nodes'][$nn] = ($this->lastDiag['nodes'][$nn] ?? 0) + $cnt;
                }
            }
            // Keep one raw device element verbatim. Field names in this
            // response are the thing most likely to differ from the docs,
            // and a real sample settles it without another trip.
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
            // Stop on: no more devices, no StateInfo, or a StateInfo we have
            // already followed. That last guard matters - a cluster that
            // returns the same StateInfo forever would otherwise loop until
            // the throttle killed it.
            if ($next === '' || $next === null || count($batch) === 0) { break; }
            if (isset($seenState[$next])) {
                cps_warn('RisPort returned a repeated StateInfo; stopping paging to avoid a loop');
                break;
            }
            $seenState[$next] = true;
            $stateInfo = $next;
            // Pacing is handled by rateLimit() inside postWithRetry().
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
        // Prefer an IPv4 address for the HTTP fetch: the phone's web server
        // answers on both, but an IPv6 literal has to be bracketed in a URL
        // and half the tooling downstream of this will not do that.
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

    /**
     * RisPort will not return more than this from one selectCmDevice
     * conversation, whatever MaxReturnedDevices asks for.
     */
    public const HARD_CAP = 1000;

    /** The most this client can expect back from a single query. */
    private function effectiveCap(): int
    {
        return min(max(1, $this->maxReturnedDevices), self::HARD_CAP);
    }

    /**
     * Enumerate every matching device, working around the response cap.
     *
     * One plain query first, because on a small cluster that is the whole
     * answer and costs one round trip. Its TotalDevicesFound is then
     * compared against what actually arrived: if fewer came back than the
     * cluster says exist, the query was truncated and it is re-run split by
     * name prefix, merging the results.
     *
     * That comparison is the only reliable truncation signal available. A
     * capped response is well formed, carries no error, and simply stops
     * producing StateInfo - so a sweep that trusts it reports a confidently
     * wrong estate size.
     *
     * @param string|array $partitions 'auto', 'off', or an explicit list
     * @return array<int,array<string,mixed>>
     */
    public function selectCmDeviceComplete(
        string $status = 'Registered',
        string $deviceClass = 'Phone',
        string|array $partitions = 'auto',
        array $devicePrefixes = [],
        ?callable $nameProvider = null,
    ): array {
        $mode = is_array($partitions) ? 'explicit' : strtolower(trim($partitions));

        $selectItems = self::selectItemsFor($devicePrefixes);
        $cap         = $this->effectiveCap();

        if ($mode !== 'explicit') {
            $rows  = $this->selectCmDevice($status, $deviceClass, $selectItems);

            // Safety net: if scoping the query returns nothing at all, the
            // cluster may not accept a wildcard in SelectItems. Falling back
            // to an unscoped query once is far better than reporting an
            // empty estate, and it says so rather than hiding the fallback.
            if (!$rows && $selectItems !== ['*']) {
                cps_warn(sprintf('%s: scoped query (%s) returned nothing - retrying unscoped',
                    $this->soap->baseUrl(), implode(',', $selectItems)));
                $rows = $this->selectCmDevice($status, $deviceClass, ['*']);
                $selectItems = ['*'];
            }
            $diag  = $this->lastDiagnostics();
            $totals = array_map('intval', array_filter(array_column($diag['rounds'] ?? [], 'total_devices_found'), 'strlen'));
            $claimed = $totals ? max($totals) : null;

            // 90% rather than 100%: the documented cap is 1000 and asking for
            // 1000 gets exactly 1000, but an administrator can lower the
            // RisPort throttle below what we asked for, in which case the
            // result stops just short of our number. Splitting one extra time
            // is cheap; missing a truncation is not.
            $atCap   = count($rows) >= (int)ceil($cap * 0.9);
            $short   = $claimed !== null && count($rows) < $claimed;
            $reason  = null;
            if ($short) {
                $reason = sprintf('returned %d of the %d it says exist', count($rows), $claimed);
            } elseif ($atCap) {
                // TotalDevicesFound is not a reliable truncation signal: on a
                // capped response some releases report the CAPPED number, so
                // returned == claimed == 1000 and a comparison alone concludes
                // the answer is complete. Landing exactly on the cap is the
                // tell. An estate having precisely $cap registered phones is
                // vanishingly unlikely, and splitting when it has costs one
                // extra pass; NOT splitting when it has not loses devices
                // silently, which is far worse.
                $reason = sprintf('returned %d, at or near the %d-device cap (TotalDevicesFound=%s)',
                    count($rows), $cap, $claimed ?? '?');
            }

            if ($mode === 'always' && $reason === null) {
                $reason = 'ris_partitions = always';
            }
            if ($mode === 'off' || $reason === null) {
                $this->lastDiag['enumeration'] = [
                    'mode' => 'single', 'returned' => count($rows),
                    'total_devices_found' => $claimed, 'truncated' => false,
                ];
                return $rows;
            }
            cps_warn(sprintf('%s: RisPort %s', $this->soap->baseUrl(), $reason));

            // Strategy 1: split by NODE. Far cheaper than splitting by name,
            // and better targeted. The cap applies per query, and phones are
            // already distributed across subscribers, so a four-node cluster
            // of 2,300 phones usually resolves in four queries.
            //
            // Splitting by name is the fallback because device names are
            // MACs: the leading characters are the OUI, and a fleet bought
            // together shares one. Over a thousand phones under SEP44* is
            // normal, so leading-character buckets walk down a single
            // dominant branch, costing sixteen queries a level against a
            // rate-limited API to make almost no progress.
            $nodes = array_keys($this->lastDiag['nodes'] ?? []);
            $nodes = array_values(array_filter($nodes, fn($n) => $n !== '' && $n !== '(unnamed)'));
            if (count($nodes) > 1) {
                $byNode   = [];
                foreach ($firstPassSeed = $rows as $r) { $byNode[$r['name']] = $r; }
                $stillCapped = [];
                foreach ($nodes as $node) {
                    try {
                        $nodeRows = $this->selectCmDevice($status, $deviceClass, $selectItems, 'Name', $node);
                    } catch (Throwable $e) {
                        cps_warn("RisPort node $node failed: " . $e->getMessage());
                        $stillCapped[] = $node;
                        continue;
                    }
                    foreach ($nodeRows as $r) {
                        if ($r['name'] !== '') { $byNode[$r['name']] = $r; }
                    }
                    cps_log(sprintf('  node %-30s %d devices', $node, count($nodeRows)));
                    if (count($nodeRows) >= $cap) { $stillCapped[] = $node; }
                }
                if (!$stillCapped) {
                    $this->lastDiag['enumeration'] = [
                        'mode' => 'partitioned (by node)',
                        'first_pass_returned' => count($rows),
                        'total_devices_found' => $claimed,
                        'nodes_queried' => $nodes,
                        'returned' => count($byNode),
                        'truncated' => false,
                        'incomplete' => false,
                    ];
                    cps_log(sprintf('%s: per-node enumeration produced %d unique devices in %d queries',
                        $this->soap->baseUrl(), count($byNode), count($nodes)));
                    return array_values($byNode);
                }
                cps_warn(sprintf('%s: node(s) %s still at the cap - falling back to name prefixes',
                    $this->soap->baseUrl(), implode(', ', $stillCapped)));
                $rows = array_values($byNode);
            }

            // Strategy 2: split on the END of the name.
            //
            // A device name is SEP plus a MAC, and the leading digits are the
            // OUI: a fleet bought together shares one, so prefix buckets keep
            // resolving to a single over-cap branch - SEP4*, SEP44*, SEP44B*,
            // SEP44B6*, SEP44B6B* all capped on a real estate, sixteen
            // queries a level to get nowhere. The last digits are the serial
            // and are close to uniform, so splitting there gives sixteen even
            // buckets in ONE level.
            //
            // Whether the cluster accepts a leading wildcard is not something
            // to assume, so it is tested with a single query and the answer
            // is checked: the rows must come back, be fewer than the cap, and
            // actually end in the requested digit. A cluster that ignores the
            // pattern would otherwise hand back the whole capped set and look
            // like success.
            cps_log('probing whether this cluster can match on the end of a device name');
            $suffix = $this->trySuffixPartitioning($status, $deviceClass, $selectItems, $cap);
            if ($suffix !== null) {
                cps_warn(sprintf('%s: re-running split on the last character of the name (%d buckets)',
                    $this->soap->baseUrl(), count($suffix)));
                $patterns  = $suffix;
                $firstPass = $rows;
                $firstClaimed = $claimed;
                return $this->runPartitions($status, $deviceClass, $patterns, $firstPass, $firstClaimed, $cap, 'suffix');
            }

            // Ask AXL for the real names and compute exact buckets. Only
            // reached when the cheaper strategies are unavailable, and only
            // costs a few unthrottled AXL calls.
            if ($nameProvider !== null) {
                try {
                    $names = $nameProvider();
                } catch (Throwable $e) {
                    cps_warn('could not list device names from AXL: ' . $e->getMessage());
                    $names = [];
                }
                if ($names) {
                    $computed = self::partitionsFromNames($names, $devicePrefixes, $cap);
                    cps_warn(sprintf('%s: computed %d exact buckets from %d device names known to AXL',
                        $this->soap->baseUrl(), count($computed), count($names)));
                    return $this->runPartitions($status, $deviceClass, $computed,
                        $rows, $claimed, $cap, 'axl-derived');
                }
            }

            cps_warn(sprintf(
                '%s: falling back to BLIND name prefix splitting. On a fleet sharing an OUI this is slow - '
                . 'every level is 16 queries and the OUI is 6 hex digits deep. Configure axl_user/axl_pass '
                . 'so the buckets can be computed from the real device names instead.',
                $this->soap->baseUrl()));
            $patterns = self::partitionsFor($devicePrefixes);
            $firstPass = $rows;
            $firstClaimed = $claimed;
        } else {
            $patterns = $partitions;
            $firstPass = [];
            $firstClaimed = null;
        }

        return $this->runPartitions($status, $deviceClass, $patterns,
            $firstPass, $firstClaimed, $cap, $mode === 'explicit' ? 'explicit' : 'prefix');
    }

    /**
     * Run a set of name patterns to exhaustion and merge the results.
     *
     * Shared by every splitting strategy so they cannot drift: the queue,
     * the subdivision of a bucket that is itself over the cap, the merge by
     * device name, and the honest reporting of any pattern that never
     * completed all behave identically whichever axis was chosen.
     *
     * @param string[] $patterns
     * @param array<int,array> $firstPass rows already in hand, merged in
     * @return array<int,array<string,mixed>>
     */
    private function runPartitions(
        string $status,
        string $deviceClass,
        array $patterns,
        array $firstPass,
        ?int $firstClaimed,
        int $cap,
        string $axis,
    ): array {
        $byName = [];
        foreach ($firstPass as $r) { $byName[$r['name']] = $r; }
        $perPattern = [];

        // Work through a queue rather than a fixed list: a bucket that comes
        // back at the cap is itself truncated and gets subdivided a level
        // deeper. Results are merged by name throughout, so subdividing can
        // only ever add devices - if a child pattern misses something the
        // parent already found, the parent's row is still there.
        $queue = array_values($patterns);
        $failedPatterns = [];
        $depth = [];
        foreach ($queue as $q) { $depth[$q] = 0; }
        // Six hex digits of OUI have to be traversed before the serial part
        // provides any spread, so a shallow limit stops right where a
        // MAC-clustered estate needs to keep going.
        $maxDepth = 8;

        while ($queue) {
            $pat = array_shift($queue);
            try {
                $part = $this->selectCmDevice($status, $deviceClass, [$pat]);
            } catch (Throwable $e) {
                cps_warn("RisPort partition $pat failed: " . $e->getMessage());
                $perPattern[$pat] = 'ERROR: ' . $e->getMessage();
                $failedPatterns[] = $pat;
                continue;
            }
            $perPattern[$pat] = count($part);
            foreach ($part as $r) {
                if ($r['name'] !== '') { $byName[$r['name']] = $r; }
            }

            if (count($part) >= $cap) {
                $d = ($depth[$pat] ?? 0) + 1;
                if ($d > $maxDepth) {
                    cps_warn(sprintf(
                        'RisPort partition %s still returns the %d-device cap at maximum subdivision depth - '
                        . 'devices under this prefix may be missing', $pat, $cap));
                } else {
                    $children = self::subdivide($pat);
                    cps_warn(sprintf('RisPort partition %s hit the %d-device cap - subdividing into %d',
                        $pat, $cap, count($children)));
                    foreach ($children as $c) { $depth[$c] = $d; $queue[] = $c; }
                }
            }
            if ($this->requestGapMs > 0) { usleep($this->requestGapMs * 1000); }
        }

        $merged = array_values($byName);
        if ($failedPatterns) {
            // Say it plainly. A partition that never completed means devices
            // under that prefix are missing, and the total looks like an
            // answer rather than a partial one.
            cps_warn(sprintf(
                '%s: %d partition(s) never completed (%s) - the device list is INCOMPLETE',
                $this->soap->baseUrl(), count($failedPatterns),
                implode(', ', array_slice($failedPatterns, 0, 6))
                    . (count($failedPatterns) > 6 ? ', ...' : '')
            ));
        }
        $this->lastDiag['enumeration'] = [
            'mode'                 => "partitioned ($axis)",
            'first_pass_returned'  => count($firstPass),
            'total_devices_found'  => $firstClaimed,
            'per_pattern'          => $perPattern,
            'failed_patterns'      => $failedPatterns,
            'returned'             => count($merged),
            'incomplete'           => (bool)$failedPatterns,
            'truncated'            => ($firstClaimed !== null && count($merged) < $firstClaimed) || (bool)$failedPatterns,
        ];
        cps_log(sprintf('%s: partitioned enumeration produced %d unique devices (first pass %d)',
            $this->soap->baseUrl(), count($merged), count($firstPass)));
        return $merged;
    }

    /**
     * SelectItems patterns for the prefixes being polled.
     *
     * Scoping the query itself, rather than filtering afterwards, means the
     * cluster never gathers or returns device types we do not want. That is
     * cheaper for CUCM, returns less data, and - on an estate with a large
     * Jabber population - can keep a node under the response cap that would
     * otherwise exceed it purely because of soft clients.
     *
     * @param string[] $prefixes
     * @return string[] ['*'] when nothing is specified
     */
    public static function selectItemsFor(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $p) {
            $p = strtoupper(trim($p));
            if ($p !== '') { $out[] = $p . '*'; }
        }
        return $out ?: ['*'];
    }

    /**
     * Build the partition set for the device prefixes actually being polled.
     *
     * Enumerating device types that are about to be discarded is pure waste,
     * and on a rate-limited API it is expensive waste: with
     * poll_device_prefixes = SEP the CSF/TCT/BOT/TAB buckets cost queries for
     * rows that never reach a report, and a large Jabber estate would push
     * CSF* over the cap and spend another subdivision enumerating it.
     *
     * A MAC-based prefix is split into 16 hex buckets; anything else is left
     * whole and only subdivided if it turns out to need it.
     *
     * @param string[] $prefixes device-name prefixes, empty for everything
     * @return string[]
     */
    public static function partitionsFor(array $prefixes): array
    {
        if (!$prefixes) { return self::DEFAULT_PARTITIONS; }
        $out = [];
        foreach ($prefixes as $p) {
            $p = strtoupper(trim($p));
            if ($p === '') { continue; }
            if (in_array($p, ['SEP', 'ATA', 'CIPC'], true)) {
                // Followed by a MAC, so the next character is a hex digit.
                foreach (str_split('0123456789ABCDEF') as $h) { $out[] = $p . $h . '*'; }
            } else {
                $out[] = $p . '*';
            }
        }
        return $out ?: self::DEFAULT_PARTITIONS;
    }

    /**
     * Build the smallest set of prefix patterns that keeps every bucket
     * under the cap, from the actual device names.
     *
     * Blind prefix splitting has to DISCOVER where the estate divides, one
     * expensive level at a time, and on a fleet sharing an OUI that means
     * traversing six hex digits before anything separates. Given the real
     * names the answer can simply be computed: descend only where a bucket
     * is genuinely too big, and only into characters that actually occur, so
     * no query is ever spent on an empty branch.
     *
     * Names come from AXL, which is not subject to RisPort's per-minute
     * throttle, so learning them is cheap compared with the queries saved.
     *
     * @param string[] $names    every configured device name
     * @param string[] $prefixes device prefixes in scope, e.g. ['SEP']
     * @return string[]
     */
    public static function partitionsFromNames(array $names, array $prefixes, int $cap): array
    {
        $limit = max(1, (int)ceil($cap * 0.9));
        $roots = [];
        foreach ($prefixes ?: [''] as $p) {
            $p = strtoupper(trim($p));
            $roots[$p] = [];
        }
        foreach ($names as $n) {
            $n = strtoupper(trim((string)$n));
            if ($n === '') { continue; }
            foreach (array_keys($roots) as $p) {
                if ($p === '' || str_starts_with($n, $p)) { $roots[$p][] = $n; break; }
            }
        }

        $out = [];
        foreach ($roots as $stem => $members) {
            if (!$members) { continue; }
            self::splitBucket($stem, $members, $limit, $out);
        }
        sort($out);
        return $out;
    }

    /** Recursive helper for partitionsFromNames(). */
    private static function splitBucket(string $stem, array $members, int $limit, array &$out): void
    {
        if (count($members) <= $limit) {
            $out[] = $stem . '*';
            return;
        }
        $next = [];
        $exhausted = 0;
        foreach ($members as $n) {
            if (strlen($n) <= strlen($stem)) { $exhausted++; continue; }
            $next[$n[strlen($stem)]][] = $n;
        }
        // Every remaining name is exactly this stem, or there is no further
        // character to split on: emit it and let the cap do what it will,
        // rather than recursing forever.
        if (!$next) { $out[] = $stem . '*'; return; }
        if ($exhausted) { $out[] = $stem; }
        foreach ($next as $ch => $sub) {
            self::splitBucket($stem . $ch, $sub, $limit, $out);
        }
    }

    /**
     * Can this cluster match on the end of a device name?
     *
     * One probe query, and its answer is verified rather than assumed. A
     * cluster that does not understand a leading wildcard may return
     * nothing, or may ignore the pattern and return everything; both are
     * rejected, and the caller falls back to prefix splitting.
     *
     * @return string[]|null the suffix patterns to use, or null if unsupported
     */
    private function trySuffixPartitioning(string $status, string $deviceClass, array $selectItems, int $cap): ?array
    {
        // Probe with the first scoped prefix, e.g. "SEP*" -> "SEP*0".
        $base = $selectItems[0] ?? '*';
        if (!str_ends_with($base, '*')) { return null; }
        $probe = $base . '0';

        try {
            $rows = $this->selectCmDevice($status, $deviceClass, [$probe]);
        } catch (Throwable $e) {
            cps_warn('suffix probe failed, falling back to prefixes: ' . $e->getMessage());
            return null;
        }
        if (!$rows) {
            cps_warn("suffix probe '$probe' returned nothing - this cluster will not match on the end of a name");
            return null;
        }
        foreach ($rows as $r) {
            if (!str_ends_with(strtoupper((string)$r['name']), '0')) {
                cps_warn("suffix probe '$probe' returned '{$r['name']}', which does not end in 0 - the cluster ignored the pattern");
                return null;
            }
        }
        if (count($rows) >= $cap) {
            cps_warn("suffix probe '$probe' is itself at the cap - one character is not enough spread");
            return null;
        }

        $out = [];
        foreach (str_split('0123456789ABCDEF') as $ch) { $out[] = $base . $ch; }
        return $out;
    }

    /**
     * Split a wildcard pattern one character deeper.
     *
     * "SEP0*" becomes "SEP00*".."SEP0Z*". The alphabet is 0-9A-Z rather than
     * just hex because these patterns are not only MAC-based: a soft-client
     * prefix is followed by a username. Over-generating is harmless - a
     * pattern matching nothing costs one cheap query - whereas leaving a
     * character out would drop devices.
     *
     * @return string[]
     */
    private static function subdivide(string $pattern): array
    {
        // A trailing-wildcard pattern ("SEP*0") splits by inserting another
        // character before the fixed tail, not by appending to the stem.
        if (preg_match('/^(.*)\*(.+)$/', $pattern, $sm)) {
            $out = [];
            foreach (str_split('0123456789ABCDEF') as $ch) { $out[] = $sm[1] . '*' . $ch . $sm[2]; }
            return $out;
        }

        $stem = rtrim($pattern, '*');
        // A MAC-shaped stem can only continue with a hex digit, so 36
        // children would be 20 queries returning nothing - and on a
        // rate-limited API those cost well over a minute each time a bucket
        // is subdivided. Everything else keeps the wider alphabet, because a
        // soft-client prefix is followed by a username.
        // Decide by the PREFIX, not by whether the tail happens to look hex:
        // a bare "CSF*" has no tail at all, and treating that as MAC-shaped
        // would generate only 0-F and miss every username starting G-Z.
        preg_match('/^(CIPC|SEP|CSF|TCT|BOT|TAB|ATA|SIP|IMS)/i', $stem, $m);
        $prefix    = strtoupper($m[1] ?? '');
        $tail      = substr($stem, strlen($prefix));
        $macShaped = in_array($prefix, ['SEP', 'ATA', 'CIPC'], true)
                     && ($tail === '' || ctype_xdigit($tail));
        $alphabet  = $macShaped
            ? '0123456789ABCDEF'
            : '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = [];
        foreach (str_split($alphabet) as $ch) { $out[] = $stem . $ch . '*'; }
        return $out;
    }

    /**
     * Query several servers and merge, keeping the best row per device.
     *
     * "Best" = a row with an IP beats one without, and Registered beats any
     * other status. A device legitimately appears from more than one server
     * because each node reports the whole cluster; taking the first answer
     * would let a node with stale or partial data win a race.
     *
     * @param CpsRisportClient[] $clients
     * @return array{devices: array<string,array>, errors: array<int,array>}
     */
    public static function discover(
        array $clients,
        string $status = 'Registered',
        string $deviceClass = 'Phone',
        string|array $partitions = 'auto',
        array $devicePrefixes = [],
        ?callable $nameProvider = null,
    ): array {
        $byName = [];
        $errors = [];
        $perServer = [];
        foreach ($clients as $c) {
            try {
                $rows = $c->selectCmDeviceComplete($status, $deviceClass, $partitions, $devicePrefixes, $nameProvider);
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
            // Per-server attribution: with more than one cluster in play, a
            // total on its own cannot show which one came up short.
            $perServer[$c->baseUrl()] = [
                'returned'    => count($rows),
                'new_unique'  => $added,
                'nodes'       => $diag['nodes'] ?? [],
                'enumeration' => $diag['enumeration'] ?? null,
            ];
            cps_log(sprintf('%s: %d devices (%d new), nodes: %s',
                $c->baseUrl(), count($rows), $added,
                ($diag['nodes'] ?? []) ? json_encode($diag['nodes']) : 'none reported'));
        }
        return ['devices' => $byName, 'errors' => $errors, 'per_server' => $perServer];
    }

    /**
     * Look up a known list of device names rather than enumerating the estate.
     *
     * Names go in SelectItems verbatim, a chunk at a time, so a few hundred
     * phones cost a few queries per cluster instead of the node/partition
     * walk. A name registered on the other cluster simply comes back empty.
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
