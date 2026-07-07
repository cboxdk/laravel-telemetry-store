<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Ingest;

use Cbox\TelemetryStore\ClickHouse\Client;

/**
 * Writes parsed OTLP rows into the ClickHouse `otel_*` tables via bulk
 * JSONEachRow inserts. Map columns (ResourceAttributes/…) are passed straight
 * through — ClickHouse's JSONEachRow accepts a JSON object for a
 * `Map(String, String)` column.
 *
 * Rows are chunked at {@see $maxRows} so a single oversized OTLP request can't
 * balloon one insert body in memory — each chunk is its own insert.
 */
final readonly class ClickHouseWriter implements StoreWriter
{
    public function __construct(
        private Client $client,
        private int $maxRows = 10000,
    ) {}

    public function writeLogs(array $rows): void
    {
        $this->insert('otel_logs', $rows);
    }

    public function writeTraces(array $rows): void
    {
        $this->insert('otel_traces', $rows);
    }

    public function writeMetrics(array $metrics): void
    {
        $this->insert('otel_metrics_sum', $metrics['sum']);
        $this->insert('otel_metrics_gauge', $metrics['gauge']);
        $this->insert('otel_metrics_histogram', $metrics['histogram']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, max(1, $this->maxRows)) as $chunk) {
            $this->client->insert($table, $chunk);
        }
    }
}
