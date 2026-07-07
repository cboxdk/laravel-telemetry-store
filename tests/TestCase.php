<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Tests;

use Cbox\TelemetryStore\TelemetryStoreServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TelemetryStoreServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('telemetry-store.clickhouse.endpoint', 'http://clickhouse.test:8123');
        $app['config']->set('telemetry-store.clickhouse.database', 'telemetry');
    }
}
