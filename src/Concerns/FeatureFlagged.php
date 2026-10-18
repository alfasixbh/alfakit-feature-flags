<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Concerns;

use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use TypeError;

/**
 * Hides a page, a resource or an action while a feature is inactive for the current user
 * (or the current tenant on panels with tenancy, see `FeatureFlagsPlugin::scopeUsing()`):
 * no navigation item, a 403 for its routes, no button.
 *
 * ```php
 * class Billing extends Page
 * {
 *     use FeatureFlagged;
 *
 *     protected static ?string $feature = 'new-billing';
 * }
 *
 * class ExportInvoicesAction extends Action
 * {
 *     use FeatureFlagged;
 *
 *     protected static ?string $feature = NewBilling::class;
 * }
 * ```
 *
 * Override `getFeatureFlag()` to decide the feature at runtime, or `getFeatureFlagScope()`
 * to check it for another scope.
 */
trait FeatureFlagged
{
    /**
     * The feature that must be active: `static::$feature` by default.
     */
    public static function getFeatureFlag(): ?string
    {
        if (! property_exists(static::class, 'feature')) {
            return null;
        }

        $feature = static::$feature;

        return is_string($feature) && $feature !== '' ? $feature : null;
    }

    public static function getFeatureFlagScope(): mixed
    {
        return FeatureFlagsPlugin::get()->getCurrentScope();
    }

    public static function isFeatureFlagActive(): bool
    {
        $feature = static::getFeatureFlag();

        if ($feature === null) {
            return true;
        }

        try {
            return FeatureFlags::store()->for(static::getFeatureFlagScope())->active($feature);
        } catch (TypeError) {
            // The feature's resolver doesn't take this kind of scope: treat it as inactive.
            return false;
        }
    }

    /**
     * Pages and resources: no access (navigation, routes, internal endpoints) while inactive.
     */
    public static function canAccess(): bool
    {
        if (! static::isFeatureFlagActive()) {
            return false;
        }

        return method_exists(parent::class, 'canAccess') ? parent::canAccess() : true;
    }

    /**
     * Actions: hidden (and refused by the action endpoint) while inactive.
     */
    public function isHidden(): bool
    {
        if (! static::isFeatureFlagActive()) {
            return true;
        }

        return method_exists(parent::class, 'isHidden') ? parent::isHidden() : false;
    }
}
