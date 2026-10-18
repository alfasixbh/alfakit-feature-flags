<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use AlfaSix\AlfakitFeatureFlags\Pennant\RuleAwareFeatureManager;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Contracts\FeatureScopeable;
use Laravel\Pennant\Drivers\Decorator;
use Laravel\Pennant\Feature;
use Laravel\Pennant\FeatureManager;
use Throwable;
use TypeError;

/**
 * Everything the panel does to a feature, usable from your own code too. Every change is
 * recorded in the audit trail (and the activity log), and takes effect at once.
 *
 * ```php
 * FeatureFlags::rollout('new-billing', 25);
 * FeatureFlags::setFor('new-billing', $user, true);
 * FeatureFlags::kill('new-billing');
 * ```
 *
 * @phpstan-type Snapshot array{mode: ?string, value: mixed, percentage: ?float, killed: bool, activate_at: ?string, deactivate_at: ?string}
 */
final class FeatureFlags
{
    // ---------------------------------------------------------------- Pennant

    public static function storeName(): string
    {
        return FeatureFlagsPlugin::get()->getStore() ?? Feature::getDefaultDriver();
    }

    public static function store(): Decorator
    {
        return Feature::store(self::storeName());
    }

    /**
     * Whether kill switches, rollouts and targeting apply to `Feature::active()` (the
     * `enforce_rules` option).
     */
    public static function enforcesRules(): bool
    {
        return app(FeatureManager::class) instanceof RuleAwareFeatureManager;
    }

    public static function rules(): RuleStore
    {
        return app(RuleStore::class);
    }

