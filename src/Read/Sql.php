<?php

declare(strict_types=1);

namespace Cbox\TelemetryStore\Read;

/**
 * Tiny SQL-building helpers for the ClickHouse read drivers: string literal
 * quoting (ClickHouse uses backslash escapes) and safe identifiers. Values from
 * the query IR are always emitted through {@see quote()} — never interpolated
 * raw — so a crafted scope/filter value can't break out of its literal.
 */
final class Sql
{
    /**
     * A quoted, escaped ClickHouse string literal.
     */
    public static function quote(string $value): string
    {
        return "'".addcslashes($value, "'\\")."'";
    }

    /**
     * A map-value lookup that falls back from the log/span attribute map to the
     * resource attribute map — OTLP puts e.g. `deployment.environment.name` on
     * the resource but `exception.group` on the record, and the UI doesn't
     * distinguish, so we look in both.
     */
    public static function attr(string $attrColumn, string $key): string
    {
        $k = self::quote($key);

        return "if(mapContains({$attrColumn}, {$k}), {$attrColumn}[{$k}], ResourceAttributes[{$k}])";
    }
}
