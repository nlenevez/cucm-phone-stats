# Changelog

## Unreleased

First working version. Never yet run against the production cluster in
anger — see `docs/airgap-runbook.md` and issue #1 for what remains unproven.

### Added

**Core**
- AXL client (`lib/axl.php`): schema-version negotiation, `executeSQLQuery`,
  paged `listPhone` with a row cap, `RegistrationDynamic` fallback query.
- RisPort70 client (`lib/risport.php`): `selectCmDevice` with StateInfo
  paging, partitioned enumeration, multi-server merge, per-server and
  per-node attribution.
- Phone collection (`lib/phone.php`): rolling-window concurrent fetch of the
  network and access port pages plus ethernet/device/config context;
  tolerant HTML table parser; label normalisation into typed speed, duplex
  and error columns, with the raw label/value pairs always retained.
- MySQL store and reporting views (`schema.sql`): sweeps, phones,
  registrations, port_stats, page_fetches; `v_port_speed_mismatch`,
  `v_port_errors`, `v_port_error_deltas`, `v_port_errors_increasing`.
- CLIs: `cucm-probe.php`, `cucm-phones.php`, `cucm-sweep.php`,
  `cucm-phone-netstats.php`, `cucm-report.php`, `cucm-initdb.php`.
- Mock CUCM and mock phones (`tests/mock-server.php`) and 189 checks
  (`tests/run-tests.php`), including negative controls throughout.
- Docs: README, `docs/design.md`, `docs/cucm-permissions.md`,
  `docs/airgap-runbook.md`.

**Scoping and scale**
- **`poll_device_prefixes` (default `SEP`) — only hard handsets are polled.**
  CSF/TCT/BOT/TAB Jabber clients, CIPC and ATA adapters have no switch or PC
  port. More importantly a Jabber client registers from the *user's
  workstation*, so an unfiltered sweep fired an HTTPS request at every desk
  in the estate. `--all-devices` or an explicit `--device-prefixes` list
  overrides; the skip is reported with a per-prefix breakdown.
- **Exact partitions computed from AXL's device names.** When a cluster will
  not match on the end of a name (a live one does not — `SEP*0` returns
  nothing), the names are fetched from AXL, which is not subject to the
  RisPort throttle, and the buckets are computed from where the estate
  actually divides: descending only where a bucket is too big and only into
  characters that occur, so no query is spent on an empty branch. On a fleet
  sharing one OUI that is ~9 buckets computed rather than ~112 queries
  discovered. `ris_use_axl_names = 0` disables it; blind prefix splitting
  remains the last resort and says so loudly.
- **Splitting on the last character of the device name**, tried after
  per-node and before prefixes. Prefix buckets are near-useless when a fleet
  shares an OUI — a live estate had `SEP4*` through `SEP44B6BE*` all over the
  cap in turn, six levels of sixteen queries to separate nothing — whereas
  the trailing serial digits split evenly in one level. Support is probed
  with a single query and the result verified (rows present, under the cap,
  and actually ending in the requested digit), since a cluster that ignored
  the pattern would return the capped set and look successful. Measured on an
  OUI-clustered estate: **0 subdivisions instead of 7**.
- **Per-node RisPort enumeration, tried before name prefixes.** Phones are
  already spread across subscribers, so a four-node cluster of 2,300 phones
  resolves in four queries instead of sixteen-plus name buckets. Device names
  are MACs and a fleet shares an OUI — over a thousand phones under `SEP44*`
  is normal — so leading-character buckets walk down one dominant branch and
  make almost no progress per query.
- **`poll_device_prefixes` now scopes the RisPort query itself.** It was
  only ever a post-discovery filter: the partition list was a hardcoded
  constant that always included `CSF*`, `TCT*`, `BOT*` and friends, so
  setting `poll_device_prefixes = SEP` still enumerated — and on a truncated
  estate subdivided — every soft-client bucket before discarding the rows.
  `SelectItems` now carries the polled prefixes on the first query, the
  per-node queries and the partitions alike, so those devices are never
  gathered at all. A scoped query returning nothing retries once unscoped
  and says so, rather than reporting an empty estate.
- **Sliding-window rate limiting** (`ris_rate_per_min`, default 12) replaces
  the fixed inter-request gap, which sat exactly on 15/min with no headroom.
  Throttle faults are recognised and **retried with backoff** — they are
  transient, and treating one as a hard failure previously skipped a
  partition and dropped exactly the devices the split existed to recover. A
  partition that never completes now marks the result incomplete and says so.
- Subdivision uses a hex alphabet for MAC-shaped stems (16 children, not 36),
  and the depth limit is raised so it can get past a six-digit OUI.
- **Partitioned RisPort enumeration** (`ris_partitions`, default `auto`).
  RisPort caps a single `selectCmDevice` conversation without saying so —
  `StateInfo` stops arriving and the truncated answer looks complete. The
  returned count is compared against the cluster's own `TotalDevicesFound`,
  and a short answer triggers a re-run split by device-name prefix, merged
  by name. Truncation is warned about, never swallowed.
