<?php
/**
 * phone.php - fetch and parse Cisco IP Phone serviceability pages
 * (/CGI/Java/Serviceability?adapter=...) into label/value pairs and columns.
 */

require_once __DIR__ . '/util.php';

/** The pages this tool collects, in fetch order. */
const CPS_ADAPTERS = [
    'network'  => 'device.statistics.port.network',
    'access'   => 'device.statistics.port.access',
    'ethernet' => 'device.statistics.ethernet',
    'device'   => 'device.statistics.device',
    'config'   => 'device.statistics.configuration',
];

/** Adapters that carry port counters and get normalised into port_stats. */
const CPS_PORT_ADAPTERS = ['network', 'access', 'ethernet'];

/** Squash a label to a comparable key: lowercase, alphanumerics only. */
function cps_norm_key(string $label): string {
    return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $label));
}

/** Normalised label => port_stats column. Unknown labels are still kept raw. */
const CPS_COUNTER_MAP = [
    // --- receive ---
    'rxtotalpkt'        => 'rx_total_pkt',
    'rxtotalpackets'    => 'rx_total_pkt',
    'rxtotalbytes'      => 'rx_total_bytes',
    'rxtotaloctets'     => 'rx_total_bytes',
    'rxcrcerr'          => 'rx_crc_err',
    'rxcrcerror'        => 'rx_crc_err',
    'rxcrcerrors'       => 'rx_crc_err',
    'rxalignerr'        => 'rx_align_err',
    'rxalignmenterr'    => 'rx_align_err',
    'rxshorterr'        => 'rx_short_err',
    'rxrunts'           => 'rx_short_err',
    'rxlongerr'         => 'rx_long_err',
    'rxgiants'          => 'rx_long_err',
    'rxjabber'          => 'rx_jabber',
    'rxoverrun'         => 'rx_overrun',
    'rxfcserr'          => 'rx_crc_err',
    'rxmulticast'       => 'rx_multicast',
    'rxmulticastpkt'    => 'rx_multicast',
    'rxbroadcast'       => 'rx_broadcast',
    'rxbroadcastpkt'    => 'rx_broadcast',
    'rxunicast'         => 'rx_unicast',
    'rxunicastpkt'      => 'rx_unicast',
    // --- transmit ---
    'txtotalpkt'        => 'tx_total_pkt',
    'txtotalpackets'    => 'tx_total_pkt',
    'txtotalbytes'      => 'tx_total_bytes',
    'txtotaloctets'     => 'tx_total_bytes',
    'txcollision'       => 'tx_collisions',
    'txcollisions'      => 'tx_collisions',
    'txtotalcollision'  => 'tx_collisions',
    'txtotalcollisions' => 'tx_collisions',
    'txlatecollision'   => 'tx_late_collision',
    'txlatecollisions'  => 'tx_late_collision',
    'txexcesscollision' => 'tx_excess_collision',
    'txexcessivecollision' => 'tx_excess_collision',
    'txexcessdefer'     => 'tx_excess_defer',
    'txexcessivedeferral' => 'tx_excess_defer',
    'txdeferred'        => 'tx_deferred',
    'txexcesslength'    => 'tx_excess_length',
    'txmulticast'       => 'tx_multicast',
    'txmulticastpkt'    => 'tx_multicast',
    'txbroadcast'       => 'tx_broadcast',
    'txbroadcastpkt'    => 'tx_broadcast',
    'txunicast'         => 'tx_unicast',
    'txunicastpkt'      => 'tx_unicast',
];

/** Fault counters summed into errors_total. */
const CPS_ERROR_COLUMNS = [
    'rx_crc_err', 'rx_align_err', 'rx_short_err', 'rx_long_err',
    'rx_jabber', 'rx_overrun',
    'tx_collisions', 'tx_late_collision', 'tx_excess_collision',
    'tx_excess_defer', 'tx_excess_length',
];

/** Labels whose value describes the link, tried in this order of trust. */
const CPS_LINK_LABELS = [
    'portinformation', 'linkstate', 'portspeed', 'speedduplex',
    'speed', 'duplex', 'autonegotiation', 'portstatus', 'link',
];

