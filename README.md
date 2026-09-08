# cucm-phone-stats

Discover registered Cisco IP phones from CUCM, pull the network and access
port statistics from each handset, and store it all in MySQL so the estate
can be reported on over time.

**PHP 8.2+**, no Composer, no external packages — it is meant to be copied
onto an airgapped box and run. Verified on **8.2, 8.3, 8.4 and 8.5**, and on
**RHEL 8.10** with its AppStream PHP 8.2. The floor is 8.2 because that is
the oldest version actually tested, not the oldest that might work.

Extensions: `curl` and `dom`, plus `pdo_mysql` **only for the database
half**. `cucm-probe.php`, `cucm-phones.php`, `cucm-phone-netstats.php` and
`cucm-sweep.php --no-db` all run without `pdo_mysql`, which is deliberate —
the probe can go across the airgap before anyone has provisioned a database.

```bash
php -r 'foreach (["curl","dom","pdo_mysql"] as $e) printf("%-10s %s\n", $e, extension_loaded($e) ? "yes" : "NO");'
```

### On RHEL 8.10

RHEL splits the PHP extensions across separate RPMs, and **`php-cli` alone
does not include DOM** — every script here parses through DOM, so that is a
hard blocker rather than a degraded mode. The AppStream default stream is
still **php 7.2**, which is far too old, and RHEL 8 has **no 8.3 stream** —
8.2 is the newest without a third-party repo such as Remi.

```bash
dnf module reset php
dnf module enable php:8.2
dnf install php-cli php-xml php-mysqlnd     # php-pdo, php-common come as deps
```

| Package | Provides | Needed for |
|---|---|---|
| `php-cli` | the interpreter, `curl`, `json` | everything |
| `php-xml` | **`dom`** | everything — not optional |
| `php-mysqlnd` | `pdo_mysql` (pulls `php-pdo`) | storing and reporting only |

`mbstring` is *not* required. On the airgapped host these come from your
Satellite or local AppStream mirror; it is one module stream plus three
packages.

**Verified** on RHEL 8.10 (Ootpa) with PHP 8.2.33 from AppStream, against
**MySQL 8.0.46** and **MySQL 8.4.11**: 84 checks, exit 0 on each. Both are
covered so it does not matter which AppStream stream the host carries.
(MariaDB 10.3 also passes, but MySQL is the deployment target.)

### Database setup

The tool needs two levels of access, and they are worth separating: building
the schema needs DDL, running a sweep does not. These grants are tested — a
sweep and all three reports run with nothing beyond SELECT/INSERT/UPDATE.

```sql
CREATE DATABASE cucm_phone_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- setup: run bin/cucm-initdb.php as this account, once per deploy/upgrade
CREATE USER 'cucm_admin'@'%' IDENTIFIED BY '...';
GRANT CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW,
      SELECT, INSERT, UPDATE ON cucm_phone_stats.* TO 'cucm_admin'@'%';

-- runtime: what cucm.conf uses for sweeps and reports
CREATE USER 'cucm'@'%' IDENTIFIED BY '...';
GRANT SELECT, INSERT, UPDATE ON cucm_phone_stats.* TO 'cucm'@'%';
```

No `DELETE` is granted, and none is needed — nothing in this tool deletes a
row. History is what the trend report reads, so pruning it is a deliberate
retention decision for later, not something a sweep should be able to do by
accident. Confirmed by negative control: the runtime user is refused `DROP`,
`CREATE` and `DELETE`, and still reads every reporting view.

Restrict the host part (`'cucm'@'10.0.0.5'`) rather than leaving `'%'`.

## What it does

```
CUCM subscribers                    each phone                   MySQL
┌────────────────────┐              ┌──────────────┐             ┌──────────────┐
│ RisPort70          │  name, IP,   │ /CGI/Java/   │  port       │ sweeps       │
│ selectCmDevice     ├─ status,  ──►│ Serviceability│─ counters ─►│ phones       │
│                    │  model       │  ?adapter=…  │  speed      │ registrations│
│ AXL (fallback)     │              │              │  duplex     │ port_stats   │
│ RegistrationDynamic│              │              │             │ page_fetches │
└────────────────────┘              └──────────────┘             └──────────────┘
```