- Discovery reports **per server**: devices returned, new-unique, and the
  per-node `CmNodes` breakdown — a single total cannot show which of several
  clusters came up short.
- `exclude_subnets` / `--exclude-subnet CIDR[,CIDR]` on `cucm-sweep.php` and
  `cucm-phones.php`, sharing one implementation so the listing and the sweep
  cannot disagree about scope. Byte-wise matching over `inet_pton`, so
  non-byte-boundary prefixes and both address families work. An invalid
  prefix is fatal; a prefix that matched nothing is named in the output.
- `--sample N` for a random spread across the estate, and `--match TEXT`
  (name/description/model/IP) applied before sampling. `--limit N` still
  takes the first N, which on a real cluster means one node's worth.
- `--match` accepts `field:value` to scope the search. It remains a
  substring search, so `--match SEP` legitimately matches `CSFJOSEPH`
  (JO-SEP-H); the docs and `--help` say so, a test pins it, and
  `--device-prefixes` is the anchored alternative.
- `--parallel N`, and progress logging at `--verbose` so a long sweep does
  not look hung.

**Uplink / CDP**
- **Switch name and port in every report.** The phone's CDP/LLDP view of what
  it is plugged into is collected per sweep into a new `uplinks` table and
  joined into `v_port_speed_mismatch`, `v_port_errors` and
  `v_port_error_deltas`, so an errors row names the switch port to go and
  look at. CDP is preferred over LLDP where both exist; a placeholder value
  is treated as absent; `neighbour_source` records which was used. A new
  `uplinks` report lists it on its own.
- Neighbour fields are harvested from **every** adapter page, not just
  `config`, because some firmware puts CDP on the network port page.
  `config` is now in the default `adapters` list.
- **`labels` report** — histograms every label the phones actually returned,
  which adapter carried it, and how the normaliser interprets it, with
  `--grep` and `--unmapped-only`. Because raw pairs are stored verbatim this
  confirms real field names from sweeps already taken rather than guessing
  them, and a missed spelling can be added and the reports re-run without
  re-sweeping the estate.

**Probe**
- `phone_probe_schemes` / `--schemes` controls which schemes the probe tries
  against a handset. Set to `https` on an all-HTTPS estate; the HTTP
  attempts time out rather than failing fast.
- The RisPort probe records `TotalDevicesFound` against the number returned,
  the per-node breakdown, the status histogram, the paging rounds and one
  `CmDevice` element verbatim, and repeats the query with `Status=Any`.

### Changed
- Verified end to end on the target platform, **RHEL 8.10** with PHP 8.2.33
  from AppStream, against **MySQL 8.0.46 and 8.4.11**. (MariaDB 10.3 also
  passes; MySQL is the target.) The earlier "MySQL 8.0" claim came from the
  `mysql:8` image tag, which now resolves to 8.4 — so 8.0 had not in fact
  been exercised until then.
- Documented floor is **PHP 8.2**, verified on 8.2, 8.3, 8.4 and 8.5. The
  previous "8.1+" was read off the code, not tested. `pdo_mysql` is needed
  only for the database half; the probe, discovery, single-phone and
  `--no-db` sweep paths run without it.
- Documented the RPM set — `php-cli php-xml php-mysqlnd` — because RHEL
  splits the extensions up and **`php-cli` alone does not provide DOM**,
  which every script here depends on. RHEL 8's AppStream default stream is
  php 7.2, and it has no 8.3 stream at all.
- Documented the **database grants**, tested rather than asserted: the
  runtime account needs only SELECT/INSERT/UPDATE, with DDL split into a
  separate setup account used once by `cucm-initdb.php`. Nothing here
  deletes a row, so no DELETE is granted.
- `cucm-probe.php` probes the configured `phone_url_base` as well as
  `https://{ip}` and `http://{ip}`.

### Fixed
- **A cluster on an older release was unreachable: AXL version negotiation
  aborted on the first refusal.** CUCM refuses a schema it does not know with
  **HTTP 599** and a bare `<axlError>` ("Wrong AXL Version") rather than a
  SOAP Fault, and any non-200 was being treated as a fatal transport error —
  so the walk stopped on its first candidate and never got down to 11.5.
  599 is AXL refusing the request, not the transport failing: it now moves to
  the next version, a bare `<axlError>` is recognised without a Fault
  wrapper, and the error text says what 599 means. A cluster that accepts
  nothing known now fails with a clear message naming the versions tried,
  rather than an uncaught `CpsHttpError`.
- `cucm-probe.php` gained `--server HOST` to probe one cluster of several,
  and now reports which schema versions were accepted and refused.
- **`error-trend` repeated the same phone and port once per sweep
  transition**, each with a different error count, which reads as duplicate
  rows rather than as the time series it is. It now returns **one row per
  phone and port** — the most recent growth — with `times_grown` and
  `total_growth` summarising the rest, since "this port has grown in four of
  the last four sweeps" is a stronger signal than any single delta.
  `--history` restores the full series.
