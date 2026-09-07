# Changelog

## Unreleased

### Added
- AXL client (`lib/axl.php`): schema-version negotiation, `executeSQLQuery`,
  paged `listPhone`, `RegistrationDynamic` fallback query.
- RisPort70 client (`lib/risport.php`): `selectCmDevice` with StateInfo
  paging, multi-server merge, IPv4-preferred address selection.
- Phone collection (`lib/phone.php`): parallel fetch of the network and
  access port pages plus ethernet/device/config context; tolerant HTML table
  parser; label normalisation into typed speed, duplex and error columns.
- MySQL store and reporting views (`schema.sql`): sweeps, phones,
  registrations, port_stats, page_fetches; `v_port_speed_mismatch`,
  `v_port_errors`, `v_port_error_deltas`, `v_port_errors_increasing`.
- CLIs: `cucm-probe.php`, `cucm-phones.php`, `cucm-sweep.php`,
  `cucm-phone-netstats.php`, `cucm-report.php`, `cucm-initdb.php`.
- Mock CUCM and mock phones (`tests/mock-server.php`) plus 84 checks
  (`tests/run-tests.php`), including negative controls.
- Docs: README, `docs/design.md`, `docs/cucm-permissions.md`,
  `docs/airgap-runbook.md`.

### Changed
- Verified end to end on the target platform, **RHEL 8.10** with PHP 8.2.33
  from AppStream, against **MySQL 8.0.46 and MySQL 8.4.11**: 84 checks,
  exit 0 on each. (MariaDB 10.3 also passes; MySQL is the target.) The
  earlier "MySQL 8.0" claim was taken from the `mysql:8` image tag, which
  now resolves to 8.4 - so 8.0 had not in fact been exercised until now.
- Documented the **database grants**, tested rather than asserted: the
  runtime account needs only SELECT/INSERT/UPDATE, with DDL split into a
  separate setup account used once by `cucm-initdb.php`. Nothing in the tool
  deletes a row, so no DELETE is granted. Documented the RPM
  set — `php-cli php-xml php-mysqlnd` — because RHEL splits the extensions
  up and **`php-cli` alone does not provide DOM**, which every script here
  depends on. Also noted that RHEL 8's AppStream default stream is php 7.2
  and that it has no 8.3 stream at all.
- Documented floor is **PHP 8.2**, verified on 8.2, 8.3, 8.4 and 8.5. The
  previous "8.1+" was read off the code, not tested. `pdo_mysql` is required
  only for the database half; the probe, discovery, single-phone and
  `--no-db` sweep paths run without it.
- `cucm-probe.php` now probes the configured `phone_url_base` as well as
  `https://{ip}` and `http://{ip}`.

### Added
- **`poll_device_prefixes` (default `SEP`) — only hard handsets are polled.**
  CSF/TCT/BOT/TAB Jabber clients, CIPC and ATA adapters have no switch or PC
  port, so there is nothing to collect from them; more importantly a Jabber
  client registers from the *user's workstation*, so an unfiltered sweep
  fired an HTTPS request at every desk in the estate. `--all-devices` or an
  explicit `--device-prefixes` list overrides. The skip is reported with a
  per-prefix breakdown.
- `--match` accepts `field:value` to scope the search to one field.
  `--match` remains a substring search across name/description/model/ip, so
  `--match SEP` legitimately matches `CSFJOSEPH` (JO-SEP-H); the docs and
  `--help` now say so, a test pins the behaviour, and `--device-prefixes` is
  pointed to as the anchored alternative.
- **Partitioned RisPort enumeration** (`ris_partitions`, default `auto`).
  RisPort caps a single `selectCmDevice` conversation without saying so -
  `StateInfo` stops arriving and the truncated answer looks complete. The
  returned count is now compared against the cluster's own
  `TotalDevicesFound`, and a short answer triggers a re-run split by
  device-name prefix, merged by name. Truncation is warned about, never
  swallowed.
