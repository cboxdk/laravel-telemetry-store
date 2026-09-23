<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\ClickHouse\Schema;
use Cbox\TelemetryStore\Ingest\ClickHouseWriter;
use Cbox\TelemetryStore\Ingest\OtlpParser;
use Cbox\TelemetryStore\Read\ClickHouseTracesSource;
use Cbox\TelemetryStore\Tests\E2ETestCase;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\SpanSort;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Testing\TestResponse;

uses(E2ETestCase::class);

/**
 * These fetch the ACTUAL dashboard panels from telemetry-ui's JSON API against
 * a live ClickHouse — the full panel → query IR → clickhouse-* driver → SQL →
 * payload chain. Skipped when ClickHouse isn't reachable on :18123, or on a
 * telemetry-ui 1.x install (its cards were Livewire components).
 */
beforeEach(function (): void {
    if (! E2ETestCase::clickhouseReachable()) {
        $this->markTestSkipped('ClickHouse not reachable on '.E2ETestCase::CLICKHOUSE);
    }

    if (! class_exists(Panel::class)) {
        $this->markTestSkipped('The end-to-end panels need telemetry-ui 2.x.');
    }

    $ch = new Client(new HttpFactory, E2ETestCase::CLICKHOUSE, E2ETestCase::DATABASE, settings: ['wait_for_async_insert' => 1]);
    (new Client(new HttpFactory, E2ETestCase::CLICKHOUSE, 'default'))->execute('CREATE DATABASE IF NOT EXISTS '.E2ETestCase::DATABASE);

    foreach (Schema::statements(30) as $ddl) {
        $ch->execute($ddl);
    }
    foreach (['otel_logs', 'otel_traces', 'otel_metrics_sum', 'otel_metrics_gauge', 'otel_metrics_histogram'] as $t) {
        $ch->execute("TRUNCATE TABLE {$t}");
    }

    $parser = new OtlpParser;
    $writer = new ClickHouseWriter($ch);
    $now = time();
    $ns = static fn (int $ago): string => (string) (($now - $ago) * 1_000_000_000);

    $writer->writeLogs($parser->logs([
        'resourceLogs' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
            'scopeLogs' => [['logRecords' => [
                ['timeUnixNano' => $ns(30), 'severityText' => 'INFO', 'body' => ['stringValue' => 'e2e-canary-9f3a']],
                ['timeUnixNano' => $ns(20), 'severityText' => 'ERROR', 'body' => ['stringValue' => 'boom'], 'attributes' => [
                    ['key' => 'exception.group', 'value' => ['stringValue' => 'e2egrp9f3a']],
                    ['key' => 'exception.type', 'value' => ['stringValue' => 'E2ECanaryException']],
                    ['key' => 'exception.message', 'value' => ['stringValue' => 'canary']],
                ]],
            ]]],
        ]],
    ]));

    // Request-duration histogram in seconds, as laravel-telemetry 2.x emits
    // it, two cumulative points (count 0 → 12) so the per-period increase is a
    // clean 12.
    $writer->writeMetrics($parser->metrics([
        'resourceMetrics' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
            'scopeMetrics' => [['metrics' => [
                ['name' => 'http.server.request.duration', 'unit' => 's', 'histogram' => ['dataPoints' => [
                    ['timeUnixNano' => $ns(600), 'count' => '0', 'sum' => 0.0, 'bucketCounts' => ['0', '0'], 'explicitBounds' => [0.1], 'attributes' => [['key' => 'http.response.status_code', 'value' => ['stringValue' => '200']]]],
                    ['timeUnixNano' => $ns(10), 'count' => '12', 'sum' => 0.24, 'bucketCounts' => ['12', '0'], 'explicitBounds' => [0.1], 'attributes' => [['key' => 'http.response.status_code', 'value' => ['stringValue' => '200']]]],
                ]]],
            ]]],
        ]],
    ]));
});

/** One dashboard panel's payload, as the SPA fetches it. */
function panel(string $id): TestResponse
{
    return test()->getJson('/telemetry-ui/api/v2/panels/'.$id.'?period=1h');
}

it('serves the log-viewer panel with lines from ClickHouse', function (): void {
    panel('log-viewer')
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertSee('e2e-canary-9f3a');
});

