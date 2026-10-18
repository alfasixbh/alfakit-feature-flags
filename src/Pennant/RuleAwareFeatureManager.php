<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Pennant;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Laravel\Pennant\Drivers\Decorator;
use Laravel\Pennant\FeatureManager;

/**
 * Pennant's manager, building every store with `RuleAwareDecorator` so the panel's rules
 * apply to the application's `Feature::active()` checks. Stores and drivers (including
 * custom ones registered with `Feature::extend()`) are created exactly as before.
 */
class RuleAwareFeatureManager extends FeatureManager
{
    /**
     * Take over a manager that may already have custom drivers, a scope resolver or the
     * morph map setting.
     */
    public static function fromManager(FeatureManager $manager, Container $container): static
    {
        $instance = new static($container);
        $instance->customCreators = $manager->customCreators;
        $instance->defaultScopeResolver = $manager->defaultScopeResolver;
        $instance->useMorphMap = $manager->useMorphMap;

        return $instance;
    }

    /**
     * @param  string  $name
     * @return Decorator
     */
    protected function resolve($name)
    {
        $decorator = parent::resolve($name);

        return new RuleAwareDecorator(
            $name,
            $decorator->getDriver(),
            $this->defaultScopeResolver($name),
            $this->container,
            new Collection,
        );
    }
}
