# Design notes

## Why RisPort70 and not AXL alone

AXL is the configuration API. `device` says a phone is *configured*; it does
not say the phone is *registered*, and it does not carry the address the
phone is on. Registration is runtime state held by the **Cisco RIS Data
Collector** on each node, and `selectCmDevice` is the supported way to ask
for it. It returns, per device: status, current IP, active firmware load,
directory number, and `HttpSupported` — which tells you in advance whether
the handset's web server is worth contacting.

`executeSQLQuery` against `RegistrationDynamic` is kept as a fallback for
when RisPort is throttled, deactivated or unreachable. It is weaker on
purpose and labelled so: those rows report status `LastKnown`, never
`Registered`, because "where the phone last was" is not "where it is".

## Raw SOAP over curl, not PHP's SoapClient

`SoapClient` wants a WSDL. CUCM's `AXLAPI.wsdl` is not published — it comes
off the cluster's plugin page and has to match the cluster version. On an
airgapped host that is an extra artefact to carry and keep in step with every
upgrade, for no benefit: the requests here are a handful of fixed shapes.

Responses are parsed **namespace-blind** (`local-name()`), so a schema
version bump, or a release that changes its prefixes, does not break parsing.
The AXL schema version itself is negotiated by walking `15.0 → 10.0` until
one is not rejected, because the cluster version is not knowable in advance
from this side of the airgap.

## Normalise, but never discard

Phone label text is not stable across firmware — `Rx totalPkt`, `RxTotalPkt`
and `Rx Total Pkt` are one counter. Keys are squashed to lowercase
alphanumerics before lookup, which absorbs most of the drift by itself, and
only then mapped to a canonical column.

Whatever the map recognises, **the full label/value set is stored verbatim**
in `page_fetches.pairs`. A counter this map has never seen is then still in
the database, and extending the map later plus re-running the reports costs
nothing. Losing it at collection time would cost a second sweep of the whole
estate. `port_stats.unmapped_labels` says how much each page had left over,
so the gap is measurable rather than invisible.

The alias table in `lib/phone.php` is **unverified against real firmware** —
it was assembled from documentation. `bin/cucm-probe.php` on one real handset
is enough to finish it.

## Decisions in the reports

- **Both ports must be up to be a mismatch.** An access port with nothing
  plugged into it legitimately reads differently from the network port.
  Counting those would bury the real findings under every unused PC port in
  the estate.
- **Duplex is reported beside speed.** The ask was speed, but a duplex
  mismatch is the more damaging fault and it falls out of the same join.
  `mismatch_kind` distinguishes them, so filtering to just speed is trivial.
- **Errors are faults, not traffic.** Broadcast/multicast/unicast counters
  are excluded from `errors_total`. A busy phone is not a broken one.
- **A counter drop is a reset, not an improvement.** Phone counters clear on
  reboot. A blind delta goes negative on every reboot and undercounts the
  sweep the reboot happened in. Drops are reported as `counter_reset = 1`
  with a NULL delta, so a rebooting phone can never read as "improving".
- **Sweep ordering breaks ties on `id`.** `started_at` is second-resolution;
  two sweeps in the same second tie, and both "the latest sweep" and the
  `LAG()` window would then pick arbitrarily among them.

## One phone must never stop the sweep

A run covers thousands of handsets and takes minutes. Anything that lets a
single device abort it turns a small fault into a total loss, and the
failure arrives at whatever point in the estate the bad phone happens to
sit, so the data you keep is an arbitrary prefix of the run.

This is not hypothetical: a phone answering HTTP 200 with a zero-byte body
reached `DOMDocument::loadHTML('')`, which throws a `ValueError` under PHP 8.
curl reported no error, the status was 200, and every guard passed it
through. The sweep died several minutes in.

Three layers now, because each catches a different class of surprise:

1. The parser refuses an empty document rather than throwing, and the
   fetcher classifies that as `empty` — a phone-side fault, distinct from
   `parse`, which means markup arrived and this code did not understand it.
2. The parse call is wrapped, so an unanticipated libxml or DOM error
   becomes one recorded page failure.
3. Each phone's processing in `cucm-sweep.php` is wrapped, so anything at
   all going wrong for one device costs that device and nothing else.

A sweep that dies regardless records the reason on its `sweeps` row.
`finished_at` stays NULL so the reporting views keep excluding a partial
run, but the row says why rather than sitting open with no explanation.

## Failure modes are kept apart

A phone that times out, one that refuses the connection, one that returns
401, and one that returns a 200 the parser did not understand are four
different problems with four different remedies. They are stored as distinct
`error_kind` values rather than collapsed into "failed", so
`SELECT error_kind, COUNT(*) FROM page_fetches WHERE ok = 0 GROUP BY 1`
tells you whether you have a network problem or a configuration one.

Similarly, a sweep that polls every phone successfully but cannot store any
of it exits non-zero. Polling is not the point; the stored data is.

## Transactions

One transaction per phone, not one per sweep. A sweep of a few thousand
handsets runs long enough that a single transaction turns any mid-run failure
into total loss. Per-phone commits leave an interrupted sweep's completed
work intact, and `finished_at` stays NULL so the reporting views exclude the
partial run.

## Known-uncertain, in order of risk

1. **The phone label alias table** — drives every normalised column.
   Resolved by `--phone` in the probe.
2. **`RegistrationDynamic` column names** — only affects the AXL fallback
   path. Probed individually.
3. **Which adapter pages a given firmware serves.** `ethernet` and `config`
   are collected best-effort; a phone that does not serve one is not counted
   as failed, provided at least one port page parsed.
