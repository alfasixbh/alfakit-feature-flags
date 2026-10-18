<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

/**
 * The plugin's strings (`alfakit-feature-flags::feature-flags.*`).
 */
final class Lang
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        return (string) __('alfakit-feature-flags::feature-flags.'.$key, $replace);
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        return trans_choice('alfakit-feature-flags::feature-flags.'.$key, $count, ['count' => $count, ...$replace]);
    }
}