**AXL cannot answer this on its own.** The `device` table is configuration —
it says a phone is *configured*, not that it is *registered*, and it does not
hold the address the phone is on right now. Registration is runtime state and
belongs to **RisPort70** on the subscribers, which is why that is the primary
source here. AXL still contributes the configuration context RisPort omits,
and `executeSQLQuery` against `RegistrationDynamic` is a usable fallback when
RisPort is throttled or deactivated.

## Quick start

```bash
cp cucm.conf.example cucm.conf && $EDITOR cucm.conf

php bin/cucm-probe.php --phone 10.0.0.50   # confirm the assumptions first
php bin/cucm-initdb.php --create-database  # tables, views, and column migrations
php bin/cucm-sweep.php                     # discover, poll, store

php bin/cucm-report.php speed-mismatch
php bin/cucm-report.php errors
php bin/cucm-report.php error-trend
```

## The tools

| Script | What it is for |
|---|---|
| `bin/cucm-probe.php` | Verifies each assumption against a real cluster, one request at a time, and writes JSON evidence. **Run this first on a new cluster.** |

> **All-HTTPS estate?** Set `phone_probe_schemes = https` (or pass
> `--schemes https`). Only the *probe* ever tries plaintext, and only to
> discover what a handset answers on; sweeps build every URL from
> `phone_url_base` and never attempt HTTP. The http attempts time out rather
> than failing fast, so turning them off saves two timeouts per probe run.
>
> Collection is additionally pinned: with an `https://` base, curl is
> configured to refuse a redirect to `http://`. Web Access = Authenticated
> sends credentials, and a phone bouncing to a plaintext page would otherwise
> have them re-sent in the clear.
| `bin/cucm-phones.php` | Lists registered phones and their IPs. `--json`, `--csv`, `--table`. |
| `bin/cucm-sweep.php` | The whole job: discover → poll every phone → store. `--sample N` for a small batch. |
| `bin/cucm-phone-netstats.php` | One phone by IP. Successor to `patching-tools/cisco-phone-netstats.php`. |
| `bin/cucm-report.php` | The reports, over one sweep or all of them. |
| `bin/cucm-initdb.php` | Creates/updates tables and views. Safe to re-run. |

### Testing against a subset

On an estate of thousands, do not sweep the lot to find out whether it works:

```bash
php bin/cucm-sweep.php --sample 20 --no-db          # 20 random phones, nothing stored
php bin/cucm-sweep.php --match Warehouse --sample 5  # narrow first, then sample
php bin/cucm-sweep.php --sample 50                  # 50 random, stored as a real sweep
```

### Large estates: the RisPort response cap

RisPort caps how many devices one `selectCmDevice` conversation yields, and
**it does not report that it truncated** — `StateInfo` simply stops arriving,
so a short answer is well formed, error free and indistinguishable from a
complete one. On a multi-thousand-phone cluster that means a confidently
wrong estate size.

**`TotalDevicesFound` alone is not enough.** On a capped response some
releases report the *capped* number, so a cluster with 2,300 phones answers
with 1,000 devices and says 1,000 exist — internally consistent, and a
comparison concludes the answer is complete.

`ris_partitions = auto` (the default) therefore uses two signals: fewer
devices than `TotalDevicesFound` claims, **or** a result sitting at or near
the cap. An estate having exactly 1,000 registered phones is vanishingly
unlikely; splitting when it does costs one extra pass, whereas not splitting
when it has been truncated loses devices silently. When either fires, the
enumeration re-runs split by device-name prefix — 16 buckets on the first hex digit of the MAC
for `SEP*`, plus the soft-client prefixes — merging by device name. The
truncation is logged rather than swallowed:

```
WARN: https://cucm1: RisPort returned 120 of 300 devices it says exist -
      re-running split by name prefix
```

Discovery also reports **per server**: how many devices each one returned,
how many were new, and the per-node breakdown from `CmNodes`. With more than
one cluster in play, a single total cannot show which one came up short.

**Then splitting on the LAST character of the name**, because device names
are MACs and prefix buckets are hopeless when a fleet shares an OUI: on a
real estate `SEP4*`, `SEP44*`, `SEP44B*`, `SEP44B6*`, `SEP44B6B*` and
`SEP44B6BE*` were all over the cap in turn — six levels, sixteen queries
each, to separate nothing. The trailing digits are the device serial and are
close to uniform, so they give sixteen even buckets in one level.

