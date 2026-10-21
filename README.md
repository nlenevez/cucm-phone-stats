# cucm-phone-stats

Finds registered Cisco IP phones through CUCM (RisPort70, with AXL for
configuration), reads each phone's network and access port speed, duplex and
error counters from its web pages, and stores them in MySQL. Reports speed
mismatches, errors and error trends from the CLI or a single-file web page.

It can also import Catalyst `show interface status` captures and compare the
switch port with the phone, record each phone's Common Phone Profile, and
move switch-mismatched phones to another profile.

## Requirements

- PHP 8.2+ (tested on 8.2 to 8.5) with `curl` and `dom`. No Composer.
- `pdo_mysql` and MySQL 8.0/8.4 for storing and reporting. Probe, listing,
  single-phone and `--no-db` runs work without it.
- A CUCM application user with **Standard AXL API Access**, and Web Access
  enabled on the phones. See [docs/cucm-permissions.md](docs/cucm-permissions.md).

On RHEL 8, `dom` comes from `php-xml`, not `php-cli`:

```bash
dnf module reset php && dnf module enable php:8.2
dnf install php-cli php-xml php-mysqlnd php-mbstring
```

## Install and database setup

```bash
cp cucm.conf.example cucm.conf && $EDITOR cucm.conf
```

```sql
CREATE DATABASE cucm_phone_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- the account in cucm.conf. DDL is for cucm-initdb.php, DELETE for cucm-prune.php.
CREATE USER 'cucm'@'%' IDENTIFIED BY '...';
GRANT CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW,
      SELECT, INSERT, UPDATE, DELETE ON cucm_phone_stats.* TO 'cucm'@'%';
```

Only `cucm-prune.php` deletes rows; sweeps, imports and reports never do.

```bash
php bin/cucm-initdb.php --create-database   # tables, views, migrations; safe to re-run
```

Run `cucm-initdb.php` again after every upgrade.

## Quick start

```bash
php bin/cucm-probe.php --phone 10.0.0.50    # check the cluster and one phone
php bin/cucm-sweep.php --sample 20 --no-db  # 20 random phones, nothing stored
php bin/cucm-sweep.php                      # discover, poll, store
php bin/cucm-report.php speed-mismatch
```

## Tools

### cucm-probe.php

Tests each assumption about the cluster and one phone with its own request.
Prints PASS/FAIL/SKIP and writes JSON evidence.

```bash
php bin/cucm-probe.php --phone 10.0.0.50 --out probe-evidence [--server HOST] [--schemes https]
```

### cucm-phones.php

Lists registered phones and their IPs.

```bash
php bin/cucm-phones.php --csv > phones.csv
```

### cucm-phone-netstats.php

Port statistics for one phone by IP.

```bash
php bin/cucm-phone-netstats.php 10.0.0.50
```

### cucm-sweep.php

Discover, poll every phone, store.

```bash
php bin/cucm-sweep.php                              # full sweep
php bin/cucm-sweep.php --sample 50                  # 50 random phones
php bin/cucm-sweep.php --match Warehouse --sample 5 # filter, then sample
php bin/cucm-sweep.php --no-db --json               # poll, print, store nothing
php bin/cucm-sweep.php --phones-from phones.csv     # skip CUCM, use a list
```

| Option | Effect |
|---|---|
| `--sample N` / `--limit N` | N random phones / the first N |
| `--match TEXT` | substring on name, description, model or IP; `field:value` scopes it |
| `--device-prefixes SEP,ATA` / `--all-devices` | device types to poll (default `poll_device_prefixes`, `SEP`) |
| `--exclude-subnet CIDR[,CIDR]` | leave subnets alone (also `exclude_subnets`) |
| `--parallel N` | phones in flight at once (default 10) |
| `--adapters a,b` | phone pages to fetch |
| `--skip-no-http` | skip phones CUCM reports as having no web server |
| `--phones-from FILE` | CSV or JSON from `cucm-phones.php`; CUCM is not queried |
| `--no-db`, `--json` | do not store; print JSON |
| `--quiet`, `--verbose` | less or more logging |

`--match` is a substring search (`--match SEP` also matches `CSFJOSEPH`); use
`--device-prefixes` for device types.

#### Re-sweeping only the problem phones

`--only-problems [SWEEP]` polls only the phones flagged by the latest (or the
given) sweep, asking RisPort for those names instead of enumerating the
cluster. The result is stored as a normal sweep and becomes the latest one.

| `--problems` reason | Phones whose... |
|---|---|
| `mismatch` | access port speed/duplex differs from the network port |
| `errors` | port has an error counter of at least `--min-errors` (default 1) |
| `growing` | error counters rose since the previous sweep |
| `switch` | network port differs from the switch's `show interface status` |

Default: `mismatch,errors,switch`.

