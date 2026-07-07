<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LineFilter;
use Cbox\TelemetryUi\Queries\Ir\LineOp;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\LogStage;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use DateTimeInterface;

/**
 * Reads logs from ClickHouse `otel_logs`, compiling the UI's backend-neutral
 * {@see LogQuery} to SQL. Registered as the `clickhouse-logs` driver via
 * `TelemetryUi::extend()`; every log card runs against it unchanged.
 */
final readonly class ClickHouseLogsSource implements LogsSource
{
    private const COLUMNS = 'toUnixTimestamp64Nano(Timestamp) AS TimestampNano, TraceId, SpanId, SeverityText, ServiceName, Body, ResourceAttributes, LogAttributes';

    public function __construct(private Client $client) {}

    public function query(LogQuery $query, DateTimeInterface $start, DateTimeInterface $end, int $limit = 100): array
    {
        if ($query->raw !== null) {
            throw SourceException::because('The ClickHouse logs driver cannot run a raw LogQL query; use the structured LogQuery API.');
        }

        $where = array_merge(
            [$this->timeRange($start, $end)],
            array_map(fn (LabelMatcher $m): string => $this->matcher(Labels::logExpression($m->label), $m), $query->stream),
            array_map($this->stage(...), $query->pipeline),
        );

        $sql = 'SELECT '.self::COLUMNS.' FROM otel_logs WHERE '.implode(' AND ', array_filter($where))
            .' ORDER BY Timestamp DESC LIMIT '.max(1, $limit);

        $entries = array_map(
            static fn (array $row): LogEntry => new LogEntry(
                timestampNano: (int) ($row['TimestampNano'] ?? 0),
                line: is_string($row['Body'] ?? null) ? $row['Body'] : '',
                labels: Labels::fromLogRow($row),
            ),
            $this->select($sql),
        );

        // The contract returns ascending; we queried newest-first for the LIMIT.
        usort($entries, static fn (LogEntry $a, LogEntry $b): int => $a->timestampNano <=> $b->timestampNano);

        return $entries;
    }

    public function labelValues(string $label, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): array
    {
        $expr = Labels::logExpression($label);

        $where = ["{$expr} != ''"];

        if ($start !== null && $end !== null) {
            $where[] = $this->timeRange($start, $end);
        }

        $sql = "SELECT DISTINCT {$expr} AS v FROM otel_logs WHERE ".implode(' AND ', $where).' ORDER BY v LIMIT 1000';

        return array_values(array_filter(array_map(
            static fn (array $row): string => is_string($row['v'] ?? null) ? $row['v'] : '',
            $this->select($sql),
        ), static fn (string $v): bool => $v !== ''));
    }

    private function stage(LogStage $stage): string
    {
        if ($stage instanceof LineFilter) {
            $value = Sql::quote($stage->value);

            return match ($stage->op) {
                LineOp::Contains => "position(Body, {$value}) > 0",
                LineOp::NotContains => "position(Body, {$value}) = 0",
                LineOp::Regex => "match(Body, {$value})",
                LineOp::NotRegex => "NOT match(Body, {$value})",
            };
        }

        if ($stage instanceof LabelFilter) {
            $conditions = array_map(
                fn (LabelMatcher $m): string => $this->matcher(Labels::logExpression($m->label), $m),
                $stage->matchers,
            );

            return '('.implode($stage->or ? ' OR ' : ' AND ', $conditions).')';
        }

        throw SourceException::because('Unsupported log stage: '.$stage::class);
    }

    private function matcher(string $expr, LabelMatcher $matcher): string
    {
        $value = Sql::quote($matcher->value);

        return match ($matcher->op) {
            MatchOp::Eq => "{$expr} = {$value}",
            MatchOp::Neq => "{$expr} != {$value}",
            MatchOp::Re => "match({$expr}, {$value})",
            MatchOp::Nre => "NOT match({$expr}, {$value})",
        };
    }

    private function timeRange(DateTimeInterface $start, DateTimeInterface $end): string
    {
        return 'Timestamp >= fromUnixTimestamp('.$start->getTimestamp().') AND Timestamp <= fromUnixTimestamp('.$end->getTimestamp().')';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function select(string $sql): array
    {
        try {
            return $this->client->select($sql);
        } catch (\Throwable $exception) {
            throw SourceException::because('The ClickHouse store log query failed.');
        }
    }
}
