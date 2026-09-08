<?php
/**
 * phone.php - fetch and parse a Cisco IP Phone's serviceability web pages.
 *
 * The phone serves pages like
 *   https://<ip>/CGI/Java/Serviceability?adapter=device.statistics.port.network
 * as an HTML table of label/value pairs. The layout drifts between firmware
 * releases (two columns, three columns with a ":" spacer, padded empty
 * cells), so the parser takes the non-empty cells of each row and treats the
 * first as the label and the remainder as the value. That tolerant approach
 * is carried over unchanged from patching-tools/cisco-phone-netstats.php,
 * which is already proven against real handsets.
 *
 * NORMALISATION, AND WHY THE RAW PAIRS ARE ALWAYS KEPT
 * ---------------------------------------------------
 * Label text is not stable across firmware: "Rx totalPkt", "RxTotalPkt" and
 * "Rx Total Pkt" are the same counter. Keys are therefore squashed to
 * lowercase alphanumerics before lookup, which absorbs most of the drift on
 * its own, and only then mapped to a canonical column.
 *
 * Whatever the map does or does not recognise, the complete label/value set
 * is stored verbatim as JSON. A counter this map has never seen is then
 * still recoverable from the database afterwards, instead of being lost at
 * collection time and needing a second sweep of the estate to get back.
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

/**
 * normalised label => canonical column.
 *
 * UNVERIFIED against Nathan's firmware - assembled from the Cisco 79xx/78xx
 * /88xx serviceability pages. Anything absent here still lands in raw_json,
 * and `bin/cucm-phone-netstats.php --raw` on one real handset is enough to
 * finish the map.
 */
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

/**
 * Counters that count a fault rather than traffic. These are what
 * "non-zero ethernet errors" and the error-trend report add up, so a
 * counter that is merely unusual (broadcasts, say) is deliberately not here.
 */
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
 * Parse a link-description string into speed / duplex / up.
 *
 * Handles the forms seen across firmware: "Auto Negotiation",
 * "100Mb Full Duplex", "1000 Full", "100 Half", "Down", "No Link",
 * "Auto 100 Full".
 *
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
        // Bare "100 Full" - only trust a number that is a real ethernet rate.
        $speed = (int)$m[1];
    }

    $duplex = null;
    if (preg_match('/\bfull\b/', $t))      { $duplex = 'full'; }
    elseif (preg_match('/\bhalf\b/', $t))  { $duplex = 'half'; }

    // "Auto Negotiation" on its own advertises the setting, not the result:
    // no speed, no duplex, and no basis to call the link up or down.
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
 * Turn raw label/value pairs from one page into normalised columns.
 *
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
            // Counter values are sometimes "1,234" or "1234 pkts".
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

    // Fallback: no recognised link label, so look for any value that reads
    // like a link description. Firmware that renames the label still tends
    // to keep the value shaped as "100Mb Full Duplex".
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
 * Collapse whitespace, including the non-breaking space, and trim.
 *
 * These pages pad their tables with &nbsp;, which DOMDocument decodes to
 * U+00A0. PCRE's \s does not match U+00A0 unless the pattern is in UTF-8
 * mode, so a plain /\s+/ leaves the spacer behind and a cell that should be
 * empty tests as non-empty - the label/value pairing then shifts by one cell
 * and the value comes out as "\u{00A0} 10" instead of "10".
 *
 * The non-UTF-8 branch is the fallback for a page in a legacy encoding,
 * where a /u pattern returns null rather than matching.
 */
function cps_squash_ws(string $s): string {
    $out = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
    if ($out === null) {
        $out = preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $s));
    }
    return trim((string)$out);
}

/**
 * Parse a serviceability HTML page into label => value pairs.
 * Carried over from cisco-phone-netstats.php: take every non-empty,
 * non-separator cell in a row; first is the label, the rest is the value.
 */
function cps_parse_stats_html(string $html): array {
    // DOMDocument::loadHTML() throws a ValueError on an empty string under
    // PHP 8, and a phone answering 200 with a zero-byte body reaches here
    // with curl reporting no error at all. Returning no pairs lets the
    // caller classify it; throwing killed an entire sweep.
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

/**
 * Build the URL for one adapter page on a phone.
 * $base is a template so tests (and http-only estates) can retarget it;
 * "{ip}" is substituted, and an IPv6 literal is bracketed.
 */
function cps_phone_url(string $ip, string $adapter, string $base = 'https://{ip}'): string {
    // Bracket only a genuine IPv6 literal. Testing merely for ":" also
    // catches "10.0.0.5:8443", turning it into "[10.0.0.5:8443]" and a curl
    // "Bad IPv6 address" - a confusing failure for what is a reasonable
    // thing to type at --phone.
    $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $host = ($isV6 && !str_starts_with($ip, '[')) ? "[$ip]" : $ip;
    $root = str_replace('{ip}', $host, $base);
    return rtrim($root, '/') . '/CGI/Java/Serviceability?adapter=' . rawurlencode($adapter);
}

/**
 * curl options confining a request to a safe set of schemes.
 *
 * Redirects are followed (some firmware bounces /CGI/... through a login
 * page), and CURLOPT_USERPWD is set whenever Web Access is Authenticated.
 * Together those mean a phone answering https:// with a 302 to http:// would
 * have curl re-send the Authorization header in cleartext. Restricting the
 * redirect protocols makes that impossible rather than unlikely.
 *
 * An https base is pinned to https - never downgrade. An http base may
 * upgrade to https, which is only ever an improvement.
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
     * Fetch every configured adapter from every phone.
     *
     * A rolling window, not batches. The previous implementation split the
     * phones into chunks of $parallel and ran each chunk to completion
     * before starting the next, which put a barrier between every chunk:
     * one handset timing out at phone_timeout stalled the nine that had
     * already answered in 200ms, so a chunk cost as much as its slowest
     * member and 100 phones took 36 seconds. Here a finished request is
     * replaced immediately, so slow handsets cost only their own latency
     * and never anyone else's.
     *
     * The window is counted in REQUESTS - $parallel phones times the number
     * of adapters - so "10 phones in flight" keeps meaning that however many
     * pages each phone is asked for.
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
                // Drop the map entry BEFORE closing: spl_object_id is reused
                // once a handle is freed, and a stale entry would then be
                // overwritten by the next request's job.
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

            // Only block once nothing is completable; with a full window and
            // nothing ready this is where the time is legitimately spent.
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

    /**
     * Turn a raw curl outcome into a page result.
     *
     * The distinct failure modes are kept apart on purpose, because each one
     * points somewhere different: refused/timeout is the network or a phone
     * that is off, 401 is Web Access set to Authenticated, and a 200 with no
     * table means the page rendered but this parser did not understand it.
     */
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
        // A 200 with no bytes is the phone's fault; a 200 we could not parse
        // is arguably ours. Different remedies, so different error_kind.
        if (trim($body) === '') {
            return ['ok' => false, 'http_code' => 200, 'error_kind' => 'empty',
                    'error' => 'phone returned HTTP 200 with an empty body'];
        }

        // Nothing a single handset does may abort a sweep of thousands, so
        // any parser surprise becomes a recorded failure for that one page.
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
