# What the cluster and the phones need

## 1. The CUCM account

Create an **Application User** (User Management → Application User), not an
End User, and give it the **Standard AXL API Access** role. That role is what
gates both `/axl/` and `/realtimeservice2/`.

If RisPort calls fail with 403 while AXL works, add the group
**Standard CCM Admin Users** as well — some releases gate the realtime
service behind it separately.

Read the HTTP status before theorising about anything else:

| Response | Means | Does not mean |
|---|---|---|
| connect timeout | silent drop in the path (firewall/ACL) | host down |
| connection refused | nothing listening, or a host firewall rejecting | filtered |
| 401 | wrong username or password | missing role |
| **403** | credentials accepted, **account** refused | wrong password |
| 404 | wrong path, or the service is not activated on this node | wrong host |
| 500 + SOAP Fault | request reached the API and was understood enough to be rejected — read `axlmessage` | transport problem |
| **599** | **AXL refused the request** — most often a schema version this cluster does not accept ("Wrong AXL Version" in the CUCM log). The body is a bare `<axlError>`, not a SOAP Fault | a transport failure |

A **403 with credentials that work in the web UI** is the signature of an
account whose access comes through a group rather than directly. Use a
dedicated application user for automation and the question does not arise.

Everything except `bin/cucm-set-profile.php` only reads. That script calls
`updatePhone` (and `applyPhone` with `--apply-config`), so it needs AXL
write access - **Standard AXL API Access**, not *Standard AXL Read Only API
Access*. A read-only account fails it with an authorisation fault and
changes nothing.

## 2. Services that must be running

- **Cisco AXL Web Service** (Serviceability → Tools → Service Activation) —
  off by default on a fresh cluster. Without it `/axl/` returns 404, which
  reads like a wrong path.
- **Cisco RIS Data Collector** — normally running; it is what answers
  `selectCmDevice`.

## 3. Web access on the phones

The phone's own web server is what serves the statistics pages, and it is
**disabled by default from CUCM 12.5 onward**. Enable it on the Common Phone
Profile (applies to every phone using that profile) or per device:

- **Web Access = Enabled** — the pages are served without credentials.
- **Web Access = Authenticated** — the pages require an end-user login; set
  `phone_user` / `phone_pass` in `cucm.conf`. The sweep reports this
  distinctly as `error_kind = auth`, so it is easy to count how much of the
  estate is affected.
- **Web Access = Disabled** — nothing to collect. RisPort reports
  `HttpSupported = No` for these, and `--skip-no-http` will skip them
  instead of spending a timeout per handset.

A phone whose web access has just been enabled may need to re-register before
it starts serving.

## 4. Rate limits

RisPort is throttled per user, on the order of fifteen requests a minute.
Exceeding it earns a fault, not a queue. `ris_gap_ms` (default 4000) is the
pause between paged calls; lower it only if you know the cluster's limit.

The phones have no such limit, but they are small devices — `phone_parallel`
defaults to 10 simultaneous fetches, which is polite and still sweeps a few
thousand handsets in minutes.