- Discovery reports **per server**: devices returned, new-unique, and the
  per-node `CmNodes` breakdown - a single total cannot show which of several
  clusters came up short.
- `exclude_subnets` / `--exclude-subnet CIDR[,CIDR]` on `cucm-sweep.php` and
  `cucm-phones.php`, sharing one implementation so the listing and the sweep
  cannot disagree about scope. Matching is byte-wise over `inet_pton`, so
  non-byte-boundary prefixes and both address families are handled. An
  invalid prefix is fatal, and a prefix that matched nothing is named in the
  output.
- `--sample N` on `cucm-sweep.php` for a random spread across the estate,
  plus `--match TEXT` (name/description/model/IP) applied before sampling.
  `--limit N` still takes the first N, which on a real cluster means one
  node's worth of phones.
- `phone_probe_schemes` / `--schemes` controls which schemes the probe tries
  against a handset. Set to `https` on an all-HTTPS estate; the HTTP attempts
  time out rather than failing fast.
- RisPort probe now records TotalDevicesFound vs the number returned, the
  per-node breakdown from `CmNodes`, the status histogram, the paging rounds
  and one `CmDevice` element verbatim, and repeats the query with
  `Status=Any`. A short-but-clean answer is otherwise indistinguishable from
  a complete one.

### Fixed
- **Phone polling batched instead of streaming, and one slow handset stalled
  nine fast ones.** `fetchMany()` split phones into chunks of
  `phone_parallel` and ran each chunk to completion before starting the
  next, so every chunk cost as much as its slowest member. Replaced with a
  rolling window that refills as each request finishes. Measured on 100
  phones with one in eight slow: **12.2s to 3.1s** at the default
  `phone_parallel = 10`, and 1.6s at 20. Added `--parallel N`, plus progress
  logging at `--verbose` so a long sweep does not look hung.
- **Config values that look boolean were being destroyed by the INI parser.**
  `parse_ini_file`'s TYPED and NORMAL scanners both turn `off`, `no`, `false`
  and `none` into `false` or `""`, so `ris_partitions = off` became an empty
  pattern list and discovery returned **zero** phones - from a config file
  that reads correctly and raises no error. Now parsed with
  `INI_SCANNER_RAW`, with the genuinely boolean settings coerced explicitly
  (`cps_cfg_bool`, which accepts 1/0, true/false, yes/no, on/off).
- **`&nbsp;` spacer cells corrupted parsed values.** These pages pad tables
  with `&nbsp;`, which decodes to U+00A0; PCRE's `\s` does not match U+00A0
  outside UTF-8 mode, so a cell that should test as empty did not, the
  label/value pairing shifted by one cell, and values came out as
  `"\u{00A0} 10"` instead of `"10"`. The same bug was present in
  `patching-tools/cisco-phone-netstats.php`, which shares this parser, and
  was fixed there in `ad37cb0`.
- Test harness referenced the `SIGTERM` constant, which only exists with
  ext-pcntl. Without it the suite passed and then fatally errored in its
  shutdown handler, turning a green run into a non-zero exit code.
  `proc_terminate()` already defaults to SIGTERM.
- `cucm-probe.php` ignored `phone_url_base`, so an estate serving the phone
  pages on a non-standard base saw every phone probe fail while the sweep
  worked - the probe contradicting the tool it exists to validate.
- **`listPhones()` walked the entire estate when only a sample was wanted.**
  Its second argument is the page size, not a row cap, so the probe's
  `listPhones([...], 3)` fetched all 6,757 configured phones three at a time
  - over two thousand SOAP round trips for what should be one. Added a
  `$maxRows` cap; the probe now pulls two pages of two, which still proves
  skip/first paging advances.
- An `https://` base could be redirected to `http://`, re-sending basic-auth
  credentials in cleartext. Redirect protocols are now pinned to the base's
  scheme (never downgrade; an http base may still upgrade).
- `cps_phone_url()` bracketed any address containing a colon as IPv6, so
  `10.0.0.5:8443` became `[10.0.0.5:8443]` and curl rejected it.
