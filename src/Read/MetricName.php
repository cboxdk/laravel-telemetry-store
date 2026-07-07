<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

/**
 * Reconciles the Prometheus-style metric names the UI cards use with how the
 * native ingest stores them in ClickHouse.
 *
 * The cards were written against a Prometheus scrape, so they use the exploded
 * histogram series (`..._bucket` / `..._count` / `..._sum`) and unit-suffixed
 * counter/gauge names. The store keeps one row per OTLP data point in
 * `otel_metrics_{sum,gauge,histogram}` under the metric's own name. This class
 * decides which table a queried name lives in and what base name to match.
 *
 * NOTE: this is the layer most in need of validation against a live store —
 * the exact unit-suffix and dotted/underscore normalisation depends on the
 * emitter's OTLP→name mapping, and is intentionally kept in one place so it can
 * be tuned without touching the driver.
 */
final class MetricName
{
    public const TABLE_SUM = 'otel_metrics_sum';

    public const TABLE_GAUGE = 'otel_metrics_gauge';

    public const TABLE_HISTOGRAM = 'otel_metrics_histogram';

    /**
     * Which histogram component a name refers to, if any: 'bucket', 'count',
     * 'sum', or null when it isn't a histogram series.
     */
    public static function histogramPart(string $name): ?string
    {
        foreach (['bucket', 'count', 'sum'] as $part) {
            if (str_ends_with($name, '_'.$part)) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The base metric name to match in ClickHouse: strip a histogram suffix
     * when present. (Unit-suffix normalisation would also live here.)
     */
    public static function base(string $name): string
    {
        $part = self::histogramPart($name);

        return $part === null ? $name : substr($name, 0, -1 - strlen($part));
    }

    /**
     * The table a query against `$name` reads from, given the range function:
     * a `_bucket/_count/_sum` name is a histogram; a rate/increase counter is a
     * sum; anything else is treated as a gauge.
     */
    public static function table(string $name, bool $isCounter): string
    {
        if (self::histogramPart($name) !== null) {
            return self::TABLE_HISTOGRAM;
        }

        return $isCounter ? self::TABLE_SUM : self::TABLE_GAUGE;
    }
}