/** Labels that carry device identity, for the phones table. */
const CPS_DEVICE_MAP = [
    'serialnumber'      => 'serial_number',
    'modelnumber'       => 'model_number',
    'macaddress'        => 'mac_address',
    'hostname'          => 'host_name',
    'appload'           => 'app_load_id',
    'apploadid'         => 'app_load_id',
    'firmwareload'      => 'app_load_id',
    'loadfile'          => 'app_load_id',
    'bootload'          => 'boot_load_id',
    'bootloadid'        => 'boot_load_id',
    'versionid'         => 'version_id',
    'version'           => 'version_id',
    'hardwarerevision'  => 'hardware_revision',
];

/**
 * Parse a link description ("100Mb Full Duplex", "1000 Full", "Down") into speed/duplex/up.
 * @return array{speed_mbps:?int, duplex:?string, link_up:?int}
 */
function cps_parse_link(string $text): array {
    $t = strtolower(trim($text));
    if ($t === '') { return ['speed_mbps' => null, 'duplex' => null, 'link_up' => null]; }

    $down = (bool)preg_match('/\b(down|no ?link|not ?connected|disabled|link ?down)\b/', $t);

    $speed = null;
    if (preg_match('/\b(\d{1,5})\s*(?:mb(?:ps|it)?|m)\b/', $t, $m)) {
        $speed = (int)$m[1];
    } elseif (preg_match('/\b(\d{1,3})\s*(?:gb(?:ps|it)?|g)\b/', $t, $m)) {
        $speed = (int)$m[1] * 1000;
    } elseif (preg_match('/\b(10|100|1000|2500|10000)\b/', $t, $m)) {
        // Bare number: only accept real ethernet rates.
        $speed = (int)$m[1];
    }

    $duplex = null;
    if (preg_match('/\bfull\b/', $t))      { $duplex = 'full'; }
    elseif (preg_match('/\bhalf\b/', $t))  { $duplex = 'half'; }

    // "Auto Negotiation" alone says nothing about the link.
    if ($speed === null && $duplex === null && !$down) {
        return ['speed_mbps' => null, 'duplex' => null, 'link_up' => null];
    }
    return [
        'speed_mbps' => $down ? null : $speed,
        'duplex'     => $down ? null : $duplex,
        'link_up'    => $down ? 0 : 1,
    ];
}

/**
 * Normalise one port page's label/value pairs.
 * @return array{counters:array<string,int>, link:array, errors_total:int, unmapped:int}
 */
function cps_normalise_port(array $pairs): array {
    $counters = [];
    $unmapped = 0;
    $link     = ['speed_mbps' => null, 'duplex' => null, 'link_up' => null];
    $linkRank = PHP_INT_MAX;

    foreach ($pairs as $label => $value) {
        $k = cps_norm_key((string)$label);

        if (isset(CPS_COUNTER_MAP[$k])) {
            // Values may be "1,234" or "1234 pkts".
            $digits = preg_replace('/[^0-9]/', '', (string)$value);
            if ($digits !== '') {
                $counters[CPS_COUNTER_MAP[$k]] = (int)$digits;
                continue;
            }
        }

        $rank = array_search($k, CPS_LINK_LABELS, true);
        if ($rank !== false && $rank < $linkRank) {
            $parsed = cps_parse_link((string)$value);
            if ($parsed['link_up'] !== null) { $link = $parsed; $linkRank = $rank; }
            continue;
        }
        if (!isset(CPS_COUNTER_MAP[$k])) { $unmapped++; }
    }

    // No known link label: take any value shaped like "100Mb Full Duplex".
    if ($link['link_up'] === null) {
        foreach ($pairs as $value) {
            if (!is_string($value)) { continue; }
            if (preg_match('/\b(full|half)\b/i', $value) && preg_match('/\d/', $value)) {
                $p = cps_parse_link($value);
                if ($p['speed_mbps'] !== null && $p['duplex'] !== null) { $link = $p; break; }
            }
        }
    }

    $errors = 0;
    foreach (CPS_ERROR_COLUMNS as $c) { $errors += $counters[$c] ?? 0; }

    return ['counters' => $counters, 'link' => $link, 'errors_total' => $errors, 'unmapped' => $unmapped];
}

/** Pull device identity fields out of the device/config pages. */
function cps_normalise_device(array $pairs): array {
    $out = [];
    foreach ($pairs as $label => $value) {
        $k = cps_norm_key((string)$label);
        if (isset(CPS_DEVICE_MAP[$k]) && !isset($out[CPS_DEVICE_MAP[$k]])) {
            $out[CPS_DEVICE_MAP[$k]] = trim((string)$value);
        }
    }
    return $out;
}