- **`dir_number` overflowed its column and cost the whole phone.** RisPort
  returns `DirNumber` as a comma-separated list of *every* line appearance,
  so a phone with many lines runs past `VARCHAR(64)` and MySQL rejects the
  row with error 1406 — and since writes are one transaction per phone, that
  lost the handset's registration, port statistics and uplink over one
  field. The column is now `VARCHAR(512)`, and **`cucm-initdb.php` migrates
  an existing database** (`CREATE TABLE IF NOT EXISTS` cannot widen a column
  that already exists; the ALTER only ever widens and is a no-op when the
  column is already big enough).
- **Over-long values are now clipped rather than failing the write.** Driven
  by the live column widths from `information_schema`, so it covers the
  fields nobody predicted would overflow, and it warns once per column
  rather than doing it silently. `dir_number` was the one that broke; it will
  not be the last.
- **Truncation went undetected when the cluster capped `TotalDevicesFound`
  too.** A CUCM with 2,300 registered phones returned exactly 1,000 and also
  reported 1,000 as the total, so comparing the two concluded the answer was
  complete and the partitioning fallback never fired. Detection now also
  treats a result sitting at or near the cap as truncated, a bucket that
  itself hits the cap is subdivided a character deeper (up to three levels),
  and `ris_partitions = always` forces the split outright.
- **A phone answering HTTP 200 with an empty body crashed the whole sweep.**
  `curl` reported no error and the status was 200, so every guard passed the
  body through to `DOMDocument::loadHTML('')`, which throws a `ValueError`
  under PHP 8 — killing a run of thousands part-way through and losing every
  phone after the bad one. Empty and whitespace-only bodies are now reported
  as `error_kind = 'empty'` (distinct from `parse`, which means the markup
  arrived but was not understood), the parser is wrapped so no future
  surprise can escape, and each phone's processing in `cucm-sweep.php` is
  contained so one handset can never abort the run. A sweep that dies anyway
  now records the reason on its `sweeps` row instead of sitting open with no
  explanation.
- **Phone polling batched instead of streaming, and one slow handset stalled
  nine fast ones.** `fetchMany()` split phones into chunks of
  `phone_parallel` and ran each to completion before starting the next, so
  every chunk cost as much as its slowest member. Replaced with a rolling
  window that refills as each request finishes. Measured on 100 phones with
  one in eight slow: **12.2s to 3.1s** at the default `phone_parallel = 10`,
  and 1.6s at 20.
- **Config values that look boolean were destroyed by the INI parser.**
  `parse_ini_file`'s TYPED and NORMAL scanners both turn `off`, `no`,
  `false` and `none` into `false` or `""`, so `ris_partitions = off` became
  an empty pattern list and discovery returned **zero** phones — from a
  config file that reads correctly and raises no error. Now parsed with
  `INI_SCANNER_RAW`, booleans coerced explicitly via `cps_cfg_bool()`.
- **`listPhones()` walked the entire estate when only a sample was wanted.**
  Its second argument is the page size, not a row cap, so the probe's
  `listPhones([...], 3)` fetched all 6,757 configured phones three at a time
  — over two thousand SOAP round trips for what should be one. Added a
  `$maxRows` cap; the probe pulls two pages of two, which still proves
  skip/first paging advances.
- **`&nbsp;` spacer cells corrupted parsed values.** These pages pad tables
  with `&nbsp;`, which decodes to U+00A0; PCRE's `\s` does not match U+00A0
  outside UTF-8 mode, so a cell that should test as empty did not, the
  label/value pairing shifted by one cell, and values came out as
  `"\u{00A0} 10"` instead of `"10"`. The same bug was present in
  `patching-tools/cisco-phone-netstats.php`, which shares this parser, and
  was fixed there in `ad37cb0`.
- **A report with no rows produced an empty CSV, with no header** — a
  zero-byte file is indistinguishable from the command having failed. The
  header now comes from the statement's column metadata rather than from the
  first row, so an empty result is a header and no data rows. CSV also
  carries every selected column rather than the first row's keys.
- An `https://` base could be redirected to `http://`, re-sending basic-auth
  credentials in cleartext. Redirect protocols are pinned to the base's
  scheme (never downgrade; an http base may still upgrade).
- `cucm-probe.php` ignored `phone_url_base`, so an estate serving the phone
  pages on a non-standard base saw every phone probe fail while the sweep
  worked — the probe contradicting the tool it exists to validate.
- `cps_phone_url()` bracketed any address containing a colon as IPv6, so
  `10.0.0.5:8443` became `[10.0.0.5:8443]` and curl rejected it.
- The test harness referenced the `SIGTERM` constant, which only exists with
  ext-pcntl. Without it the suite passed and then fatally errored in its
  shutdown handler, turning a green run into a non-zero exit code.
  `proc_terminate()` already defaults to SIGTERM.
