<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use JsonException;

/**
 * Feature values as the panel edits and shows them: JSON in, a short summary out.
 */
final class JsonValue
{
    /** The longest JSON value the panel accepts (64 KB). */
    public const MAX_LENGTH = 65535;

    /**
     * @throws JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function isValid(string $json): bool
    {
        try {
            self::decode($json);

            return true;
        } catch (JsonException) {
            return false;
        }
    }

    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

        return (string) json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    /**
     * Pennant's rule: every value but `false` is active.
     */
    public static function isActive(mixed $value): bool
    {
        return $value !== false;
    }

    public static function isRich(mixed $value): bool
    {
        return ! is_bool($value);
    }

    /**
     * "On", "Off", or the value itself (shortened).
     */
    public static function summary(mixed $value, int $limit = 60): string
    {
        if ($value === true) {
            return (string) __('alfakit-feature-flags::feature-flags.values.on');
        }

        if ($value === false) {
            return (string) __('alfakit-feature-flags::feature-flags.values.off');
        }

        $text = is_string($value) ? $value : self::encode($value);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
