<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Drivers\ArrayDriver;
use Laravel\Pennant\Drivers\DatabaseDriver;
use Throwable;

/**
 * What Pennant stored for each feature: how many scopes have it active and inactive, and
 * when a value last changed. Read straight from the `database` driver's table (one
 * grouped query for every feature) or from the `array` driver's memory; other drivers
 * can't be listed.
 *
 * @phpstan-type Summary array{active: int, inactive: int, rich: bool, updated_at: ?CarbonImmutable}
 */
final class StoredValues
{
    /**
     * Whether the store's values can be counted and listed.
     */
    public static function isListable(): bool
    {
        $driver = self::driver();

        return $driver instanceof DatabaseDriver || $driver instanceof ArrayDriver;
    }

    public static function isDatabase(): bool
    {
        return self::driver() instanceof DatabaseDriver;
    }

    /**
     * Feature names with stored values.
     *
     * @return list<string>
     */
    public static function features(): array
    {
        try {
            return array_values(array_map(strval(...), FeatureFlags::store()->stored()));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, Summary>
     */
    public static function summaries(): array
    {
        $driver = self::driver();

        try {
            if ($driver instanceof DatabaseDriver) {
                return self::databaseSummaries();
            }

            if ($driver instanceof ArrayDriver) {
                return self::arraySummaries($driver);
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @return Summary|null
     */
    public static function summary(string $feature): ?array
    {
        return self::isListable() ? (self::summaries()[$feature] ?? ['active' => 0, 'inactive' => 0, 'rich' => false, 'updated_at' => null]) : null;
    }

    /**
     * The `database` store's rows of a feature (`scope`, `value`, `updated_at`), or null for
     * other drivers.
     */
    public static function query(string $feature): ?Builder
    {
        return self::isDatabase() ? self::table()->where('name', $feature) : null;
    }

    /**
     * @return array<string, Summary>
     */
    private static function databaseSummaries(): array
    {
        $rows = self::table()
            ->select(['name', 'value'])
            ->selectRaw('count(*) as aggregate')
            ->selectRaw('max(updated_at) as last_updated_at')
            ->groupBy('name', 'value')
            ->get();

        $summaries = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $value = (string) $row->value;
            $count = (int) $row->aggregate;
            $summary = $summaries[$name] ?? ['active' => 0, 'inactive' => 0, 'rich' => false, 'updated_at' => null];

            if ($value === 'false') {
                $summary['inactive'] += $count;
            } else {
                $summary['active'] += $count;
                $summary['rich'] = $summary['rich'] || $value !== 'true';
            }

            $updated = is_string($row->last_updated_at) && $row->last_updated_at !== '' ? CarbonImmutable::parse($row->last_updated_at) : null;

            if ($updated !== null && ($summary['updated_at'] === null || $updated->greaterThan($summary['updated_at']))) {
                $summary['updated_at'] = $updated;
            }

            $summaries[$name] = $summary;
        }

        return $summaries;
    }

    /**
     * @return array<string, Summary>
     */
    private static function arraySummaries(ArrayDriver $driver): array
    {
        /** @var array<string, array<string, mixed>> $states */
        $states = Closure::bind(static fn (ArrayDriver $driver): array => $driver->resolvedFeatureStates, null, ArrayDriver::class)($driver);
        $summaries = [];

        foreach ($states as $name => $scopes) {
            $summary = ['active' => 0, 'inactive' => 0, 'rich' => false, 'updated_at' => null];

            foreach ($scopes as $value) {
                if ($value === false) {
                    $summary['inactive']++;
                } else {
                    $summary['active']++;
                    $summary['rich'] = $summary['rich'] || $value !== true;
                }
            }

            $summaries[(string) $name] = $summary;
        }

        return $summaries;
    }

    private static function table(): Builder
    {
        $store = FeatureFlags::storeName();
        $connection = config("pennant.stores.{$store}.connection");
        $table = config("pennant.stores.{$store}.table");

        return DB::connection(is_string($connection) ? $connection : null)->table(is_string($table) ? $table : 'features');
    }

    private static function driver(): mixed
    {
        try {
            return FeatureFlags::store()->getDriver();
        } catch (Throwable) {
            return null;
        }
    }
}
