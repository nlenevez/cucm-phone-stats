<?php
/**
 * config.php - configuration loading and shared CLI plumbing.
 *
 * Precedence, lowest to highest: built-in defaults, config file, environment,
 * command line. Passwords may be given inline, but preferably as
 * *_pass_file (a file the account can read) or *_pass_env (the name of an
 * environment variable) so a credential never has to sit in the repo or in
 * the process list of a shared box.
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/soap.php';
require_once __DIR__ . '/axl.php';
require_once __DIR__ . '/risport.php';
require_once __DIR__ . '/phone.php';

const CPS_VERSION = '1.0.0';

const CPS_DEFAULTS = [
    'cucm_servers'    => '',
    'cucm_port'       => 8443,
    'axl_user'        => '',
    'axl_pass'        => '',
    'axl_pass_file'   => '',
    'axl_pass_env'    => '',
    'axl_version'     => '',       // blank = negotiate
    'verify_tls'      => 0,
    'ca_bundle'       => '',
    'soap_timeout'    => 60,
    'ris_gap_ms'      => 4000,
    'ris_max_devices' => 1000,

    'phone_url_base'  => 'https://{ip}',
    'phone_user'      => '',
    'phone_pass'      => '',
    'phone_pass_file' => '',
    'phone_pass_env'  => '',
    // Schemes bin/cucm-probe.php tries when probing a handset. An estate
    // that is entirely HTTPS should set this to "https": the http attempts
    // do not fail fast, they time out, costing phone_timeout twice per run.
    // This does NOT affect collection - sweeps only ever use phone_url_base.
    'phone_probe_schemes' => 'https,http',
    'phone_parallel'  => 10,
    'phone_timeout'   => 10,
    'phone_connect_timeout' => 5,
    'adapters'        => 'network,access,ethernet,device',

    'db_enabled'      => 1,
    'db_host'         => '127.0.0.1',
    'db_port'         => 3306,
    'db_name'         => 'cucm_phone_stats',
    'db_user'         => 'cucm',
    'db_pass'         => '',
    'db_pass_file'    => '',
    'db_pass_env'     => '',
];

/** Load config file (INI), overlay environment, return the merged map. */
function cps_load_config(?string $file): array
{
    $cfg = CPS_DEFAULTS;

    if ($file === null) {
        foreach ([getcwd() . '/cucm.conf', dirname(__DIR__) . '/cucm.conf', getenv('HOME') . '/.cucm-phone-stats.conf'] as $cand) {
            if ($cand && is_readable($cand)) { $file = $cand; break; }
        }
    }
    if ($file !== null) {
        if (!is_readable($file)) { cps_die("config file not readable: $file"); }
        $ini = parse_ini_file($file, false, INI_SCANNER_TYPED);
        if ($ini === false) { cps_die("config file is not valid INI: $file"); }
        foreach ($ini as $k => $v) { $cfg[strtolower($k)] = $v; }
        cps_log("config loaded from $file", 2);
    }

    // CPS_* environment overrides, e.g. CPS_AXL_PASS
    foreach (array_keys($cfg) as $k) {
        $env = getenv('CPS_' . strtoupper($k));
        if ($env !== false && $env !== '') { $cfg[$k] = $env; }
    }

    foreach (['axl', 'phone', 'db'] as $which) {
        $cfg["{$which}_pass"] = cps_resolve_secret(
            (string)$cfg["{$which}_pass"],
            (string)$cfg["{$which}_pass_file"],
            (string)$cfg["{$which}_pass_env"],
            $which
        );
    }
    return $cfg;
}

function cps_resolve_secret(string $inline, string $file, string $envName, string $label): string
{
    if ($file !== '') {
        if (!is_readable($file)) { cps_die("{$label}_pass_file not readable: $file"); }
        return rtrim((string)file_get_contents($file), "\r\n");
    }
    if ($envName !== '') {
        $v = getenv($envName);
        if ($v === false) { cps_die("{$label}_pass_env names \$$envName, which is not set"); }
        return $v;
    }
    return $inline;
}

