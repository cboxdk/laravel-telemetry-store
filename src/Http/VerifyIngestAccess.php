<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Http;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the OTLP ingest endpoints: a bearer token (constant-time compared) and
 * an optional IP allowlist. Both are opt-in — an unset token/allowlist means
 * that check is skipped, so a private-network deployment can run open.
 */
final readonly class VerifyIngestAccess
{
    public function __construct(private Config $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->config->get('telemetry-store.ingest.token');

        if (is_string($token) && $token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            abort(401, 'Invalid ingest token.');
        }

        /** @var list<string> $allowed */
        $allowed = (array) $this->config->get('telemetry-store.ingest.allowed_ips', []);

        if ($allowed !== [] && ! in_array((string) $request->ip(), $allowed, true)) {
            abort(403, 'IP not allowed.');
        }

        return $next($request);
    }
}