    /**
     * The panel's current tenant on panels with tenancy.
     */
    public static function currentTenant(): ?Model
    {
        try {
            return alfakit()->getCurrentOrDefaultPanel()->hasTenancy() ? alfakit()->getTenant() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether `$feature` is active for `$scope`, or for the current user or tenant (the
     * plugin's `scopeUsing()`) without one.
     */
    public static function active(string $feature, mixed ...$scope): bool
    {
        $scope = $scope === [] ? FeatureFlagsPlugin::get()->getCurrentScope() : $scope[0];

        return self::store()->for($scope)->active($feature);
    }

    // ---------------------------------------------------------------- Reading

    /**
     * The feature's value for `$scope` and where it came from.
     */
    public static function evaluate(string $feature, mixed $scope): Decision
    {
        $scope = self::resolveScope($scope);

        try {
            $value = self::store()->for($scope)->value($feature);
        } catch (TypeError) {
            // The feature's resolver doesn't take this kind of scope.
            $value = false;
        }

        if (! self::enforcesRules()) {
            return new Decision($value, Decision::SOURCE_DEFAULT);
        }

        $rules = self::rules();

        if ($rules->isKilled($feature)) {
            return new Decision(false, Decision::SOURCE_KILLED);
        }

        $decision = $rules->target($feature, Scopes::serialize($scope));

        if ($decision === null) {
            return new Decision($value, Decision::SOURCE_DEFAULT);
        }

        // A class feature's `before()` hook wins over targeting.
        return $decision->value === $value ? $decision : new Decision($value, Decision::SOURCE_BEFORE);
    }

    public static function override(string $feature, mixed $scope): ?FeatureFlagOverride
    {
        return FeatureFlagOverride::query()
            ->where('feature', $feature)
            ->where('scope', Scopes::serialize(self::resolveScope($scope)))
            ->first();
    }

    public static function rule(string $feature): ?FeatureFlagRule
    {
        return FeatureFlagRule::query()->where('feature', $feature)->first();
    }

    /**
     * How the panel manages the feature, as recorded in the audit trail.
     *
     * @return Snapshot
     */
    public static function snapshot(string $feature): array
    {
        $rule = self::rule($feature);

        return [
            'mode' => $rule?->mode,
            'value' => $rule?->mode === FeatureFlagRule::MODE_EVERYONE ? $rule->getEveryoneValue() : null,
            'percentage' => $rule?->getPercentage(),
            'killed' => $rule?->isKilled() ?? false,
            'activate_at' => $rule?->activate_at?->toIso8601String(),
            'deactivate_at' => $rule?->deactivate_at?->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- Everyone

    /**
     * Give everyone `$value` (true by default): the stored values, the targeting entries
     * and every scope that resolves later.
     */
    public static function activateForEveryone(string $feature, mixed $value = true, string $source = FeatureFlagAudit::SOURCE_PANEL): void
    {
        if ($value === false) {
            self::deactivateForEveryone($feature, $source);

            return;
        }

        self::setForEveryone($feature, $value, JsonValue::isRich($value) ? Audit::VALUE_SET_FOR_EVERYONE : Audit::ACTIVATED_FOR_EVERYONE, $source);
    }

    public static function deactivateForEveryone(string $feature, string $source = FeatureFlagAudit::SOURCE_PANEL): void
    {
        self::setForEveryone($feature, false, Audit::DEACTIVATED_FOR_EVERYONE, $source);
    }

    /**
     * Stop forcing a value on everyone (or rolling out): scopes resolve through targeting,
     * the stored values and the feature's definition again.
     */
    public static function useDefinition(string $feature): void
    {
        self::changeRule($feature, Audit::DEFINITION_RESTORED, static function (FeatureFlagRule $rule): void {
            $rule->mode = null;
            $rule->value = null;
            $rule->percentage = null;
        });
    }

    /**
     * Activate the feature for `$percentage`% of the scopes. A scope's place in the
     * rollout is stable: raising the percentage only adds scopes. Targeting entries still
     * win.
     */
    public static function rollout(string $feature, float $percentage): void
    {
        $percentage = Rollout::normalise($percentage);

        self::changeRule($feature, Audit::ROLLOUT_SET, static function (FeatureFlagRule $rule) use ($percentage): void {
            $rule->mode = FeatureFlagRule::MODE_ROLLOUT;
            $rule->value = null;
            $rule->percentage = number_format($percentage, 2, '.', '');
        });
    }

    /**
     * The kill switch: inactive for everyone, whatever else says, until `restore()`.
     */
    public static function kill(string $feature): void
    {
        self::changeRule($feature, Audit::KILLED, static function (FeatureFlagRule $rule): void {
            $rule->killed_at = now();
        });
    }

    public static function restore(string $feature): void
    {
        self::changeRule($feature, Audit::RESTORED, static function (FeatureFlagRule $rule): void {
            $rule->killed_at = null;
        });
    }

    /**
     * Activate the feature for everyone at `$activateAt` (with `$value`) and deactivate it
     * at `$deactivateAt`; null clears either. Applied by `alfakit-feature-flags:apply-schedules`.
     */
    public static function schedule(string $feature, ?DateTimeInterface $activateAt, ?DateTimeInterface $deactivateAt, mixed $value = true): void
    {
        self::changeRule($feature, Audit::SCHEDULED, static function (FeatureFlagRule $rule) use ($activateAt, $deactivateAt, $value): void {
            $rule->activate_at = $activateAt === null ? null : Carbon::instance($activateAt);
            $rule->deactivate_at = $deactivateAt === null ? null : Carbon::instance($deactivateAt);
            $rule->setActivateValue($activateAt === null ? null : $value);
        });
    }

    /**
     * Forget every stored value and targeting entry: each scope resolves again from the
     * rules and the feature's definition. The rules stay.
     */
    public static function purge(string $feature): void
    {
        $before = self::storedSnapshot($feature);

        OverrideSync::withoutTracking(static fn () => self::store()->purge($feature));
        FeatureFlagOverride::query()->where('feature', $feature)->delete();
        self::changed();

        Audit::record($feature, Audit::PURGED, $before, self::storedSnapshot($feature));
    }

    /**
     * Purge the feature and remove its rules (kill switch, everyone, rollout, schedule):
     * back to the feature's definition.
     */
    public static function reset(string $feature): void
    {
        $before = [...self::snapshot($feature), ...self::storedSnapshot($feature)];

        OverrideSync::withoutTracking(static fn () => self::store()->purge($feature));
        FeatureFlagOverride::query()->where('feature', $feature)->delete();
        FeatureFlagRule::query()->where('feature', $feature)->delete();
        self::changed();

        Audit::record($feature, Audit::RESET, $before, [...self::snapshot($feature), ...self::storedSnapshot($feature)]);
    }

    // ---------------------------------------------------------------- One scope

    /**
     * Give one scope (a user, a team, a tenant…) its own value: true, false or a rich value.
     * It wins over the value for everyone and rollouts; only the kill switch beats it.
     */
    public static function setFor(string $feature, mixed $scope, mixed $value): void
    {
        $scope = self::resolveScope($scope);
        $serialised = Scopes::serialize($scope);
        $label = Scopes::label($serialised, $scope instanceof Model ? $scope : null);
        $before = self::scopeSnapshot($feature, $scope);

        OverrideSync::withoutTracking(static function () use ($feature, $scope, $value): void {
            $value === false
                ? self::store()->for($scope)->deactivate($feature)
                : self::store()->for($scope)->activate($feature, $value);
        });

        ['type' => $type, 'id' => $id] = Scopes::parse($serialised);
        $tenant = self::currentTenant();
        $actor = Audit::actor();

        $override = FeatureFlagOverride::query()->firstOrNew(['feature' => $feature, 'scope' => $serialised]);
        $override->forceFill([
            'scope_type' => $type,
            'scope_id' => $id,
            'updated_by_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'updated_by_id' => $actor === null ? null : (string) $actor->getAuthIdentifier(),
            'updated_by_name' => $actor === null ? null : mb_substr(Audit::actorName($actor), 0, 255),
        ]);

        if (! $override->exists || $tenant !== null) {
            $override->forceFill([
                'tenant_type' => $tenant?->getMorphClass(),
                'tenant_id' => $tenant === null ? null : (string) $tenant->getKey(),
            ]);
        }

        $override->setValue($value)->save();
        self::changed();

        $action = match (true) {
            $value === false => Audit::SCOPE_DEACTIVATED,
            JsonValue::isRich($value) => Audit::SCOPE_VALUE_SET,
            default => Audit::SCOPE_ACTIVATED,
        };

        Audit::record($feature, $action, $before, self::scopeSnapshot($feature, $scope), $serialised, $label);
    }

    /**
     * Remove a scope's own value: it follows the rules and the definition again.
     */
    public static function forgetFor(string $feature, mixed $scope): void
    {
        $scope = self::resolveScope($scope);
        $serialised = Scopes::serialize($scope);
        $label = Scopes::label($serialised, $scope instanceof Model ? $scope : null);
        $before = self::scopeSnapshot($feature, $scope);

        OverrideSync::withoutTracking(static fn () => self::store()->for($scope)->forget($feature));
        FeatureFlagOverride::query()->where('feature', $feature)->where('scope', $serialised)->delete();
        self::changed();

        Audit::record($feature, Audit::SCOPE_FORGOTTEN, $before, self::scopeSnapshot($feature, $scope), $serialised, $label);
    }

    // ---------------------------------------------------------------- Schedules

    /**
     * Apply the schedules that are due: activate or deactivate those features for
     * everyone and clear the schedule. Returns what was applied.
     *
     * @return list<array{feature: string, action: string}>
     */
    public static function applySchedules(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $applied = [];

        $rules = FeatureFlagRule::query()
            ->where(static fn ($query) => $query->where('activate_at', '<=', $now)->orWhere('deactivate_at', '<=', $now))
            ->orderBy('feature')
            ->get();

        foreach ($rules as $rule) {
            $events = [];

            if ($rule->activate_at !== null && $rule->activate_at->lessThanOrEqualTo($now)) {
                $events[] = ['at' => $rule->activate_at->getTimestamp(), 'action' => 'activate', 'value' => $rule->getActivateValue()];
            }

            if ($rule->deactivate_at !== null && $rule->deactivate_at->lessThanOrEqualTo($now)) {
                $events[] = ['at' => $rule->deactivate_at->getTimestamp(), 'action' => 'deactivate', 'value' => false];
            }

            usort($events, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

            foreach ($events as $event) {
                // Clear the schedule first, so the change records it as done.
                if ($event['action'] === 'activate') {
                    $rule->activate_at = null;
                    $rule->activate_value = null;
                } else {
                    $rule->deactivate_at = null;
                }

                $rule->save();

                $event['action'] === 'activate'
                    ? self::activateForEveryone($rule->feature, $event['value'], FeatureFlagAudit::SOURCE_SCHEDULE)
                    : self::deactivateForEveryone($rule->feature, FeatureFlagAudit::SOURCE_SCHEDULE);

                $applied[] = ['feature' => $rule->feature, 'action' => $event['action']];
                $rule->refresh();
            }
        }

        return $applied;
    }

    // ---------------------------------------------------------------- Internals

    private static function setForEveryone(string $feature, mixed $value, string $action, string $source): void
    {
        $before = self::snapshot($feature);
        $rule = FeatureFlagRule::query()->firstOrNew(['feature' => $feature]);
        $rule->mode = FeatureFlagRule::MODE_EVERYONE;
        $rule->percentage = null;
        $rule->setEveryoneValue($value)->save();

        OverrideSync::withoutTracking(static fn () => $value === false
            ? self::store()->deactivateForEveryone($feature)
            : self::store()->activateForEveryone($feature, $value));

        // Pennant updates every stored value; the targeting entries follow.
        FeatureFlagOverride::query()->where('feature', $feature)->update(['value' => JsonValue::encode($value), 'updated_at' => now()]);
        self::changed();

        Audit::record($feature, $action, $before, self::snapshot($feature), source: $source);
    }

    /**
     * @param  Closure(FeatureFlagRule): void  $change
     */
    private static function changeRule(string $feature, string $action, Closure $change): void
    {
        $before = self::snapshot($feature);
        $rule = FeatureFlagRule::query()->firstOrNew(['feature' => $feature]);
        $change($rule);

        if ($rule->isEmpty()) {
            if ($rule->exists) {
                $rule->delete();
            }
        } else {
            $rule->save();
        }

        self::changed();

        Audit::record($feature, $action, $before, self::snapshot($feature));
    }

    /**
     * @return array{stored_active: ?int, stored_inactive: ?int, overrides: int}
     */
    private static function storedSnapshot(string $feature): array
    {
        $summary = StoredValues::summary($feature);

        return [
            'stored_active' => $summary['active'] ?? null,
            'stored_inactive' => $summary['inactive'] ?? null,
            'overrides' => FeatureFlagOverride::query()->where('feature', $feature)->count(),
        ];
    }

    /**
     * @return array{value: mixed, source: string, override: mixed}
     */
    private static function scopeSnapshot(string $feature, mixed $scope): array
    {
        $decision = self::evaluate($feature, $scope);
        $override = self::override($feature, $scope);

        return [
            'value' => $decision->value,
            'source' => $decision->source,
            'override' => $override?->getValue(),
        ];
    }

    /**
     * Scopes as Pennant gets them: `FeatureScopeable` identifiers, and serialised model
     * scopes (`App\Models\User|5`, as the targeting table keeps them) back as their
     * model, so resolvers typed `User $user` receive one.
     */
    private static function resolveScope(mixed $scope): mixed
    {
        if ($scope instanceof FeatureScopeable) {
            return $scope->toFeatureIdentifier(self::storeName());
        }

        if (is_string($scope) && ($model = Scopes::model($scope)) !== null) {
            return $model;
        }

        return $scope;
    }

    /**
     * Forget what this request already resolved, so the change shows at once.
     */
    private static function changed(): void
    {
        self::rules()->flush();

        try {
            Feature::flushCache();
        } catch (Throwable) {
            // No store resolved yet.
        }
    }
}
