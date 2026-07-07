<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\ClickHouse\Schema;
use Cbox\TelemetryStore\Ingest\ClickHouseWriter;
use Cbox\TelemetryStore\Ingest\OtlpParser;
use Cbox\TelemetryStore\Tests\E2ETestCase;
use Cbox\TelemetryUi\Cards\Builtin\LogViewer;
use Cbox\TelemetryUi\Cards\Builtin\RequestsActivity;
use Cbox\TelemetryUi\Cards\Builtin\UnifiedErrors;
use Illuminate\Http\Client\Factory as HttpFactory;
use Livewire\Livewire;

uses(E2ETestCase::class);

/**
 * These render the ACTUAL dashboard cards through Livewire against a live
 * ClickHouse — the full card → query IR → clickhouse-* driver → SQL → HTML
 * chain. Skipped when ClickHouse isn't reachable on :18123.
 */
beforeEach(function (): void {
    if (! E2ETestCase::clickhouseReachable()) {
        $this->markTestSkipped('ClickHouse not reachable on '.E2ETestCase::CLICKHOUSE);
    }

    $ch = new Client(new HttpFactory, E2ETestCase::CLICKHOUSE, 'telemetry', settings: ['wait_for_async_insert' => 1]);
    (new Client(new HttpFactory, E2ETestCase::CLICKHOUSE, 'default'))->execute('CREATE DATABASE IF NOT EXISTS telemetry');

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

    // Request-duration histogram, two cumulative points (count 0 → 12) so the
    // per-period increase is a clean 12.
    $writer->writeMetrics($parser->metrics([
        'resourceMetrics' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'demo']]]],
            'scopeMetrics' => [['metrics' => [
                ['name' => 'http.server.request.duration', 'unit' => 'ms', 'histogram' => ['dataPoints' => [
                    ['timeUnixNano' => $ns(600), 'count' => '0', 'sum' => 0.0, 'bucketCounts' => ['0', '0'], 'explicitBounds' => [100.0], 'attributes' => [['key' => 'http.response.status_code', 'value' => ['stringValue' => '200']]]],
                    ['timeUnixNano' => $ns(10), 'count' => '12', 'sum' => 240.0, 'bucketCounts' => ['12', '0'], 'explicitBounds' => [100.0], 'attributes' => [['key' => 'http.response.status_code', 'value' => ['stringValue' => '200']]]],
                ]]],
            ]]],
        ]],
    ]));
});

it('renders the LogViewer card with lines from ClickHouse', function (): void {
    Livewire::test(LogViewer::class)
        ->assertOk()
        ->assertSee('e2e-canary-9f3a');
});

it('renders the UnifiedErrors card grouped from ClickHouse exception records', function (): void {
    Livewire::test(UnifiedErrors::class)
        ->assertOk()
        ->assertSee('E2ECanaryException');
});

it('renders the RequestsActivity metrics card against ClickHouse without a driver error', function (): void {
    Livewire::test(RequestsActivity::class)
        ->assertOk()
        ->assertSee('Requests')
        ->assertDontSee('Unexpected response')
        ->assertDontSee('Could not reach')
        ->assertDontSee('store query failed');
});
