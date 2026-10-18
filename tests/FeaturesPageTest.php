<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Pages\Features;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureCatalog;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\block;
use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\featuresPage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
    actingAs(flagsUser());
});

it('lists defined, class-based and stored-only features with their description and type', function (): void {
    Feature::for('legacy-scope')->activate('legacy-flag');
    Feature::store()->flushCache();

    $records = array_column(block('/flags/feature-flags', 'table')['records'], null, 'key');

    expect(array_keys($records))->toBe(['beta-reports', 'dark-mode', 'legacy-flag', 'new-billing', 'purchase-button'])
        ->and($records['beta-reports']['cells']['name']['description'])->toBe('Reports that are still in beta.')
        ->and($records['new-billing']['cells']['name']['description'])->toBe('The new billing page with invoices and payment methods.')
        ->and($records['purchase-button']['cells']['name']['description'])->toBe('The colour of the purchase button.')
        ->and($records['purchase-button']['cells']['type']['state'])->toBe('Rich value')
        ->and($records['new-billing']['cells']['type']['state'])->toBe('On / off')
        ->and($records['beta-reports']['url'])->toBe(ManageFeature::urlFor('beta-reports', alfakit()->getPanel('flags')));

    expect(FeatureCatalog::info('new-billing')->class)->toBe(AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features\NewBilling::class)
        ->and(FeatureCatalog::info('legacy-flag')->isDefined)->toBeFalse();
});

it('counts active and inactive stored values and shows when a feature last changed', function (): void {
    $users = collect(range(1, 3))->map(fn (int $i) => flagsUser("User {$i}"));
    Feature::for($users[0])->activate('beta-reports');
    Feature::for($users[1])->active('beta-reports');
    Feature::for($users[2])->active('beta-reports');

    $row = Features::rows(['beta-reports'])['beta-reports'];

    expect($row['active'])->toBe(1)
        ->and($row['inactive'])->toBe(2)
        ->and($row['overrides'])->toBe(1)
        ->and($row['last_changed'])->not->toBeNull()
        ->and($row['status'])->toBe(Features::STATUS_DEFAULT);
});

it('shows the status of each feature', function (): void {
    FeatureFlags::activateForEveryone('dark-mode');
    FeatureFlags::rollout('beta-reports', 25);
    FeatureFlags::kill('new-billing');
    FeatureFlags::activateForEveryone('purchase-button', 'green');

    $rows = Features::rows();

    expect($rows['dark-mode']['status_label'])->toBe('On for everyone')
        ->and($rows['beta-reports']['status_label'])->toBe('Rolling out to 25%')
        ->and($rows['new-billing']['status_label'])->toBe('Killed')
        ->and($rows['purchase-button']['status_label'])->toBe('Value for everyone');

    featuresPage()->filterTable('status', 'killed')->assertCanSeeTableRecords(['new-billing'])->assertCountTableRecords(1);
    featuresPage()->filterTable('type', 'rich')->assertCanSeeTableRecords(['purchase-button'])->assertCountTableRecords(1);
    featuresPage()->searchTable('billing')->assertCanSeeTableRecords(['new-billing'])->assertCountTableRecords(1);
});

it('shows a feature page with its overview, targeting, stored values and audit trail', function (): void {
    $user = flagsUser('Grace');
    Feature::for($user)->active('beta-reports');
    FeatureFlags::setFor('beta-reports', $user, true);

    $page = featurePage('beta-reports')->assertOk()->assertTitle('beta-reports');

    expect($page->getContentBlock('infolist'))->not->toBeNull()
        ->and($page->getTableRecordKeys())->toHaveCount(1);

    $url = ManageFeature::urlFor('beta-reports', alfakit()->getPanel('flags'));
    $audit = block(parse_url($url, PHP_URL_PATH), 'table:audit');
    $stored = block(parse_url($url, PHP_URL_PATH), 'table:stored');

    expect($audit['records'])->toHaveCount(1)
        ->and($audit['records'][0]['cells']['action']['state'])->toBe('Turned on for a scope')
        // Grace, and Ada: the navigation checked `beta-reports` for her (the BetaReports page).
        ->and(array_column(array_column(array_column($stored['records'], 'cells'), 'scope'), 'state'))->toContain('Grace', 'Ada Admin');
});

it('answers 404 for unknown features and malformed names', function (): void {
    featurePage('does-not-exist')->assertNotFound();
    \Pest\Laravel\get('/flags/feature-flags/%2E%2E')->assertNotFound();
});

it('finds features with class names', function (): void {
    Feature::define('App\\Features\\Something', fn (): bool => true);

    featurePage('App\\Features\\Something')->assertOk()->assertTitle('App\\Features\\Something');
});

it('counts the array store\'s values too', function (): void {
    AlfaSix\AlfakitFeatureFlags\Tests\flagsPlugin()->store('array');
    $users = [flagsUser('A'), flagsUser('B')];
    Feature::store('array')->for($users[0])->activate('dark-mode');
    Feature::store('array')->for($users[1])->deactivate('dark-mode');

    $row = Features::rows(['dark-mode'])['dark-mode'];

    expect($row['active'])->toBe(1)->and($row['inactive'])->toBe(1);
});

it('labels feature values with valueLabels() and translatable values() closures', function (): void {
    Feature::define(AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features\CheckoutLayout::class);
    Feature::define(AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features\ShippingSpeed::class);
    AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin::get()->values([
        'dark-mode' => fn (): array => ['on' => app()->getLocale() === 'de' ? 'An' : 'On', 'off' => 'Off'],
    ]);

    app()->setLocale('de');

    expect(FeatureCatalog::info('checkout-layout')->options)->toBe(['"single"' => 'Eine Seite', '"steps"' => 'Schritte', '"express"' => 'express'])
        ->and(FeatureCatalog::info('shipping-speed')->options)->toBe(['"standard"' => 'Standard', '"next_day"' => 'Next day'])
        ->and(FeatureCatalog::info('dark-mode')->options)->toBe(['"on"' => 'An', '"off"' => 'Off'])
        // The attribute still labels values on its own.
        ->and(FeatureCatalog::info('purchase-button')->options)->toBe(['"blue"' => 'Blue button', '"green"' => 'Green button']);

    app()->setLocale('en');
});