it('serves the unified-errors panel grouped from ClickHouse exception records', function (): void {
    panel('unified-errors')
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertSee('E2ECanaryException');
});

it('serves the requests-activity metrics panel with the seeded request count', function (): void {
    $stats = panel('requests-activity')
        ->assertOk()
        ->assertJsonPath('error', null)
        ->json('stats');

    expect($stats[0]['label'])->toBe('Requests')
        ->and($stats[0]['value'])->toBe('12');
});

/**
 * Seed real db.client spans for two parameterised statements and prove the
 * exact server-side aggregation ranks them by TOTAL db time, carries the
 * db.system.name, and converts ns → ms — against a live ClickHouse.
 *
 *   query A "... users where id = ?" : 5 calls × 4ms = 20ms total
 *   query B "... orders where uid = ?": 2 calls × 3ms =  6ms total
 */
function seedDbSpans(Client $ch): void
{
    $now = time();
    $ns = static fn (int $ago): string => (string) (($now - $ago) * 1_000_000_000);

    $span = static function (string $id, string $sql, int $startAgo, int $durationMs) use ($ns): array {
        $startNs = (int) $ns($startAgo);

        return [
            'traceId' => 'trace-'.$id, 'spanId' => 'span-'.$id, 'name' => 'db.query',
            'kind' => 3,
            'startTimeUnixNano' => (string) $startNs,
            'endTimeUnixNano' => (string) ($startNs + $durationMs * 1_000_000),
            'attributes' => [
                ['key' => 'db.query.text', 'value' => ['stringValue' => $sql]],
                ['key' => 'db.system.name', 'value' => ['stringValue' => 'mysql']],
            ],
        ];
    };

    $queryA = 'select * from users where id = ?';
    $queryB = 'select * from orders where user_id = ?';
    $spans = [];
    foreach (range(1, 5) as $i) {
        $spans[] = $span('a'.$i, $queryA, 30, 4);
    }
    foreach (range(1, 2) as $i) {
        $spans[] = $span('b'.$i, $queryB, 30, 3);
    }

    (new ClickHouseWriter($ch))->writeTraces((new OtlpParser)->traces([
        'resourceSpans' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
            'scopeSpans' => [['spans' => $spans]],
        ]],
    ]));
}

it('aggregates spans exactly against live ClickHouse: total-time ranking, carried system, ns→ms', function (): void {
    $ch = new Client(new HttpFactory, E2ETestCase::CLICKHOUSE, E2ETestCase::DATABASE, settings: ['wait_for_async_insert' => 1]);
    seedDbSpans($ch);

    $source = new ClickHouseTracesSource($ch);
    expect($source)->toBeInstanceOf(AggregatesSpans::class);

    $buckets = $source->aggregateSpans(
        new SpanAggregation(
            where: (new TraceQuery)->where(TraceCondition::nil('span.db.query.text')),
            groupBy: 'span.db.query.text',
            carry: ['span.db.system.name'],
            sort: SpanSort::Total,
        ),
        new DateTimeImmutable('@'.(time() - 3600)),
        new DateTimeImmutable('@'.(time() + 60)),
    );

    expect($buckets)->toHaveCount(2)
        ->and($buckets[0]->key)->toBe('select * from users where id = ?')
        ->and($buckets[0]->count)->toBe(5)
        ->and($buckets[0]->avgMs)->toBe(4.0)
        ->and($buckets[0]->maxMs)->toBe(4.0)
        ->and($buckets[0]->totalMs)->toBe(20.0)
        ->and($buckets[0]->attributes['db.system.name'])->toBe('mysql')
        ->and($buckets[1]->key)->toBe('select * from orders where user_id = ?')
        ->and($buckets[1]->count)->toBe(2)
        ->and($buckets[1]->totalMs)->toBe(6.0);
});

it('serves the query-performance panel using the exact aggregation from ClickHouse', function (): void {
    seedDbSpans(new Client(new HttpFactory, E2ETestCase::CLICKHOUSE, E2ETestCase::DATABASE, settings: ['wait_for_async_insert' => 1]));

    panel('query-performance')
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertSee('select * from users where id = ?', false)
        ->assertSee('select * from orders where user_id = ?', false);
});
