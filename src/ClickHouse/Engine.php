<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\ClickHouse;

/**
 * The MergeTree family engine the schema is created with: a plain `MergeTree`
 * for single-node, or `ReplicatedMergeTree` (optionally `ON CLUSTER`) for HA.
 *
 * The ZooKeeper/Keeper path keeps `{shard}`/`{database}`/`{replica}` as
 * ClickHouse macros (resolved per node from its config), and substitutes
 * `{table}` here so each table gets a distinct path even where the `{table}`
 * macro isn't configured.
 */
final readonly class Engine
{
    public function __construct(
        public bool $replicated = false,
        public string $zooPath = '/clickhouse/tables/{shard}/{database}/{table}',
        public string $replica = '{replica}',
        public ?string $cluster = null,
    ) {}

    public static function mergeTree(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $config  the `telemetry-store.clickhouse.engine` block
     */
    public static function fromConfig(array $config): self
    {
        $cluster = $config['cluster'] ?? null;

        return new self(
            replicated: (bool) ($config['replicated'] ?? false),
            zooPath: (string) ($config['zoo_path'] ?? '/clickhouse/tables/{shard}/{database}/{table}'),
            replica: (string) ($config['replica'] ?? '{replica}'),
            cluster: is_string($cluster) && $cluster !== '' ? $cluster : null,
        );
    }

    /**
     * The `ENGINE = …` clause body for a table.
     */
    public function clause(string $table): string
    {
        if (! $this->replicated) {
            return 'MergeTree';
        }

        $path = str_replace('{table}', $table, $this->zooPath);

        return "ReplicatedMergeTree('".$this->escape($path)."', '".$this->escape($this->replica)."')";
    }

    /**
     * The ` ON CLUSTER …` fragment for the CREATE statement, or empty.
     */
    public function onCluster(): string
    {
        return $this->cluster === null ? '' : ' ON CLUSTER `'.str_replace('`', '', $this->cluster).'`';
    }

    private function escape(string $value): string
    {
        return addcslashes($value, "'\\");
    }
}
