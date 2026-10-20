# Running on an isolated network

Build and test elsewhere, verify on the target with the probe, then correct.

## 1. Copy the repo

```bash
tar czf cucm-phone-stats.tgz cucm-phone-stats/
```

Needs PHP 8.2+ with `curl` and `dom`; `pdo_mysql` only for storing results.
Steps 2 and 3 run without a database.

```bash
php -r 'foreach (["curl","dom","pdo_mysql"] as $e) printf("%-10s %s\n", $e, extension_loaded($e) ? "yes" : "NO");'
```

On RHEL 8: `dnf module enable php:8.2 && dnf install php-cli php-xml php-mysqlnd php-mbstring`.

## 2. Probe

```bash
cp cucm.conf.example cucm.conf && $EDITOR cucm.conf
php bin/cucm-probe.php --phone <phone IP> --out probe-evidence
```

Each check is a separate request: TCP/TLS to each server, AXL login and
schema version, `executeSQLQuery`, `RegistrationDynamic` columns, the
`typemodel` join, `listPhone` paging, `selectCmDevice` on each server, and
one phone over HTTPS and HTTP. Output is a PASS/FAIL/SKIP table and
`probe-evidence/probe-<timestamp>.json`.

## 3. Bring the evidence back

The JSON holds sample rows, fault text, and the full label/value set from a
real phone. In the phone section:

- `mapped_counters`: labels the normaliser recognised.
- `unmapped_labels`: labels it did not. If high, add those spellings to the
  alias table in `lib/phone.php`.

Unmapped labels are also stored in `page_fetches.pairs`, so reports can be
re-run after extending the map without a new sweep.

## 4. Sweep

```bash
php bin/cucm-initdb.php --create-database
php bin/cucm-sweep.php --sample 5 --no-db --json   # look first
php bin/cucm-sweep.php
```

Then schedule it (see "Running from cron" in the README). `error-trend` needs
two sweeps.

## Discovery and polling on different hosts

```bash
php bin/cucm-phones.php --csv > phones.csv            # host that reaches CUCM
php bin/cucm-sweep.php --phones-from phones.csv       # host that reaches the phones
```

`--phones-from` accepts the CSV or JSON from `cucm-phones.php`.
