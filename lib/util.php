<?php
/**
 * util.php - shared helpers: logging, XML walking, output rendering.
 *
 * Deliberately dependency-free. This runs on an airgapped host where the
 * only safe assumptions are the PHP core extensions: curl and dom, plus
 * pdo_mysql for the database half only. SimpleXML is deliberately not used -
 * everything here parses through DOM, so one fewer extension has to be
 * present at the far end.
 */

/** Verbosity: 0 quiet, 1 normal, 2 verbose, 3 debug. */
$GLOBALS['CPS_VERBOSITY'] = 1;

function cps_set_verbosity(int $v): void { $GLOBALS['CPS_VERBOSITY'] = $v; }

function cps_log(string $msg, int $level = 1): void {
    if ($level <= $GLOBALS['CPS_VERBOSITY']) {
        fwrite(STDERR, '[' . date('H:i:s') . '] ' . $msg . "\n");
    }
}

function cps_warn(string $msg): void { fwrite(STDERR, "WARN: $msg\n"); }

function cps_die(string $msg, int $code = 1): void {
    fwrite(STDERR, "Error: $msg\n");
    exit($code);
}

/**
 * Find descendant elements by LOCAL name, ignoring namespaces entirely.
 *
 * Every SOAP response here is parsed this way on purpose. Cisco varies the
 * namespace prefixes and the AXL schema version between releases, and an
 * XPath bound to a prefix breaks on a cluster we cannot test against first.
 * Matching the local name is the one thing that stays true across versions.
 *
 * @return DOMElement[]
 */
function cps_xml_find(DOMNode $ctx, string $localName): array {
    $out = [];
    $doc = $ctx instanceof DOMDocument ? $ctx : $ctx->ownerDocument;
    $xp  = new DOMXPath($doc);
    // local-name() is namespace-blind; './/' keeps it under $ctx.
    foreach ($xp->query(".//*[local-name()=" . cps_xpath_literal($localName) . "]", $ctx) as $n) {
        $out[] = $n;
    }
    return $out;
}

/** First descendant with the given local name, or null. */
function cps_xml_first(DOMNode $ctx, string $localName): ?DOMElement {
    $all = cps_xml_find($ctx, $localName);
    return $all[0] ?? null;
}

/** Text of the first descendant with the given local name, or $default. */
function cps_xml_text(DOMNode $ctx, string $localName, ?string $default = null): ?string {
    $el = cps_xml_first($ctx, $localName);
    return $el === null ? $default : trim($el->textContent);
}

/** Direct-child elements of $el (any namespace). @return DOMElement[] */
function cps_xml_children(DOMNode $el): array {
    $out = [];
    foreach ($el->childNodes as $c) {
        if ($c instanceof DOMElement) { $out[] = $c; }
    }
    return $out;
}

/**
 * Quote a string for use as an XPath literal, handling embedded quotes.
 * XPath 1.0 has no escape sequence, so a string with both quote characters
 * has to be built with concat().
 */
function cps_xpath_literal(string $s): string {
    if (!str_contains($s, "'")) { return "'" . $s . "'"; }
    if (!str_contains($s, '"')) { return '"' . $s . '"'; }
    $parts = explode("'", $s);
    return 'concat(' . implode(", \"'\", ", array_map(fn($p) => "'$p'", $parts)) . ')';
}

/** XML-escape a value destined for a SOAP envelope. */
function cps_xesc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Flatten an element's descendants into a leaf-name => text map.
 * Used for the phone XML endpoints, whose exact element names vary by
 * firmware; keeping every leaf means a field we did not anticipate is
 * still captured rather than silently dropped.
 */
function cps_xml_flatten(DOMNode $el, string $prefix = ''): array {
    $out = [];
    foreach (cps_xml_children($el) as $child) {
        $name = $prefix === '' ? $child->localName : $prefix . '.' . $child->localName;
        $kids = cps_xml_children($child);
        if ($kids) {
            $out += cps_xml_flatten($child, $name);
        } else {
            $out[$name] = trim($child->textContent);
        }
    }
    return $out;
}

/** Render an assoc array as an aligned "key : value" block. */
function cps_render_kv(array $kv, string $indent = '  '): string {
    if (!$kv) { return $indent . "(none)\n"; }
    $w = max(array_map('strlen', array_map('strval', array_keys($kv))));
    $s = '';
    foreach ($kv as $k => $v) {
        $s .= sprintf("%s%-{$w}s : %s\n", $indent, $k, is_scalar($v) ? (string)$v : json_encode($v));
    }
    return $s;
}

