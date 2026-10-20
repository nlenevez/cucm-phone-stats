# Design notes

## Sources

- **RisPort70 `selectCmDevice`** gives registration state: status, current
  IP, firmware, directory number, `HttpSupported`. AXL holds configuration
  only, not whether a phone is registered or its current address.
- **AXL** gives model names (`typemodel`), the Common Phone Profile, and the
  `RegistrationDynamic` fallback (`--source axl-sql`). Fallback rows have
  status `LastKnown`, not `Registered`.

## Enumeration

RisPort returns at most 1000 devices per answer without flagging truncation.
One query is sent; if it returns at or near the cap, or fewer than
`TotalDevicesFound`, each node is queried separately and results are merged
by device name. A node still at the cap is reported as incomplete. Multiple
`cucm_servers` are merged, preferring rows with an IP and `Registered`
status. Device-type and subnet filters are applied to the results.

## SOAP

Requests are built by hand and sent with curl; no WSDL is needed. Responses
are parsed namespace-blind. The AXL schema version is negotiated from 15.0
down to 10.0; HTTP 599 means "try the next version".

## Phone parsing

Labels are normalised (lowercase alphanumerics) and mapped to canonical
columns via the alias table in `lib/phone.php`. All label/value pairs are
stored verbatim in `page_fetches.pairs`; `port_stats.unmapped_labels` counts
the leftovers.

## Report rules

- A speed mismatch needs both ports up.
- Duplex is reported alongside speed (`mismatch_kind`).
- Errors exclude traffic counters (broadcast, multicast, unicast).
- A counter that drops is a reset: `counter_reset = 1`, delta NULL.
- Sweep order breaks ties on `id`.

## Failure handling

- One phone cannot stop a sweep: empty bodies (`error_kind = empty`), parse
  errors and any per-phone exception are recorded and the sweep continues.
- Failure kinds (timeout, refused, auth, empty, parse, ...) are stored
  separately in `page_fetches.error_kind`.
- One transaction per phone. A sweep that dies keeps `finished_at` NULL
  (excluded from reports) and records the reason.
- A sweep that polls phones but cannot store anything exits non-zero.
- Over-long values are clipped to the column width with a warning.

## Unverified on real hardware

1. The label alias table. Check with `cucm-probe.php --phone`.
2. `RegistrationDynamic` column names (fallback only).
3. Which adapter pages a firmware serves. `ethernet` and `config` are
   best-effort.
