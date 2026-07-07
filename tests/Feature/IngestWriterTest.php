<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\Ingest\ClickHouseWriter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function writer(int $maxRows = 10000): ClickHouseWriter
{
    return new ClickHouseWriter(new Client(app(HttpFactory::class), 'http://clickhouse.test:8123', 'telemetry'), $maxRows);
}

it('chunks a large insert into maxRows-sized batches', function (): void {
    Http::fake(['clickhouse.test:8123/*' => Http::response('')]);

    writer(maxRows: 2)->writeLogs(array_fill(0, 5, ['ServiceName' => 'x', 'Body' => 'y']));

    // 5 rows / 2 per insert → 3 inserts.
    Http::assertSentCount(3);
});

it('is a no-op for empty inputs', function (): void {
    Http::fake();

    writer()->writeLogs([]);
    writer()->writeTraces([]);
    writer()->writeMetrics(['sum' => [], 'gauge' => [], 'histogram' => []]);

    Http::assertNothingSent();
});
