<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Ingest;

use Cbox\TelemetryStore\ClickHouse\Client;

/**
 * Writes parsed OTLP rows into the ClickHouse `otel_*` tables via bulk
 * JSONEachRow inserts. Map columns (ResourceAttributes/…) are passed straight
 * through — ClickHouse's JSONEachRow accepts a JSON object for a
 * `Map(String, String)` column.
 */
final readonly class ClickHouseWriter implements StoreWriter
{
    public function __construct(private Client $client) {}

    public function writeLogs(array $rows): void
    {
        $this->client->insert('otel_logs', $rows);
    }

    public function writeTraces(array $rows): void
    {
        $this->client->insert('otel_traces', $rows);
    }

    public function writeMetrics(array $metrics): void
    {
        $this->client->insert('otel_metrics_sum', $metrics['sum']);
        $this->client->insert('otel_metrics_gauge', $metrics['gauge']);
        $this->client->insert('otel_metrics_histogram', $metrics['histogram']);
    }
}
