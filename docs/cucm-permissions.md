# CUCM and phone prerequisites

## CUCM account

Create an **Application User** with the **Standard AXL API Access** role. It
covers `/axl/` and `/realtimeservice2/`. If RisPort returns 403 while AXL
works, also add **Standard CCM Admin Users**.

`bin/cucm-set-profile.php` calls `updatePhone` and `applyPhone`, so it needs
Standard AXL API Access, not *Standard AXL Read Only API Access*. Everything
else only reads.

| Response | Meaning |
|---|---|
| connect timeout | dropped by a firewall or ACL |
| connection refused | nothing listening |
| 401 | wrong username or password |
| 403 | credentials accepted, account lacks the role |
| 404 | wrong path, or the service is not activated on that node |
| 500 + SOAP Fault | request rejected; read `axlmessage` |
| 599 | AXL rejected the request, usually an unsupported schema version |

## Services

- **Cisco AXL Web Service** (Serviceability > Tools > Service Activation).
  Off by default; without it `/axl/` returns 404.
- **Cisco RIS Data Collector**: answers `selectCmDevice`.

## Phone web access

Phone web servers are disabled by default from CUCM 12.5. Set it on the
Common Phone Profile or per device:

- **Enabled**: no credentials needed.
- **Authenticated**: set `phone_user` / `phone_pass`. Failures are recorded
  as `error_kind = auth`.
- **Disabled**: nothing to collect. RisPort reports `HttpSupported = No`;
  `--skip-no-http` skips these.

A phone may need to re-register after web access is enabled.

## Rate limits

RisPort is rate limited per user (commonly 15 requests a minute). Set
`ris_rate_per_min` below the cluster's limit; rate-limit faults are retried
with backoff. `phone_parallel` (default 10) sets how many phones are polled
at once.
