<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

use Cbox\TelemetryUi\Queries\Results\LogEntry;

/**
 * Bridges the UI's Loki/Prometheus-style **snake_case** label names and the
 * ClickHouse store's **dotted OTLP** attribute keys + promoted columns.
 *
 * The UI cards were written against the LGTM stack, so they filter and read
 * labels like `service_name`, `trace_id`, `level`, `exception_group`,
 * `deployment_environment_name`. In ClickHouse those live as either a promoted
 * column (ServiceName, TraceId, SpanId, SeverityText) or a `Map` entry keyed by
 * the dotted OTLP name (`exception.group`, `deployment.environment.name`). This
 * class maps a filter label to its SQL expression, and rebuilds the flat
 * snake_case label map the cards expect from a result row.
 */
final class Labels
{
    /**
     * Snake-case UI label → promoted ClickHouse column (for logs). Anything not
     * here is an attribute-map lookup under its dotted OTLP key.
     *
     * @var array<string, string>
     */
    private const LOG_COLUMNS = [
        'service_name' => 'ServiceName',
        'trace_id' => 'TraceId',
        'span_id' => 'SpanId',
        'level' => 'SeverityText',
        'detected_level' => 'SeverityText',
        'severity_text' => 'SeverityText',
    ];

    /**
     * The SQL expression a log filter on `$label` compiles to — a column when
     * promoted, otherwise a resource/log attribute lookup under the dotted key.
     */
    public static function logExpression(string $label): string
    {
        return self::LOG_COLUMNS[$label] ?? Sql::attr('LogAttributes', self::toDotted($label));
    }

    /**
     * Rebuild the flat snake_case label map a {@see LogEntry}
     * carries, from a decoded `otel_logs` row: promoted columns first, then every
     * resource + log attribute keyed by its snake_case form (so both
     * `exception_group` and analytics' dotted `session.id` lookups resolve).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public static function fromLogRow(array $row): array
    {
        $labels = [
            'service_name' => self::str($row['ServiceName'] ?? ''),
            'trace_id' => self::str($row['TraceId'] ?? ''),
            'span_id' => self::str($row['SpanId'] ?? ''),
            'level' => self::str($row['SeverityText'] ?? ''),
        ];

        foreach (['ResourceAttributes', 'LogAttributes'] as $mapColumn) {
            $map = $row[$mapColumn] ?? null;

            if (! is_array($map)) {
                continue;
            }

            foreach ($map as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                // Keep the dotted key AND a snake_case alias — the UI reads both
                // spellings (LogViewer wants `exception_group`, Analytics probes
                // `session.id` then `session_id`).
                $labels[$key] = self::str($value);
                $labels[self::toSnake($key)] = self::str($value);
            }
        }

        return array_filter($labels, static fn (string $v): bool => $v !== '');
    }

    /** `exception_group` → `exception.group` (dotted OTLP attribute key). */
    public static function toDotted(string $label): string
    {
        return str_replace('_', '.', $label);
    }

    /** `exception.group` → `exception_group` (Loki-style label). */
    public static function toSnake(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    /**
     * @param  mixed  $value
     */
    private static function str($value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