/**
 * Collapse whitespace (including U+00A0) and trim.
 * &nbsp; decodes to U+00A0, which \s only matches with /u; the fallback
 * handles non-UTF-8 input, where /u returns null.
 */
function cps_squash_ws(string $s): string {
    $out = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
    if ($out === null) {
        $out = preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $s));
    }
    return trim((string)$out);
}

/**
 * Parse a serviceability page into label => value pairs: per row, the first
 * non-empty cell is the label and the rest is the value.
 */
function cps_parse_stats_html(string $html): array {
    // loadHTML('') throws ValueError on PHP 8.
    if (trim($html) === '') { return []; }

    $prev = libxml_use_internal_errors(true);
    $dom  = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $stats = [];
    foreach ($dom->getElementsByTagName('tr') as $row) {
        $texts = [];
        foreach ($row->getElementsByTagName('td') as $cell) {
            $t = cps_squash_ws($cell->textContent);
            if ($t === '' || $t === ':' || $t === '=') { continue; }
            $texts[] = $t;
        }
        if (count($texts) < 2) { continue; }
        $key = array_shift($texts);
        $stats[$key] = implode(' ', $texts);
    }
    return $stats;
}

/** URL of one adapter page; "{ip}" in $base is replaced, IPv6 literals bracketed. */
function cps_phone_url(string $ip, string $adapter, string $base = 'https://{ip}'): string {
    // Not a ":" test: "10.0.0.5:8443" must stay unbracketed.
    $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $host = ($isV6 && !str_starts_with($ip, '[')) ? "[$ip]" : $ip;
    $root = str_replace('{ip}', $host, $base);
    return rtrim($root, '/') . '/CGI/Java/Serviceability?adapter=' . rawurlencode($adapter);
}

/**
 * curl protocol limits: an https base never redirects to http, so credentials
 * are never re-sent in cleartext.
 */
function cps_curl_scheme_limits(string $base): array {
    $scheme = strtolower((string)parse_url(str_replace('{ip}', '0.0.0.0', $base), PHP_URL_SCHEME));
    $allowed = $scheme === 'https' ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS);
    return [
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => $allowed,
    ];
}

class CpsPhoneFetcher
{
    /**
     * @param string      $urlBase   template, "{ip}" replaced by the address
     * @param int         $parallel  simultaneous phones in flight
     */
    public function __construct(
        private string  $urlBase = 'https://{ip}',
        private ?string $username = null,
        private ?string $password = null,
        private bool    $verifyTls = false,
        private int     $timeout = 10,
        private int     $connectTimeout = 5,
        private int     $parallel = 10,
        private array   $adapters = ['network', 'access'],
    ) {}

    public function adapters(): array { return $this->adapters; }

