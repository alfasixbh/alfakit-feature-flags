<?php

declare(strict_types=1);

use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;

use function AlfaSix\AlfakitFeatureFlags\Tests\block;
use function AlfaSix\AlfakitFeatureFlags\Tests\featurePage;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsPlugin;
use function AlfaSix\AlfakitFeatureFlags\Tests\flagsUser;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitFeatureFlags\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('flags'));
    actingAs($this->ada = flagsUser());
});

it('records who changed what, with the state before and after', function (): void {
    FeatureFlags::rollout('beta-reports', 10);
    FeatureFlags::rollout('beta-reports', 35);

    $audit = FeatureFlagAudit::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('rollout_set')
        ->and($audit->actor_name)->toBe('Ada Admin')
        ->and($audit->actor_id)->toBe((string) $this->ada->getKey())
        ->and($audit->source)->toBe('panel')
        ->and($audit->panel)->toBe('flags')
        ->and($audit->getBefore())->toMatchArray(['mode' => 'rollout', 'percentage' => 10])
        ->and($audit->getAfter())->toMatchArray(['mode' => 'rollout', 'percentage' => 35]);
});

it('records scope changes with the scope and its value', function (): void {
    $grace = flagsUser('Grace');
    FeatureFlags::setFor('purchase-button', $grace, 'green');

    $audit = FeatureFlagAudit::query()->firstOrFail();

    expect($audit->action)->toBe('scope_value_set')
        ->and($audit->scope_label)->toBe('Grace')
        ->and($audit->getBefore())->toMatchArray(['value' => 'blue', 'source' => 'default', 'override' => null])
        ->and($audit->getAfter())->toMatchArray(['value' => 'green', 'source' => 'override', 'override' => 'green']);
});

it('shows the audit trail on the feature page, newest first, with details', function (): void {
    FeatureFlags::kill('beta-reports');
    $this->travel(1)->minutes();
    FeatureFlags::restore('beta-reports');

    $url = (string) parse_url(ManageFeature::urlFor('beta-reports', alfakit()->getPanel('flags')), PHP_URL_PATH);
    $records = block($url, 'table:audit')['records'];

    expect(array_map(static fn (array $record): string => $record['cells']['action']['state'], $records))->toBe(['Restored', 'Killed'])
        ->and($records[1]['cells']['change']['state'])->toBe('Definition → Killed')
        ->and($records[0]['cells']['actor_name']['state'])->toBe('Ada Admin');
});

it('also writes to the activity log when it is installed', function (): void {
    if (! function_exists('activity')) {
        $this->markTestSkipped('spatie/laravel-activitylog is not installed.');
    }

    flagsPlugin()->activityLog();
    FeatureFlags::kill('beta-reports');

    $activity = Illuminate\Support\Facades\DB::table('activity_log')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->log_name)->toBe('feature-flags')
        ->and($activity->event)->toBe('killed')
        ->and($activity->description)->toBe('Killed: beta-reports')
        ->and($activity->causer_id)->toBe($this->ada->getKey())
        ->and(json_decode((string) $activity->properties, true))->toMatchArray(['feature' => 'beta-reports']);

    flagsPlugin()->activityLog(false);
    FeatureFlags::restore('beta-reports');

    expect(Illuminate\Support\Facades\DB::table('activity_log')->count())->toBe(1)
        ->and(FeatureFlagAudit::query()->count())->toBe(2);
});

it('keeps the audit trail of a feature page to that feature', function (): void {
    FeatureFlags::kill('beta-reports');
    FeatureFlags::kill('dark-mode');

    $url = (string) parse_url(ManageFeature::urlFor('dark-mode', alfakit()->getPanel('flags')), PHP_URL_PATH);

    expect(block($url, 'table:audit')['records'])->toHaveCount(1);
    featurePage('dark-mode')->assertOk();
});
