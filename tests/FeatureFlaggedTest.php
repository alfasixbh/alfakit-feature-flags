<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Actions\ExportReportsAction;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Pages\BetaReports;

use function AlfaSix\AlfakitFeatureFlags\Tests\flagsTeam;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function AlfaSix\AlfakitFeatureFlags\Tests\inertiaGet;
use function AlfaSix\AlfakitFeatureFlags\Tests\pageProps;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
});

it('hides a page and refuses its route while the feature is inactive for the user', function (): void {
    actingAs($user = flagsUser('Grace'));

    inertiaGet('/flags/beta-reports')->assertForbidden();

    expect(BetaReports::getNavigationItems())->toBe([]);

    FeatureFlags::setFor('beta-reports', $user, true);

    inertiaGet('/flags/beta-reports')->assertOk();
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));

    expect(BetaReports::getNavigationItems())->toHaveCount(1);
});

it('follows rollouts and the kill switch', function (): void {
    actingAs(flagsUser('Grace'));
    FeatureFlags::rollout('beta-reports', 100);

    inertiaGet('/flags/beta-reports')->assertOk();

    FeatureFlags::kill('beta-reports');

    inertiaGet('/flags/beta-reports')->assertForbidden();
});

it('hides actions while their feature is inactive', function (): void {
    actingAs($user = flagsUser('Grace'));
    FeatureFlags::setFor('beta-reports', $user, true);

    $page = alfakit()->testPage(BetaReports::class, panel: 'flags');
    $page->assertActionHidden('exportReports');

    FeatureFlags::setFor('new-billing', $user, true);

    alfakit()->testPage(BetaReports::class, panel: 'flags')->assertActionVisible('exportReports');

    expect(ExportReportsAction::getFeatureFlag())->toBe('new-billing');
});

it('checks the current tenant on panels with tenancy', function (): void {
    $team = flagsTeam('Alpha');
    $user = flagsUser('Grace');
    $user->teams()->attach($team);
    actingAs($user);

    // The user has it, the team doesn't: the tenant is the scope.
    FeatureFlags::setFor('beta-reports', $user, true);
    inertiaGet('/flags-team/alpha/beta-reports')->assertForbidden();

    alfakit()->setCurrentPanel(alfakit()->getPanel('flags-team'));
    FeatureFlags::setFor('beta-reports', $team, true);

    expect(pageProps('/flags-team/alpha/beta-reports'))->toHaveKey('title');
});
