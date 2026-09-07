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

### Fixed
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
