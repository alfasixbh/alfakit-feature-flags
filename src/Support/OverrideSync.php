<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Events\AllFeaturesPurged;
use Laravel\Pennant\Events\FeatureDeleted;
use Laravel\Pennant\Events\FeaturesPurged;
use Laravel\Pennant\Events\FeatureUpdated;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;
use Throwable;

/**
 * Keeps targeting in step with explicit Pennant writes made in code: `activate()` and
 * `deactivate()` for a scope become (or update) its targeting entry, `forget()` and
 * `purge()` remove them. Values Pennant resolves and stores on its own are not explicit and
 * are left alone, so rollouts still apply to them.
 */
final class OverrideSync
{
    private static int $paused = 0;

    public static function register(Dispatcher $events): void
    {
        $events->listen(FeatureUpdated::class, static function (FeatureUpdated $event): void {
            self::whenTracking(static function () use ($event): void {
                $scope = Scopes::serialize($event->scope);
                ['type' => $type, 'id' => $id] = Scopes::parse($scope);
                $override = FeatureFlagOverride::query()->firstOrNew(['feature' => (string) $event->feature, 'scope' => $scope]);
                $override->forceFill(['scope_type' => $type, 'scope_id' => $id])->setValue($event->value);

                $actor = Audit::actor();

                if ($actor !== null) {
                    $override->forceFill([
                        'updated_by_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
                        'updated_by_id' => (string) $actor->getAuthIdentifier(),
                        'updated_by_name' => mb_substr(Audit::actorName($actor), 0, 255),
                    ]);
                }

                $override->save();
            });
        });

        $events->listen(FeatureDeleted::class, static function (FeatureDeleted $event): void {
            self::whenTracking(static fn () => FeatureFlagOverride::query()
                ->where('feature', (string) $event->feature)
                ->where('scope', Scopes::serialize($event->scope))
                ->delete());
        });

        $events->listen(FeaturesPurged::class, static function (FeaturesPurged $event): void {
            self::whenTracking(static fn () => FeatureFlagOverride::query()->whereIn('feature', array_map(strval(...), (array) $event->features))->delete());
        });

        $events->listen(AllFeaturesPurged::class, static function (): void {
            self::whenTracking(static fn () => FeatureFlagOverride::query()->delete());
        });

        $events->listen(FeatureUpdatedForAllScopes::class, static function (FeatureUpdatedForAllScopes $event): void {
            self::whenTracking(static fn () => FeatureFlagOverride::query()
                ->where('feature', (string) $event->feature)
                ->update(['value' => JsonValue::encode($event->value), 'updated_at' => now()]));
        });
    }

    /**
     * Run `$callback` without mirroring its Pennant writes (the panel writes its own
     * targeting entries, with who made them and in which tenant).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutTracking(Closure $callback): mixed
    {
        self::$paused++;

        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }

    private static function whenTracking(Closure $callback): void
    {
        if (self::$paused > 0 || ! RuleStore::tablesAvailable()) {
            return;
        }

        try {
            $callback();
        } catch (Throwable $exception) {
            // Missing tables (not migrated yet) must never break the application's writes.
            report($exception);
        }

        app(RuleStore::class)->flush();
    }
}