    private function makeHandle(string $ip, string $adapter): CurlHandle {
        $ch = curl_init(cps_phone_url($ip, CPS_ADAPTERS[$adapter] ?? $adapter, $this->urlBase));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'cucm-phone-stats/1.0',
        ] + cps_curl_scheme_limits($this->urlBase));
        if ($this->username !== null && $this->username !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . (string)$this->password);
        }
        return $ch;
    }

    /**
     * Fetch every configured adapter from every phone, using a rolling window
     * of ($parallel x adapters) requests.
     *
     * @param array<int,array{name:string, ip:string}> $phones
     * @return array<string, array> keyed by device name
     */
    public function fetchMany(array $phones): array
    {
        $jobs = [];
        foreach ($phones as $p) {
            foreach ($this->adapters as $a) { $jobs[] = ['phone' => $p, 'adapter' => $a]; }
        }
        $total = count($jobs);
        if ($total === 0) { return []; }

        $window  = max(1, $this->parallel) * max(1, count($this->adapters));
        $mh      = curl_multi_init();
        $inFlight = [];
        $results = [];
        $next    = 0;
        $done    = 0;
        $t0      = microtime(true);
        $step    = max(50, (int)($total / 20));   // ~20 progress lines, at most

        $launch = function () use (&$next, &$inFlight, $jobs, $mh, $total) {
            if ($next >= $total) { return false; }
            $job = $jobs[$next++];
            $ch  = $this->makeHandle($job['phone']['ip'], $job['adapter']);
            curl_multi_add_handle($mh, $ch);
            $inFlight[spl_object_id($ch)] = $job + ['ch' => $ch];
            return true;
        };

        while (count($inFlight) < $window && $launch()) { /* prime the window */ }

        while ($inFlight) {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                cps_warn('curl_multi_exec failed: ' . curl_multi_strerror($status));
                break;
            }

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $id  = spl_object_id($ch);
                $job = $inFlight[$id] ?? null;
                // Unset before close: spl_object_id is reused once freed.
                unset($inFlight[$id]);

                if ($job !== null) {
                    $name = $job['phone']['name'];
                    $results[$name]['phone'] = $job['phone'];
                    $results[$name]['pages'][$job['adapter']] = $this->interpret(
                        (string)curl_multi_getcontent($ch),
                        curl_errno($ch), curl_error($ch),
                        (int)curl_getinfo($ch, CURLINFO_HTTP_CODE)
                    );
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $done++;
                if ($done % $step === 0) {
                    $rate = $done / max(0.001, microtime(true) - $t0);
                    cps_log(sprintf('  %d/%d requests (%.0f/s, ~%ds left)',
                        $done, $total, $rate, (int)(($total - $done) / max(0.01, $rate))), 2);
                }
                $launch();   // refill the window straight away
            }

            if ($inFlight && $running) { curl_multi_select($mh, 0.2); }
        }
        curl_multi_close($mh);

        cps_log(sprintf('fetched %d pages from %d phones in %.1fs (window %d)',
            $total, count($phones), microtime(true) - $t0, $window), 2);
        return $results;
    }

    /** Fetch one adapter from one phone (used by the single-phone CLI). */
    public function fetchOne(string $ip, string $adapter): array
    {
        $ch    = $this->makeHandle($ip, $adapter);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $this->interpret($body === false ? '' : $body, $errno, $err, $code);
    }

    /** Turn a curl outcome into a page result, with an error_kind per failure mode. */
    private function interpret(string $body, int $errno, string $err, int $code): array
    {
        if ($errno !== 0) {
            return ['ok' => false, 'http_code' => $code, 'error' => "curl $errno: $err",
                    'error_kind' => match ($errno) {
                        CURLE_OPERATION_TIMEDOUT   => 'timeout',
                        CURLE_COULDNT_CONNECT      => 'refused',
                        CURLE_COULDNT_RESOLVE_HOST => 'dns',
                        CURLE_SSL_CONNECT_ERROR    => 'tls',
                        default                    => 'transport',
                    }];
        }
        if ($code === 401) {
            return ['ok' => false, 'http_code' => 401, 'error_kind' => 'auth',
                    'error' => 'phone requires authentication (Web Access = Authenticated); set phone_user/phone_pass'];
        }
        if ($code !== 200) {
            return ['ok' => false, 'http_code' => $code, 'error_kind' => 'http',
                    'error' => "phone returned HTTP $code"];
        }
        if (trim($body) === '') {
            return ['ok' => false, 'http_code' => 200, 'error_kind' => 'empty',
                    'error' => 'phone returned HTTP 200 with an empty body'];
        }

        // A parser failure fails this page only, never the sweep.
        try {
            $pairs = cps_parse_stats_html($body);
        } catch (Throwable $e) {
            return ['ok' => false, 'http_code' => 200, 'error_kind' => 'parse',
                    'error' => 'parser failed: ' . $e->getMessage(),
                    'raw' => substr($body, 0, 4000)];
        }
        if (!$pairs) {
            return ['ok' => false, 'http_code' => 200, 'error_kind' => 'parse',
                    'error' => 'page fetched but no label/value rows parsed', 'raw' => $body];
        }
        return ['ok' => true, 'http_code' => 200, 'pairs' => $pairs];
    }
}

