<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\SpanSort;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Reads traces from ClickHouse `otel_traces`, compiling the UI's
 * {@see TraceQuery} to SQL. Registered as the `clickhouse-traces` driver.
 *
 * `search()` returns one {@see TraceSummary} per matched trace, its matched
 * spans carrying the span attributes the cards read (dotted OTLP keys, exactly
 * as Tempo delivers them). The trace root is approximated from the matched
 * spans — good enough for the list display; the full waterfall comes from
 * {@see Trace()}.
 */
final readonly class ClickHouseTracesSource implements AggregatesSpans, TracesSource
{
    public function __construct(private Client $client) {}

    /**
     * Exact span aggregation over `otel_traces`: GROUP BY the attribute with
     * count/avg/p95/max/sum of Duration (ns → ms). This is what lets the
     * query-performance view rank by total DB time over EVERY span, not a
     * sample — the thing Tempo can't do for high-cardinality attributes.
     */
    public function aggregateSpans(SpanAggregation $aggregation, DateTimeInterface $start, DateTimeInterface $end): array
    {
        if ($aggregation->where->raw !== null) {
            throw SourceException::because('The ClickHouse traces driver cannot aggregate a raw TraceQL query; use the structured TraceQuery API.');
        }

        $group = TraceFields::expr($aggregation->groupBy);

        $where = array_merge(
            [
                'Timestamp >= fromUnixTimestamp('.$start->getTimestamp().')',
                'Timestamp <= fromUnixTimestamp('.$end->getTimestamp().')',
                $group." != ''",
            ],
            array_map(TraceFields::condition(...), $aggregation->where->conditions),
        );

        $select = [
            $group.' AS k',
            'count() AS c',
            'avg(Duration) AS a',
            'quantile('.self::number($aggregation->quantile).')(Duration) AS p',
            'max(Duration) AS mx',
            'sum(Duration) AS s',
        ];

        $carry = [];
        foreach (array_values($aggregation->carry) as $i => $field) {
            $select[] = 'any('.TraceFields::expr($field).') AS carry_'.$i;
            $carry[$i] = self::attrName($field);
        }

        $sortColumn = match ($aggregation->sort) {
            SpanSort::Avg => 'a',
            SpanSort::P95 => 'p',
            SpanSort::Max => 'mx',
            SpanSort::Calls => 'c',
            default => 's',
        };

        $sql = 'SELECT '.implode(', ', $select)
            .' FROM otel_traces WHERE '.implode(' AND ', $where)
            .' GROUP BY k ORDER BY '.$sortColumn.' DESC LIMIT '.max(1, $aggregation->limit);

        return array_map(function (array $row) use ($carry): SpanBucket {
            $attributes = [];
            foreach ($carry as $i => $name) {
                $value = $row['carry_'.$i] ?? null;
                if (is_string($value) && $value !== '') {
                    $attributes[$name] = $value;
                }
            }

            return new SpanBucket(
                key: is_string($row['k'] ?? null) ? $row['k'] : '',
                count: (int) self::float($row['c'] ?? 0),
                avgMs: self::float($row['a'] ?? 0) / 1_000_000,
                p95Ms: self::float($row['p'] ?? 0) / 1_000_000,
                maxMs: self::float($row['mx'] ?? 0) / 1_000_000,
                totalMs: self::float($row['s'] ?? 0) / 1_000_000,
                attributes: $attributes,
            );
        }, $this->select($sql));
    }

    public function search(TraceQuery $query, DateTimeInterface $start, DateTimeInterface $end, int $limit = 20): array
    {
        if ($query->raw !== null) {
            throw SourceException::because('The ClickHouse traces driver cannot run a raw TraceQL query; use the structured TraceQuery API.');
        }

        $where = array_merge(
            ['Timestamp >= fromUnixTimestamp('.$start->getTimestamp().')', 'Timestamp <= fromUnixTimestamp('.$end->getTimestamp().')'],
            array_map(TraceFields::condition(...), $query->conditions),
        );

        // Pull the matched spans newest-first, then fold into per-trace
        // summaries. Over-fetch spans so a trace isn't split across the limit.
        $sql = 'SELECT TraceId, SpanId, ParentSpanId, SpanName, ServiceName, '
            .'toUnixTimestamp64Nano(Timestamp) AS StartNano, Duration, StatusCode, SpanAttributes '
            .'FROM otel_traces WHERE '.implode(' AND ', $where)
            .' ORDER BY Timestamp DESC LIMIT '.max(1, $limit * 50);

        /** @var array<string, list<array<string, mixed>>> $byTrace */
        $byTrace = [];

        foreach ($this->select($sql) as $row) {
            $traceId = is_string($row['TraceId'] ?? null) ? $row['TraceId'] : '';

            if ($traceId !== '') {
                $byTrace[$traceId][] = $row;
            }
        }

        $summaries = [];

        foreach (array_slice($byTrace, 0, $limit, true) as $traceId => $rows) {
            $summaries[] = $this->summary($traceId, $rows);
        }

        return $summaries;
    }

    public function trace(string $traceId): Trace
    {
        $sql = 'SELECT SpanId, ParentSpanId, SpanName, ServiceName, SpanKind, '
            .'toUnixTimestamp64Nano(Timestamp) AS StartNano, Duration, StatusCode, SpanAttributes, ResourceAttributes, '
            .'Links.TraceId AS LinkTraceIds, Links.SpanId AS LinkSpanIds '
            .'FROM otel_traces WHERE TraceId = '.Sql::quote($traceId)
            .' ORDER BY Timestamp ASC LIMIT 5000';

        $spans = [];

        /** @var array<string, array<string, mixed>> $services */
        $services = [];

        foreach ($this->select($sql) as $row) {
            $start = (int) ($row['StartNano'] ?? 0);
            $parent = is_string($row['ParentSpanId'] ?? null) ? $row['ParentSpanId'] : '';
            $service = is_string($row['ServiceName'] ?? null) ? $row['ServiceName'] : '';

            $spans[] = new Span(
                spanId: is_string($row['SpanId'] ?? null) ? $row['SpanId'] : '',
                parentSpanId: $parent !== '' ? $parent : null,
                name: is_string($row['SpanName'] ?? null) ? $row['SpanName'] : '',
                serviceName: $service,
                kind: SpanKind::tryFrom(strtolower(is_string($row['SpanKind'] ?? null) ? $row['SpanKind'] : '')) ?? SpanKind::Unspecified,
                startNano: $start,
                endNano: $start + (int) ($row['Duration'] ?? 0),
                attributes: self::map($row['SpanAttributes'] ?? null),
                hasError: ($row['StatusCode'] ?? null) === 'Error',
                links: self::links($row['LinkTraceIds'] ?? null, $row['LinkSpanIds'] ?? null),
            );

            if ($service !== '' && ! isset($services[$service])) {
                $services[$service] = self::map($row['ResourceAttributes'] ?? null);
            }
        }

        return new Trace($traceId, $spans, $services);
    }

    public function tagValues(string $tag, ?TraceQuery $filter = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null, int $limit = 0): array
    {
        $expr = self::tagExpr($tag);

        $where = ["{$expr} != ''"];

        if ($start !== null && $end !== null) {
            $where[] = 'Timestamp >= fromUnixTimestamp('.$start->getTimestamp().')';
            $where[] = 'Timestamp <= fromUnixTimestamp('.$end->getTimestamp().')';
        }

        if ($filter !== null && $filter->raw === null) {
            $where = array_merge($where, array_map(TraceFields::condition(...), $filter->conditions));
        }

        $sql = "SELECT DISTINCT {$expr} AS v FROM otel_traces WHERE ".implode(' AND ', $where)
            .' ORDER BY v LIMIT '.($limit > 0 ? $limit : 1000);

        return array_values(array_filter(array_map(
            static fn (array $row): string => is_string($row['v'] ?? null) ? $row['v'] : '',
            $this->select($sql),
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function summary(string $traceId, array $rows): TraceSummary
    {
        // Prefer a root span (no parent) for the display name; else the earliest.
        usort($rows, static fn (array $a, array $b): int => (int) ($a['StartNano'] ?? 0) <=> (int) ($b['StartNano'] ?? 0));

        $root = $rows[0];
        foreach ($rows as $row) {
            if (($row['ParentSpanId'] ?? '') === '') {
                $root = $row;
                break;
            }
        }

        $matched = array_map(
            static fn (array $row): MatchedSpan => new MatchedSpan(
                spanId: is_string($row['SpanId'] ?? null) ? $row['SpanId'] : '',
                name: is_string($row['SpanName'] ?? null) ? $row['SpanName'] : '',
                startNano: (int) ($row['StartNano'] ?? 0),
                durationMs: (int) ($row['Duration'] ?? 0) / 1_000_000,
                attributes: self::map($row['SpanAttributes'] ?? null),
            ),
            $rows,
        );

        return new TraceSummary(
            traceId: $traceId,
            rootServiceName: is_string($root['ServiceName'] ?? null) ? $root['ServiceName'] : '',
            rootTraceName: is_string($root['SpanName'] ?? null) ? $root['SpanName'] : '',
            startedAt: new DateTimeImmutable('@'.intdiv((int) ($root['StartNano'] ?? 0), 1_000_000_000)),
            durationMs: (int) ($root['Duration'] ?? 0) / 1_000_000,
            matchedSpans: $matched,
        );
    }

    private static function tagExpr(string $tag): string
    {
        $tag = ltrim($tag, '.');

        return match (true) {
            $tag === 'service.name', $tag === 'resource.service.name' => 'ServiceName',
            $tag === 'name' => 'SpanName',
            str_starts_with($tag, 'resource.') => 'ResourceAttributes['.Sql::quote(substr($tag, 9)).']',
            str_starts_with($tag, 'span.') => 'SpanAttributes['.Sql::quote(substr($tag, 5)).']',
            default => 'SpanAttributes['.Sql::quote($tag).']',
        };
    }

    /**
     * Zip the parallel `Links.TraceId` / `Links.SpanId` arrays into the UI's
     * span-link shape.
     *
     * @param  mixed  $traceIds
     * @param  mixed  $spanIds
     * @return list<array{traceId: string, spanId: string}>
     */
    private static function links($traceIds, $spanIds): array
    {
        if (! is_array($traceIds) || ! is_array($spanIds)) {
            return [];
        }

        $traceIds = array_values($traceIds);
        $spanIds = array_values($spanIds);
        $links = [];

        foreach ($traceIds as $i => $traceId) {
            if (is_string($traceId) && $traceId !== '' && isset($spanIds[$i]) && is_string($spanIds[$i])) {
                $links[] = ['traceId' => $traceId, 'spanId' => $spanIds[$i]];
            }
        }

        return $links;
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    private static function map($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /** The attribute key a carried field lands under (drop the `span.`/`resource.` scope). */
    private static function attrName(string $field): string
    {
        return match (true) {
            str_starts_with($field, 'span.') => substr($field, 5),
            str_starts_with($field, 'resource.') => substr($field, 9),
            default => $field,
        };
    }

    /** ClickHouse returns UInt64/aggregate values as JSON numbers or strings; coerce either to float. */
    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** A quantile like 0.95 as an unlocalised SQL literal. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function select(string $sql): array
    {
        try {
            return $this->client->select($sql);
        } catch (Throwable $exception) {
            throw SourceException::because('The ClickHouse store trace query failed.');
        }
    }
}
