<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\ClickHouse;

/**
 * The ClickHouse table schema for the three OTLP signals, modelled on the
 * OpenTelemetry Collector's `clickhouseexporter` tables so the data is familiar
 * and tool-compatible. A MergeTree per signal, partitioned by day, ordered by
 * `(ServiceName, Timestamp)`, with a per-signal TTL for cheap partition-drop
 * retention.
 *
 * The engine is configurable: a plain `MergeTree` for single-node, or
 * `ReplicatedMergeTree` (optionally `ON CLUSTER`) for HA — see
 * {@see Engine}. ClickHouse expands the
 * `{shard}`/`{replica}`/`{database}`/`{table}` macros in the ZooKeeper path.
 */
final class Schema
{
    /**
     * Every CREATE TABLE statement, in dependency order, with the retention TTL
     * (days) baked into each table and the given storage engine.
     *
     * @return list<string>
     */
    public static function statements(int $retentionDays, ?Engine $engine = null): array
    {
        $engine ??= Engine::mergeTree();
        $ttl = "TTL toDateTime(Timestamp) + INTERVAL {$retentionDays} DAY";

        return [
            self::logs($ttl, $engine),
            self::traces($ttl, $engine),
            self::metricsSum($ttl, $engine),
            self::metricsGauge($ttl, $engine),
            self::metricsHistogram($ttl, $engine),
            // Rollup for the query-performance dashboard: a summary + a
            // materialized view keeping it current, so ranking DB statements
            // stays sub-second at billions of spans (raw GROUP BY on the
            // SpanAttributes map is scan-bound). Created after otel_traces.
            self::dbQuerySummary($retentionDays, $engine),
            self::dbQuerySummaryMv($engine),
        ];
    }

    private static function logs(string $ttl, Engine $engine): string
    {
        return self::table('otel_logs', $engine, <<<'COLS'
                Timestamp DateTime64(9, 'UTC') CODEC(Delta(8), ZSTD(1)),
                TraceId String CODEC(ZSTD(1)),
                SpanId String CODEC(ZSTD(1)),
                SeverityText LowCardinality(String) CODEC(ZSTD(1)),
                SeverityNumber Int32 CODEC(ZSTD(1)),
                ServiceName LowCardinality(String) CODEC(ZSTD(1)),
                Body String CODEC(ZSTD(1)),
                ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                LogAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                INDEX idx_trace_id TraceId TYPE bloom_filter(0.001) GRANULARITY 1,
                INDEX idx_body Body TYPE tokenbf_v1(32768, 3, 0) GRANULARITY 1,
                INDEX idx_log_attr_key mapKeys(LogAttributes) TYPE bloom_filter(0.01) GRANULARITY 1,
                INDEX idx_log_attr_value mapValues(LogAttributes) TYPE bloom_filter(0.01) GRANULARITY 1
            COLS,
            'PARTITION BY toDate(Timestamp)',
            'ORDER BY (ServiceName, toDateTime(Timestamp))',
            $ttl,
        );
    }

    private static function traces(string $ttl, Engine $engine): string
    {
        return self::table('otel_traces', $engine, <<<'COLS'
                Timestamp DateTime64(9, 'UTC') CODEC(Delta(8), ZSTD(1)),
                TraceId String CODEC(ZSTD(1)),
                SpanId String CODEC(ZSTD(1)),
                ParentSpanId String CODEC(ZSTD(1)),
                SpanName LowCardinality(String) CODEC(ZSTD(1)),
                SpanKind LowCardinality(String) CODEC(ZSTD(1)),
                ServiceName LowCardinality(String) CODEC(ZSTD(1)),
                ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                SpanAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                Duration UInt64 CODEC(ZSTD(1)),
                StatusCode LowCardinality(String) CODEC(ZSTD(1)),
                StatusMessage String CODEC(ZSTD(1)),
                Events Nested (Timestamp DateTime64(9, 'UTC'), Name LowCardinality(String), Attributes Map(LowCardinality(String), String)) CODEC(ZSTD(1)),
                Links Nested (TraceId String, SpanId String, Attributes Map(LowCardinality(String), String)) CODEC(ZSTD(1)),
                INDEX idx_trace_id TraceId TYPE bloom_filter(0.001) GRANULARITY 1,
                INDEX idx_span_attr_key mapKeys(SpanAttributes) TYPE bloom_filter(0.01) GRANULARITY 1,
                INDEX idx_span_attr_value mapValues(SpanAttributes) TYPE bloom_filter(0.01) GRANULARITY 1,
                INDEX idx_duration Duration TYPE minmax GRANULARITY 1
            COLS,
            'PARTITION BY toDate(Timestamp)',
            'ORDER BY (ServiceName, SpanName, toDateTime(Timestamp))',
            $ttl,
        );
    }

