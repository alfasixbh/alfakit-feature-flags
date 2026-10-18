<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

/**
 * Stable percentage rollouts. Every scope gets a bucket between 0 and 100 from a hash of
 * the feature's salt and the scope, so the same scope always lands in the same bucket:
 * raising the percentage only ever adds scopes, lowering it only removes the newest ones.
 */
final class Rollout
{
    /** Buckets per percent: rollouts have a 0.01% resolution. */
    private const RESOLUTION = 100;

    /**
     * The scope's bucket, from 0 (inclusive) to 100 (exclusive).
     */
    public static function bucket(string $salt, string $scope): float
    {
        $hash = hash('xxh3', $salt."\0".$scope);
        $number = (int) hexdec(substr($hash, 0, 8));

        return ($number % (100 * self::RESOLUTION)) / self::RESOLUTION;
    }

    /**
     * Whether the scope is inside a rollout to `$percentage`% of the scopes.
     */
    public static function includes(string $salt, string $scope, float $percentage): bool
    {
        if ($percentage <= 0) {
            return false;
        }

        if ($percentage >= 100) {
            return true;
        }

        return self::bucket($salt, $scope) < $percentage;
    }

    public static function normalise(mixed $percentage): float
    {
        $value = is_numeric($percentage) ? (float) $percentage : 0.0;

        return round(min(100.0, max(0.0, $value)), 2);
    }
}
