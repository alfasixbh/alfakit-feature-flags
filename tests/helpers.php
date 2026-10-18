<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests;

use AlfaSix\Alfakit\Testing\PageTester;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Pages\Features;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function inertiaGet(string $url): TestResponse
{
    return \Pest\Laravel\get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
}

/**
 * @return array<string, mixed>
 */
function pageProps(string $url): array
{
    $response = inertiaGet($url);
    $response->assertOk();

    return $response->json('props.page');
}

/**
 * A content block of a page by key (`table`, `table:audit`, `infolist`).
 *
 * @return array<string, mixed>
 */
function block(string $url, string $key): array
{
    $block = collect(pageProps($url)['content'])->firstWhere('key', $key);

    return is_array($block) ? $block : throw new RuntimeException("No [{$key}] block on {$url}.");
}

function flagsUser(string $name = 'Ada Admin'): TenantUser
{
    return TenantUser::query()->create(['name' => $name, 'email' => uniqid('user', true).'@example.com', 'password' => 'password']);
}

function flagsTeam(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

function flagsPlugin(string $panel = 'flags'): FeatureFlagsPlugin
{
    $plugin = alfakit()->getPanel($panel)->getPlugin(FeatureFlagsPlugin::ID);

    return $plugin instanceof FeatureFlagsPlugin ? $plugin : throw new RuntimeException('The feature flags plugin is not registered.');
}

function featuresPage(string $panel = 'flags', array $parameters = []): PageTester
{
    return alfakit()->testPage(Features::class, $parameters, panel: $panel);
}

function featurePage(string $feature, string $panel = 'flags', array $parameters = []): PageTester
{
    return alfakit()->testPage(ManageFeature::class, ['feature' => ManageFeature::encode($feature), ...$parameters], panel: $panel);
}
