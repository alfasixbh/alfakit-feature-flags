<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags;

use AlfaSix\AlfakitFeatureFlags\Commands\ApplySchedulesCommand;
use AlfaSix\AlfakitFeatureFlags\Pennant\RuleAwareFeatureManager;
use AlfaSix\AlfakitFeatureFlags\Support\OverrideSync;
use AlfaSix\AlfakitFeatureFlags\Support\RuleStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Pennant\FeatureManager;

class AlfakitFeatureFlagsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/alfakit-feature-flags.php', 'alfakit-feature-flags');

        // Loaded once per request (or Octane request / queued job).
        $this->app->scoped(RuleStore::class);

        if ((bool) config('alfakit-feature-flags.enforce_rules', true)) {
            $this->app->extend(FeatureManager::class, static fn (FeatureManager $manager, Container $app): FeatureManager => $manager instanceof RuleAwareFeatureManager
                ? $manager
                : RuleAwareFeatureManager::fromManager($manager, $app));

            Feature::clearResolvedInstance(FeatureManager::class);
        }
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-feature-flags');

        if ((bool) config('alfakit-feature-flags.track_pennant_writes', true)) {
            OverrideSync::register($this->app['events']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ApplySchedulesCommand::class]);

            $this->publishes([
                __DIR__.'/../config/alfakit-feature-flags.php' => config_path('alfakit-feature-flags.php'),
            ], 'alfakit-feature-flags-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-feature-flags-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-feature-flags'),
            ], 'alfakit-feature-flags-translations');
        }

        if ((bool) config('alfakit-feature-flags.register_schedule', true)) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command(ApplySchedulesCommand::class)->everyMinute()->name('alfakit-feature-flags:apply-schedules');
            });
        }
    }
}
