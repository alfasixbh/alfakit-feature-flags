<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Infolists\Components\FeatureFlagsEntry;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources\MemberResource\Pages\ViewMember;
use Laravel\Pennant\Feature;

use function AlfaSix\AlfakitFeatureFlags\Tests\flagsPlugin;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function AlfaSix\AlfakitFeatureFlags\Tests\pageProps;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
    actingAs(flagsUser());
    $this->grace = flagsUser('Grace');
});

function memberPage(): AlfaSix\Alfakit\Testing\PageTester
{
    return alfakit()->testPage(ViewMember::class, ['record' => test()->grace->getKey()], panel: 'flags');
}

it('shows the record\'s value of each feature and where it comes from', function (): void {
    FeatureFlags::rollout('new-billing', 100);

    $json = json_encode(pageProps('/flags/members/'.test()->grace->getKey())['content']);

    expect($json)->toContain('data-alfakit-feature-flags')
        ->toContain('"data-feature":"beta-reports"')
        ->toContain('"data-feature":"purchase-button"')
        ->toContain('Rollout')
        ->toContain('"content":"blue"');
});

it('turns a feature on and off for the record and goes back to the rules', function (): void {
    memberPage()->callFormComponentAction('feature-flags-0-actions', 'activate-0', schema: 'infolist');

    expect(Feature::for(test()->grace)->active('beta-reports'))->toBeTrue();

    memberPage()->callFormComponentAction('feature-flags-0-actions', 'deactivate-0', schema: 'infolist');

    expect(Feature::for(test()->grace)->active('beta-reports'))->toBeFalse()
        ->and(FeatureFlags::override('beta-reports', test()->grace)?->getValue())->toBeFalse();

    memberPage()->callFormComponentAction('feature-flags-0-actions', 'forget-0', schema: 'infolist');

    expect(FeatureFlags::override('beta-reports', test()->grace))->toBeNull();
});

it('sets a rich value for the record', function (): void {
    memberPage()->callFormComponentAction('feature-flags-2-actions', 'value-2', ['value' => '"green"'], schema: 'infolist');

    expect(Feature::for(test()->grace)->value('purchase-button'))->toBe('green');
});

it('is hidden from users the plugin does not authorise', function (): void {
    actingAs(flagsUser('Eve Intruder'));

    $json = json_encode(pageProps('/flags/members/'.test()->grace->getKey())['content']);

    expect($json)->not->toContain('data-alfakit-feature-flags');
});

it('refuses changes without the target ability', function (): void {
    flagsPlugin()->authorizeActions(static fn (string $ability): bool => $ability !== 'target');

    memberPage()->assertOk();

    try {
        memberPage()->callFormComponentAction('feature-flags-0-actions', 'activate-0', schema: 'infolist');
        $offered = true;
    } catch (PHPUnit\Framework\AssertionFailedError) {
        $offered = false;
    }

    expect($offered)->toBeFalse();

    expect(Feature::for(test()->grace)->active('beta-reports'))->toBeFalse();
});

it('lists every feature by default and takes another scope', function (): void {
    $entry = FeatureFlagsEntry::make();

    expect($entry->getFeatures())->toContain('beta-reports', 'dark-mode', 'new-billing', 'purchase-button');
});
