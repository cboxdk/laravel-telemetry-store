<?php

declare(strict_types=1);

use Cbox\TelemetryStore\Http\IngestController;
use Cbox\TelemetryStore\Http\VerifyIngestAccess;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;

/** @var Repository $config */
$config = app('config');

/** @var string $path */
$path = $config->get('telemetry-store.ingest.path', 'telemetry-store');

/** @var list<string> $middleware */
$middleware = (array) $config->get('telemetry-store.ingest.middleware', ['api']);

Route::prefix($path)
    ->middleware([...$middleware, VerifyIngestAccess::class])
    ->group(static function (): void {
        Route::post('v1/traces', [IngestController::class, 'traces']);
        Route::post('v1/metrics', [IngestController::class, 'metrics']);
        Route::post('v1/logs', [IngestController::class, 'logs']);
    });
