<?php

namespace App\Support;

/**
 * The maintainer's own client addresses, which neither Nightwatch nor the
 * visitor stats should count. One environment list serves both.
 */
class IgnoredIps
{
    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        $configured = (string) config('services.nightwatch.ignored_ips', '');

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    public static function contains(?string $ip): bool
    {
        return $ip !== null && in_array($ip, static::all(), true);
    }
}