Whether a cluster accepts a leading wildcard is probed with a single query
and the answer is *verified* — the rows must arrive, be under the cap, and
actually end in the requested digit — because a cluster that ignored the
pattern would return the whole capped set and look like success. If it is
not supported, prefix splitting is the fallback.

A bucket that itself comes back at the cap is subdivided a character deeper
(`SEP0*` → `SEP00*`…`SEP0Z*`), up to three levels. Results merge by device
name throughout, so subdividing can only add devices, never lose them.

`ris_partitions = always` forces the split regardless — reach for it if
counts still look low. `off` reproduces the untruncated behaviour, and an
explicit pattern list is also accepted.

### Speed

Phones are fetched through a **rolling window** of `phone_parallel`
concurrent requests (default 10, `--parallel N` to override). A finished
request is replaced immediately, so a handset that times out costs only its
own latency and never holds up phones that already answered.

Measured on 100 phones, one in eight slow (1.5s), one adapter each:

| `--parallel` | Elapsed |
|---|---|
| batched (previous behaviour) | 12.2s |
| 5 | 4.6s |
| **10 (default)** | **3.1s** |
| 20 | 1.6s |
| 30 | 1.6s |

It plateaus once the window is wide enough that the slow handsets overlap;
past that you are bounded by their own latency. **20–25 is a reasonable
setting for a large estate.** The other lever is `adapters` — each one is a
separate request per phone, so trimming `adapters` to `network,access`
halves the work against the four-adapter default.

### Which devices get polled

By default only **`SEP*`** devices — hard handsets. Everything else CUCM
registers has no switch port and no PC port, so there are no port statistics
to collect from it:

| Prefix | What it is | Has ports? |
|---|---|---|
| `SEP` | desk handset | **yes** |
| `CSF` / `TCT` / `BOT` / `TAB` | Jabber desktop / iPhone / Android / tablet | no |
| `CIPC` | IP Communicator softphone | no |
| `ATA` | analog adapter | no |

This matters beyond tidiness: **a Jabber client registers from the user's
workstation**, so an unfiltered sweep of a few thousand devices fires an
HTTPS request at every desk in the estate. Nothing about the *address* says
not to poll it — only the device name does.

```ini
poll_device_prefixes = SEP          # cucm.conf; blank polls everything
```

This scopes the **RisPort query itself**, not just the results: `SelectItems`
asks the cluster for `SEP*` only, so CSF/TCT/BOT/TAB devices are never
gathered, never returned and never partitioned. Cheaper for CUCM, less data
back, and on an estate with a large Jabber population it can keep a node
under the response cap it would otherwise exceed purely because of soft
clients.

If a scoped query returns nothing at all — which would mean the cluster does
not accept a wildcard in `SelectItems` — it retries once unscoped and says
so, rather than reporting an empty estate.
```bash
php bin/cucm-sweep.php --all-devices             # poll everything, once
php bin/cucm-sweep.php --device-prefixes SEP,ATA # explicit list
```

The skip is always reported with a per-prefix breakdown, so the count is
explained rather than mysteriously short:

```
4 device(s) skipped as not SEP (CSF=1, TCT=1, BOT=1, ATA=1) - no ports to poll
```

That line is also the answer to "CUCM says 3,000 devices and I only see
2,000": the difference is soft clients.

### Leaving subnets alone

```ini
exclude_subnets = 10.20.0.0/16, 10.30.4.0/24     # cucm.conf
```
```bash
php bin/cucm-sweep.php  --exclude-subnet 10.20.0.0/16,10.30.4.0/24
php bin/cucm-phones.php --exclude-subnet 10.20.0.0/16
```

Applies to discovery and sweeps alike, and both tools share one
implementation — if the listing and the sweep disagreed about scope, a CSV
carried between them would quietly put the excluded phones back.

Two deliberate behaviours: an **invalid prefix is fatal**, never ignored
(a silently dropped exclusion means the tool polls a subnet you believe is
off limits and nothing says so), and a prefix that **matched nothing is
called out by name**, since a zero is how you find out a CIDR is wrong or
the estate has moved.

