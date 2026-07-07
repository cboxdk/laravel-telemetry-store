<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Ingest;

/**
 * Replicates cboxdk/laravel-telemetry's OTLP → Prometheus metric-name mapping,
 * so the store keeps metrics under the SAME name the emitter's Prometheus scrape
 * would produce — which is exactly what the telemetry-ui cards query.
 *
 * Rules (see the emitter's PrometheusRenderer + MetricDefinition):
 *  - dots → underscores
 *  - a unit suffix (`ms`→`_milliseconds`, `By`→`_bytes`, …) before `_total`
 *  - `_total` only for monotonic counters (not gauges, up-down counters, histograms)
 *
 * Histograms are stored under this base name (no suffix); the exploded
 * `_bucket`/`_count`/`_sum` series are reconstructed at read time.
 */
final class PromName
{
    public static function from(string $otlpName, string $unit, bool $counter): string
    {
        return str_replace('.', '_', $otlpName).self::unitSuffix($unit).($counter ? '_total' : '');
    }

    private static function unitSuffix(string $unit): string
    {
        return match ($unit) {
            'ms' => '_milliseconds',
            's' => '_seconds',
            'By', 'bytes' => '_bytes',
            'By/s' => '_bytes_per_second',
            '%' => '_percent',
            default => '',
        };
    }
}
