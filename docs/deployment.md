# Deployment

A production checklist for running the ClickHouse OTEL store.

## 1. ClickHouse

Any ClickHouse ≥ 23.8 works (tested against 24.8 and 26.7). Run it however you
run stateful services — a managed ClickHouse, a container, or a binary.

```bash
docker run -d --name clickhouse \
  -p 8123:8123 -p 9000:9000 \
  --ulimit nofile=262144:262144 \
  clickhouse/clickhouse-server:24.8
```

### Host access (the one gotcha)

The official image locks the `default` user to connections from inside the
container (`::1`/`127.0.0.1`). Requests from your app arrive through the port
mapping as the Docker gateway IP and are **rejected with an auth error**. Either:

- **Set a password** — `-e CLICKHOUSE_PASSWORD=…` opens the user to any network;
  put the same value in `TELEMETRY_STORE_CLICKHOUSE_PASSWORD`. (Recommended.)
- **or mount an override** opening the network:
  ```xml
  <!-- users.d/open.xml -->
  <clickhouse><users><default>
    <networks replace="replace"><ip>::/0</ip></networks>
  </default></users></clickhouse>
  ```

Put ClickHouse on a private network and never expose 8123/9000 publicly.

## 2. Schema

```bash
php artisan telemetry-store:install
```

Idempotent (`CREATE TABLE IF NOT EXISTS`). Re-run after upgrading. Retention is a
per-table TTL from `telemetry-store.retention_days` (default 30); ClickHouse
drops whole day-partitions, so retention is essentially free. Bump it and
re-run, or `ALTER TABLE … MODIFY TTL` for an immediate change.

Sizing: budget by ingested rows/day × retention. Metrics and logs dominate;
traces are the largest per-row. Start with the defaults and watch
`system.parts` / disk.

## 3. Ingest

Point the emitter's OTLP exporter at this app — no OpenTelemetry Collector:

```dotenv
# in the app(s) emitting telemetry
TELEMETRY_OTLP_ENDPOINT=https://dashboard.example.com/telemetry-store
```

Secure the endpoint (both are opt-in; set at least one in production):

```dotenv
TELEMETRY_STORE_INGEST_TOKEN=$(openssl rand -hex 32)   # emitter sends it as a bearer token
TELEMETRY_STORE_INGEST_ALLOWED_IPS=10.0.0.0/8          # optional IP allowlist
```

**Batching / throughput.** Inserts use ClickHouse [async inserts]
(`async_insert=1`, `wait_for_async_insert=0` in `telemetry-store.clickhouse.settings`)
— ClickHouse buffers writes server-side and flushes in batches, which is what
you want under load. Keep `wait=0` in production (fire-and-forget, low latency);
set `wait=1` only in tests that read immediately after writing. Run the ingest
route on a queue/worker-friendly path if you expect very high volume.

## 4. Connect the dashboard

In `cboxdk/laravel-telemetry-ui`, point each connection at the store. Mix and
match — you can move one signal at a time:

```php
// config/telemetry-ui.php
'connections' => [
    'logs'    => ['driver' => 'clickhouse-logs',    'url' => env('TELEMETRY_STORE_CLICKHOUSE_URL'), 'database' => 'telemetry'],
    'traces'  => ['driver' => 'clickhouse-traces',  'url' => env('TELEMETRY_STORE_CLICKHOUSE_URL'), 'database' => 'telemetry'],
    'metrics' => ['driver' => 'clickhouse-metrics', 'url' => env('TELEMETRY_STORE_CLICKHOUSE_URL'), 'database' => 'telemetry'],
],
```

Connection config keys: `url`, `database`, `username`, `password`, `timeout`.
Anything omitted falls back to `telemetry-store.clickhouse.*`.

## 5. Verify

- `curl "$CH/?query=SELECT+count()+FROM+telemetry.otel_logs"` after some traffic.
- Open the dashboard — Logs, Traces and a metrics page should populate.

## Known limits

- **Metric naming** assumes the emitter's Prometheus conventions
  (`Ingest\PromName`); a custom emitter with different unit suffixes needs that
  map adjusted.
- Counter `rate`/`increase` is a windowed `max−min` (no counter-reset stitching);
  histogram quantiles sum bucket counts across the window. Good for dashboards;
  not a metrics-accuracy SLA.
- Ingest is synchronous per request (relying on ClickHouse async inserts for
  batching); a dedicated spool/queue is a future option for extreme volume.
