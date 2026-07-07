<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Ingest;

use Cbox\TelemetryStore\ClickHouse\Schema;

/**
 * Maps decoded OTLP/HTTP JSON payloads into ClickHouse rows matching
 * {@see Schema}. One method per signal; each
 * returns plain arrays ready for {@see StoreWriter}. Resource attributes are
 * flattened once per batch and merged onto every row.
 */
final class OtlpParser
{
    /**
     * @param  array<string, mixed>  $payload  decoded `{"resourceLogs": [...]}`
     * @return list<array<string, mixed>>
     */
    public function logs(array $payload): array
    {
        $rows = [];

        foreach (self::listOf($payload, 'resourceLogs') as $resourceLogs) {
            $resource = Otlp::attributes(self::resourceAttributes($resourceLogs));
            $service = Otlp::serviceName($resource);

            foreach (self::listOf($resourceLogs, 'scopeLogs', 'instrumentationLibraryLogs') as $scope) {
                foreach (self::listOf($scope, 'logRecords', 'log_records') as $record) {
                    if (! is_array($record)) {
                        continue;
                    }

                    $rows[] = [
                        'Timestamp' => Otlp::nanoToDateTime64($record['timeUnixNano'] ?? $record['observedTimeUnixNano'] ?? 0),
                        'TraceId' => Otlp::string($record['traceId'] ?? ''),
                        'SpanId' => Otlp::string($record['spanId'] ?? ''),
                        'SeverityText' => Otlp::string($record['severityText'] ?? ''),
                        'SeverityNumber' => Otlp::int($record['severityNumber'] ?? 0),
                        'ServiceName' => $service,
                        'Body' => Otlp::scalar($record['body'] ?? null),
                        'ResourceAttributes' => Otlp::map($resource),
                        'LogAttributes' => Otlp::attributeMap($record['attributes'] ?? null),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload  decoded `{"resourceSpans": [...]}`
     * @return list<array<string, mixed>>
     */
    public function traces(array $payload): array
    {
        $rows = [];

        foreach (self::listOf($payload, 'resourceSpans') as $resourceSpans) {
            $resource = Otlp::attributes(self::resourceAttributes($resourceSpans));
            $service = Otlp::serviceName($resource);

            foreach (self::listOf($resourceSpans, 'scopeSpans', 'instrumentationLibrarySpans') as $scope) {
                foreach (self::listOf($scope, 'spans') as $span) {
                    if (! is_array($span)) {
                        continue;
                    }

                    $start = Otlp::int($span['startTimeUnixNano'] ?? 0);
                    $end = Otlp::int($span['endTimeUnixNano'] ?? 0);
                    $status = is_array($span['status'] ?? null) ? $span['status'] : [];

                    $rows[] = [
                        'Timestamp' => Otlp::nanoToDateTime64($start),
                        'TraceId' => Otlp::string($span['traceId'] ?? ''),
                        'SpanId' => Otlp::string($span['spanId'] ?? ''),
                        'ParentSpanId' => Otlp::string($span['parentSpanId'] ?? ''),
                        'SpanName' => Otlp::string($span['name'] ?? ''),
                        'SpanKind' => self::spanKind($span['kind'] ?? null),
                        'ServiceName' => $service,
                        'ResourceAttributes' => Otlp::map($resource),
                        'SpanAttributes' => Otlp::attributeMap($span['attributes'] ?? null),
                        'Duration' => max(0, $end - $start),
                        'StatusCode' => self::statusCode($status['code'] ?? null),
                        'StatusMessage' => Otlp::string($status['message'] ?? ''),
                        ...self::events($span),
                        ...self::links($span),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * A span's events as the parallel-array form ClickHouse's JSONEachRow wants
     * for the `Events` Nested column. Empty individual attribute maps become
     * objects (empty Map = `{}`).
     *
     * @param  array<string, mixed>  $span
     * @return array{'Events.Timestamp': list<string>, 'Events.Name': list<string>, 'Events.Attributes': list<object|array<string, string>>}
     */
    private static function events(array $span): array
    {
        $timestamps = $names = $attributes = [];

        foreach (self::listOf($span, 'events') as $event) {
            if (! is_array($event)) {
                continue;
            }

            $timestamps[] = Otlp::nanoToDateTime64($event['timeUnixNano'] ?? 0);
            $names[] = Otlp::string($event['name'] ?? '');
            $attributes[] = Otlp::attributeMap($event['attributes'] ?? null);
        }

        return ['Events.Timestamp' => $timestamps, 'Events.Name' => $names, 'Events.Attributes' => $attributes];
    }

    /**
     * A span's links as the parallel-array form for the `Links` Nested column.
     *
     * @param  array<string, mixed>  $span
     * @return array{'Links.TraceId': list<string>, 'Links.SpanId': list<string>, 'Links.Attributes': list<object|array<string, string>>}
     */
    private static function links(array $span): array
    {
        $traceIds = $spanIds = $attributes = [];

        foreach (self::listOf($span, 'links') as $link) {
            if (! is_array($link)) {
                continue;
            }

            $traceIds[] = Otlp::string($link['traceId'] ?? '');
            $spanIds[] = Otlp::string($link['spanId'] ?? '');
            $attributes[] = Otlp::attributeMap($link['attributes'] ?? null);
        }

        return ['Links.TraceId' => $traceIds, 'Links.SpanId' => $spanIds, 'Links.Attributes' => $attributes];
    }

    /**
     * @param  array<string, mixed>  $payload  decoded `{"resourceMetrics": [...]}`
     * @return array{sum: list<array<string, mixed>>, gauge: list<array<string, mixed>>, histogram: list<array<string, mixed>>}
     */
    public function metrics(array $payload): array
    {
        $out = ['sum' => [], 'gauge' => [], 'histogram' => []];

        foreach (self::listOf($payload, 'resourceMetrics') as $resourceMetrics) {
            $resource = Otlp::attributes(self::resourceAttributes($resourceMetrics));
            $service = Otlp::serviceName($resource);

            foreach (self::listOf($resourceMetrics, 'scopeMetrics', 'instrumentationLibraryMetrics') as $scope) {
                foreach (self::listOf($scope, 'metrics') as $metric) {
                    if (! is_array($metric)) {
                        continue;
                    }

                    // Store under the emitter's Prometheus name (dots→underscores +
                    // unit suffix + _total for monotonic counters), so the cards'
                    // Prometheus-style queries match. OTLP carries the unit separately.
                    $otlpName = Otlp::string($metric['name'] ?? '');
                    $unit = Otlp::string($metric['unit'] ?? '');

                    if (is_array($metric['sum'] ?? null)) {
                        $monotonic = (bool) ($metric['sum']['isMonotonic'] ?? false);
                        $name = PromName::from($otlpName, $unit, $monotonic);

                        foreach (self::listOf($metric['sum'], 'dataPoints', 'data_points') as $point) {
                            $out['sum'][] = self::numberPoint($name, $service, $resource, $point) + [
                                'AggregationTemporality' => Otlp::int($metric['sum']['aggregationTemporality'] ?? 0),
                                'IsMonotonic' => $monotonic,
                            ];
                        }
                    } elseif (is_array($metric['gauge'] ?? null)) {
                        $name = PromName::from($otlpName, $unit, false);

                        foreach (self::listOf($metric['gauge'], 'dataPoints', 'data_points') as $point) {
                            $out['gauge'][] = self::numberPoint($name, $service, $resource, $point);
                        }
                    } elseif (is_array($metric['histogram'] ?? null)) {
                        // Base name only; _bucket/_count/_sum are reconstructed at read time.
                        $name = PromName::from($otlpName, $unit, false);

                        foreach (self::listOf($metric['histogram'], 'dataPoints', 'data_points') as $point) {
                            $out['histogram'][] = self::histogramPoint($name, $service, $resource, $point, Otlp::int($metric['histogram']['aggregationTemporality'] ?? 0));
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $resource
     * @param  mixed  $point
     * @return array<string, mixed>
     */
    private static function numberPoint(string $name, string $service, array $resource, $point): array
    {
        $point = is_array($point) ? $point : [];

        return [
            'Timestamp' => Otlp::nanoToDateTime64($point['timeUnixNano'] ?? 0),
            'StartTimestamp' => Otlp::nanoToDateTime64($point['startTimeUnixNano'] ?? 0),
            'MetricName' => $name,
            'ServiceName' => $service,
            'ResourceAttributes' => $resource,
            'Attributes' => Otlp::attributeMap($point['attributes'] ?? null),
            'Value' => array_key_exists('asInt', $point) ? Otlp::float($point['asInt']) : Otlp::float($point['asDouble'] ?? 0),
        ];
    }

    /**
     * @param  array<string, string>  $resource
     * @param  mixed  $point
     * @return array<string, mixed>
     */
    private static function histogramPoint(string $name, string $service, array $resource, $point, int $temporality): array
    {
        $point = is_array($point) ? $point : [];

        return [
            'Timestamp' => Otlp::nanoToDateTime64($point['timeUnixNano'] ?? 0),
            'StartTimestamp' => Otlp::nanoToDateTime64($point['startTimeUnixNano'] ?? 0),
            'MetricName' => $name,
            'ServiceName' => $service,
            'ResourceAttributes' => $resource,
            'Attributes' => Otlp::attributeMap($point['attributes'] ?? null),
            'Count' => Otlp::int($point['count'] ?? 0),
            'Sum' => Otlp::float($point['sum'] ?? 0),
            'BucketCounts' => array_map(Otlp::int(...), self::listOf($point, 'bucketCounts', 'bucket_counts')),
            'ExplicitBounds' => array_map(Otlp::float(...), self::listOf($point, 'explicitBounds', 'explicit_bounds')),
            'AggregationTemporality' => $temporality,
        ];
    }

    /**
     * The resource.attributes list off a resource* envelope.
     *
     * @param  mixed  $envelope
     * @return mixed
     */
    private static function resourceAttributes($envelope)
    {
        if (! is_array($envelope)) {
            return null;
        }

        $resource = is_array($envelope['resource'] ?? null) ? $envelope['resource'] : [];

        return $resource['attributes'] ?? null;
    }

    /**
     * Read a list under the first present key, tolerating the camelCase/snake
     * proto-JSON spellings.
     *
     * @param  mixed  $node
     * @return list<mixed>
     */
    private static function listOf($node, string ...$keys): array
    {
        if (! is_array($node)) {
            return [];
        }

        foreach ($keys as $key) {
            if (is_array($node[$key] ?? null)) {
                return array_values($node[$key]);
            }
        }

        return [];
    }

    /**
     * @param  mixed  $kind
     */
    private static function spanKind($kind): string
    {
        return match (true) {
            $kind === 2 || $kind === 'SPAN_KIND_SERVER' => 'Server',
            $kind === 3 || $kind === 'SPAN_KIND_CLIENT' => 'Client',
            $kind === 4 || $kind === 'SPAN_KIND_PRODUCER' => 'Producer',
            $kind === 5 || $kind === 'SPAN_KIND_CONSUMER' => 'Consumer',
            $kind === 1 || $kind === 'SPAN_KIND_INTERNAL' => 'Internal',
            default => 'Unspecified',
        };
    }

    /**
     * @param  mixed  $code
     */
    private static function statusCode($code): string
    {
        return match (true) {
            $code === 2 || $code === 'STATUS_CODE_ERROR' => 'Error',
            $code === 1 || $code === 'STATUS_CODE_OK' => 'Ok',
            default => 'Unset',
        };
    }
}
