<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Pages\BetaReports;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources\MemberResource;
use Illuminate\Database\Eloquent\Builder;

class FeatureFlagsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('flags')
            ->path('flags')
            ->login()
            ->middleware(['web'])
            ->pages([Dashboard::class, BetaReports::class])
            ->resources([MemberResource::class])
            ->plugin(
                FeatureFlagsPlugin::make()
                    ->navigationGroup('Settings')
                    ->descriptions(['beta-reports' => 'Reports that are still in beta.'])
                    ->scopes([
                        TenantUser::class => static fn (Builder $query, ?string $search): Builder => $query->when($search, static fn (Builder $query, string $search): Builder => $query->where('name', 'like', "%{$search}%")),
                        Team::class,
                    ]),
            );
    }
}