/** Render a list of assoc arrays as an aligned text table. */
function cps_render_table(array $rows, array $cols = []): string {
    if (!$rows) { return "(no rows)\n"; }
    if (!$cols) {
        $cols = [];
        foreach ($rows as $r) { foreach (array_keys($r) as $k) { $cols[$k] = true; } }
        $cols = array_keys($cols);
    }
    $w = [];
    foreach ($cols as $c) {
        $w[$c] = strlen($c);
        foreach ($rows as $r) {
            $w[$c] = max($w[$c], strlen((string)($r[$c] ?? '')));
        }
    }
    $line = function (array $vals) use ($cols, $w) {
        $p = [];
        foreach ($cols as $c) { $p[] = sprintf("%-{$w[$c]}s", (string)($vals[$c] ?? '')); }
        return rtrim(implode('  ', $p));
    };
    $s = $line(array_combine($cols, $cols)) . "\n";
    $s .= implode('  ', array_map(fn($c) => str_repeat('-', $w[$c]), $cols)) . "\n";
    foreach ($rows as $r) { $s .= $line($r) . "\n"; }
    return $s;
}

/** Render a list of assoc arrays as CSV text (union of all keys as header). */
function cps_render_csv(array $rows, array $cols = []): string {
    // With columns supplied, an empty result is still a valid CSV: one
    // header line. Returning nothing at all makes "no findings" look
    // identical to "the command failed".
    if (!$rows && !$cols) { return ''; }
    if (!$cols) {
        $cols = [];
        foreach ($rows as $r) { foreach (array_keys($r) as $k) { $cols[$k] = true; } }
        $cols = array_keys($cols);
    }
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $cols, ',', '"', '\\');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            $line[] = is_scalar($v) || $v === null ? (string)$v : json_encode($v);
        }
        fputcsv($fh, $line, ',', '"', '\\');
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return $out;
}

