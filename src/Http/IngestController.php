<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Http;

use Cbox\TelemetryStore\Ingest\OtlpParser;
use Cbox\TelemetryStore\Ingest\StoreWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Native OTLP/HTTP JSON ingest. The three endpoints mirror the collector's
 * `/v1/{traces,metrics,logs}`, so the emitter's OTLP exporter needs only its
 * endpoint pointed here. Returns the OTLP-standard empty-body 200 on success;
 * a parse error is a 400 (permanent, don't retry), a write error bubbles as 500
 * (retryable) so the emitter's circuit breaker backs off.
 */
final readonly class IngestController
{
    public function __construct(
        private OtlpParser $parser,
        private StoreWriter $writer,
    ) {}

    public function traces(Request $request): JsonResponse
    {
        $this->writer->writeTraces($this->parser->traces($this->payload($request)));

        return self::ok();
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->writer->writeMetrics($this->parser->metrics($this->payload($request)));

        return self::ok();
    }

    public function logs(Request $request): JsonResponse
    {
        $this->writer->writeLogs($this->parser->logs($this->payload($request)));

        return self::ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            abort(400, 'Invalid OTLP JSON: '.$exception->getMessage());
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function ok(): JsonResponse
    {
        // OTLP/HTTP success is a 200 with an empty (or {}) body.
        return new JsonResponse((object) [], 200);
    }
}
