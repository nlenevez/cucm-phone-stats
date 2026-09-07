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
    if (!$rows) { return ''; }
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
