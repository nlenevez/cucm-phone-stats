# Running this against the airgapped cluster

The target cluster cannot be reached from where this code was written, so
every field name in it came from Cisco's documentation rather than from the
instance. Documentation is not the instance. The workflow that fits is
**build here, verify there, correct here** — and the thing to avoid is one
round trip per wrong guess.

## Step 1 — carry the repo across

The whole directory is the unit. No Composer, no wheels, no downloads:

```bash
tar czf cucm-phone-stats.tgz cucm-phone-stats/
```

Needs **PHP 8.2+** with `curl` and `dom`. `pdo_mysql` is needed only to
store results — steps 2 and 3 below run without it, so the probe can go
across before a database exists. Check with:

```bash
php -v
php -r 'foreach (["curl","dom","pdo_mysql"] as $e) printf("%-10s %s\n", $e, extension_loaded($e) ? "yes" : "NO");'
```

**On RHEL 8.10**, `dom` is the one to watch: it ships in `php-xml`, not in
`php-cli`, so a box with PHP installed can still be missing it — and every
script here parses through DOM. The AppStream default stream is php **7.2**,
and RHEL 8 has no 8.3 stream, so 8.2 is the target:

```bash
dnf module reset php && dnf module enable php:8.2
dnf install php-cli php-xml php-mysqlnd
```

From your Satellite or local AppStream mirror — one module stream, three
packages, no third-party repo.

For the database half, create the schema and the two accounts before step 4
- the grants are in "Database setup" in the README. The runtime account
needs only SELECT/INSERT/UPDATE; the DDL account is used once, by
`cucm-initdb.php`.

Verified on PHP 8.2, 8.3, 8.4 and 8.5, and on RHEL 8.10 (PHP 8.2.33 from
AppStream) against MySQL 8.0.46 and 8.4.11.

## Step 2 — probe before anything else

```bash
cp cucm.conf.example cucm.conf && $EDITOR cucm.conf
php bin/cucm-probe.php --phone <one phone IP> --out probe-evidence
```

Every assumption gets **its own request**, and each states what it expects
before it runs. A single query selecting six columns fails as a unit and
never tells you which column name was wrong; six queries name the column.

It checks, separately: TCP/TLS reach to each server; AXL authentication and
which schema version the cluster accepts; that `executeSQLQuery` is permitted;
that Informix `SELECT FIRST n` works (there is no `LIMIT` on CUCM's database);
each `RegistrationDynamic` column this tool uses; the `typemodel` join;
`listPhone` paging; `selectCmDevice` on every configured server, including
whether it returns IP addresses and `HttpSupported`; and one real phone over
both HTTPS and HTTP, reporting how many of its labels the normaliser
recognised.

Output is a PASS/FAIL/SKIP table plus `probe-evidence/probe-<timestamp>.json`.

## Step 3 — carry the evidence back

The JSON is the deliverable. It carries the sample rows, the exact fault text
for anything that failed, and — most valuable — the **complete label/value
set from a real phone**, which is what finishes the alias table in
`lib/phone.php`.

Two numbers in the phone probes matter most:

- `mapped_counters` — how many labels the normaliser recognised.
- `unmapped_labels` — how many it did not. If this is large, that firmware
  spells its labels differently and the map needs those spellings adding.

Nothing is lost in the meantime: unmapped labels are still stored verbatim in
`page_fetches.pairs`, so extending the map later and re-running the reports
does not need another sweep of the estate.

## Step 4 — sweep

```bash
php bin/cucm-initdb.php --create-database
php bin/cucm-sweep.php --limit 5 --no-db --json    # look before storing
php bin/cucm-sweep.php                             # the real thing
```

Then schedule it — hourly or daily. `error-trend` needs at least two sweeps
before it says anything, and the gap between them sets the resolution of
"increasing over time".

## Splitting discovery from polling

If the host that can reach CUCM is not the host that can reach the phones:

```bash
# on the host that can reach CUCM
php bin/cucm-phones.php --csv > phones.csv

# on the host that can reach the phones
php bin/cucm-sweep.php --phones-from phones.csv
```

`--phones-from` takes either the CSV or the JSON that `cucm-phones.php`
emits, sniffed by content, so whichever got carried across works.
