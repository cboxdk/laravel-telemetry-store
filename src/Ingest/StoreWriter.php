<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Ingest;

/**
 * The write seam of the store. A {@see ClickHouseWriter} is the shipped
 * implementation; the abstraction is where a future relational (Eloquent)
 * writer would plug in without touching the OTLP ingest.
 */
interface StoreWriter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function writeLogs(array $rows): void;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function writeTraces(array $rows): void;

    /**
     * @param  array{sum: list<array<string, mixed>>, gauge: list<array<string, mixed>>, histogram: list<array<string, mixed>>}  $metrics
     */
    public function writeMetrics(array $metrics): void;
}
