<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\MetricFn;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\DataPoint;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use DateTimeInterface;
use Throwable;

/**
 * Reads metrics from ClickHouse `otel_metrics_{sum,gauge,histogram}`, compiling
 * the UI's {@see MetricQuery} to SQL. Registered as the `clickhouse-metrics`
 * driver.
 *
 * Cumulative counters become a windowed delta (`max(Value) - min(Value)`);
 * gauges resolve to their latest value; histogram quantiles are computed in PHP
 * from the summed bucket counts + bounds. This is the driver most in need of
 * validation against a live store — see {@see MetricName} for the naming
 * assumptions and the class docblocks for the delta/quantile approximations.
 * A raw PromQL query (config-driven exporter cards) is not supported.
 */
final readonly class ClickHouseMetricsSource implements MetricsSource
{
    public function __construct(private Client $client) {}

    public function query(MetricQuery $query, ?DateTimeInterface $at = null): array
    {
        $this->guard($query);

        // An instant query still needs a window for a counter delta; default to
        // the metric's own range window, or a short lookback for a gauge.
        $end = $at?->getTimestamp() ?? time();
        $start = $end - max(1, $this->windowSeconds($query, 3600));

        if ($query->quantile !== null) {
            return $this->quantileSamples($query, $start, $end);
        }

        [$select, $groupBy] = $this->grouping($query);
        $value = $this->valueExpr($query).' AS v';

        $sql = 'SELECT '.implode(', ', [...$select, $value])
            .' FROM '.$this->table($query)
            .' WHERE '.$this->where($query, $start, $end)
            .($groupBy === [] ? '' : ' GROUP BY '.implode(', ', $groupBy));

        return array_map(
            fn (array $row): Sample => new Sample(
                labels: $this->labels($query, $row),
                timestamp: (float) $end,
                value: $this->scaled($query, (float) ($row['v'] ?? 0)),
            ),
            $this->select($sql),
        );
    }

    public function queryRange(MetricQuery $query, DateTimeInterface $start, DateTimeInterface $end, ?int $step = null): array
    {
        $this->guard($query);

        $step ??= max(15, (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 250));

        [$select, $groupBy] = $this->grouping($query);
        $bucketCol = 'toUnixTimestamp(toStartOfInterval(Timestamp, INTERVAL '.$step.' SECOND)) AS t';

        // Histogram quantile per time bucket: sum the bucket arrays in each
        // bucket, compute the quantile in PHP.
        if ($query->quantile !== null) {
            $sql = 'SELECT '.implode(', ', [$bucketCol, ...$select, 'sumForEach(BucketCounts) AS buckets', 'any(ExplicitBounds) AS bounds'])
                .' FROM '.MetricName::TABLE_HISTOGRAM
                .' WHERE '.$this->where($query, $start->getTimestamp(), $end->getTimestamp())
                .' GROUP BY '.implode(', ', ['t', ...$groupBy])
                .' ORDER BY t';

            return $this->buildSeries($sql, $query, function (array $row) use ($query): float {
                $buckets = array_values(array_map(self::float(...), is_array($row['buckets'] ?? null) ? $row['buckets'] : []));
                $bounds = array_values(array_map(self::float(...), is_array($row['bounds'] ?? null) ? $row['bounds'] : []));

                return Histogram::quantile($buckets, $bounds, $query->quantile ?? 0.95);
            });
        }

        $sql = 'SELECT '.implode(', ', [$bucketCol, ...$select, $this->bucketValueExpr($query, $step).' AS v'])
            .' FROM '.$this->table($query)
            .' WHERE '.$this->where($query, $start->getTimestamp(), $end->getTimestamp())
            .' GROUP BY '.implode(', ', ['t', ...$groupBy])
            .' ORDER BY t';

        return $this->buildSeries($sql, $query, fn (array $row): float => $this->scaled($query, (float) ($row['v'] ?? 0)));
    }

    /**
     * Group range-query rows into one {@see TimeSeries} per label set, taking the
     * per-row value from $value.
     *
     * @param  callable(array<string, mixed>): float  $value
     * @return list<TimeSeries>
     */
    private function buildSeries(string $sql, MetricQuery $query, callable $value): array
    {
        /** @var array<string, array{labels: array<string, string>, points: list<DataPoint>}> $series */
        $series = [];

        foreach ($this->select($sql) as $row) {
            $labels = $this->labels($query, $row);
            $key = json_encode($labels) ?: '';

            $series[$key] ??= ['labels' => $labels, 'points' => []];
            $series[$key]['points'][] = new DataPoint(
                timestamp: (float) ($row['t'] ?? 0),
                value: $value($row),
            );
        }

        return array_values(array_map(
            static fn (array $s): TimeSeries => new TimeSeries($s['labels'], $s['points']),
            $series,
        ));
    }

    public function labelValues(string $label, ?string $match = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): array
    {
        $expr = $this->labelExpr($label);
        $where = ["{$expr} != ''"];

        if ($start !== null && $end !== null) {
            $where[] = 'Timestamp >= fromUnixTimestamp('.$start->getTimestamp().')';
            $where[] = 'Timestamp <= fromUnixTimestamp('.$end->getTimestamp().')';
        }

        // Label enumeration spans all metric tables; the sum table is the
        // broadest single source for service/attribute labels.
        $sql = "SELECT DISTINCT {$expr} AS v FROM ".MetricName::TABLE_SUM.' WHERE '.implode(' AND ', $where).' ORDER BY v LIMIT 1000';

        return array_values(array_filter(array_map(
            static fn (array $row): string => is_string($row['v'] ?? null) ? $row['v'] : '',
            $this->select($sql),
        ), static fn (string $v): bool => $v !== ''));
    }

    private function guard(MetricQuery $query): void
    {
        if ($query->raw !== null) {
            throw SourceException::because('The ClickHouse metrics driver cannot run a raw PromQL query; use the structured MetricQuery API.');
        }
    }

    private function table(MetricQuery $query): string
    {
        $isCounter = in_array($query->fn, [MetricFn::Rate, MetricFn::Increase, MetricFn::CounterIncrease], true);

        return MetricName::table($query->name, $isCounter);
    }

    /**
     * The per-group value for an instant query: latest value for a gauge, a
     * windowed delta for an increase, delta/window for a rate, or the histogram
     * count/sum delta.
     */
    private function valueExpr(MetricQuery $query): string
    {
        $part = MetricName::histogramPart($query->name);
        $delta = 'greatest(max(Value) - min(Value), 0)';

        return match (true) {
            $part === 'count' => 'toFloat64(greatest(max(Count) - min(Count), 0))',
            $part === 'sum' => 'greatest(max(Sum) - min(Sum), 0)',
            $query->fn === MetricFn::None => 'argMax(Value, Timestamp)',
            $query->fn === MetricFn::Rate => '('.$delta.') / '.max(1, $this->windowSeconds($query, 60)),
            default => $delta, // Increase, CounterIncrease
        };
    }

    private function bucketValueExpr(MetricQuery $query, int $step): string
    {
        return match ($query->fn) {
            MetricFn::Rate => '(greatest(max(Value) - min(Value), 0)) / '.$step,
            MetricFn::Increase, MetricFn::CounterIncrease => 'greatest(max(Value) - min(Value), 0)',
            MetricFn::None => 'avg(Value)',
        };
    }

    private function scaled(MetricQuery $query, float $value): float
    {
        // Rate division happens in the value expression (instant) / bucket
        // expression (range); here we only apply the scalar multiplier (e.g. *60).
        return $query->scalar !== null ? $value * $query->scalar : $value;
    }

    /**
     * SELECT exprs + GROUP BY exprs for the query's `by` labels.
     *
     * @return array{list<string>, list<string>}
     */
    private function grouping(MetricQuery $query): array
    {
        $select = [];
        $groupBy = [];

        foreach ($query->by as $label) {
            $expr = $this->labelExpr($label);
            $select[] = $expr.' AS `'.str_replace('`', '', $label).'`';
            $groupBy[] = $expr;
        }

        return [$select, $groupBy];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function labels(MetricQuery $query, array $row): array
    {
        $labels = [];

        foreach ($query->by as $label) {
            $value = $row[$label] ?? null;
            $labels[$label] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
        }

        return $labels;
    }

    private function where(MetricQuery $query, int $start, int $end): string
    {
        $conditions = [
            'MetricName = '.Sql::quote(MetricName::base($query->name)),
            'Timestamp >= fromUnixTimestamp('.$start.')',
            'Timestamp <= fromUnixTimestamp('.$end.')',
        ];

        foreach ($query->matchers as $matcher) {
            $conditions[] = $this->matcher($matcher);
        }

        foreach ($query->rawMatchers as $raw) {
            $conditions[] = $this->rawMatcher($raw);
        }

        return implode(' AND ', $conditions);
    }

    private function matcher(LabelMatcher $matcher): string
    {
        $expr = $this->labelExpr($matcher->label);
        $value = Sql::quote($matcher->value);

        return match ($matcher->op) {
            MatchOp::Eq => "{$expr} = {$value}",
            MatchOp::Neq => "{$expr} != {$value}",
            MatchOp::Re => "match({$expr}, {$value})",
            MatchOp::Nre => "NOT match({$expr}, {$value})",
        };
    }

    /**
     * Parse a verbatim PromQL matcher fragment (`label=~"value"`) into SQL. The
     * driver can't run arbitrary PromQL, but the entity-scope fragments cards
     * emit are simple `label OP "value"` pairs.
     */
    private function rawMatcher(string $raw): string
    {
        if (preg_match('/^(\w+)\s*(=~|!=|!~|=)\s*"(.*)"$/s', trim($raw), $m) !== 1) {
            throw SourceException::because('The ClickHouse metrics driver cannot parse the matcher: '.$raw);
        }

        $op = match ($m[2]) {
            '=' => MatchOp::Eq,
            '!=' => MatchOp::Neq,
            '=~' => MatchOp::Re,
            default => MatchOp::Nre,
        };

        return $this->matcher(new LabelMatcher($m[1], $op, stripcslashes($m[3])));
    }

    private function labelExpr(string $label): string
    {
        return match ($label) {
            'service_name' => 'ServiceName',
            'deployment_environment_name' => Sql::attr('Attributes', 'deployment.environment.name'),
            default => 'Attributes['.Sql::quote(str_replace('_', '.', $label)).']',
        };
    }

    private function windowSeconds(MetricQuery $query, int $default): int
    {
        if ($query->window === '' || preg_match('/^([0-9]+)([smhd])$/', $query->window, $m) !== 1) {
            return $default;
        }

        return (int) $m[1] * match ($m[2]) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 1,
        };
    }

    /**
     * Histogram-quantile samples, computed in PHP from the summed bucket counts
     * and bounds per group. Approximation: buckets are summed across the window
     * rather than delta'd — the distribution shape (not the absolute count) is
     * what the quantile needs.
     *
     * @return list<Sample>
     */
    private function quantileSamples(MetricQuery $query, int $start, int $end): array
    {
        [$select, $groupBy] = $this->grouping($query);

        $sql = 'SELECT '.implode(', ', [...$select, 'sumForEach(BucketCounts) AS buckets', 'any(ExplicitBounds) AS bounds'])
            .' FROM '.MetricName::TABLE_HISTOGRAM
            .' WHERE '.$this->where($query, $start, $end)
            .($groupBy === [] ? '' : ' GROUP BY '.implode(', ', $groupBy));

        $samples = [];

        foreach ($this->select($sql) as $row) {
            $buckets = array_values(array_map(self::float(...), is_array($row['buckets'] ?? null) ? $row['buckets'] : []));
            $bounds = array_values(array_map(self::float(...), is_array($row['bounds'] ?? null) ? $row['bounds'] : []));

            $samples[] = new Sample(
                labels: $this->labels($query, $row),
                timestamp: (float) $end,
                value: Histogram::quantile($buckets, $bounds, $query->quantile ?? 0.95),
            );
        }

        return $samples;
    }

    /**
     * @param  mixed  $value
     */
    private static function float($value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : (is_string($value) && is_numeric($value) ? (float) $value : 0.0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function select(string $sql): array
    {
        try {
            return $this->client->select($sql);
        } catch (Throwable $exception) {
            throw SourceException::because('The ClickHouse store metric query failed.');
        }
    }
}
