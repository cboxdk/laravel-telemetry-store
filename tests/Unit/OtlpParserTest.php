<?php

declare(strict_types=1);

use Cbox\TelemetryStore\Ingest\OtlpParser;
use Cbox\TelemetryStore\Ingest\PromName;

it('maps OTLP log records to otel_logs rows', function (): void {
    $rows = (new OtlpParser)->logs([
        'resourceLogs' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ['key' => 'deployment.environment.name', 'value' => ['stringValue' => 'prod']],
            ]],
            'scopeLogs' => [[
                'logRecords' => [[
                    'timeUnixNano' => '1712345678123456789',
                    'severityText' => 'ERROR',
                    'traceId' => 'abc123',
                    'body' => ['stringValue' => 'analytics.page_view'],
                    'attributes' => [
                        ['key' => 'session.id', 'value' => ['stringValue' => 's-1']],
                        ['key' => 'exception.group', 'value' => ['stringValue' => 'g-9']],
                    ],
                ]],
            ]],
        ]],
    ]);

    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    expect($row['ServiceName'])->toBe('checkout')
        ->and($row['Body'])->toBe('analytics.page_view')
        ->and($row['SeverityText'])->toBe('ERROR')
        ->and($row['TraceId'])->toBe('abc123')
        ->and($row['Timestamp'])->toBe('2024-04-05 19:34:38.123456789')
        ->and($row['ResourceAttributes'])->toBe([
            'service.name' => 'checkout',
            'deployment.environment.name' => 'prod',
        ])
        ->and($row['LogAttributes'])->toBe(['session.id' => 's-1', 'exception.group' => 'g-9']);
});

it('splits OTLP metrics into sum, gauge and histogram rows', function (): void {
    $out = (new OtlpParser)->metrics([
        'resourceMetrics' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'api']]]],
            'scopeMetrics' => [[
                'metrics' => [
                    ['name' => 'queue.jobs.processed', 'sum' => [
                        'isMonotonic' => true,
                        'dataPoints' => [['timeUnixNano' => '1000000000', 'asInt' => '42', 'attributes' => [['key' => 'queue', 'value' => ['stringValue' => 'default']]]]],
                    ]],
                    ['name' => 'queue.depth', 'gauge' => [
                        'dataPoints' => [['timeUnixNano' => '1000000000', 'asInt' => '7']],
                    ]],
                    ['name' => 'http.server.request.duration', 'unit' => 'ms', 'histogram' => [
                        'dataPoints' => [['timeUnixNano' => '1000000000', 'count' => '3', 'sum' => 12.0, 'bucketCounts' => ['1', '2'], 'explicitBounds' => [10.0]]],
                    ]],
                ],
            ]],
        ]],
    ]);

    // Stored under the emitter's Prometheus names: monotonic counter → _total,
    // gauge → bare, histogram → base + unit suffix.
    expect($out['sum'])->toHaveCount(1)
        ->and($out['sum'][0]['MetricName'])->toBe('queue_jobs_processed_total')
        ->and($out['sum'][0]['Value'])->toBe(42.0)
        ->and($out['sum'][0]['IsMonotonic'])->toBeTrue()
        ->and($out['sum'][0]['Attributes'])->toBe(['queue' => 'default'])
        ->and($out['gauge'])->toHaveCount(1)
        ->and($out['gauge'][0]['MetricName'])->toBe('queue_depth')
        ->and($out['gauge'][0]['Value'])->toBe(7.0)
        ->and($out['histogram'])->toHaveCount(1)
        ->and($out['histogram'][0]['MetricName'])->toBe('http_server_request_duration_milliseconds')
        ->and($out['histogram'][0]['Count'])->toBe(3)
        ->and($out['histogram'][0]['BucketCounts'])->toBe([1, 2])
        ->and($out['histogram'][0]['ExplicitBounds'])->toBe([10.0]);
});

it('maps OTLP metric names to the emitter Prometheus names', function (): void {
    expect(PromName::from('db.queries', '', true))->toBe('db_queries_total')
        ->and(PromName::from('http.server.request.duration', 'ms', false))->toBe('http_server_request_duration_milliseconds')
        ->and(PromName::from('http.server.memory.peak', 'By', false))->toBe('http_server_memory_peak_bytes')
        ->and(PromName::from('system.cpu.utilization', '1', false))->toBe('system_cpu_utilization')
        ->and(PromName::from('queue.size', '{jobs}', false))->toBe('queue_size');
});

it('maps span kind and status to the collector spellings', function (): void {
    $rows = (new OtlpParser)->traces([
        'resourceSpans' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'web']]]],
            'scopeSpans' => [[
                'spans' => [[
                    'traceId' => 't1', 'spanId' => 's1', 'name' => 'GET /',
                    'kind' => 2, 'startTimeUnixNano' => '1000', 'endTimeUnixNano' => '3000',
                    'status' => ['code' => 2],
                    'attributes' => [['key' => 'http.route', 'value' => ['stringValue' => '/']]],
                ]],
            ]],
        ]],
    ]);

    expect($rows[0]['SpanKind'])->toBe('Server')
        ->and($rows[0]['StatusCode'])->toBe('Error')
        ->and($rows[0]['Duration'])->toBe(2000)
        ->and($rows[0]['SpanAttributes'])->toBe(['http.route' => '/']);
});
