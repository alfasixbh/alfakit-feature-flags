<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsTeam;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
    actingAs(flagsUser());
});

it('searches users and teams through the scope resolvers', function (): void {
    $grace = flagsUser('Grace Hopper');
    flagsUser('Linus');
    $team = flagsTeam('Gamma');

    $options = ManageFeature::searchScopes('G');

    expect($options)->toHaveKey(Feature::serializeScope($grace))
        ->and($options[Feature::serializeScope($grace)])->toBe('Grace Hopper (Tenant Users)')
        ->and($options)->toHaveKey(Feature::serializeScope($team))
        ->and(array_values($options))->not->toContain('Linus (Tenant Users)');
});

it('targets chosen scopes with on, off or a rich value', function (): void {
    $grace = flagsUser('Grace');
    $linus = flagsUser('Linus');

    featurePage('beta-reports')
        ->callTableAction('addScopes', data: ['scopes' => [Feature::serializeScope($grace), Feature::serializeScope($linus)], 'state' => 'on'])
        ->assertHasNoActionErrors()
        ->assertNotified('2 scopes targeted');

    expect(Feature::for($grace)->active('beta-reports'))->toBeTrue()
        ->and(Feature::for($linus)->active('beta-reports'))->toBeTrue()
        ->and(FeatureFlagOverride::query()->where('feature', 'beta-reports')->pluck('updated_by_name')->unique()->all())->toBe(['Ada Admin']);

    featurePage('purchase-button')
        ->callTableAction('addScopes', data: ['scopes' => [Feature::serializeScope($grace)], 'state' => 'value', 'value' => '"green"'])
        ->assertHasNoActionErrors();

    expect(Feature::for($grace)->value('purchase-button'))->toBe('green');
});

it('refuses scopes no resolver offers, whatever the browser posts', function (): void {
    featurePage('beta-reports')
        ->callTableAction('addScopes', data: ['scopes' => ['App\\Models\\Admin|1'], 'state' => 'on'])
        ->assertHasActionErrors(['scopes.0']);

    featurePage('beta-reports')
        ->callTableAction('addScopes', data: ['scopes' => [TenantUser::class.'|999'], 'state' => 'on'])
        ->assertHasActionErrors(['scopes.0']);

    expect(FeatureFlagOverride::query()->count())->toBe(0);
});

it('toggles, sets and removes targeted scopes from the table', function (): void {
    $grace = flagsUser('Grace');
    FeatureFlags::setFor('beta-reports', $grace, true);
    $override = FeatureFlagOverride::query()->firstOrFail();

    featurePage('beta-reports')
        ->assertCanSeeTableRecords([$override])
        ->assertTableActionHidden('activateScope', $override)
        ->callTableAction('deactivateScope', $override);

    expect(Feature::for($grace)->active('beta-reports'))->toBeFalse();

    featurePage('beta-reports')->callTableAction('forgetScope', $override)->assertNotified('Scope removed');

    expect(FeatureFlagOverride::query()->count())->toBe(0);
});

it('removes several targeted scopes at once', function (): void {
    FeatureFlags::setFor('beta-reports', flagsUser('A'), true);
    FeatureFlags::setFor('beta-reports', flagsUser('B'), true);

    featurePage('beta-reports')
        ->callTableBulkAction('forgetScopes', FeatureFlagOverride::query()->get())
        ->assertNotified('2 scopes removed');

    expect(FeatureFlagOverride::query()->count())->toBe(0);
});
