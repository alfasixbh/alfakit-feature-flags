<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Commands;

use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use Illuminate\Console\Command;

/**
 * Activates and deactivates the features whose schedule is due. The plugin schedules it
 * every minute (`register_schedule`); run it from your own schedule otherwise.
 */
class ApplySchedulesCommand extends Command
{
    protected $signature = 'alfakit-feature-flags:apply-schedules';

    protected $description = 'Activate and deactivate the feature flags whose schedule is due';

    public function handle(): int
    {
        $applied = FeatureFlags::applySchedules();

        foreach ($applied as $change) {
            $this->components->info(sprintf('%s: %s', $change['feature'], $change['action'] === 'activate' ? 'activated for everyone' : 'deactivated for everyone'));
        }

        if ($applied === []) {
            $this->components->info('No feature flag schedule is due.');
        }

        return self::SUCCESS;
    }
}
