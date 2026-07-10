<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\Read\ClickHouseLogsSource;
use Cbox\TelemetryStore\Read\ClickHouseMetricsSource;
use Cbox\TelemetryStore\Read\ClickHouseTracesSource;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\SpanSort;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
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

it('aggregates spans server-side via the raw scan: GROUP BY the attribute with duration stats in ms', function (): void {
    // Durations come back in nanoseconds; the driver divides by 1e6 → ms.
    chRespond([
        [
            'k' => 'select * from users where id = ?',
            'c' => '1200',
            'a' => 2_500_000,
            'p' => 9_000_000,
            'mx' => 42_000_000,
            's' => 3_000_000_000,
            'carry_0' => 'mysql',
        ],
        [
            'k' => 'select * from orders where user_id = ?',
            'c' => '300',
            'a' => 1_000_000,
            'p' => 4_000_000,
            'mx' => 8_000_000,
            's' => 300_000_000,
            'carry_0' => 'mysql',
        ],
    ]);

    // A min-duration filter on top of the presence check forces the exact raw
    // scan (the rollup can't answer a duration threshold).
    $aggregation = new SpanAggregation(
        where: (new TraceQuery)->where(
            TraceCondition::nil('span.db.query.text'),
            TraceCondition::token('duration', TraceOp::Gt, '1ms'),
        ),
        groupBy: 'span.db.query.text',
        carry: ['span.db.system.name'],
        limit: 50,
        sort: SpanSort::Total,
    );

    $buckets = (new ClickHouseTracesSource(chClient()))->aggregateSpans(
        $aggregation,
        new DateTimeImmutable('@1712345000'),
        new DateTimeImmutable('@1712346000'),
    );

    expect($buckets)->toHaveCount(2)
        ->and($buckets[0]->key)->toBe('select * from users where id = ?')
        ->and($buckets[0]->count)->toBe(1200)
        ->and($buckets[0]->avgMs)->toBe(2.5)
        ->and($buckets[0]->p95Ms)->toBe(9.0)
        ->and($buckets[0]->maxMs)->toBe(42.0)
        ->and($buckets[0]->totalMs)->toBe(3000.0)
        ->and($buckets[0]->attributes['db.system.name'])->toBe('mysql');

    Http::assertSent(function ($request): bool {
        $sql = $request->body();

        return str_contains($sql, 'FROM otel_traces')
            && str_contains($sql, "SpanAttributes['db.query.text'] AS k")
            && str_contains($sql, 'count() AS c')
            && str_contains($sql, 'quantile(0.95)(Duration) AS p')
            && str_contains($sql, 'sum(Duration) AS s')
            && str_contains($sql, "any(SpanAttributes['db.system.name']) AS carry_0")
            && str_contains($sql, "SpanAttributes['db.query.text'] != ''")
            && str_contains($sql, 'GROUP BY k')
            && str_contains($sql, 'ORDER BY s DESC')
            && str_contains($sql, 'LIMIT 50');
    });
});

it('rejects a raw TraceQL aggregation', function (): void {
    chRespond([]);

    (new ClickHouseTracesSource(chClient()))->aggregateSpans(
        new SpanAggregation(where: TraceQuery::raw('{ span.db.system.name = "mysql" }'), groupBy: 'span.db.query.text'),
        new DateTimeImmutable('@0'),
        new DateTimeImmutable('@1'),
    );
})->throws(SourceException::class);

it('serves the default db-query ranking from the minute rollup, not a raw span scan', function (): void {
    // Same SpanBucket shape as the raw path, but read from the summary table.
    chRespond([[
        'k' => 'select * from users where id = ?',
        'c' => '1200', 'a' => 2_500_000, 'p' => 9_000_000, 'mx' => 42_000_000, 's' => 3_000_000_000,
        'carry_0' => 'mysql',
    ]]);

    $aggregation = new SpanAggregation(
        where: (new TraceQuery)->where(TraceCondition::nil('span.db.query.text')),
        groupBy: 'span.db.query.text',
        carry: ['span.db.system.name'],
        sort: SpanSort::Total,
    );

    $buckets = (new ClickHouseTracesSource(chClient()))->aggregateSpans(
        $aggregation, new DateTimeImmutable('@1712345000'), new DateTimeImmutable('@1712346000'),
    );

    expect($buckets)->toHaveCount(1)
        ->and($buckets[0]->totalMs)->toBe(3000.0)
        ->and($buckets[0]->avgMs)->toBe(2.5)
        ->and($buckets[0]->attributes['db.system.name'])->toBe('mysql');

    Http::assertSent(function ($request): bool {
        $sql = $request->body();

        return str_contains($sql, 'FROM otel_db_query_summary')
            && str_contains($sql, 'sum(DurationSum) AS s')
            && str_contains($sql, 'quantileTDigestMerge(0.95)(DurationQuantile) AS p')
            && str_contains($sql, 'any(DbSystem) AS carry_0')
            && str_contains($sql, 'ORDER BY s DESC')
            && ! str_contains($sql, 'otel_traces');
    });
});

it('falls back to the exact raw span scan when the aggregation carries an extra filter', function (): void {
    chRespond([]);

    // A min-duration threshold on top of the presence check: the rollup cannot
    // answer it (durations were pre-aggregated), so it must scan otel_traces.
    $aggregation = new SpanAggregation(
        where: (new TraceQuery)->where(
            TraceCondition::nil('span.db.query.text'),
            TraceCondition::token('duration', TraceOp::Gt, '50ms'),
        ),
        groupBy: 'span.db.query.text',
        carry: ['span.db.system.name'],
    );

    (new ClickHouseTracesSource(chClient()))->aggregateSpans(
        $aggregation, new DateTimeImmutable('@0'), new DateTimeImmutable('@1'),
    );

    Http::assertSent(function ($request): bool {
        $sql = $request->body();

        return str_contains($sql, 'FROM otel_traces')
            && str_contains($sql, 'Duration > 50000000')
            && ! str_contains($sql, 'otel_db_query_summary');
    });
});
