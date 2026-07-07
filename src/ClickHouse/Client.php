<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\ClickHouse;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * A thin ClickHouse client over its HTTP interface (default :8123) — no PHP
 * extension, just the Laravel HTTP client. Used by both the OTLP ingest
 * (bulk inserts) and the telemetry-ui read driver (SELECT ... FORMAT JSON).
 *
 * DDL/DML with no result set go through {@see execute()}; row inserts through
 * {@see insert()} (JSONEachRow); reads through {@see select()} (FORMAT JSON,
 * decoded to a list of associative rows).
 */
final readonly class Client
{
    /**
     * @param  array<string, scalar>  $settings  ClickHouse settings sent as
     *                                           query params on every request
     */
    public function __construct(
        private HttpFactory $http,
        private string $endpoint,
        private string $database,
        private string $username = 'default',
        private string $password = '',
        private float $timeout = 10.0,
        private array $settings = [],
    ) {}

    /**
     * Run a statement that returns no rows (DDL, INSERT ... SELECT, ALTER).
     */
    public function execute(string $sql): void
    {
        $this->request()->withBody($sql, 'text/plain')->throw()->post($this->url());
    }

    /**
     * Bulk-insert rows into a table using JSONEachRow. A no-op for an empty set.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $body = implode("\n", array_map(
            static fn (array $row): string => (string) json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $rows,
        ));

        $this->request()
            ->withBody("INSERT INTO {$table} FORMAT JSONEachRow\n".$body, 'text/plain')
            ->throw()
            ->post($this->url());
    }

    /**
     * Run a SELECT and return its rows. `FORMAT JSON` is appended automatically.
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql): array
    {
        $response = $this->request()
            ->withBody(rtrim($sql, "; \n")."\nFORMAT JSON", 'text/plain')
            ->throw()
            ->post($this->url());

        /** @var array{data?: list<array<string, mixed>>} $decoded */
        $decoded = $response->json();

        return array_values(is_array($decoded['data'] ?? null) ? $decoded['data'] : []);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->timeout($this->timeout)
            ->withHeaders([
                'X-ClickHouse-User' => $this->username,
                'X-ClickHouse-Key' => $this->password,
            ]);
    }

    private function url(): string
    {
        $params = ['database' => $this->database] + $this->settings;

        return rtrim($this->endpoint, '/').'/?'.http_build_query($params);
    }
}
