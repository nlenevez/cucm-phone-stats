# Changelog

## Unreleased

First release.

- Phone discovery via RisPort70 `selectCmDevice`, with an AXL
  `RegistrationDynamic` fallback; multiple servers merged.
- RisPort enumeration: one query, re-queried per CUCM node when the answer
  is at or near the 1000 cap; nodes still at the cap reported as incomplete
  (`ris_partitions` = auto | always | off).
- Sliding-window RisPort rate limiting with retry on throttle faults.
- AXL client with schema-version negotiation; model names from `typemodel`.
- Concurrent polling of phone web pages; network/access port speed, duplex
  and error counters, with raw label/value pairs stored.
- Device-type scoping (`poll_device_prefixes`, `--all-devices`) and subnet
  exclusion (`exclude_subnets`, `--exclude-subnet`).
- `cucm-sweep.php`: `--sample`, `--limit`, `--match`, `--phones-from`,
  `--no-db`, `--parallel`.
- `--only-problems [SWEEP]` re-sweep with `--problems
  mismatch,errors,growing,switch` and `--min-errors N`.
- CDP/LLDP uplink (switch and port) recorded per phone.
- Common Phone Profile recorded per phone (`phone_config`).
- `cucm-switch-import.php`: imports Catalyst `show interface status`
  captures; switch-vs-phone comparison reports.
- `cucm-set-profile.php`: moves switch-mismatched phones to another Common
  Phone Profile; dry run by default, `--apply`, `--apply-config [BATCH|latest]`,
  `--undo BATCH`.
- `cucm-report.php`: `speed-mismatch`, `errors`, `error-trend`,
  `switch-mismatch`, `switch-compare`, `switch-captures`, `phones`,
  `uplinks`, `labels`, `sweeps`; table, CSV and JSON output.
- `web/cucm-reports.php`: single-file report page with search, sorting,
  sweep selector, CSV export and light/dark theme.
- `cucm-probe.php` to check a new cluster and phone; `cucm-initdb.php` for
  schema creation and migrations.
- Test suite with a mock CUCM and mock phones.
