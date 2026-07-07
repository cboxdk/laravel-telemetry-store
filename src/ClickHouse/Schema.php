<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\ClickHouse;

/**
 * The ClickHouse table schema for the three OTLP signals, modelled on the
 * OpenTelemetry Collector's `clickhouseexporter` tables so the data is familiar
 * and tool-compatible. Kept intentionally close to that layout: a MergeTree per
 * signal, partitioned by day, ordered by (ServiceName, Timestamp), with a
 * per-signal TTL for cheap partition-drop retention.
 *
 * These are the DDL statements the install command runs; there is no ORM here —
 * ClickHouse is a columnar store, not an Eloquent connection.
 */
final class Schema
{
    /**
     * Every CREATE TABLE statement, in dependency order, with the retention TTL
     * (days) baked into each table.
     *
     * @return list<string>
     */
    public static function statements(int $retentionDays): array
    {
        $ttl = "TTL toDateTime(Timestamp) + INTERVAL {$retentionDays} DAY";

        return [
            self::logs($ttl),
            self::traces($ttl),
            self::metricsSum($ttl),
            self::metricsGauge($ttl),
            self::metricsHistogram($ttl),
        ];
    }

    private static function logs(string $ttl): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS otel_logs (
                Timestamp DateTime64(9) CODEC(Delta(8), ZSTD(1)),
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
            ) ENGINE = MergeTree
            PARTITION BY toDate(Timestamp)
            ORDER BY (ServiceName, toDateTime(Timestamp))
            {$ttl}
            SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1
            SQL;
    }

    private static function traces(string $ttl): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS otel_traces (
                Timestamp DateTime64(9) CODEC(Delta(8), ZSTD(1)),
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
                Events Nested (Timestamp DateTime64(9), Name LowCardinality(String), Attributes Map(LowCardinality(String), String)) CODEC(ZSTD(1)),
                Links Nested (TraceId String, SpanId String, Attributes Map(LowCardinality(String), String)) CODEC(ZSTD(1)),
                INDEX idx_trace_id TraceId TYPE bloom_filter(0.001) GRANULARITY 1,
                INDEX idx_span_attr_key mapKeys(SpanAttributes) TYPE bloom_filter(0.01) GRANULARITY 1,
                INDEX idx_span_attr_value mapValues(SpanAttributes) TYPE bloom_filter(0.01) GRANULARITY 1,
                INDEX idx_duration Duration TYPE minmax GRANULARITY 1
            ) ENGINE = MergeTree
            PARTITION BY toDate(Timestamp)
            ORDER BY (ServiceName, SpanName, toDateTime(Timestamp))
            {$ttl}
            SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1
            SQL;
    }

    private static function metricsSum(string $ttl): string
    {
        return self::metricPoints('otel_metrics_sum', 'Value Float64 CODEC(ZSTD(1)),
                AggregationTemporality Int32 CODEC(ZSTD(1)),
                IsMonotonic Bool CODEC(ZSTD(1)),', $ttl);
    }

    private static function metricsGauge(string $ttl): string
    {
        return self::metricPoints('otel_metrics_gauge', 'Value Float64 CODEC(ZSTD(1)),', $ttl);
    }

    private static function metricsHistogram(string $ttl): string
    {
        return self::metricPoints('otel_metrics_histogram', 'Count UInt64 CODEC(ZSTD(1)),
                Sum Float64 CODEC(ZSTD(1)),
                BucketCounts Array(UInt64) CODEC(ZSTD(1)),
                ExplicitBounds Array(Float64) CODEC(ZSTD(1)),
                AggregationTemporality Int32 CODEC(ZSTD(1)),', $ttl);
    }

    private static function metricPoints(string $table, string $valueColumns, string $ttl): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                Timestamp DateTime64(9) CODEC(Delta(8), ZSTD(1)),
                StartTimestamp DateTime64(9) CODEC(Delta(8), ZSTD(1)),
                MetricName LowCardinality(String) CODEC(ZSTD(1)),
                ServiceName LowCardinality(String) CODEC(ZSTD(1)),
                ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                Attributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
                {$valueColumns}
                INDEX idx_attr_key mapKeys(Attributes) TYPE bloom_filter(0.01) GRANULARITY 1
            ) ENGINE = MergeTree
            PARTITION BY toDate(Timestamp)
            ORDER BY (MetricName, ServiceName, toDateTime(Timestamp))
            {$ttl}
            SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1
            SQL;
    }
}
