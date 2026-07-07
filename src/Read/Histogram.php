<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

/**
 * Prometheus-style `histogram_quantile` over an explicit-bounds histogram,
 * computed in PHP from the (summed) bucket counts and bounds a ClickHouse
 * `otel_metrics_histogram` group yields.
 */
final class Histogram
{
    /**
     * The value at quantile $q, linearly interpolated within the crossing
     * bucket. $buckets has one more entry than $bounds (the +Inf overflow).
     * Returns NAN when the histogram is empty.
     *
     * @param  list<float>  $buckets
     * @param  list<float>  $bounds
     */
    public static function quantile(array $buckets, array $bounds, float $q): float
    {
        $total = array_sum($buckets);

        if ($total <= 0.0 || $bounds === []) {
            return NAN;
        }

        $rank = $q * $total;
        $cumulative = 0.0;

        foreach ($buckets as $i => $count) {
            $cumulative += $count;

            if ($cumulative >= $rank) {
                $lower = $i === 0 ? 0.0 : ($bounds[$i - 1] ?? 0.0);
                $upper = $bounds[$i] ?? $lower;
                $inBucket = $count > 0.0 ? ($rank - ($cumulative - $count)) / $count : 0.0;

                return $lower + ($upper - $lower) * $inBucket;
            }
        }

        return $bounds[count($bounds) - 1] ?? NAN;
    }
}