`--sample N` takes a **random** spread; `--limit N` takes the **first** N.
They are not the same test. RisPort returns devices grouped by node, so the
first N are typically all from one subscriber, one site, often one switch —
the population least likely to show up a problem only part of the estate
has. `--match` filters on name, description, model or IP and is applied
before sampling.

**`--match` is a substring search, not a device-type filter.** `--match SEP`
also matches `CSFJOSEPH`, because JO-**SEP**-H contains it — a Jabber client
belonging to anyone called Joseph. Use `--device-prefixes` for device types
(it anchors to the start of the name), or scope the search to one field with
`--match name:SEP0`.

## Reports

**`speed-mismatch`** — phones whose access (PC) port is running at a
different speed or duplex from the network (switch) port.

```
device_name      ip_address  network_speed_mbps  network_duplex  access_speed_mbps  access_duplex  mismatch_kind
SEP001122334402  10.10.10.2  1000                full            100                full           speed
SEP001122334403  10.10.10.3  100                 full            100                half           duplex
```

Only phones with **both ports up** are compared. An access port with nothing
plugged into it is not a mismatch, and counting it as one would bury the real
findings under every unused PC port in the building. Duplex is reported
alongside speed because a duplex mismatch is the more damaging of the two and
the query is the same join.

**`errors`** — any port with a non-zero error counter, worst first.
`--min N` raises the floor. Counts CRC, alignment, runt, giant, jabber and
overrun on receive; collisions, late and excessive collisions, excessive
deferral and excess length on transmit. Traffic counters (broadcast,
multicast, unicast) are deliberately *not* errors.

**`error-trend`** — ports whose error counters actually grew between two
sweeps, with the growth, not just the total.

Phone counters are free-running and clear when the handset reboots, so a
naive delta goes sharply negative on every reboot. A drop is reported as
`counter_reset = 1` with a NULL delta instead, so a rebooting phone can never
appear to be "improving". `--include-resets` shows the reset rows too, which
is what you want when a phone keeps clearing its counters and you are working
out why. `--since YYYY-MM-DD` bounds the window.

## Schema

**After upgrading, run `php bin/cucm-initdb.php` before the next sweep.** It
creates anything new and applies column migrations to an existing database.
It is idempotent, so running it when nothing has changed costs nothing.

`sweeps` one run · `phones` inventory keyed on device name · `registrations`
where each phone was in each sweep · `port_stats` normalised link state and
counters per port · `page_fetches` the verbatim label/value pairs and every
failure.

`page_fetches.pairs` keeps **every** label the phone returned, mapped or not.
Label text drifts between firmware releases, and a counter the normaliser has
not seen is then still recoverable from the database afterwards rather than
needing a second sweep of the estate to collect again. `port_stats.unmapped_labels`
counts how many a page had — if that number is large on your firmware, the
alias table in `lib/phone.php` wants extending.

Reporting views: `v_port_speed_mismatch`, `v_port_errors`,
`v_port_error_deltas`, `v_port_errors_increasing`, `v_latest_sweep`. They are
plain SQL, so Grafana or Excel can read them without going through this tool.

## Prerequisites on the cluster

An **application user** with the **Standard AXL API Access** role, and
**Web Access enabled** on the phones (Common Phone Profile, or per device).
Full detail, including the CUCM 12.5+ default that turns phone web access
off, is in [docs/cucm-permissions.md](docs/cucm-permissions.md).

## Testing

```bash
php tests/run-tests.php --unit     # no server, no database
php tests/run-tests.php            # + mock CUCM over HTTP + real MySQL
```

`tests/mock-server.php` stands in for a CUCM cluster and eight phones chosen
to produce specific outcomes — a speed mismatch, a duplex mismatch, growing
errors, a phone that demands authentication, a phone with its web server off,
an unregistered phone that must not appear, and an access port that is down.

**What the tests prove, and what they do not.** They exercise the plumbing:
SOAP construction, namespace-blind parsing, RisPort StateInfo paging, the
tolerant HTML parser, normalisation, the database writes and the views. They
**cannot** prove Cisco's real field names match the ones this tool looks for,
because the fixtures were written from the same reading of the documentation
as the code. A fixture cannot disagree with the assumption that produced it.
That is what `bin/cucm-probe.php` is for.

See [docs/airgap-runbook.md](docs/airgap-runbook.md) for the
build-here / verify-there workflow.
