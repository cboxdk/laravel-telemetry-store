<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Tests;

use Cbox\TelemetryStore\TelemetryStoreServiceProvider;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a real Laravel app with the telemetry-ui dashboard AND this store, its
 * three connections pointed at a live ClickHouse — so a test can render an
 * actual dashboard card through Livewire and prove the whole card → IR → driver
 * → ClickHouse → HTML chain end to end. Integration only; tests gate themselves
 * on ClickHouse being reachable.
 */
abstract class E2ETestCase extends Orchestra
{
    public const CLICKHOUSE = 'http://localhost:18123';

    protected function setUp(): void
    {
        parent::setUp();

        // Cards stream in lazily in the browser; render them eagerly in tests.
        Livewire::withoutLazyLoading();

        // Open the dashboard gate so routes/links resolve during rendering.
        Gate::define('viewTelemetryUi', static fn (): bool => true);
        Gate::define('manageTelemetryUi', static fn (): bool => true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TelemetryUiServiceProvider::class,
            TelemetryStoreServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('cache.default', 'array');
        $config->set('telemetry-ui.cache.ttl', 0);

        $ch = ['url' => self::CLICKHOUSE, 'database' => 'telemetry'];
        $config->set('telemetry-ui.connections.logs', ['driver' => 'clickhouse-logs'] + $ch);
        $config->set('telemetry-ui.connections.traces', ['driver' => 'clickhouse-traces'] + $ch);
        $config->set('telemetry-ui.connections.metrics', ['driver' => 'clickhouse-metrics'] + $ch);

        $config->set('telemetry-store.clickhouse.endpoint', self::CLICKHOUSE);
        $config->set('telemetry-store.clickhouse.database', 'telemetry');
        $config->set('telemetry-store.ingest.enabled', false);
    }

    public static function clickhouseReachable(): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);

        return @file_get_contents(self::CLICKHOUSE.'/ping', false, $ctx) !== false;
    }
}
