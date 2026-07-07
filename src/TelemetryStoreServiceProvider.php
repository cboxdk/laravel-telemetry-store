<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore;

use Cbox\TelemetryStore\ClickHouse\Client;
use Cbox\TelemetryStore\Console\InstallSchemaCommand;
use Cbox\TelemetryStore\Ingest\ClickHouseWriter;
use Cbox\TelemetryStore\Ingest\StoreWriter;
use Cbox\TelemetryStore\Read\ClickHouseLogsSource;
use Cbox\TelemetryStore\Read\ClickHouseMetricsSource;
use Cbox\TelemetryStore\Read\ClickHouseTracesSource;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the ClickHouse store into a Laravel app: the ClickHouse client, the
 * schema-install command, the native OTLP ingest routes, and the telemetry-ui
 * read drivers (registered via TelemetryUi::extend(), so they only activate
 * when the UI package is installed).
 *
 * Boot hygiene mirrors laravel-telemetry-ui: registrations only, no I/O and no
 * connector instantiation at boot — the ClickHouse client is resolved lazily.
 */
final class TelemetryStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telemetry-store.php', 'telemetry-store');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var array{endpoint: string, database: string, username: string, password: string, timeout: float, settings: array<string, scalar>} $ch */
            $ch = $app->make('config')->get('telemetry-store.clickhouse');

            return new Client(
                http: $app->make(HttpFactory::class),
                endpoint: $ch['endpoint'],
                database: $ch['database'],
                username: $ch['username'],
                password: $ch['password'],
                timeout: $ch['timeout'],
                settings: $ch['settings'] ?? [],
            );
        });

        $this->app->singleton(StoreWriter::class, static fn (Application $app): StoreWriter => new ClickHouseWriter($app->make(Client::class)));
    }

    public function boot(): void
    {
        if ((bool) $this->app->make('config')->get('telemetry-store.ingest.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/ingest.php');
        }

        $this->registerReadDrivers();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telemetry-store.php' => $this->app->configPath('telemetry-store.php'),
            ], 'telemetry-store-config');

            $this->commands([InstallSchemaCommand::class]);
        }
    }

    /**
     * Register the ClickHouse read drivers into telemetry-ui, so a connection
     * with `driver => clickhouse-{logs,traces,metrics}` resolves to them. Guards
     * on the UI package being installed — the store can run ingest-only.
     */
    private function registerReadDrivers(): void
    {
        if (! class_exists(ConnectionManager::class) || ! $this->app->bound(ConnectionManager::class)) {
            return;
        }

        $manager = $this->app->make(ConnectionManager::class);

        $manager->extend('clickhouse-logs', fn (array $config): ClickHouseLogsSource => new ClickHouseLogsSource($this->readClient($config)));
        $manager->extend('clickhouse-traces', fn (array $config): ClickHouseTracesSource => new ClickHouseTracesSource($this->readClient($config)));
        $manager->extend('clickhouse-metrics', fn (array $config): ClickHouseMetricsSource => new ClickHouseMetricsSource($this->readClient($config)));
    }

    /**
     * Build a ClickHouse {@see Client} for a telemetry-ui connection config,
     * falling back to the store's own `telemetry-store.clickhouse` defaults for
     * any key the connection omits — so a connection can be just
     * `['driver' => 'clickhouse-logs']`.
     *
     * @param  array<string, mixed>  $config
     */
    private function readClient(array $config): Client
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) $this->app->make('config')->get('telemetry-store.clickhouse', []);

        $get = static fn (string $key, string $fallback, mixed $default): string => (string) ($config[$key] ?? $config[$fallback] ?? $defaults[$key] ?? $default);

        return new Client(
            http: $this->app->make(HttpFactory::class),
            endpoint: $get('url', 'endpoint', 'http://localhost:8123'),
            database: $get('database', 'db', 'telemetry'),
            username: $get('username', 'user', 'default'),
            password: $get('password', 'pass', ''),
            timeout: (float) ($config['timeout'] ?? $defaults['timeout'] ?? 10.0),
            settings: is_array($defaults['settings'] ?? null) ? $defaults['settings'] : [],
        );
    }
}
