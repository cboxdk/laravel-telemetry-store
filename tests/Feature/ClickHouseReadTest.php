<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\Read\ClickHouseLogsSource;
use Cbox\TelemetryStore\Read\ClickHouseMetricsSource;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function chClient(): Client
{
    return new Client(app(HttpFactory::class), 'http://clickhouse.test:8123', 'telemetry');
}

function chRespond(array $data): void
{
    Http::fake(['clickhouse.test:8123/*' => Http::response(['data' => $data])]);
}

it('compiles a LogQuery to SQL and maps rows back to LogEntries', function (): void {
    chRespond([[
        'TimestampNano' => '1712345678123456789',
        'TraceId' => 't-1',
        'ServiceName' => 'checkout',
        'SeverityText' => 'error',
        'Body' => 'boom',
        'ResourceAttributes' => [],
        'LogAttributes' => ['exception.group' => 'g-1'],
    ]]);

    $query = LogQuery::stream(LabelMatcher::eq('service_name', 'checkout'))
        ->whereLabel('exception_group', MatchOp::Neq, '');

    $entries = (new ClickHouseLogsSource(chClient()))->query(
        $query,
        new DateTimeImmutable('@1712345000'),
        new DateTimeImmutable('@1712346000'),
        limit: 50,
    );

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->line)->toBe('boom')
        ->and($entries[0]->timestampNano)->toBe(1712345678123456789)
        ->and($entries[0]->labels['service_name'])->toBe('checkout')
        ->and($entries[0]->labels['exception_group'])->toBe('g-1');

    Http::assertSent(function ($request): bool {
        $sql = $request->body();

        return str_contains($sql, 'FROM otel_logs')
            && str_contains($sql, "ServiceName = 'checkout'")
            && str_contains($sql, "if(mapContains(LogAttributes, 'exception.group')")
            && str_contains($sql, 'LIMIT 50');
    });
});

it('rejects a raw LogQL query', function (): void {
    chRespond([]);

    (new ClickHouseLogsSource(chClient()))->query(
        LogQuery::raw('{service_name="x"}'),
        new DateTimeImmutable('@0'),
        new DateTimeImmutable('@1'),
    );
})->throws(SourceException::class);

it('reads a gauge metric as its latest value', function (): void {
    chRespond([['queue' => 'default', 'v' => 7]]);

    $samples = (new ClickHouseMetricsSource(chClient()))->query(
        (new MetricQuery('queue_depth'))->maxBy('queue'),
    );

    expect($samples)->toHaveCount(1)
        ->and($samples[0]->value)->toBe(7.0)
        ->and($samples[0]->labels)->toBe(['queue' => 'default']);

    Http::assertSent(function ($request): bool {
        $sql = $request->body();

        return str_contains($sql, 'FROM otel_metrics_gauge')
            && str_contains($sql, "MetricName = 'queue_depth'")
            && str_contains($sql, "Attributes['queue'] AS `queue`");
    });
});
