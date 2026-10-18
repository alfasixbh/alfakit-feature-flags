<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitFeatureFlags\Pages\Features;
use AlfaSix\AlfakitFeatureFlags\Pages\ManageFeature;
use AlfaSix\AlfakitFeatureFlags\Support\ScopeResolver;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * A control centre for Laravel Pennant: every feature with its status and counts, the
 * kill switch, values for everyone, percentage and scheduled rollouts, per-scope
 * targeting and the audit trail.
 *
 * Nobody can open it until you allow it: pass `authorize()` or define the
 * `manageFeatureFlags` gate.
 *
 * ```php
 * $panel->plugin(
 *     FeatureFlagsPlugin::make()
 *         ->authorize(fn (User $user): bool => $user->is_admin)
 *         ->scopes([
 *             User::class => fn (Builder $query, string $search) => $query->where('email', 'like', "%{$search}%"),
 *             Team::class,
 *         ]),
 * );
 * ```
 */
class FeatureFlagsPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'feature-flags';

    /** The gate checked when no `authorize()` callback is set. */
    public const GATE = 'manageFeatureFlags';

    // Abilities passed to `authorizeActions()`.

    /** Activate, deactivate or set a value for everyone (or for the current tenant). */
    public const ABILITY_TOGGLE = 'toggle';

    /** Give single scopes (users, teams…) their own value. */
    public const ABILITY_TARGET = 'target';

    public const ABILITY_ROLLOUT = 'rollout';

    public const ABILITY_SCHEDULE = 'schedule';

    public const ABILITY_KILL = 'kill';

    /** Purge stored values and reset features. */
    public const ABILITY_PURGE = 'purge';

    /** @var list<string> */
    public const ABILITIES = [self::ABILITY_TOGGLE, self::ABILITY_TARGET, self::ABILITY_ROLLOUT, self::ABILITY_SCHEDULE, self::ABILITY_KILL, self::ABILITY_PURGE];

    protected bool|Closure|null $authorizeUsing = null;

    protected string $gate = self::GATE;

    protected ?Closure $authorizeActionsUsing = null;

    protected bool|Closure $isReadOnly = false;

    protected bool|Closure $requiresPasswordConfirmation = false;

    protected ?string $store = null;

    /** @var array<class-string<Model>, ScopeResolver> */
    protected array $scopeResolvers = [];

    /** @var array<string, string> */
    protected array $descriptions = [];

    /** @var array<string, array<array-key, string|int|float>|Closure> */
    protected array $values = [];

    /** @var list<string> */
    protected array $hiddenFeatures = [];

    /** @var list<array{namespace: string, path: ?string}> */
    protected array $discover = [];

    protected ?Closure $scopeUsing = null;

    protected bool $usesTenantAsScope = true;

    protected bool $hasGlobalActionsOnTenantPanels = false;

    protected ?bool $logsToActivityLog = null;

    protected string|Closure|null $navigationLabel = null;

    protected string|Closure|null $navigationGroup = null;

    protected string|Closure|null $navigationIcon = 'flag';

    protected int|Closure|null $navigationSort = null;

    protected bool|Closure $shouldRegisterNavigation = true;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin registered on the current panel, or one with the default options (which
     * denies access unless the `manageFeatureFlags` gate allows it).
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if ($panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([Features::class, ManageFeature::class]);
    }

    public function boot(Panel $panel): void
    {
        foreach ($this->discover as ['namespace' => $namespace, 'path' => $path]) {
            if (is_dir($path ?? base_path('app/Features'))) {
                Feature::store($this->store)->discover($namespace, $path);
            }
        }
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'panel' => [alfakit()->getCurrentOrDefaultPanel()],
            'user' => [$this->getUser()],
            'tenant' => [alfakit()->getCurrentOrDefaultPanel()->hasTenancy() ? alfakit()->getTenant() : null],
            default => [],
        };
    }

    protected function getUser(): ?Authenticatable
    {
        return alfakit()->getCurrentOrDefaultPanel()->auth()->user();
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Who may open the feature flag pages: `fn (User $user): bool`. Without it only users
     * the `manageFeatureFlags` gate (see `gate()`) allows may; guests never may.
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * The gate checked when no `authorize()` callback is set (default `manageFeatureFlags`).
     */
    public function gate(string $ability): static
    {
        $this->gate = $ability;

        return $this;
    }

    public function getGate(): string
    {
        return $this->gate;
    }

    public function isAuthorized(): bool
    {
        $user = $this->getUser();

        if ($user === null) {
            return false;
        }

        if ($this->authorizeUsing !== null) {
            return (bool) $this->evaluate($this->authorizeUsing);
        }

        return Gate::forUser($user)->allows($this->gate);
    }

    /**
     * Decide per action what a user may do: `fn (string $ability, ?string $feature, User $user): bool`
     * with a `FeatureFlagsPlugin::ABILITY_*` value. It only narrows `authorize()`.
     */
    public function authorizeActions(?Closure $callback): static
    {
        $this->authorizeActionsUsing = $callback;

        return $this;
    }

    /**
     * Show everything, change nothing.
     */
    public function readOnly(bool|Closure $condition = true): static
    {
        $this->isReadOnly = $condition;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->evaluate($this->isReadOnly);
    }

    /**
     * Whether the current user may perform `$ability` (`FeatureFlagsPlugin::ABILITY_*`),
     * on `$feature` when given.
     */
    public function can(string $ability, ?string $feature = null): bool
    {
        if (! $this->isAuthorized() || $this->isReadOnly()) {
            return false;
        }

        if ($this->authorizeActionsUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->authorizeActionsUsing, ['ability' => $ability, 'feature' => $feature]);
    }

    /**
     * Ask for the user's password before the kill switch, purging and resetting.
     */
    public function requiresPasswordConfirmation(bool|Closure $condition = true): static
    {
        $this->requiresPasswordConfirmation = $condition;

        return $this;
    }

    public function shouldRequirePasswordConfirmation(): bool
    {
        return (bool) $this->evaluate($this->requiresPasswordConfirmation);
    }

    // ---------------------------------------------------------------- Pennant

    /**
     * The Pennant store managed (default: Pennant's default store).
     */
    public function store(?string $store): static
    {
        $this->store = $store;

        return $this;
    }

    public function getStore(): ?string
    {
        return $this->store;
    }

    /**
     * Discover class-based features like `Feature::discover()` (`app/Features` by
     * default) so the panel lists them before anything resolved them.
     */
    public function discoverFeatures(string $namespace = 'App\\Features', ?string $path = null): static
    {
        $this->discover[] = ['namespace' => $namespace, 'path' => $path];

        return $this;
    }

    /**
     * What features do, by name, for features that don't describe themselves with
     * `#[Description]` or a `description()` method.
     *
     * @param  array<string, string>  $descriptions
     */
    public function descriptions(array $descriptions): static
    {
        $this->descriptions = [...$this->descriptions, ...$descriptions];

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getDescriptions(): array
    {
        return $this->descriptions;
    }

    /**
     * The values rich features take, offered in a select: `['purchase-button' => ['blue', 'green']]`
     * or with labels `['purchase-button' => ['blue' => 'Blue button']]`. A closure is resolved
     * when the values are shown, so labels can be translated:
     * `['purchase-button' => fn () => ['blue' => __('features.blue')]]`.
     *
     * @param  array<string, array<array-key, string|int|float>|Closure>  $values
     */
    public function values(array $values): static
    {
        $this->values = [...$this->values, ...$values];

        return $this;
    }

    /**
     * @return array<string, array<array-key, string|int|float>>
     */
    public function getValues(): array
    {
        $values = [];

        foreach ($this->values as $feature => $featureValues) {
            $resolved = $this->evaluate($featureValues);

            if (is_array($resolved)) {
                $values[$feature] = $resolved;
            }
        }

        return $values;
    }

    /**
     * Features the panel doesn't list (internal or experimental ones).
     *
     * @param  array<string>  $features
     */
    public function hideFeatures(array $features): static
    {
        $this->hiddenFeatures = array_values(array_unique([...$this->hiddenFeatures, ...array_map(strval(...), $features)]));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getHiddenFeatures(): array
    {
        return $this->hiddenFeatures;
    }

    // ---------------------------------------------------------------- Scopes

    /**
     * The kinds of scope the panel can target and search: model classes (searched by
     * their title attribute), `Model::class => fn (Builder $query, ?string $search, ?Model $tenant) => …`
     * or `ScopeResolver` instances.
     *
     * @param  array<int|class-string<Model>, class-string<Model>|Closure|ScopeResolver>  $scopes
     */
    public function scopes(array $scopes): static
    {
        foreach ($scopes as $key => $scope) {
            $resolver = match (true) {
                $scope instanceof ScopeResolver => $scope,
                $scope instanceof Closure && is_string($key) => ScopeResolver::make($key)->search($scope),
                is_string($scope) && is_int($key) => ScopeResolver::make($scope),
                default => throw new InvalidArgumentException('Pass scope models as `Model::class`, `Model::class => fn (…) => …` or a ScopeResolver.'),
            };

            /** @var class-string<Model> $model */
            $model = $resolver->getModel();
            $this->scopeResolvers[$model] = $resolver;
        }

        return $this;
    }

    /**
     * @return array<class-string<Model>, ScopeResolver>
     */
    public function getScopeResolvers(): array
    {
        return $this->scopeResolvers;
    }

    public function getScopeResolver(string $model): ?ScopeResolver
    {
        if (isset($this->scopeResolvers[$model])) {
            return $this->scopeResolvers[$model];
        }

        foreach ($this->scopeResolvers as $class => $resolver) {
            if (is_a($model, $class, true)) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * The Pennant scope of the current request, used by `FeatureFlagged` and
     * `FeatureFlags::active()`: `fn (?User $user, ?Model $tenant) => …`. Without it the
     * current tenant on panels with tenancy (see `tenantScope()`), the signed-in user
     * otherwise.
     */
    public function scopeUsing(?Closure $callback): static
    {
        $this->scopeUsing = $callback;

        return $this;
    }

    /**
     * On panels with tenancy, use the current tenant as the Pennant scope (default) and
     * manage features for that tenant. Pass false to keep the signed-in user as the scope.
     */
    public function tenantScope(bool $condition = true): static
    {
        $this->usesTenantAsScope = $condition;

        return $this;
    }

    public function usesTenantAsScope(): bool
    {
        return $this->usesTenantAsScope;
    }

    public function getCurrentScope(): mixed
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $tenant = $panel->hasTenancy() ? alfakit()->getTenant() : null;

        if ($this->scopeUsing !== null) {
            return $this->evaluate($this->scopeUsing, ['tenant' => $tenant]);
        }

        if ($tenant !== null && $this->usesTenantAsScope) {
            return $tenant;
        }

        return $this->getUser();
    }

    /**
     * The tenant whose features the pages manage: the current tenant on panels with
     * tenancy (unless `tenantScope(false)`), null on other panels.
     */
    public function getManagedTenant(): ?Model
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        return $panel->hasTenancy() && $this->usesTenantAsScope ? alfakit()->getTenant() : null;
    }

    /**
     * On panels with tenancy the pages only change the current tenant's values. Allow
     * the actions that affect every tenant there too (everyone, rollouts, schedules, the
     * kill switch, purging), e.g. on an internal staff panel.
     */
    public function globalActionsOnTenantPanels(bool $condition = true): static
    {
        $this->hasGlobalActionsOnTenantPanels = $condition;

        return $this;
    }

    /**
     * Whether the actions that affect everyone are offered on the current panel.
     */
    public function hasGlobalActions(): bool
    {
        return $this->hasGlobalActionsOnTenantPanels || ! alfakit()->getCurrentOrDefaultPanel()->hasTenancy();
    }

    // ---------------------------------------------------------------- Activity log

    /**
     * Also write every change to the activity log (on by default when
     * `alfasix/alfakit-activity-log` is installed).
     */
    public function activityLog(bool $condition = true): static
    {
        $this->logsToActivityLog = $condition;

        return $this;
    }

    public function logsToActivityLog(): bool
    {
        return $this->logsToActivityLog ?? class_exists(\AlfaSix\AlfakitActivityLog\ActivityLogPlugin::class);
    }

    // ---------------------------------------------------------------- Navigation

    public function navigationLabel(string|Closure|null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        $label = $this->evaluate($this->navigationLabel);

        return is_string($label) ? $label : (string) __('alfakit-feature-flags::feature-flags.navigation.label');
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) ? $group : null;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) ? $icon : null;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        return is_int($sort) ? $sort : null;
    }

    public function registerNavigation(bool|Closure $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }
}
