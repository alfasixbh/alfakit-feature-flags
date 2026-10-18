<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use AlfaSix\AlfakitFeatureFlags\Pages\Features;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\featuresPage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsPlugin;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function AlfaSix\AlfakitFeatureFlags\Tests\inertiaGet;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
});

it('registers the pages on the panel', function (): void {
    expect(alfakit()->getPanel('flags')->getPages())->toContain(Features::class, ManageFeature::class);
});

it('opens the pages for users the manageFeatureFlags gate allows', function (): void {
    actingAs(flagsUser());

    inertiaGet('/flags/feature-flags')->assertOk();
    featurePage('beta-reports')->assertOk();
});

it('denies everyone else by default', function (): void {
    actingAs(flagsUser('Eve Intruder'));

    inertiaGet('/flags/feature-flags')->assertForbidden();
    inertiaGet('/flags/feature-flags/'.ManageFeature::encode('beta-reports'))->assertForbidden();

    expect(Features::getNavigationItems())->toBe([]);
});

it('denies when the gate is not defined', function (): void {
    actingAs(flagsUser());
    Gate::define('manageFeatureFlags', static fn (): bool => false);

    inertiaGet('/flags/feature-flags')->assertForbidden();
});

it('sends guests to the login page', function (): void {
    get('/flags/feature-flags')->assertRedirect();
});

it('uses authorize() instead of the gate', function (): void {
    flagsPlugin()->authorize(static fn ($user): bool => $user->getAttribute('name') === 'Eve Operator');

    actingAs(flagsUser('Eve Operator'));
    inertiaGet('/flags/feature-flags')->assertOk();

    actingAs(flagsUser());
    inertiaGet('/flags/feature-flags')->assertForbidden();
});

it('checks another gate with gate()', function (): void {
    actingAs(flagsUser('Eve Operator'));
    Gate::define('releaseManager', static fn (): bool => true);
    flagsPlugin()->gate('releaseManager');

    inertiaGet('/flags/feature-flags')->assertOk();
});

it('narrows what users may do per ability and feature', function (): void {
    actingAs(flagsUser());
    flagsPlugin()->authorizeActions(static fn (string $ability, ?string $feature): bool => $ability !== FeatureFlagsPlugin::ABILITY_KILL && $feature !== 'dark-mode');

    featuresPage()
        ->assertTableActionHidden('kill', 'beta-reports')
        ->assertTableActionVisible('activateForEveryone', 'beta-reports')
        ->assertTableActionHidden('activateForEveryone', 'dark-mode');
});

it('changes nothing when read only', function (): void {
    actingAs(flagsUser());
    flagsPlugin()->readOnly();

    featurePage('beta-reports')
        ->assertOk()
        ->assertActionHidden('activateForEveryone')
        ->assertActionHidden('rollout')
        ->assertTableActionHidden('addScopes');
});

it('refuses actions posted by users who may not run them', function (): void {
    actingAs(flagsUser());
    $token = featurePage('beta-reports')->getToken();

    actingAs(flagsUser('Eve Intruder'));

    postJson('/flags/_alfakit/actions', ['token' => $token, 'component' => 'page', 'action' => 'kill', 'data' => [], 'state' => []])
        ->assertForbidden();

    expect(FeatureFlagRule::query()->exists())->toBeFalse();
});
