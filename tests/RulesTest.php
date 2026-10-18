<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Pennant\RuleAwareDecorator;
use AlfaSix\AlfakitFeatureFlags\Support\Decision;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\Rollout;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('decorates every Pennant store', function (): void {
    expect(Feature::store())->toBeInstanceOf(RuleAwareDecorator::class)
        ->and(Feature::store('array'))->toBeInstanceOf(RuleAwareDecorator::class)
        ->and(FeatureFlags::enforcesRules())->toBeTrue();
});

it('gives every scope a stable bucket', function (): void {
    $buckets = [];

    foreach (range(1, 500) as $id) {
        $buckets[$id] = Rollout::bucket('new-billing', "App\\Models\\User|{$id}");
    }

    foreach (range(1, 500) as $id) {
        expect(Rollout::bucket('new-billing', "App\\Models\\User|{$id}"))->toBe($buckets[$id]);
    }

    $min = min($buckets);
    $max = max($buckets);

    expect($min)->toBeGreaterThanOrEqual(0.0)->and($max)->toBeLessThan(100.0)
        // Another salt shuffles the scopes.
        ->and(Rollout::bucket('other', 'App\\Models\\User|1'))->not->toBe($buckets[1]);
});

it('rolls out to roughly the percentage, and raising it only adds scopes', function (): void {
    $scopes = array_map(static fn (int $id): string => "user-{$id}", range(1, 2000));
    $at = static fn (float $percentage): array => array_values(array_filter($scopes, static fn (string $scope): bool => Rollout::includes('salt', $scope, $percentage)));

    $ten = $at(10);
    $thirty = $at(30);

    expect(count($ten))->toBeGreaterThan(150)->toBeLessThan(250)
        ->and(count($thirty))->toBeGreaterThan(500)->toBeLessThan(700)
        ->and(array_diff($ten, $thirty))->toBe([])
        ->and($at(0))->toBe([])
        ->and($at(100))->toHaveCount(2000);
});

it('applies a rollout to Feature::active() without storing its decisions', function (): void {
    $users = collect(range(1, 40))->map(fn (int $i) => flagsUser("User {$i}"));
    $users->each(fn ($user) => Feature::for($user)->active('beta-reports'));

    FeatureFlags::rollout('beta-reports', 50);

    $active = $users->filter(fn ($user): bool => Feature::for($user)->active('beta-reports'));
    $expected = $users->filter(fn ($user): bool => Rollout::bucket('beta-reports', Feature::serializeScope($user)) < 50);

    expect($active->keys()->all())->toBe($expected->keys()->all())
        ->and($active->count())->toBeGreaterThan(5)->toBeLessThan(35)
        // The stored values are untouched: removing the rollout brings them back.
        ->and(DB::table('features')->where('name', 'beta-reports')->where('value', 'true')->count())->toBe(0);

    FeatureFlags::useDefinition('beta-reports');

    expect($users->filter(fn ($user): bool => Feature::for($user)->active('beta-reports')))->toHaveCount(0);
});

it('resolves in order: kill switch, before hook, own value, everyone or rollout, Pennant', function (): void {
    $user = flagsUser('Grace');

    FeatureFlags::rollout('beta-reports', 0);
    expect(FeatureFlags::evaluate('beta-reports', $user))->toEqual(new Decision(false, Decision::SOURCE_ROLLOUT));

    FeatureFlags::setFor('beta-reports', $user, true);
    expect(FeatureFlags::evaluate('beta-reports', $user))->toEqual(new Decision(true, Decision::SOURCE_OVERRIDE))
        ->and(Feature::for($user)->active('beta-reports'))->toBeTrue();

    FeatureFlags::kill('beta-reports');
    expect(FeatureFlags::evaluate('beta-reports', $user))->toEqual(new Decision(false, Decision::SOURCE_KILLED))
        ->and(Feature::for($user)->active('beta-reports'))->toBeFalse();

    FeatureFlags::restore('beta-reports');
    FeatureFlags::forgetFor('beta-reports', $user);
    FeatureFlags::useDefinition('beta-reports');

    expect(FeatureFlags::evaluate('beta-reports', $user))->toEqual(new Decision(false, Decision::SOURCE_DEFAULT));
});

it('loads features in bulk with the rules applied', function (): void {
    $users = [flagsUser('A'), flagsUser('B')];
    FeatureFlags::setFor('beta-reports', $users[1], true);

    $loaded = Feature::for($users)->load(['beta-reports', 'dark-mode']);

    expect($loaded)->toBe(['dark-mode' => [true, true], 'beta-reports' => [false, true]])
        ->and(Feature::for($users[1])->values(['beta-reports', 'dark-mode']))->toBe(['beta-reports' => true, 'dark-mode' => true]);
});

it('turns explicit Pennant writes in code into targeting', function (): void {
    $user = flagsUser('Grace');
    FeatureFlags::rollout('beta-reports', 0);

    Feature::for($user)->activate('beta-reports');

    expect(FeatureFlagOverride::query()->where('feature', 'beta-reports')->count())->toBe(1)
        // An explicit activation wins over the rollout.
        ->and(Feature::for($user)->active('beta-reports'))->toBeTrue();

    Feature::for($user)->forget('beta-reports');

    expect(FeatureFlagOverride::query()->count())->toBe(0)
        ->and(Feature::for($user)->active('beta-reports'))->toBeFalse();

    Feature::for($user)->activate('beta-reports');
    Feature::purge('beta-reports');

    expect(FeatureFlagOverride::query()->count())->toBe(0);
});

it('leaves features without rules on Pennant\'s own path', function (): void {
    $user = flagsUser('Grace');

    expect(Feature::for($user)->active('dark-mode'))->toBeTrue()
        ->and(Feature::for($user)->active('beta-reports'))->toBeFalse()
        ->and(DB::table('features')->count())->toBe(2);
});