/** CDP/LLDP neighbour labels, normalised key => uplinks field. */
const CPS_NEIGHBOUR_MAP = [
    // --- CDP ---
    'cdpneighbordeviceid'      => 'cdp_neighbour',
    'cdpneighbourdeviceid'     => 'cdp_neighbour',
    'cdpneighbordevice'        => 'cdp_neighbour',
    'cdpneighborid'            => 'cdp_neighbour',
    'cdpneighborport'          => 'cdp_neighbour_port',
    'cdpneighbourport'         => 'cdp_neighbour_port',
    'cdpneighborportid'        => 'cdp_neighbour_port',
    'cdpneighboripaddress'     => 'cdp_neighbour_ip',
    'cdpneighbouripaddress'    => 'cdp_neighbour_ip',
    'cdpneighborip'            => 'cdp_neighbour_ip',
    'cdpneighborplatform'      => 'cdp_neighbour_platform',
    // --- LLDP (incl. LLDP-MED) ---
    'lldpneighbordeviceid'     => 'lldp_neighbour',
    'lldpneighbourdeviceid'    => 'lldp_neighbour',
    'lldpmedneighbordeviceid'  => 'lldp_neighbour',
    'lldpneighborsystemname'   => 'lldp_neighbour',
    'lldpsystemname'           => 'lldp_neighbour',
    'lldpneighborport'         => 'lldp_neighbour_port',
    'lldpneighbourport'        => 'lldp_neighbour_port',
    'lldpneighborportid'       => 'lldp_neighbour_port',
    'lldpportid'               => 'lldp_neighbour_port',
    'lldpmedneighborport'      => 'lldp_neighbour_port',
    'lldpneighboripaddress'    => 'lldp_neighbour_ip',
    'lldpneighborip'           => 'lldp_neighbour_ip',
];

/** Neighbour fields, in the order a report should prefer them. */
const CPS_NEIGHBOUR_FIELDS = [
    'cdp_neighbour', 'cdp_neighbour_port', 'cdp_neighbour_ip', 'cdp_neighbour_platform',
    'lldp_neighbour', 'lldp_neighbour_port', 'lldp_neighbour_ip',
];

/**
 * Extract the CDP/LLDP neighbour the phone reports: alias table first, then a
 * name heuristic that never overrides it. Placeholders ("none", "n/a") are dropped.
 * $fallback = false returns alias-table matches only.
 * @return array<string,string>
 */
function cps_normalise_neighbour(array $pairs, bool $fallback = true): array {
    $out = [];
    // Includes on/off flags: the config page's "CDP: SW Port  Yes" says CDP is enabled, not where it leads.
    $placeholder = ['', 'n/a', 'na', 'none', 'not available', 'unknown', '0.0.0.0', '-',
                    'yes', 'no', 'enabled', 'disabled', 'on', 'off', 'true', 'false'];

    foreach ($pairs as $label => $value) {
        $k = cps_norm_key((string)$label);
        if (!isset(CPS_NEIGHBOUR_MAP[$k])) { continue; }
        $v = trim((string)$value);
        if (in_array(strtolower($v), $placeholder, true)) { continue; }
        $out[CPS_NEIGHBOUR_MAP[$k]] ??= $v;
    }
    if (!$fallback) { return $out; }

    foreach ($pairs as $label => $value) {
        $k = cps_norm_key((string)$label);
        if (!preg_match('/^(cdp|lldp)/', $k, $m) || !preg_match('/neighbo|nbr/', $k)) { continue; }
        $v = trim((string)$value);
        if (in_array(strtolower($v), $placeholder, true)) { continue; }
        $proto = $m[1] === 'cdp' ? 'cdp' : 'lldp';
        // "port" first: "neighborportid" also contains "id".
        if (str_contains($k, 'port')) {
            $out[$proto . '_neighbour_port'] ??= $v;
        } elseif (str_contains($k, 'ip') || str_contains($k, 'address')) {
            $out[$proto . '_neighbour_ip'] ??= $v;
        } elseif (str_contains($k, 'deviceid') || str_contains($k, 'systemname') || str_contains($k, 'device')) {
            $out[$proto . '_neighbour'] ??= $v;
        }
    }
    return $out;
}

/**
 * Collapse CDP and LLDP into one switch/port answer; CDP preferred.
 * @return array{switch_name:?string, switch_port:?string, switch_ip:?string, source:?string}
 */
function cps_neighbour_summary(array $n): array {
    foreach (['cdp', 'lldp'] as $proto) {
        if (!empty($n[$proto . '_neighbour']) || !empty($n[$proto . '_neighbour_port'])) {
            return [
                'switch_name' => $n[$proto . '_neighbour'] ?? null,
                'switch_port' => $n[$proto . '_neighbour_port'] ?? null,
                'switch_ip'   => $n[$proto . '_neighbour_ip'] ?? null,
                'source'      => $proto,
            ];
        }
    }
    return ['switch_name' => null, 'switch_port' => null, 'switch_ip' => null, 'source' => null];
}
