<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
    actingAs(flagsUser());
});

it('schedules an activation and a deactivation from the feature page', function (): void {
    featurePage('beta-reports')
        ->callAction('schedule', ['activate_at' => '2030-01-01 09:00:00', 'deactivate_at' => '2030-02-01 09:00:00'])
        ->assertHasNoActionErrors()
        ->assertNotified('The schedule of beta-reports was saved');

    $rule = FeatureFlagRule::query()->where('feature', 'beta-reports')->firstOrFail();

    expect($rule->activate_at?->toDateTimeString())->toBe('2030-01-01 09:00:00')
        ->and($rule->deactivate_at?->toDateTimeString())->toBe('2030-02-01 09:00:00');
});

it('refuses a deactivation before the activation', function (): void {
    featurePage('beta-reports')
        ->callAction('schedule', ['activate_at' => '2030-02-01 09:00:00', 'deactivate_at' => '2030-01-01 09:00:00'])
        ->assertHasActionErrors(['deactivate_at']);

    expect(FeatureFlagRule::query()->exists())->toBeFalse();
});

it('applies due schedules with the command, once', function (): void {
    $user = flagsUser('Grace');
    FeatureFlags::schedule('beta-reports', now()->addHour(), now()->addDays(2));

    artisan('alfakit-feature-flags:apply-schedules')->expectsOutputToContain('No feature flag schedule is due')->assertSuccessful();
    expect(Feature::for($user)->active('beta-reports'))->toBeFalse();

    travelTo(now()->addHours(2));
    artisan('alfakit-feature-flags:apply-schedules')->expectsOutputToContain('beta-reports: activated for everyone')->assertSuccessful();

    expect(Feature::for($user)->active('beta-reports'))->toBeTrue()
        ->and(FeatureFlagRule::query()->where('feature', 'beta-reports')->value('activate_at'))->toBeNull()
        ->and(FeatureFlagAudit::query()->where('source', FeatureFlagAudit::SOURCE_SCHEDULE)->where('action', 'activated_for_everyone')->count())->toBe(1);

    artisan('alfakit-feature-flags:apply-schedules')->expectsOutputToContain('No feature flag schedule is due');

    travelTo(now()->addDays(3));
    artisan('alfakit-feature-flags:apply-schedules')->expectsOutputToContain('beta-reports: deactivated for everyone');

    expect(Feature::for($user)->active('beta-reports'))->toBeFalse()
        ->and(FeatureFlagRule::query()->where('feature', 'beta-reports')->firstOrFail()->hasSchedule())->toBeFalse();
});

it('applies an activation and a deactivation that are both due in order', function (): void {
    FeatureFlags::schedule('dark-mode', now()->subHours(2), now()->subHour());
    $applied = FeatureFlags::applySchedules();

    expect($applied)->toBe([
        ['feature' => 'dark-mode', 'action' => 'activate'],
        ['feature' => 'dark-mode', 'action' => 'deactivate'],
    ])->and(Feature::for(flagsUser('Grace'))->active('dark-mode'))->toBeFalse();
});

it('activates rich features with the scheduled value', function (): void {
    FeatureFlags::schedule('purchase-button', now()->subMinute(), null, 'green');
    FeatureFlags::applySchedules();

    expect(Feature::for(flagsUser('Grace'))->value('purchase-button'))->toBe('green');
});

it('registers the command with the scheduler', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    expect($events->contains(fn (string $command): bool => str_contains($command, 'alfakit-feature-flags:apply-schedules')))->toBeTrue();
});
