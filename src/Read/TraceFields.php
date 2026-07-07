<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;

/**
 * Compiles a single {@see TraceCondition} to a ClickHouse boolean expression
 * over `otel_traces`, translating TraceQL fields and value tokens to columns and
 * literals:
 *  - `resource.service.name` → ServiceName, `resource.*` → ResourceAttributes[*]
 *  - `span.*` → SpanAttributes[*] (dotted key, `span.` stripped)
 *  - intrinsics `name`/`status`/`kind`/`duration` → SpanName/StatusCode/SpanKind/Duration
 *  - tokens `error`/`server`/`true`/`nil`/`100ms`/`500` → the right literal/compare
 */
final class TraceFields
{
    public static function condition(TraceCondition $c): string
    {
        // Numeric / duration comparisons.
        if (in_array($c->op, [TraceOp::Gt, TraceOp::Gte, TraceOp::Lt, TraceOp::Lte], true)) {
            $op = $c->op->value;

            if ($c->field === 'duration') {
                return 'Duration '.$op.' '.self::durationNs($c->value);
            }

            return 'toInt64OrZero('.self::expr($c->field).') '.$op.' '.self::intLiteral($c->value);
        }

        // Regex.
        if ($c->op === TraceOp::Re || $c->op === TraceOp::Nre) {
            $match = 'match('.self::expr($c->field).', '.Sql::quote($c->value).')';

            return $c->op === TraceOp::Nre ? 'NOT '.$match : $match;
        }

        // Equality / inequality (Eq | Neq).
        $eq = $c->op === TraceOp::Eq;

        // Presence checks: `!= nil` / `= nil`.
        if (! $c->quoted && $c->value === 'nil') {
            return self::expr($c->field).($eq ? " = ''" : " != ''");
        }

        [$expr, $literal] = self::intrinsic($c->field, $c->value, $c->quoted);

        return $expr.($eq ? ' = ' : ' != ').$literal;
    }

    /**
     * Resolve intrinsic field + token pairs to (expr, literal). `status`/`kind`
     * tokens map to the stored TitleCase, `true/false` stay string literals in
     * the attribute map.
     *
     * @return array{string, string}
     */
    private static function intrinsic(string $field, string $value, bool $quoted): array
    {
        return match ($field) {
            'status' => ['StatusCode', Sql::quote(ucfirst($value))],
            'kind' => ['SpanKind', Sql::quote(ucfirst($value))],
            default => [self::expr($field), Sql::quote($value)],
        };
    }

    private static function expr(string $field): string
    {
        return match (true) {
            $field === 'name' => 'SpanName',
            $field === 'status' => 'StatusCode',
            $field === 'kind' => 'SpanKind',
            $field === 'duration' => 'Duration',
            $field === 'resource.service.name' => 'ServiceName',
            str_starts_with($field, 'resource.') => 'ResourceAttributes['.Sql::quote(substr($field, 9)).']',
            str_starts_with($field, 'span.') => 'SpanAttributes['.Sql::quote(substr($field, 5)).']',
            default => 'SpanAttributes['.Sql::quote($field).']',
        };
    }

    /** `100ms` / `5s` / `250us` / `2m` → nanoseconds. */
    private static function durationNs(string $value): string
    {
        if (preg_match('/^([0-9.]+)\s*(ns|us|µs|ms|s|m|h)?$/', trim($value), $m) !== 1) {
            return '0';
        }

        $n = (float) $m[1];
        $unit = $m[2] ?? 'ms';

        $factor = match ($unit) {
            'ns' => 1.0,
            'us', 'µs' => 1_000.0,
            's' => 1_000_000_000.0,
            'm' => 60_000_000_000.0,
            'h' => 3_600_000_000_000.0,
            default => 1_000_000.0,
        };

        return (string) (int) round($n * $factor);
    }

    private static function intLiteral(string $value): string
    {
        return is_numeric($value) ? (string) (int) $value : '0';
    }
}
