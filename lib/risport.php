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
    public function __construct(
        private CpsSoapClient $soap,
        private int $maxReturnedDevices = 1000,
        private int $requestGapMs = 4000,
    ) {}

    public function baseUrl(): string { return $this->soap->baseUrl(); }

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
            $doc = $this->soap->post(CPS_RIS_PATH, $env, '""');

            $found = cps_xml_text($doc, 'TotalDevicesFound');
            $batch = $this->parseDevices($doc);
            $devices = array_merge($devices, $batch);
            cps_log(sprintf(
                'RisPort %s round %d: %d devices (TotalDevicesFound=%s), %d accumulated',
                $this->soap->baseUrl(), $round, count($batch), $found ?? '?', count($devices)
            ), 2);

            $next = cps_xml_text($doc, 'StateInfo', '');
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

            if ($this->requestGapMs > 0) { usleep($this->requestGapMs * 1000); }
        }
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
    public static function discover(array $clients, string $status = 'Registered', string $deviceClass = 'Phone'): array
    {
        $byName = [];
        $errors = [];
        foreach ($clients as $c) {
            try {
                $rows = $c->selectCmDevice($status, $deviceClass);
            } catch (Throwable $e) {
                $errors[] = ['server' => $c->baseUrl(), 'error' => $e->getMessage()];
                cps_warn($c->baseUrl() . ': ' . $e->getMessage());
                continue;
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
