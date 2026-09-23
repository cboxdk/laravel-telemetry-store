# Changelog

All notable changes to `cboxdk/laravel-telemetry-store` are documented here.

## v1.4.1

### Changed
- Supports `cboxdk/laravel-telemetry-ui` 2.x alongside 1.x
  (`^1.0 || ^2.0`). The read drivers are unchanged: 2.0 kept the contracts,
  query IR and result DTOs they implement.
- The end-to-end suite fetches the real dashboard panels from telemetry-ui's
  v2 JSON API instead of rendering Livewire cards, and runs in its own
  `telemetry_e2e` database — it truncates its tables, so it no longer touches
  a `telemetry` database a dev box may be using for real data.

### Fixed
- A range query over a histogram's `_count` or `_sum` (the requests-per-minute
  chart, `http_server_request_duration_seconds_count`) read a `Value` column
  the histogram table doesn't have, so the chart failed with a driver error.
  It now reads `Count` / `Sum`, as the instant query already did. The old E2E
  test missed it by asserting on the wrong error text.

## v1.4.0

### Added
- **Query-performance rollup for scale.** A `otel_db_query_summary`
  `AggregatingMergeTree` + materialized view keep a per-minute rollup of DB
  query spans keyed by statement fingerprint (count / sum / max /
  `quantileTDigest` merge states). `ClickHouseTracesSource::aggregateSpans`
  serves the query-performance card's default DB-statement ranking from this
  rollup instead of scanning `otel_traces`, so wide time windows stay
  sub-second where the raw map-extraction GROUP BY was scan-bound (~50M rows/s).
  Any aggregation with an extra filter (min-duration, service) or a different
  grouping falls back to the exact raw scan automatically.
  Validated on a 36-billion-row corpus: a 24h ranking dropped from ~130s (raw)
  to a few ms (rollup). Existing deployments backfill once with
  `INSERT INTO otel_db_query_summary SELECT toStartOfMinute(Timestamp), …
  quantileTDigestState(Duration) FROM otel_traces WHERE SpanName='db.query' …
  GROUP BY 1,2,3`.

## v1.3.0

### Added
- **Exact span aggregation.** `ClickHouseTracesSource` implements the UI's
  `AggregatesSpans` contract: a single `GROUP BY` over `otel_traces` returns
  count/avg/p95/max/sum of `Duration` (ns → ms) per attribute value, with
  carried representative attributes. This powers the query-performance view's
  exact ranking by total DB time over *every* span — something Tempo can't do
  for high-cardinality attributes like `db.query.text`. Raw TraceQL
  aggregations are rejected; the structured `TraceQuery` API only.
  Verified end-to-end against a live ClickHouse (total-time ranking, ns → ms,
  carried `db.system.name`, and the rendered `QueryPerformance` card).

### Notes
- **Running ClickHouse for the local E2E suite.** Since the `24.x` server image,
  the entrypoint disables network access for the passwordless `default` user
  unless you set a password or pass `CLICKHOUSE_SKIP_USER_SETUP=1`. The E2E
  tests connect without credentials, so start a throwaway container with that
  flag: `docker run --rm -e CLICKHOUSE_SKIP_USER_SETUP=1 -p 18123:8123
  clickhouse/clickhouse-server:24.8`. Without it, queries fail with
  `AUTHENTICATION_FAILED` (code 516) even though `/ping` returns `Ok`. (CI
  achieves the same by mounting `.github/clickhouse/open.xml` into
  `users.d/` instead of using the env flag.)

## v1.2.0

### Added
- **High availability.** The schema engine is configurable: a plain `MergeTree`
  for single-node, or `ReplicatedMergeTree` (optionally `ON CLUSTER`) for HA via
  `telemetry-store.clickhouse.engine`. The `{shard}`/`{database}`/`{replica}`
  macros resolve per node; `{table}` is substituted per table. Verified against a
  Keeper-enabled ClickHouse (tables registered in Keeper, ingest + reads work
  through the replicated tables).

## v1.1.1

### Added
- Ingest chunks inserts at `ingest.max_rows_per_insert` (was unused) so an
  oversized OTLP request can't balloon one insert body in memory.
- CI now runs the `tests/E2E` suite against a ClickHouse service (was skipped).
- Load/scale numbers in the deployment guide (~180–200k rows/s ingest,
  single/low-double-digit-ms reads over 500k logs / 200k spans / 10k series).

## v1.1.0

The metrics path now actually matches the dashboard, and everything is proven
end-to-end against a live ClickHouse.

### Fixed
- **Metric-name reconciliation.** The ingest now stores metrics under the exact
  Prometheus name the emitter's scrape would produce (dots→underscores, unit
  suffix, `_total` for monotonic counters) via `Ingest\PromName`, so the cards'
  Prometheus-style queries resolve. Previously metrics were stored under raw
  OTLP names and never matched.
- **Rate double-division** in `queryRange` (rate was divided by both the step
  and the window). Rate scaling now lives in the value expression only.
- Ingest timestamps are `DateTime64(9, 'UTC')` datetime strings (a numeric
  string was parsed as a date and rejected); empty `Map` columns serialize as
  `{}` (ClickHouse rejects `[]` for a Map).

### Added
- **Histogram quantile over `queryRange`** (per-bucket `sumForEach` + quantile),
  so latency charts render, not just instant stats.
- **Span events + links** are ingested (were dropped); `trace()` reconstructs
  `Span.links`.
- End-to-end test suite (`tests/E2E`) rendering real dashboard cards through
  Livewire against a live ClickHouse, plus unit/feature coverage of the parser,
  compilers and quantile math.

## v1.0.0

Initial release: ClickHouse OTel schema + install command, native OTLP/HTTP
ingest, and the `clickhouse-{logs,traces,metrics}` read drivers for
`cboxdk/laravel-telemetry-ui`.
