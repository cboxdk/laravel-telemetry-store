<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\Ingest\OtlpParser;
use Cbox\TelemetryStore\Read\ClickHouseLogsSource;
use Cbox\TelemetryStore\Read\ClickHouseMetricsSource;
use Cbox\TelemetryStore\Read\ClickHouseTracesSource;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Illuminate\Http\Client\Factory as HttpFactory;

it('parses empty / missing OTLP payloads to empty rows', function (): void {
    $parser = new OtlpParser;

    expect($parser->logs([]))->toBe([])
        ->and($parser->traces([]))->toBe([])
        ->and($parser->metrics([]))->toBe(['sum' => [], 'gauge' => [], 'histogram' => []])
        ->and($parser->logs(['resourceLogs' => 'nonsense']))->toBe([]);
});

it('defaults a missing service name', function (): void {
    $rows = (new OtlpParser)->logs([
        'resourceLogs' => [[
            'scopeLogs' => [['logRecords' => [['timeUnixNano' => '1000000000', 'body' => ['stringValue' => 'x']]]]],
        ]],
    ]);

    expect($rows[0]['ServiceName'])->toBe('unknown_service');
});

it('rejects raw queries on every ClickHouse driver', function (): void {
    $client = new Client(new HttpFactory, 'http://clickhouse.invalid:8123', 'telemetry');
    $start = new DateTimeImmutable('-1 hour');
    $end = new DateTimeImmutable;

    expect(fn () => (new ClickHouseLogsSource($client))->query(LogQuery::raw('{x="y"}'), $start, $end))
        ->toThrow(SourceException::class);

    expect(fn () => (new ClickHouseTracesSource($client))->search(TraceQuery::raw('{}'), $start, $end))
        ->toThrow(SourceException::class);

    expect(fn () => (new ClickHouseMetricsSource($client))->query(MetricQuery::raw('up')))
        ->toThrow(SourceException::class);
});
