<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\featuresPage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsPlugin;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
    actingAs(flagsUser());
});

it('activates a feature for everyone, including scopes that resolve it later', function (): void {
    $before = flagsUser('Before');
    Feature::for($before)->active('beta-reports');

    featuresPage()->callTableAction('activateForEveryone', 'beta-reports')->assertNotified('beta-reports is on for everyone');

    $later = flagsUser('Later');

    expect(Feature::for($before)->active('beta-reports'))->toBeTrue()
        ->and(Feature::for($later)->active('beta-reports'))->toBeTrue()
        ->and(DB::table('features')->where('name', 'beta-reports')->where('scope', Feature::serializeScope($before))->value('value'))->toBe('true');

    featuresPage()->assertTableActionHidden('activateForEveryone', 'beta-reports');
});

it('deactivates a feature for everyone from its page', function (): void {
    FeatureFlags::activateForEveryone('dark-mode');
    $user = flagsUser('Grace');

    featurePage('dark-mode')->callAction('deactivateForEveryone')->assertNotified('dark-mode is off for everyone');

    expect(Feature::for($user)->active('dark-mode'))->toBeFalse()
        ->and(FeatureFlagRule::query()->where('feature', 'dark-mode')->value('value'))->toBe('false');
});

it('sets a validated rich value for everyone', function (): void {
    featurePage('purchase-button')
        ->callAction('setValueForEveryone', ['value' => '"green"'])
        ->assertHasNoActionErrors();

    expect(Feature::for(flagsUser('Grace'))->value('purchase-button'))->toBe('green');
});

it('rejects rich values that are not valid JSON', function (): void {
    flagsPlugin()->values([]);
    Feature::define('checkout-config', static fn (mixed $scope): array => ['steps' => 3]);

    featurePage('checkout-config')
        ->callAction('setValueForEveryone', ['value' => '{steps: 3'])
        ->assertHasActionErrors(['value']);

    expect(FeatureFlagRule::query()->where('feature', 'checkout-config')->exists())->toBeFalse();

    featurePage('checkout-config')
        ->callAction('setValueForEveryone', ['value' => '{"steps": 4, "express": true}'])
        ->assertHasNoActionErrors();

    expect(Feature::for(flagsUser('Grace'))->value('checkout-config'))->toBe(['steps' => 4, 'express' => true]);
});

it('only offers known values in a select when the feature lists them', function (): void {
    featurePage('purchase-button')
        ->callAction('setValueForEveryone', ['value' => '"red"'])
        ->assertHasActionErrors(['value']);
});

it('kills a feature for everyone, whatever else says, and restores it', function (): void {
    $user = flagsUser('Grace');
    FeatureFlags::setFor('dark-mode', $user, true);
    FeatureFlags::activateForEveryone('dark-mode');

    featuresPage()->callTableAction('kill', 'dark-mode')->assertNotified('dark-mode was killed');

    expect(Feature::for($user)->active('dark-mode'))->toBeFalse()
        ->and(Feature::for(flagsUser('New'))->active('dark-mode'))->toBeFalse();

    featuresPage()->assertTableActionHidden('kill', 'dark-mode');
    featurePage('dark-mode')->callAction('restore')->assertNotified('dark-mode was restored');

    expect(Feature::for($user)->active('dark-mode'))->toBeTrue();
});

it('purges stored values and targeting but keeps the rules', function (): void {
    $user = flagsUser('Grace');
    FeatureFlags::setFor('beta-reports', $user, true);
    FeatureFlags::rollout('beta-reports', 40);
    Feature::for(flagsUser('Other'))->active('beta-reports');

    featuresPage()->callTableAction('purge', 'beta-reports')->assertNotified('The stored values of beta-reports were purged');

    expect(DB::table('features')->where('name', 'beta-reports')->count())->toBe(0)
        ->and(FeatureFlagOverride::query()->where('feature', 'beta-reports')->count())->toBe(0)
        ->and(FeatureFlagRule::query()->where('feature', 'beta-reports')->first()?->getPercentage())->toBe(40.0);
});

it('resets a feature to its definition', function (): void {
    FeatureFlags::activateForEveryone('beta-reports');
    FeatureFlags::kill('beta-reports');

    featurePage('beta-reports')->callAction('reset')->assertNotified('beta-reports was reset');

    expect(FeatureFlagRule::query()->where('feature', 'beta-reports')->exists())->toBeFalse()
        ->and(Feature::for(flagsUser('Grace'))->active('beta-reports'))->toBeFalse()
        ->and(FeatureFlagAudit::query()->where('action', 'reset')->exists())->toBeTrue();
});

it('goes back to the definition', function (): void {
    FeatureFlags::activateForEveryone('beta-reports');

    featurePage('beta-reports')->callAction('useDefinition')->assertNotified();

    expect(FeatureFlagRule::query()->where('feature', 'beta-reports')->exists())->toBeFalse()
        // The stored values Pennant updated stay.
        ->and(Feature::for(flagsUser('Grace'))->active('beta-reports'))->toBeFalse();
});

it('rolls out with the slider value', function (): void {
    featurePage('beta-reports')->callAction('rollout', ['percentage' => 30])->assertNotified('beta-reports is rolling out to 30%');

    expect(FeatureFlagRule::query()->where('feature', 'beta-reports')->first()?->getPercentage())->toBe(30.0);
});

it('asks for confirmation before changing everyone', function (): void {
    $page = featurePage('beta-reports')->mountAction('activateForEveryone');

    expect($page->getMountedActionModal()['heading'])->toBe('Activate beta-reports for everyone?');
});

it('asks for the password before destructive actions when configured', function (): void {
    flagsPlugin()->requiresPasswordConfirmation();

    $page = featurePage('beta-reports')->mountAction('kill');

    expect(json_encode($page->getMountedActionModal()))->toContain('password');
});
