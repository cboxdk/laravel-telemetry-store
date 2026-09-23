<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Tests;

use Cbox\TelemetryStore\TelemetryStoreServiceProvider;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a real Laravel app with the telemetry-ui dashboard AND this store, its
 * three connections pointed at a live ClickHouse — so a test can fetch an
 * actual dashboard panel from the JSON API and prove the whole panel → IR →
 * driver → ClickHouse → payload chain end to end. Integration only; tests gate
 * themselves on ClickHouse being reachable.
 *
 * The suite truncates its tables, so it uses a database of its own — never
 * `telemetry`, which on a dev box may hold a corpus someone is testing with.
 */
abstract class E2ETestCase extends Orchestra
{
    public const CLICKHOUSE = 'http://localhost:18123';

    public const DATABASE = 'telemetry_e2e';

    protected function setUp(): void
    {
        parent::setUp();

        // Open the dashboard gate so routes/links resolve during rendering.
        // Nullable user: Laravel only calls a gate for a guest when it says it
        // accepts one. v2 passes the page as the second argument.
        Gate::define('viewTelemetryUi', static fn (?object $user = null, ?string $page = null): bool => true);
        Gate::define('manageTelemetryUi', static fn (?object $user = null): bool => true);
    }

    protected function getPackageProviders($app): array
    {
        return [
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

        $ch = ['url' => self::CLICKHOUSE, 'database' => self::DATABASE];
        $config->set('telemetry-ui.connections.logs', ['driver' => 'clickhouse-logs'] + $ch);
        $config->set('telemetry-ui.connections.traces', ['driver' => 'clickhouse-traces'] + $ch);
        $config->set('telemetry-ui.connections.metrics', ['driver' => 'clickhouse-metrics'] + $ch);

        $config->set('telemetry-store.clickhouse.endpoint', self::CLICKHOUSE);
        $config->set('telemetry-store.clickhouse.database', self::DATABASE);
        $config->set('telemetry-store.ingest.enabled', false);
    }

    public static function clickhouseReachable(): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);

        return @file_get_contents(self::CLICKHOUSE.'/ping', false, $ctx) !== false;
    }
}
