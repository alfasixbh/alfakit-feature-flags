<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitFeatureFlags\AlfakitFeatureFlagsServiceProvider;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features\NewBilling;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features\PurchaseButton;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Panels\FeatureFlagsPanelProvider;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Panels\FeatureFlagsTenantPanelProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Laravel\Pennant\PennantServiceProvider;
use RuntimeException;

abstract class TestCase extends AlfakitTestCase
{
    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The default authorisation: only Ada may manage feature flags.
        Gate::define('manageFeatureFlags', static fn (Authenticatable $user): bool => $user->getAttribute('name') === 'Ada Admin');

        Feature::define('beta-reports', static fn (TenantUser|Team $scope): bool => false);
        Feature::define('dark-mode', static fn (mixed $scope): bool => true);
        Feature::define(NewBilling::class);
        Feature::define(PurchaseButton::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ...(class_exists(\Spatie\Activitylog\ActivitylogServiceProvider::class) ? [\Spatie\Activitylog\ActivitylogServiceProvider::class] : []),
            PennantServiceProvider::class,
            AlfakitFeatureFlagsServiceProvider::class,
            FeatureFlagsPanelProvider::class,
            FeatureFlagsTenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TenantUser::class);
        $app['config']->set('pennant.default', 'database');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__, 4).'/vendor/laravel/pennant/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
