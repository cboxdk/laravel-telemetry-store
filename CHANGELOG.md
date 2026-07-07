# Changelog

All notable changes to `cboxdk/laravel-telemetry-store` are documented here.

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
