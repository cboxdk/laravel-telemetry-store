<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Ingest;

/**
 * Small OTLP/HTTP JSON helpers shared by the row mappers: flatten an OTLP
 * attribute list to a `Map(String, String)`-shaped array, and read the various
 * shapes a value/timestamp can arrive in. Accepts both the camelCase
 * (`stringValue`, `timeUnixNano`) and snake_case proto-JSON spellings.
 */
final class Otlp
{
    /**
     * Flatten `[{key, value:{stringValue|intValue|...}}, ...]` to `[key => "str"]`.
     *
     * @param  mixed  $attributes
     * @return array<string, string>
     */
    public static function attributes($attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $out = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $key = $attribute['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $out[$key] = self::scalar($attribute['value'] ?? null);
        }

        return $out;
    }

    /**
     * Render an OTLP AnyValue as a string (the storage shape for attribute maps).
     *
     * @param  mixed  $value
     */
    public static function scalar($value): string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        return match (true) {
            array_key_exists('stringValue', $value) => (string) $value['stringValue'],
            array_key_exists('boolValue', $value) => $value['boolValue'] ? 'true' : 'false',
            array_key_exists('intValue', $value) => (string) $value['intValue'],
            array_key_exists('doubleValue', $value) => (string) $value['doubleValue'],
            array_key_exists('bytesValue', $value) => (string) $value['bytesValue'],
            array_key_exists('arrayValue', $value), array_key_exists('kvlistValue', $value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => '',
        };
    }

    /**
     * A nanosecond epoch as a ClickHouse DateTime64(9) literal string
     * (`1712345678.123456789`).
     *
     * @param  mixed  $nano
     */
    public static function nanoToDateTime64($nano): string
    {
        $ns = self::int($nano);

        $seconds = intdiv($ns, 1_000_000_000);
        $fraction = $ns % 1_000_000_000;

        return sprintf('%d.%09d', $seconds, $fraction);
    }

    /**
     * @param  mixed  $value
     */
    public static function int($value): int
    {
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);
    }

    /**
     * @param  mixed  $value
     */
    public static function float($value): float
    {
        return is_int($value) || is_float($value)
            ? (float) $value
            : (is_string($value) && is_numeric($value) ? (float) $value : 0.0);
    }

    /**
     * @param  mixed  $value
     */
    public static function string($value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * The `service.name` resource attribute, the store's primary partition key.
     *
     * @param  array<string, string>  $resourceAttributes
     */
    public static function serviceName(array $resourceAttributes): string
    {
        return $resourceAttributes['service.name'] ?? 'unknown_service';
    }
}
