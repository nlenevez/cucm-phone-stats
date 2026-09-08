#!/usr/bin/env php
<?php
/**
 * cucm-initdb.php - create the tables and reporting views.
 *
 * Usage:  php bin/cucm-initdb.php [--config FILE] [--create-database]
 *
 * The schema is written with CREATE TABLE IF NOT EXISTS and CREATE OR
 * REPLACE VIEW, so re-running it is safe and is how a view change is
 * deployed after an upgrade.
 */

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/db.php';

$p    = cps_parse_args($argv, ['create-database', 'verbose', 'quiet', 'debug', 'help']);
$opts = $p['opts'];
if (!empty($opts['help'])) {
    fwrite(STDERR, "Usage: php bin/cucm-initdb.php [--config FILE] [--create-database]\n");
    exit(0);
}
$cfg = cps_load_config(cps_common_opts($opts));

if (!empty($opts['create-database'])) {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['db_host'], (int)$cfg['db_port']);
    $pdo = new PDO($dsn, $cfg['db_user'], (string)$cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = preg_replace('/[^A-Za-z0-9_]/', '', (string)$cfg['db_name']);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    cps_log("database `$name` ensured");
}

$db = new CpsDb($cfg);
$n  = $db->applySchema(dirname(__DIR__) . '/schema.sql');
echo "Applied {$n} statements to {$cfg['db_name']} on {$cfg['db_host']}.\n";

// CREATE TABLE IF NOT EXISTS cannot widen a column that already exists, so
// an existing database needs explicit ALTERs. Idempotent: a column already
// wide enough is left alone.
$migrated = $db->migrate((string)$cfg['db_name']);
foreach ($migrated as $m) { echo "Migrated: $m\n"; }
if (!$migrated) { echo "No column migrations needed.\n"; }

$tables = $db->query('SELECT TABLE_NAME AS n, TABLE_TYPE AS t FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE, TABLE_NAME', [$cfg['db_name']]);
echo cps_render_table($tables, ['n', 't']);
