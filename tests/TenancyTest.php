<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use AlfaSix\AlfakitFeatureFlags\Pages\Features;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\block;
use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\featuresPage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsPlugin;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsTeam;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->alpha = flagsTeam('Alpha');
    $this->beta = flagsTeam('Beta');
    $this->ada = flagsUser();
    $this->ada->teams()->attach([$this->alpha->getKey(), $this->beta->getKey()]);
    actingAs($this->ada);
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags-team'));
});

function teamFeatures(string $team = 'alpha'): AlfaSix\Alfakit\Testing\PageTester
{
    return featuresPage('flags-team', ['tenant' => $team]);
}

it('shows the current tenant\'s value and where it comes from instead of global counts', function (): void {
    FeatureFlags::setFor('beta-reports', test()->alpha, true);
    Feature::for(flagsUser('Someone'))->active('beta-reports');

    $records = array_column(teamFeatures()->getTable()['records'], null, 'key');

    expect($records['beta-reports']['cells']['value_label']['state'])->toBe('On')
        ->and($records['beta-reports']['cells']['source']['state'])->toBe('Own value')
        ->and($records['beta-reports']['cells'])->not->toHaveKey('active');

    $other = array_column(teamFeatures('beta')->getTable()['records'], null, 'key');

    expect($other['beta-reports']['cells']['value_label']['state'])->toBe('Off');
});

it('turns features on and off for the current tenant only', function (): void {
    teamFeatures()->callTableAction('activateForTenant', 'beta-reports')->assertNotified('beta-reports is on');

    expect(Feature::for(test()->alpha)->active('beta-reports'))->toBeTrue()
        ->and(Feature::for(test()->beta)->active('beta-reports'))->toBeFalse()
        ->and(FeatureFlagOverride::query()->firstOrFail()->tenant_id)->toBe((string) test()->alpha->getKey())
        ->and(FeatureFlagRule::query()->exists())->toBeFalse();

    teamFeatures()->callTableAction('forgetForTenant', 'beta-reports');

    expect(Feature::for(test()->alpha)->active('beta-reports'))->toBeFalse();
});

it('offers no action that affects every tenant', function (): void {
    teamFeatures()
        ->assertTableActionDoesNotExist('kill', 'beta-reports')
        ->assertTableActionDoesNotExist('activateForEveryone', 'beta-reports')
        ->assertTableActionDoesNotExist('purge', 'beta-reports');

    featurePage('beta-reports', 'flags-team', ['tenant' => 'alpha'])
        ->assertActionDoesNotExist('kill')
        ->assertActionDoesNotExist('rollout')
        ->assertActionExists('activateForTenant');
});

it('allows global actions on tenant panels when asked to', function (): void {
    flagsPlugin('flags-team')->globalActionsOnTenantPanels();

    teamFeatures()->callTableAction('kill', 'beta-reports');

    expect(FeatureFlags::rules()->isKilled('beta-reports'))->toBeTrue();
});

it('only lists and audits what was done in the current tenant', function (): void {
    alfakit()->setTenant(test()->alpha);
    FeatureFlags::setFor('beta-reports', test()->alpha, true);
    alfakit()->setTenant(test()->beta);
    FeatureFlags::setFor('beta-reports', test()->beta, true);
    alfakit()->setTenant(null);

    $page = featurePage('beta-reports', 'flags-team', ['tenant' => 'alpha']);

    expect($page->getTableRecordKeys())->toHaveCount(1)
        ->and(FeatureFlagAudit::query()->count())->toBe(2);

    alfakit()->setTenant(test()->alpha);
    $path = (string) parse_url(ManageFeature::urlFor('beta-reports', alfakit()->getPanel('flags-team')), PHP_URL_PATH);

    expect($path)->toBe('/flags-team/alpha/feature-flags/'.ManageFeature::encode('beta-reports'))
        ->and(block($path, 'table:audit')['records'])->toHaveCount(1)
        // No cross-tenant stored values on tenant panels.
        ->and(collect(\AlfaSix\AlfakitFeatureFlags\Tests\pageProps($path)['content'])->firstWhere('key', 'table:stored'))->toBeNull();
});

it('only offers the tenant and its members as scopes', function (): void {
    $member = flagsUser('Grace Member');
    $member->teams()->attach(test()->alpha);
    $outsider = flagsUser('Gary Outsider');
    $outsider->teams()->attach(test()->beta);

    alfakit()->setTenant(test()->alpha);
    $options = ManageFeature::searchScopes('G');

    expect(array_values($options))->toContain('Grace Member (Tenant Users)')
        ->and(array_values($options))->not->toContain('Gary Outsider (Tenant Users)')
        ->and(ManageFeature::resolveScopeOption(Feature::serializeScope($member))?->getKey())->toBe($member->getKey())
        ->and(ManageFeature::resolveScopeOption(Feature::serializeScope($outsider)))->toBeNull()
        ->and(ManageFeature::resolveScopeOption(Feature::serializeScope(test()->alpha))?->getKey())->toBe(test()->alpha->getKey())
        ->and(ManageFeature::resolveScopeOption(Feature::serializeScope(test()->beta)))->toBeNull();
});

it('refuses to target another tenant\'s members through the form', function (): void {
    $outsider = flagsUser('Gary Outsider');
    $outsider->teams()->attach(test()->beta);

    featurePage('beta-reports', 'flags-team', ['tenant' => 'alpha'])
        ->callTableAction('addScopes', data: ['scopes' => [Feature::serializeScope($outsider)], 'state' => 'on'])
        ->assertHasActionErrors();

    expect(FeatureFlagOverride::query()->count())->toBe(0);
});

it('uses the user as the scope with tenantScope(false)', function (): void {
    flagsPlugin('flags-team')->tenantScope(false);

    expect(flagsPlugin('flags-team')->getManagedTenant())->toBeNull();

    $rows = Features::rows(['beta-reports']);

    expect($rows['beta-reports']['active'])->not->toBeNull();
});
