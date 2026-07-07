<?php

declare(strict_types=1);

use Cbox\TelemetryStore\ClickHouse\Engine;
use Cbox\TelemetryStore\ClickHouse\Schema;

it('generates single-node MergeTree DDL by default', function (): void {
    $ddl = Schema::statements(30);

    expect($ddl)->toHaveCount(5)
        ->and($ddl[0])->toContain('CREATE TABLE IF NOT EXISTS otel_logs (')
        ->and($ddl[0])->toContain('ENGINE = MergeTree')
        ->and($ddl[0])->not->toContain('ReplicatedMergeTree')
        ->and($ddl[0])->not->toContain('ON CLUSTER')
        ->and($ddl[0])->toContain('INTERVAL 30 DAY');
});

it('generates ReplicatedMergeTree ON CLUSTER DDL for HA', function (): void {
    $engine = Engine::fromConfig([
        'replicated' => true,
        'cluster' => 'lgtm',
        'zoo_path' => '/ch/tables/{shard}/{database}/{table}',
        'replica' => '{replica}',
    ]);

    $ddl = Schema::statements(14, $engine);

    expect($ddl[0])->toContain('CREATE TABLE IF NOT EXISTS otel_logs ON CLUSTER `lgtm` (')
        ->and($ddl[0])->toContain("ENGINE = ReplicatedMergeTree('/ch/tables/{shard}/{database}/otel_logs', '{replica}')")
        ->and($ddl[0])->toContain('INTERVAL 14 DAY')
        // {table} is substituted per table, so each gets a distinct ZK path.
        ->and($ddl[1])->toContain("ReplicatedMergeTree('/ch/tables/{shard}/{database}/otel_traces'")
        ->and($ddl[2])->toContain("ReplicatedMergeTree('/ch/tables/{shard}/{database}/otel_metrics_sum'");
});
