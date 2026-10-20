<?php
/**
 * switch.php - parse Catalyst "show interface status" captures.
 * cps_if_key() and cps_switch_key() must agree with the SQL in v_uplink_keys.
 */

require_once __DIR__ . '/util.php';

/** Interface abbreviations as IOS prints them; "twe" must precede "tw". */
const CPS_IF_PREFIXES = ['twe', 'tw', 'te', 'gi', 'fa', 'fi', 'fo', 'hu', 'ap', 'po', 'et', 'vl'];

/** "GigabitEthernet1/0/12", "Gi1/0/12", "gi 1/0/12" -> "gi1/0/12". */
function cps_if_key(string $port): string
{
    $v   = strtolower(str_replace(' ', '', trim($port)));
    $pfx = (string)preg_replace('/[0-9].*$/', '', $v);
    $rest = substr($v, strlen($pfx));
    foreach (CPS_IF_PREFIXES as $p) {
        if (str_starts_with($pfx, $p)) { return $p . $rest; }
    }
    return $v;
}

/** "SW01.corp.local", "SW01(FOC1234X)", "sw01" -> "sw01"; an IPv4 is kept whole. */
function cps_switch_key(string $name): string
{
    $n = trim($name);
    if (filter_var($n, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return $n; }
    $n = explode('(', $n, 2)[0];
    $n = explode('.', $n, 2)[0];
    return strtolower(trim($n));
}

/** "a-1000" -> [1000, true]; "10G" -> [10000, false]; "auto" -> [null, true]. */
function cps_switch_speed(string $raw): array
{
    $s    = strtolower(trim($raw));
    $auto = str_starts_with($s, 'a-') || $s === 'auto';
    $s    = preg_replace('/^a-/', '', $s);
    if (preg_match('/^(\d+(?:\.\d+)?)g$/', $s, $m)) { return [(int)round((float)$m[1] * 1000), $auto]; }
    if (preg_match('/^\d+$/', $s))                   { return [(int)$s, $auto]; }
    return [null, $auto];
}

/** "a-full" -> ['full', true]; "half" -> ['half', false]; "auto" -> [null, true]. */
function cps_switch_duplex(string $raw): array
{
    $s    = strtolower(trim($raw));
    $auto = str_starts_with($s, 'a-') || $s === 'auto';
    $s    = preg_replace('/^a-/', '', $s);
    return [in_array($s, ['full', 'half'], true) ? $s : null, $auto];
}

/** Unwrap a capture saved as Ansible JSON (stdout / stdout_lines) to plain text. */
function cps_switch_unwrap(string $text): string
{
    $t = ltrim($text);
    if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) { return $text; }
    $j = json_decode($t, true);
    if (!is_array($j)) { return $text; }
    $lines = [];
    $walk = function ($v) use (&$walk, &$lines) {
        if (is_string($v)) { $lines[] = $v; return; }
        if (is_array($v)) { foreach ($v as $x) { $walk($x); } }
    };
    $walk($j['stdout_lines'] ?? $j['stdout'] ?? $j);
    return implode("\n", $lines);
}

/**
 * Split a capture into one block per "HOST#sh int status" prompt; text before
 * any prompt belongs to $defaultName.
 * @return array<int,array{switch:string,text:string}>
 */
function cps_switch_split(string $text, string $defaultName): array
{
    $blocks = [];
    $cur = ['switch' => $defaultName, 'lines' => []];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^\s*([A-Za-z0-9._\-]+)[#>]\s*sh(?:ow)?\s+int\S*\s+stat/i', $line, $m)) {
            if ($cur['lines']) { $blocks[] = $cur; }
            $cur = ['switch' => $m[1], 'lines' => []];
            continue;
        }
        $cur['lines'][] = $line;
    }
    if ($cur['lines']) { $blocks[] = $cur; }
    $out = [];
    foreach ($blocks as $b) {
        $body = implode("\n", $b['lines']);
        if (trim($body) === '') { continue; }
        $out[] = ['switch' => $b['switch'], 'text' => $body];
    }
    return $out;
}

/**
 * Parse the table. Port/Name is cut at the header's Status column; the rest
 * is anchored on the duplex token, since Name and Type may contain spaces.
 * @return array{ports: array<int,array>, unparsed: int}
 */
function cps_parse_interface_status(string $text): array
{
    $tail = '/^(\S+)\s+(\S+)\s+((?:a-)?(?:full|half)|auto)\s+(\S+)(?:\s+(.*))?$/i';
    $whole = '/^(\S+)\s+(.*?)\s+(\S+)\s+(\S+)\s+((?:a-)?(?:full|half)|auto)\s+(\S+)(?:\s+(.*))?$/i';

    $statusCol = null;
    $ports = [];
    $unparsed = 0;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = rtrim(str_replace("\t", '    ', $line));
        if (preg_match('/^Port\s+Name\s+Status\s+Vlan\s+Duplex\s+Speed/i', $line)) {
            $statusCol = stripos($line, 'Status');
            continue;
        }
        // Rows start with an interface name; skip everything else.
        if (!preg_match('/^[A-Za-z][A-Za-z\-]*\d[^\s#>]*(\s|$)/', $line)) { continue; }

        $row = null;
        if ($statusCol !== null && strlen($line) > $statusCol && $line[$statusCol - 1] === ' '
            && preg_match($tail, substr($line, $statusCol), $m)) {
            $head = substr($line, 0, $statusCol);
            $port = strtok($head, " ");
            $row  = [$port, trim(substr($head, strlen($port))), $m[1], $m[2], $m[3], $m[4], $m[5] ?? ''];
        } elseif (preg_match($whole, $line, $m)) {
            $row = [$m[1], trim($m[2]), $m[3], $m[4], $m[5], $m[6], $m[7] ?? ''];
        }
        if ($row === null) { $unparsed++; continue; }

        [$port, $name, $status, $vlan, $dup, $spd, $type] = $row;
        [$speed, $speedAuto] = cps_switch_speed($spd);
        [$duplex, $dupAuto]  = cps_switch_duplex($dup);
        $ports[] = [
            'port'        => $port,
            'port_key'    => cps_if_key($port),
            'name'        => $name !== '' ? $name : null,
            'status'      => $status,
            'vlan'        => $vlan,
            'duplex_raw'  => $dup,
            'speed_raw'   => $spd,
            'port_type'   => trim($type) !== '' ? trim($type) : null,
            'duplex'      => $duplex,
            'speed_mbps'  => $speed,
            'duplex_auto' => $dupAuto ? 1 : 0,
            'speed_auto'  => $speedAuto ? 1 : 0,
        ];
    }
    return ['ports' => $ports, 'unparsed' => $unparsed];
}