```bash
php bin/cucm-sweep.php --only-problems
php bin/cucm-sweep.php --only-problems --problems errors --min-errors 100
php bin/cucm-sweep.php --only-problems --problems growing
php bin/cucm-sweep.php --only-problems 42 --problems errors    # flagged in sweep 42
```

Error counters are cumulative until the phone reboots; use `--min-errors` or
`growing` to focus on current faults. With `--phones-from`, the file is
filtered to the flagged phones.

#### RisPort enumeration

RisPort returns at most 1000 devices per answer and does not say when it has
truncated. Discovery sends one query; if the answer is at or near the cap (or
short of `TotalDevicesFound`) it queries each CUCM node separately and merges
the results. A node still at the cap is reported as incomplete.
`ris_partitions`: `auto` (default), `always` (always query per node), `off`
(one query only). Device-type scoping (`poll_device_prefixes`,
`--all-devices`) is applied to the results.

### cucm-report.php

```bash
php bin/cucm-report.php <report> [--sweep N|--all] [--min N] [--since YYYY-MM-DD] [--json|--csv|--table]
```

| Report | Shows |
|---|---|
| `speed-mismatch` | access port speed/duplex differs from the network port (both ports up) |
| `errors` | ports with non-zero error counters, worst first; `--min N` |
| `error-trend` | ports whose errors grew between sweeps, one row per port with `times_grown` and `total_growth`; `--history`, `--include-resets` |
| `switch-mismatch` | phone network port differs from the switch port |
| `switch-compare` | every uplink against the switch; `--result match\|speed\|duplex\|no-capture\|no-port\|...` |
| `switch-captures` | imported switch captures |
| `switch-missing` | switches phones report via CDP/LLDP that have no capture (`switch_key` = expected file name) |
| `phones` | inventory, including Common Phone Profile |
| `uplinks` | switch and port each phone reports (CDP/LLDP) |
| `labels` | every label returned by phones and how it maps; `--grep TEXT`, `--unmapped-only` |
| `sweeps` | sweep history |

`error-trend` needs at least two sweeps. A counter that drops (phone reboot)
is marked `counter_reset = 1` instead of being shown as a negative delta.

### cucm-switch-import.php

Loads `show interface status` captures (files or folders, plain text or
Ansible JSON with `stdout`/`stdout_lines`) for the switch comparison.

```bash
php bin/cucm-switch-import.php switch-outputs/ --dry-run   # parse only, check the switch column
php bin/cucm-switch-import.php switch-outputs/
php bin/cucm-switch-import.php captures/ --name-regex '/^show_(.+)\.txt$/'
```

- Switch name: a `HOST#show interface status` prompt in the file, otherwise
  the file name without `.txt/.log/.out/.json` and a trailing `-status` /
  `_status` (also `-int-status`, `_show_interface_status`). So
  `SW01-status.txt` is `SW01`. `--name-regex` takes capture group 1;
  `--switch NAME` sets it outright.
- Names match CDP case-insensitively with the domain removed; a file named
  by IP matches the CDP management address. Interface names are normalised
  (`GigabitEthernet1/0/12` = `Gi1/0/12`).
- Each phone is compared with the newest capture of its switch.
  `captured_at` is the file mtime unless `--captured-at` is given.
- Identical re-imports are not stored twice; files with no interface rows
  are rejected.

Example playbook:

```yaml
- hosts: switches
  gather_facts: false
  tasks:
    - cisco.ios.ios_command:
        commands: show interface status
      register: st
    - ansible.builtin.copy:
        content: "{{ st.stdout[0] }}\n"
        dest: "switch-outputs/{{ inventory_hostname }}.txt"
      delegate_to: localhost
```

### cucm-set-profile.php

**Writes to CUCM.** Moves phones in `switch-mismatch` (latest sweep) from one
Common Phone Profile to another with AXL `updatePhone`. Needs an AXL account
with write access (Standard AXL API Access, not the read-only role).

```bash
php bin/cucm-set-profile.php --from OLD-UUID --to NEW-UUID                  # dry run
php bin/cucm-set-profile.php --from OLD-UUID --to NEW-UUID --apply          # change
php bin/cucm-set-profile.php --from OLD-UUID --to NEW-UUID --apply --apply-config
php bin/cucm-set-profile.php --apply-config latest --apply                  # Apply Config to the last batch
php bin/cucm-set-profile.php --apply-config 20261020-153012 --apply         # ...or a named batch
php bin/cucm-set-profile.php --undo 20261020-153012 --apply                 # revert a batch
```

- Dry run unless `--apply`; the dry run does the same live checks.
- Verifies `--to` exists and skips phones not currently on `--from`.
- Narrow with `--sweep N`, `--result speed|duplex|speed+duplex`,
  `--device NAME[,NAME]`, `--limit N`.
- Changes are logged to `profile_changes` under a batch id. Phones pick up
  the new profile on Apply Config or reset.
