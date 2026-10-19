# AlfaKit Feature Flags

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/feature-flags). Please open issues and pull requests there.

A feature flag control centre for [Laravel Pennant](https://laravel.com/docs/pennant) in [AlfaKit](https://alfakit.dev) panels: every feature with its status and counts, a kill switch, values for everyone, stable percentage rollouts, schedules, per-user and per-tenant targeting, rich values and a full audit trail. It renders in React, Vue and Svelte with AlfaKit's built-in components.

## Installation

```bash
composer require alfasix/alfakit-feature-flags
php artisan vendor:publish --tag=pennant-migrations   # Pennant's `features` table, if you don't have it yet
php artisan vendor:publish --tag=alfakit-feature-flags-migrations
php artisan migrate
```

## Register

```php
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;

$panel->plugin(
    FeatureFlagsPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)
        ->scopes([
            User::class => fn (Builder $query, ?string $search) => $query->where('email', 'like', "%{$search}%"),
            Team::class,
        ]),
);
```

Nobody can open the pages until you pass `authorize()` or define the `manageFeatureFlags` gate.

```php
use AlfaSix\AlfakitFeatureFlags\Concerns\FeatureFlagged;

class Billing extends Page
{
    use FeatureFlagged;

    protected static ?string $feature = 'new-billing';
}
```

Full documentation: https://alfakit.dev/docs/plugins/feature-flags
