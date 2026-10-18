<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Models\Concerns;

use JsonException;

/**
 * The plugin's tables live on `alfakit-feature-flags.connection` (the default connection
 * when null).
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait UsesFeatureFlagsConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('alfakit-feature-flags.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /**
     * Decode a JSON column: `$default` when it is empty or not valid JSON.
     */
    protected static function decodeJson(mixed $json, mixed $default = null): mixed
    {
        if (! is_string($json) || $json === '') {
            return $default;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }
    }

    protected static function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