- **Apply Config restarts the phones** (calls in progress drop). Run it out
  of hours.

### cucm-prune.php

Deletes sweeps (with their registrations, port stats, page data and
uplinks) and superseded switch captures older than N days.

```bash
php bin/cucm-prune.php --keep-days 7                                        # dry run: counts only
php bin/cucm-prune.php --keep-days 7 --apply
```

- Dry run unless `--apply`. The account in `cucm.conf` needs `DELETE`.
- Always keeps the newest completed sweep and the newest capture of each
  switch, so a cron that stopped for a week cannot empty the database.
- Keeps the phone inventory, Common Phone Profiles and `profile_changes`
  (needed for `--undo`).
- `error-trend` and `--problems growing` only see the sweeps that remain.

### cucm-initdb.php

Creates or updates tables and views and applies migrations. Safe to re-run.

```bash
php bin/cucm-initdb.php [--create-database]
```

## Web page

`web/cucm-reports.php` is a single file showing the reports as tabs with
search, sorting, a sweep selector and CSV export. It needs only `SELECT`.

```bash
cp web/cucm-reports.php /var/www/html/
```

Configuration comes from `CPS_DB_*` environment variables, `CPS_CONF`, or a
`cucm.conf` next to the script. See
[docs/web-deployment.md](docs/web-deployment.md).

## Running from cron

Run as the user that owns `cucm.conf`. Use absolute paths and `flock` to
avoid overlapping runs.

```cron
# m  h    dom mon dow  command
# Keep 7 days of history. Same lock as the sweeps.
45 0    *   *   *    cd /path/to/cucm-phone-stats && flock -n /tmp/cucm-sweep.lock php bin/cucm-prune.php --keep-days 7 --apply >> /var/log/cucm-phone-stats.log 2>&1

# Nightly full sweep.
15 1    *   *   *    cd /path/to/cucm-phone-stats && flock -n /tmp/cucm-sweep.lock php bin/cucm-sweep.php --quiet >> /var/log/cucm-phone-stats.log 2>&1

# Import switch captures. The Ansible pull (e.g. 01:00) runs separately;
# import after the captures land and before the profile step.
45 1    *   *   *    cd /path/to/cucm-phone-stats && php bin/cucm-switch-import.php switch-outputs/ --quiet >> /var/log/cucm-phone-stats.log 2>&1

# Business hours: re-check phones whose errors are climbing.
0  8-18 *   *   1-5  cd /path/to/cucm-phone-stats && flock -n /tmp/cucm-sweep.lock php bin/cucm-sweep.php --only-problems --problems growing --quiet >> /var/log/cucm-phone-stats.log 2>&1

# Profile changes (WRITES TO CUCM): move switch-mismatched phones, then send
# Apply Config out of hours. Or use "--apply --apply-config" in one step.
0  2    *   *   *    cd /path/to/cucm-phone-stats && php bin/cucm-set-profile.php --from OLD-UUID --to NEW-UUID --apply >> /var/log/cucm-phone-stats.log 2>&1
30 2    *   *   *    cd /path/to/cucm-phone-stats && php bin/cucm-set-profile.php --apply-config latest --apply >> /var/log/cucm-phone-stats.log 2>&1
```

Run the `cucm-set-profile.php` dry run by hand before scheduling `--apply`.
Apply Config restarts the phones, so keep it out of hours. To confirm the
fix once they have re-registered (allow a few minutes), re-sweep the
switch-mismatched phones:

```bash
php bin/cucm-sweep.php --only-problems --problems switch
```

## Configuration

All settings are in [cucm.conf.example](cucm.conf.example), one line each.
The file is looked up in `./cucm.conf`, `<repo>/cucm.conf`,
`~/.cucm-phone-stats.conf`, or given with `--config FILE`. Any key can be
set from the environment as `CPS_<KEY>` (e.g. `CPS_AXL_PASS`); command-line
flags take priority over both.

## Schema

Tables: `sweeps`, `phones`, `registrations`, `port_stats`, `page_fetches`
(raw label/value pairs and failures), `uplinks`, `phone_config`,
`device_models`, `switch_captures`, `switch_ports`, `profile_changes`.
Reporting views (`v_port_speed_mismatch`, `v_port_errors`,
`v_port_error_deltas`, `v_port_errors_increasing`, `v_switch_port_compare`,
`v_switch_port_mismatch`, `v_latest_sweep`) are plain SQL. See
[schema.sql](schema.sql).

## Testing

```bash
php tests/run-tests.php --unit     # no server, no database
php tests/run-tests.php            # adds a mock CUCM and phones, and MySQL
```

The tests use a mock cluster, so they do not confirm the field names a real
cluster or phone firmware uses; run `bin/cucm-probe.php` for that. See
[docs/airgap-runbook.md](docs/airgap-runbook.md) and
[docs/design.md](docs/design.md).
