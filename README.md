# Laravel Telemetry Store (ClickHouse)

A native **ClickHouse** OTEL store for
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry) — one
SQL store instead of the Tempo + Loki + Mimir stack.

It does two things:

1. **Ingest** — a native PHP OTLP/HTTP endpoint that receives the emitter's
   traces, logs and metrics and writes them straight to ClickHouse. No
   OpenTelemetry Collector in the loop; point `TELEMETRY_OTLP_ENDPOINT` at this
   app.
2. **Read** — a ClickHouse-backed driver for
   [`cboxdk/laravel-telemetry-ui`](https://github.com/cboxdk/laravel-telemetry-ui),
   registered as an additional connection driver. Every existing dashboard card
   runs against ClickHouse unchanged, because the UI's query layer is a
   backend-neutral IR (`LogQuery` / `TraceQuery` / `MetricQuery`) that each
   driver compiles to its own dialect — LogQL/TraceQL/PromQL for the LGTM stack,
   SQL here.

It is an **additional** backend, selectable per connection: you can point logs
at ClickHouse while keeping metrics on Prometheus, or move everything over.

## Status

Early. The schema, ClickHouse client and OTLP ingest are the first milestone;
the read drivers land signal by signal (logs → traces → metrics), matching the
difficulty of expressing each in SQL (`rate()`/`histogram_quantile()` over
cumulative series is the hard part).

## Install

```bash
composer require cboxdk/laravel-telemetry-store
php artisan telemetry-store:install   # creates the ClickHouse tables (idempotent)
```

Configure the ClickHouse connection and ingest in `config/telemetry-store.php`
(publish with `--tag=telemetry-store-config`) or via env:

```dotenv
TELEMETRY_STORE_CLICKHOUSE_URL=http://clickhouse:8123
TELEMETRY_STORE_CLICKHOUSE_DB=telemetry
TELEMETRY_STORE_RETENTION_DAYS=30

# point the emitter at this app's ingest endpoint:
TELEMETRY_OTLP_ENDPOINT=https://your-app.test/telemetry-store
```

Then select the ClickHouse driver in `telemetry-ui.connections` (see the UI
package docs).

**For production** — ClickHouse host-access, retention, securing the ingest
endpoint, throughput and connecting the dashboard — see
[docs/deployment.md](docs/deployment.md).

## Schema

Modelled on the OpenTelemetry Collector's `clickhouseexporter` tables so the
data is familiar and tool-compatible: `otel_logs`, `otel_traces`,
`otel_metrics_sum` / `_gauge` / `_histogram` — MergeTree, partitioned by day,
with a per-signal TTL for cheap partition-drop retention.

## Development

```bash
composer install
composer check   # pint, phpstan (level 8), pest
```

The design notes live in the UI package at
`docs/design/storage-backends.md`.

## License

MIT.
