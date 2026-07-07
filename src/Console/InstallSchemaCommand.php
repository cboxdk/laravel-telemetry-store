<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Console;

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\ClickHouse\Schema;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Creates the ClickHouse tables for the three OTLP signals (idempotent —
 * every statement is `CREATE TABLE IF NOT EXISTS`). Run once against a fresh
 * ClickHouse, and again after upgrading to pick up new tables. Retention is
 * read from `telemetry-store.retention_days` and baked in as a TTL.
 */
final class InstallSchemaCommand extends Command
{
    protected $signature = 'telemetry-store:install {--retention= : Override retention days for the table TTLs}';

    protected $description = 'Create (or update) the ClickHouse tables for OTLP traces, logs and metrics';

    public function handle(Client $client, Config $config): int
    {
        $retention = $this->option('retention') !== null
            ? (int) $this->option('retention')
            : (int) $config->get('telemetry-store.retention_days', 30);

        foreach (Schema::statements($retention) as $statement) {
            $table = self::tableName($statement);

            try {
                $client->execute($statement);
                $this->components->task("Ensuring table {$table}");
            } catch (Throwable $exception) {
                $this->components->error("Failed on {$table}: ".$exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->components->info("ClickHouse schema installed (retention: {$retention} days).");

        return self::SUCCESS;
    }

    private static function tableName(string $statement): string
    {
        return preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $statement, $m) === 1 ? $m[1] : 'table';
    }
}
