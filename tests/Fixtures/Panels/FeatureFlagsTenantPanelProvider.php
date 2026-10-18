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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeatureFlagsTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('flags-team')
            ->path('flags-team')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->pages([Dashboard::class, BetaReports::class])
            ->plugin(
                FeatureFlagsPlugin::make()->scopes([
                    // Only the members of the current team.
                    TenantUser::class => static fn (Builder $query, ?Model $tenant): Builder => $query->whereHas('teams', static fn (Builder $teams): Builder => $teams->whereKey($tenant?->getKey())),
                ]),
            );
    }
}