/** @return string[] the configured CUCM servers, normalised to base URLs. */
function cps_server_urls(array $cfg): array
{
    $raw = trim((string)$cfg['cucm_servers']);
    if ($raw === '') { return []; }
    $out = [];
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $s) {
        $out[] = str_contains($s, '://') ? rtrim($s, '/') : 'https://' . $s . ':' . (int)$cfg['cucm_port'];
    }
    return $out;
}

function cps_soap_for(array $cfg, string $baseUrl): CpsSoapClient
{
    return new CpsSoapClient(
        $baseUrl,
        (string)$cfg['axl_user'],
        (string)$cfg['axl_pass'],
        (bool)$cfg['verify_tls'],
        (int)$cfg['soap_timeout'],
        10,
        ((string)$cfg['ca_bundle']) !== '' ? (string)$cfg['ca_bundle'] : null,
    );
}

/** @return CpsRisportClient[] */
function cps_risport_clients(array $cfg): array
{
    $out = [];
    foreach (cps_server_urls($cfg) as $u) {
        $out[] = new CpsRisportClient(
            cps_soap_for($cfg, $u),
            (int)$cfg['ris_max_devices'],
            (int)$cfg['ris_gap_ms'],
        );
    }
    return $out;
}

function cps_axl_client(array $cfg, ?string $server = null): CpsAxlClient
{
    $urls = cps_server_urls($cfg);
    if (!$urls) { cps_die('no cucm_servers configured'); }
    $ver = ((string)$cfg['axl_version']) !== '' ? (string)$cfg['axl_version'] : null;
    return new CpsAxlClient(cps_soap_for($cfg, $server ?? $urls[0]), $ver);
}

function cps_phone_fetcher(array $cfg, ?array $adapters = null): CpsPhoneFetcher
{
    $ads = $adapters ?? preg_split('/[\s,]+/', (string)$cfg['adapters'], -1, PREG_SPLIT_NO_EMPTY);
    foreach ($ads as $a) {
        if (!isset(CPS_ADAPTERS[$a])) {
            cps_die("unknown adapter '$a'; known: " . implode(', ', array_keys(CPS_ADAPTERS)));
        }
    }
    return new CpsPhoneFetcher(
        (string)$cfg['phone_url_base'],
        ((string)$cfg['phone_user']) !== '' ? (string)$cfg['phone_user'] : null,
        (string)$cfg['phone_pass'],
        (bool)$cfg['verify_tls'],
        (int)$cfg['phone_timeout'],
        (int)$cfg['phone_connect_timeout'],
        (int)$cfg['phone_parallel'],
        $ads,
    );
}

/**
 * Minimal long-option parser: --key=value, --key value, --flag, and
 * positionals. Kept deliberately small - this has to be readable by whoever
 * inherits it on a box with no internet to look anything up.
 *
 * @return array{opts: array<string,string|bool>, args: string[]}
 */
function cps_parse_args(array $argv, array $flags = []): array
{
    $opts = [];
    $args = [];
    $n    = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if ($a === '--') { $args = array_merge($args, array_slice($argv, $i + 1)); break; }
        if (str_starts_with($a, '--')) {
            $body = substr($a, 2);
            if (str_contains($body, '=')) {
                [$k, $v] = explode('=', $body, 2);
                $opts[$k] = $v;
            } elseif (in_array($body, $flags, true)) {
                $opts[$body] = true;
            } else {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || str_starts_with($next, '--')) { $opts[$body] = true; }
                else { $opts[$body] = $next; $i++; }
            }
        } else {
            $args[] = $a;
        }
    }
    return ['opts' => $opts, 'args' => $args];
}

/** Apply --verbose/-v/--quiet and return the config file path from --config. */
function cps_common_opts(array $opts): ?string
{
    if (!empty($opts['quiet']))   { cps_set_verbosity(0); }
    if (!empty($opts['verbose'])) { cps_set_verbosity(2); }
    if (!empty($opts['debug']))   { cps_set_verbosity(3); }
    $c = $opts['config'] ?? null;
    return is_string($c) ? $c : null;
}