    private static function metricsSum(string $ttl, Engine $engine): string
    {
        return self::metricPoints('otel_metrics_sum', $engine, 'Value Float64 CODEC(ZSTD(1)),
                AggregationTemporality Int32 CODEC(ZSTD(1)),
                IsMonotonic Bool CODEC(ZSTD(1)),', $ttl);
    }

    private static function metricsGauge(string $ttl, Engine $engine): string
    {
        return self::metricPoints('otel_metrics_gauge', $engine, 'Value Float64 CODEC(ZSTD(1)),', $ttl);
    }

    private static function metricsHistogram(string $ttl, Engine $engine): string
    {
        return self::metricPoints('otel_metrics_histogram', $engine, 'Count UInt64 CODEC(ZSTD(1)),
                Sum Float64 CODEC(ZSTD(1)),
                BucketCounts Array(UInt64) CODEC(ZSTD(1)),
                ExplicitBounds Array(Float64) CODEC(ZSTD(1)),
                AggregationTemporality Int32 CODEC(ZSTD(1)),', $ttl);
    }

    private static function metricPoints(string $table, Engine $engine, string $valueColumns, string $ttl): string
    {
        return self::table($table, $engine, "Timestamp DateTime64(9, 'UTC') CODEC(Delta(8), ZSTD(1)),
                StartTimestamp DateTime64(9, 'UTC') CODEC(Delta(8), ZSTD(1)),
                MetricName LowCardinality(String) CODEC(ZSTD(1)),
                ServiceName LowCardinality(String) CODEC(ZSTD(1)),
                ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                Attributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                {$valueColumns}
                INDEX idx_attr_key mapKeys(Attributes) TYPE bloom_filter(0.01) GRANULARITY 1",
            'PARTITION BY toDate(Timestamp)',
            'ORDER BY (MetricName, ServiceName, toDateTime(Timestamp))',
            $ttl,
        );
    }

    /**
     * Per-minute rollup of DB query spans, keyed by statement fingerprint, that
     * the query-performance card reads instead of scanning `otel_traces`. Merge
     * states keep count/sum/max/quantile exact and re-aggregatable across any
     * time window. Populated by {@see dbQuerySummaryMv()} on new inserts and a
     * one-time backfill for existing data.
     */
    private static function dbQuerySummary(int $retentionDays, Engine $engine): string
    {
        $columns = <<<'COLS'
                Bucket DateTime CODEC(Delta(4), ZSTD(1)),
                QueryText String CODEC(ZSTD(1)),
                DbSystem LowCardinality(String) CODEC(ZSTD(1)),
                Calls SimpleAggregateFunction(sum, UInt64),
                DurationSum SimpleAggregateFunction(sum, UInt64),
                DurationMax SimpleAggregateFunction(max, UInt64),
                DurationQuantile AggregateFunction(quantileTDigest, UInt64)
            COLS;

        return "CREATE TABLE IF NOT EXISTS otel_db_query_summary{$engine->onCluster()} (\n"
            .$columns."\n"
            .') ENGINE = '.$engine->clauseFor('otel_db_query_summary', 'AggregatingMergeTree')."\n"
            ."PARTITION BY toDate(Bucket)\n"
            ."ORDER BY (Bucket, DbSystem, QueryText)\n"
            ."TTL Bucket + INTERVAL {$retentionDays} DAY\n"
            .'SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1';
    }

    /**
     * Materialized view feeding {@see dbQuerySummary()} from every `otel_traces`
     * insert — the minute rollup stays current without a batch job.
     */
    private static function dbQuerySummaryMv(Engine $engine): string
    {
        return "CREATE MATERIALIZED VIEW IF NOT EXISTS otel_db_query_summary_mv{$engine->onCluster()} TO otel_db_query_summary AS
            SELECT
                toStartOfMinute(Timestamp) AS Bucket,
                SpanAttributes['db.query.text'] AS QueryText,
                SpanAttributes['db.system.name'] AS DbSystem,
                count() AS Calls,
                sum(Duration) AS DurationSum,
                max(Duration) AS DurationMax,
                quantileTDigestState(Duration) AS DurationQuantile
            FROM otel_traces
            WHERE SpanName = 'db.query' AND SpanAttributes['db.query.text'] != ''
            GROUP BY Bucket, QueryText, DbSystem";
    }

    /**
     * Assemble one CREATE TABLE from its columns + clauses and the engine.
     */
    private static function table(string $name, Engine $engine, string $columns, string $partitionBy, string $orderBy, string $ttl): string
    {
        return "CREATE TABLE IF NOT EXISTS {$name}{$engine->onCluster()} (\n"
            .$columns."\n"
            .") ENGINE = {$engine->clause($name)}\n"
            .$partitionBy."\n"
            .$orderBy."\n"
            .$ttl."\n"
            .'SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1';
    }
}
