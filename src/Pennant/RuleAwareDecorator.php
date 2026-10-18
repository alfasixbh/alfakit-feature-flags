<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Pennant;

use AlfaSix\AlfakitFeatureFlags\Support\RuleStore;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Drivers\Decorator;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Feature;

/**
 * Pennant's store decorator with the panel's rules on top. For features the panel
 * manages, a value resolves in this order:
 *
 * 1. the kill switch (inactive for everyone),
 * 2. the class feature's `before()` hook,
 * 3. the scope's own value (targeting),
 * 4. the value set for everyone, or the percentage rollout,
 * 5. Pennant: the stored value, or the feature's definition.
 *
 * Rule decisions are never written to the store, so changing a rule takes effect at once
 * and removing it brings the stored values back. Features without rules take Pennant's own
 * path untouched.
 */
class RuleAwareDecorator extends Decorator
{
    public function get($feature, $scope): mixed
    {
        $feature = $this->resolveFeature($feature);
        $scope = $this->resolveScope($scope);
        $rules = $this->rules();

        if ($rules === null || ! $rules->hasRules($feature)) {
            return parent::get($feature, $scope);
        }

        $value = $this->resolveWithRules($feature, $scope, $rules);

        Event::dispatch(new FeatureRetrieved($feature, $scope, $value));

        return $value;
    }

    public function getAll($features): array
    {
        $rules = $this->rules();

        if ($rules === null) {
            return parent::getAll($features);
        }

        $normalised = $this->normalizeFeaturesToLoad($features);
        $ruled = $normalised->filter(static fn (array $scopes, int|string $feature): bool => $rules->hasRules((string) $feature));

        if ($ruled->isEmpty()) {
            return parent::getAll($features);
        }

        $plain = $normalised->diffKeys($ruled);

        $results = $plain->isEmpty() ? [] : parent::getAll($plain
            ->map(static fn (array $scopes, int|string $feature): array => ['feature' => (string) $feature, 'scope' => $scopes])
            ->values()
            ->all());

        foreach ($ruled as $feature => $scopes) {
            $results[$feature] = array_map(fn (mixed $scope): mixed => $this->resolveWithRules((string) $feature, $scope, $rules), $scopes);
        }

        return $results;
    }

    public function flushCache()
    {
        parent::flushCache();

        $this->rules()?->flush();
    }

    protected function resolveWithRules(string $feature, mixed $scope, RuleStore $rules): mixed
    {
        if ($rules->isKilled($feature)) {
            return false;
        }

        if ($this->hasBeforeHook($feature)) {
            $class = $this->implementationClass($feature);
            $value = $class === null ? null : $this->resolveBeforeHook($feature, $scope, $this->container->make($class)->before(...));

            if ($value !== null) {
                return $value;
            }
        }

        $serialised = Feature::serializeScope($scope);

        if (($decision = $rules->target($feature, $serialised)) !== null) {
            return $decision->value;
        }

        $item = $this->cache->whereStrict('scope', $serialised)->whereStrict('feature', $feature)->first();

        if ($item !== null) {
            return $item['value'];
        }

        $value = $this->driver->get($feature, $scope);

        $this->putInCache($feature, $scope, $value);

        return $value;
    }

    protected function rules(): ?RuleStore
    {
        return $this->container->bound(RuleStore::class) ? $this->container->make(RuleStore::class) : null;
    }
}
