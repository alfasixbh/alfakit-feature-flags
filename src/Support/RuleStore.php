<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The panel's rules, loaded once per request (a scoped binding, flushed with Pennant's
 * cache): one query for every rule, one for the features that have targeting, and one per
 * feature and scope for the targeting entries themselves.
 */
final class RuleStore
{
    /** Set once the tables were found, for the rest of the process. */
    private static bool $tablesExist = false;

    /** @var array<string, FeatureFlagRule>|null */
    private ?array $rules = null;

    /** @var array<string, true>|null */
    private ?array $targetedFeatures = null;

    /** @var array<string, array<string, FeatureFlagOverride|false>> */
    private array $overrides = [];

    /**
     * @return array<string, FeatureFlagRule>
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        if (! self::tablesAvailable()) {
            $this->targetedFeatures = [];

            return $this->rules = [];
        }

        try {
            $this->rules = FeatureFlagRule::query()->get()->keyBy('feature')->all();
            $this->targetedFeatures = array_fill_keys(
                FeatureFlagOverride::query()->distinct()->pluck('feature')->map(strval(...))->all(),
                true,
            );
        } catch (Throwable) {
            $this->rules = [];
            $this->targetedFeatures = [];
        }

        return $this->rules;
    }

    public function rule(string $feature): ?FeatureFlagRule
    {
        return $this->rules()[$feature] ?? null;
    }

    /**
     * Whether the panel manages the feature at all (the fast path skips it otherwise).
     */
    public function hasRules(string $feature): bool
    {
        $this->rules();

        return isset($this->rules[$feature]) || isset($this->targetedFeatures[$feature]);
    }

    public function isKilled(string $feature): bool
    {
        return $this->rule($feature)?->isKilled() ?? false;
    }

    public function hasOverrides(string $feature): bool
    {
        $this->rules();

        return isset($this->targetedFeatures[$feature]);
    }

    public function override(string $feature, string $scope): ?FeatureFlagOverride
    {
        if (! $this->hasOverrides($feature)) {
            return null;
        }

        if (! isset($this->overrides[$feature][$scope])) {
            try {
                $this->overrides[$feature][$scope] = FeatureFlagOverride::query()
                    ->where('feature', $feature)
                    ->where('scope', $scope)
                    ->first() ?? false;
            } catch (Throwable) {
                $this->overrides[$feature][$scope] = false;
            }
        }

        $override = $this->overrides[$feature][$scope];

        return $override === false ? null : $override;
    }

    /**
     * The targeting decision for a (serialised) scope, without the kill switch: its own
     * value, the value for everyone, or the rollout. Null leaves it to Pennant.
     */
    public function target(string $feature, string $scope): ?Decision
    {
        if (($override = $this->override($feature, $scope)) !== null) {
            return new Decision($override->getValue(), Decision::SOURCE_OVERRIDE);
        }

        $rule = $this->rule($feature);

        if ($rule === null) {
            return null;
        }

        if ($rule->mode === FeatureFlagRule::MODE_EVERYONE) {
            return new Decision($rule->getEveryoneValue(), Decision::SOURCE_EVERYONE);
        }

        if (($percentage = $rule->getPercentage()) !== null) {
            return new Decision(Rollout::includes($rule->getSalt(), $scope, $percentage), Decision::SOURCE_ROLLOUT);
        }

        return null;
    }

    public function flush(): void
    {
        $this->rules = null;
        $this->targetedFeatures = null;
        $this->overrides = [];
    }

    /**
     * Whether the plugin's tables are migrated (remembered for the process once they are).
     */
    public static function tablesAvailable(): bool
    {
        if (self::$tablesExist) {
            return true;
        }

        try {
            $schema = Schema::connection((new FeatureFlagRule)->getConnectionName());

            return self::$tablesExist = $schema->hasTable('alfakit_feature_flag_rules') && $schema->hasTable('alfakit_feature_flag_overrides');
        } catch (Throwable) {
            return false;
        }
    }
}