function cps_json(mixed $v): string {
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * Derive a MAC address from a Cisco device name (SEP001122334455 -> 001122334455).
 * Returns null when the name is not in a recognised device-name form; ATA and
 * some analog devices use other prefixes, so this is best-effort by design.
 */
function cps_mac_from_device_name(string $name): ?string {
    if (preg_match('/^(?:SEP|CIPC|TCT|BOT|TAB|CSF|ATA)([0-9A-Fa-f]{12})$/', $name, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

/**
 * Coerce a RisPort-style boolean to 1/0/null.
 *
 * HttpSupported comes back as "Yes"/"No" on some releases and 1/0 on others,
 * and as an empty element when the node has no opinion. An unrecognised
 * value becomes NULL rather than 0, so "we do not know" stays distinct from
 * "the phone has no web server" - the two lead to different actions.
 */
function cps_tri_bool(mixed $v): ?int {
    if ($v === null) { return null; }
    $s = strtolower(trim((string)$v));
    if ($s === '') { return null; }
    if (in_array($s, ['1', 'yes', 'true', 'y', 'on', 'supported'], true))  { return 1; }
    if (in_array($s, ['0', 'no', 'false', 'n', 'off', 'unsupported'], true)) { return 0; }
    return null;
}

/**
 * Is $ip inside $cidr?
 *
 * Byte-wise against inet_pton output, so it is correct for both address
 * families and for prefixes that are not on a byte boundary. A mismatch in
 * family (an IPv4 address against an IPv6 prefix) is false, not an error -
 * mixing the two in one exclusion list is legitimate.
 *
 * A bare address with no "/" is treated as a single host (/32 or /128).
 * Host bits set in the network part are ignored: "10.1.2.5/24" matches the
 * whole of 10.1.2.0/24, which is what someone writing that means.
 */
function cps_ip_in_cidr(string $ip, string $cidr): bool {
    $cidr  = trim($cidr);
    $slash = strrpos($cidr, '/');
    $net   = $slash === false ? $cidr : substr($cidr, 0, $slash);
    $bitsS = $slash === false ? null : substr($cidr, $slash + 1);

    $ipBin  = @inet_pton(trim($ip));
    $netBin = @inet_pton(trim($net));
    if ($ipBin === false || $netBin === false) { return false; }
    if (strlen($ipBin) !== strlen($netBin)) { return false; } // v4 vs v6

    $maxBits = strlen($netBin) * 8;
    $bits    = $bitsS === null ? $maxBits : (int)$bitsS;
    if ($bits < 0 || $bits > $maxBits) { return false; }
    // Guard /0 explicitly: it matches everything, and the byte loop below
    // would otherwise compare nothing and fall through to the same answer
    // only by accident.
    if ($bits === 0) { return true; }

    $whole = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($whole > 0 && strncmp($ipBin, $netBin, $whole) !== 0) { return false; }
    if ($rem === 0) { return true; }
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return (($ipBin[$whole] & $mask) === ($netBin[$whole] & $mask));
}

/**
 * Validate and split a comma/space separated CIDR list.
 *
 * Invalid entries are fatal rather than ignored. A silently dropped
 * exclusion is the worst outcome here: the operator believes a subnet is
 * being left alone, the tool polls it anyway, and nothing in the output
 * says so. Better to refuse to start.
 *
 * @return string[]
 */
function cps_parse_cidr_list(string $list, string $label = 'subnet list'): array {
    $out = [];
    foreach (preg_split('/[\s,]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        $slash = strrpos($entry, '/');
        $net   = $slash === false ? $entry : substr($entry, 0, $slash);
        $bitsS = $slash === false ? null : substr($entry, $slash + 1);
        $bin   = @inet_pton($net);
        if ($bin === false) {
            cps_die("$label: '$entry' is not a valid address or CIDR");
        }
        if ($bitsS !== null) {
            if ($bitsS === '' || !ctype_digit($bitsS)) {
                cps_die("$label: '$entry' has a non-numeric prefix length");
            }
            $max = strlen($bin) * 8;
            if ((int)$bitsS > $max) {
                cps_die("$label: '$entry' prefix length exceeds /$max for this address family");
            }
        }
        $out[] = $entry;
    }
    return $out;
}

/**
 * Split rows into kept and excluded by CIDR, recording which prefix matched.
 *
 * Returns the excluded rows too, so a caller can say what it dropped and
 * why. A filter that shrinks the estate without saying so is how a partial
 * sweep gets mistaken for a complete one.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{kept: array, excluded: array, by_cidr: array<string,int>}
 */
function cps_filter_by_cidr(array $rows, array $cidrs, string $ipField = 'ip'): array {
    if (!$cidrs) { return ['kept' => $rows, 'excluded' => [], 'by_cidr' => []]; }
    $kept = $excluded = [];
    $byCidr = array_fill_keys($cidrs, 0);
    foreach ($rows as $r) {
        $ip = (string)($r[$ipField] ?? '');
        $hit = null;
        if ($ip !== '') {
            foreach ($cidrs as $c) {
                if (cps_ip_in_cidr($ip, $c)) { $hit = $c; break; }
            }
        }
        if ($hit === null) {
            $kept[] = $r;
        } else {
            $r['excluded_by'] = $hit;
            $excluded[] = $r;
            $byCidr[$hit]++;
        }
    }
    return ['kept' => $kept, 'excluded' => $excluded, 'by_cidr' => $byCidr];
}

/**
 * Cisco device-name prefixes, longest-first so no prefix shadows another.
 *
 * SEP is a hard handset. The rest are soft clients and analog adapters:
 * CSF (Jabber desktop), TCT (iPhone), BOT (Android), TAB (tablet),
 * CIPC (the old IP Communicator), ATA (analog adapter), SIP/IMS (third
 * party). None of the non-SEP kinds have a switch port or a PC port, so
 * none of them have port statistics to collect.
 */
const CPS_DEVICE_PREFIXES = ['CIPC', 'SEP', 'CSF', 'TCT', 'BOT', 'TAB', 'ATA', 'SIP', 'IMS'];

/** The device-name prefix of a device, or 'other' if unrecognised. */
function cps_device_prefix(string $name): string {
    foreach (CPS_DEVICE_PREFIXES as $p) {
        if (stripos($name, $p) === 0) { return $p; }
    }
    return 'other';
}

/**
 * Split rows by device-name prefix.
 *
 * @param string[] $prefixes kept prefixes; empty means keep everything
 * @return array{kept: array, excluded: array, by_prefix: array<string,int>}
 */
function cps_filter_by_device_prefix(array $rows, array $prefixes, string $nameField = 'name'): array {
    if (!$prefixes) { return ['kept' => $rows, 'excluded' => [], 'by_prefix' => []]; }
    $want = array_map('strtoupper', $prefixes);
    $kept = $excluded = [];
    $byPrefix = [];
    foreach ($rows as $r) {
        $name = (string)($r[$nameField] ?? '');
        $hit  = false;
        foreach ($want as $p) {
            if (stripos($name, $p) === 0) { $hit = true; break; }
        }
        if ($hit) {
            $kept[] = $r;
        } else {
            $excluded[] = $r;
            $k = cps_device_prefix($name);
            $byPrefix[$k] = ($byPrefix[$k] ?? 0) + 1;
        }
    }
    arsort($byPrefix);
    return ['kept' => $kept, 'excluded' => $excluded, 'by_prefix' => $byPrefix];
}
