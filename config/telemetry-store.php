<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ClickHouse connection
    |--------------------------------------------------------------------------
    |
    | The store talks to ClickHouse over its HTTP interface (default port
    | 8123) — no PHP extension required. Both the OTLP ingest (writes) and the
    | telemetry-ui read driver (reads) use this one connection.
    |
    */
    'clickhouse' => [
        'endpoint' => env('TELEMETRY_STORE_CLICKHOUSE_URL', 'http://localhost:8123'),
        'database' => env('TELEMETRY_STORE_CLICKHOUSE_DB', 'telemetry'),
        'username' => env('TELEMETRY_STORE_CLICKHOUSE_USER', 'default'),
        'password' => env('TELEMETRY_STORE_CLICKHOUSE_PASSWORD', ''),
        'timeout' => (float) env('TELEMETRY_STORE_CLICKHOUSE_TIMEOUT', 10.0),
        // Extra ClickHouse settings sent as query params on every request
        // (e.g. async_insert, max_execution_time).
        'settings' => [
            'async_insert' => 1,
            'wait_for_async_insert' => 0,
        ],

        /*
        |----------------------------------------------------------------------
        | Storage engine (HA)
        |----------------------------------------------------------------------
        |
        | Single-node uses a plain MergeTree. For high availability, enable
        | `replicated` (needs ClickHouse Keeper/ZooKeeper); set `cluster` to
        | also create the tables `ON CLUSTER` across all nodes at once. The
        | `{shard}`/`{database}`/`{replica}` macros resolve from each node's
        | config; `{table}` is substituted per table.
        |
        */
        'engine' => [
            'replicated' => (bool) env('TELEMETRY_STORE_CLICKHOUSE_REPLICATED', false),
            'zoo_path' => env('TELEMETRY_STORE_CLICKHOUSE_ZOO_PATH', '/clickhouse/tables/{shard}/{database}/{table}'),
            'replica' => env('TELEMETRY_STORE_CLICKHOUSE_REPLICA', '{replica}'),
            'cluster' => env('TELEMETRY_STORE_CLICKHOUSE_CLUSTER'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep each signal. Applied as a ClickHouse TTL on the tables when
    | the schema is installed (`php artisan telemetry-store:install`); cheap,
    | since ClickHouse drops whole partitions.
    |
    */
    'retention_days' => (int) env('TELEMETRY_STORE_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | OTLP ingest
    |--------------------------------------------------------------------------
    |
    | Native OTLP/HTTP JSON ingest — point the emitter's
    | TELEMETRY_OTLP_ENDPOINT at `<app>/<path>` and it writes straight to
    | ClickHouse, no OpenTelemetry Collector in the loop. Secure it with a
    | bearer token and/or an IP allowlist; it accepts the same
    | /v1/{traces,metrics,logs} paths the collector does.
    |
    */
    'ingest' => [
        'enabled' => (bool) env('TELEMETRY_STORE_INGEST_ENABLED', true),
        'path' => env('TELEMETRY_STORE_INGEST_PATH', 'telemetry-store'),
        'middleware' => ['api'],
        'token' => env('TELEMETRY_STORE_INGEST_TOKEN'),
        /** @var list<string> */
        'allowed_ips' => array_values(array_filter(
            explode(',', (string) env('TELEMETRY_STORE_INGEST_ALLOWED_IPS', '')),
            static fn (string $ip): bool => $ip !== '',
        )),
        'max_rows_per_insert' => (int) env('TELEMETRY_STORE_INGEST_MAX_ROWS', 5000),
    ],
];
