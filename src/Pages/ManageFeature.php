<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Infolists\Components\CodeEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Pages\Concerns\InteractsWithFeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\Audit;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureActions;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureCatalog;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureInfo;
use AlfaSix\AlfakitFeatureFlags\Support\JsonValue;
use AlfaSix\AlfakitFeatureFlags\Support\Lang;
use AlfaSix\AlfakitFeatureFlags\Support\Scopes;
use AlfaSix\AlfakitFeatureFlags\Support\StoredValues;
use AlfaSix\AlfakitFeatureFlags\Support\ValueField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One feature: its status and counts, the actions that affect everyone (or the current
 * tenant), the scopes with their own value (targeting) and the audit trail.
 * `/{panel}/feature-flags/{feature}` (the name base64url encoded).
 */
class ManageFeature extends Page
{
    use InteractsWithFeatureFlags;

    protected static ?string $slug = 'feature-flag';

    protected static ?string $routePath = '/feature-flags/{feature}';

    protected static bool $isDiscovered = false;

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $feature = null;

    protected ?FeatureInfo $info = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function encode(string $feature): string
    {
        return rtrim(strtr(base64_encode($feature), '+/', '-_'), '=');
    }

    public static function decode(string $parameter): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $parameter) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($parameter, '-_', '+/'), true);

        return is_string($decoded) && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
    }

    public static function urlFor(string $feature, ?\AlfaSix\Alfakit\Panel $panel = null): string
    {
        $parameters = ['feature' => static::encode($feature)];
        $panel ??= alfakit()->getCurrentOrDefaultPanel();
        $tenant = $panel->hasTenancy() ? alfakit()->getTenant() : null;

        if ($tenant !== null) {
            $parameters['tenant'] = $panel->getTenantRouteKey($tenant);
        }

        return static::getUrl($parameters, panel: $panel);
    }

    public function mount(mixed ...$parameters): void
    {
        $parameter = $parameters[0] ?? null;
        $feature = is_string($parameter) ? static::decode($parameter) : null;

        abort_if($feature === null || ! FeatureCatalog::exists($feature), 404);

        $this->feature = $feature;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->feature = null;
        $this->info = null;
    }

    public function getFeature(): string
    {
        return (string) $this->feature;
    }

    public function getInfo(): FeatureInfo
    {
        return $this->info ??= FeatureCatalog::info($this->getFeature());
    }

    public function getTitle(): string
    {
        return $this->getFeature();
    }

    public function getSubheading(): ?string
    {
        return $this->getInfo()->description;
    }

    /**
     * @return array<string, string>|array<int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [Features::getUrl() => Lang::get('features.title'), $this->getFeature()];
    }

    protected function getHeaderActions(): array
    {
        $actions = FeatureActions::forFeature($this->getFeature());

        $tenantActions = static::getPlugin()->getManagedTenant() === null ? [] : [
            $actions->activateForTenant()->button(),
            $actions->deactivateForTenant()->button(),
            $actions->setValueForTenant()->button(),
            $actions->forgetForTenant()->button(),
        ];

        if (! static::getPlugin()->hasGlobalActions()) {
            return $tenantActions;
        }

        if ($tenantActions !== []) {
            // Both: the tenant's own toggles first, everything that affects every tenant in the menu.
            return [
                ...$tenantActions,
                ActionGroup::make([
                    $actions->activate(),
                    $actions->deactivate(),
                    $actions->setValue(),
                    $actions->rollout(),
                    $actions->schedule(),
                    $actions->useDefinition(),
                    $actions->kill(),
                    $actions->restore(),
                    $actions->purge(),
                    $actions->reset(),
                ])->label(Lang::get('actions.everyone'))->icon('globe')->button()->color('gray'),
            ];
        }

        return [
            $actions->activate()->button(),
            $actions->deactivate()->button(),
            $actions->setValue()->button(),
            $actions->rollout()->button(),
            $actions->restore()->button(),
            ActionGroup::make([
                $actions->schedule(),
                $actions->useDefinition(),
                $actions->kill(),
                $actions->purge(),
                $actions->reset(),
            ])->label(Lang::get('actions.more'))->icon('ellipsis-vertical')->button()->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $feature = $this->getFeature();
        $info = $this->getInfo();
        $rule = FeatureFlags::rules()->rule($feature);
        $status = static::statusOf($rule);
        $tenant = static::getPlugin()->getManagedTenant();
        $summary = $tenant === null ? StoredValues::summary($feature) : null;

        $entries = [
            TextEntry::make('type')
                ->label(Lang::get('columns.type'))
                ->state(Lang::get('types.'.$info->type))
                ->badge()
                ->color($info->isRich() ? 'info' : 'gray'),
        ];

        if ($tenant !== null) {
            $decision = FeatureFlags::evaluate($feature, $tenant);
            $entries[] = TextEntry::make('tenant_value')
                ->label(Lang::get('details.tenant_value', ['tenant' => static::tenantName($tenant)]))
                ->state(static::valueLabel($decision->value))
                ->badge()
                ->color(static::valueColor($decision->value));
            $entries[] = TextEntry::make('tenant_source')
                ->label(Lang::get('columns.source'))
                ->state(static::sourceLabel($decision->source))
                ->badge()
                ->color('gray');
        } else {
            $entries[] = TextEntry::make('status')
                ->label(Lang::get('columns.status'))
                ->state(static::statusLabel($status, $rule))
                ->badge()
                ->icon(static::statusIcon($status))
                ->color(static::statusColor($status));

            if ($rule?->mode === \AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule::MODE_EVERYONE && JsonValue::isRich($rule->getEveryoneValue())) {
                $entries[] = TextEntry::make('everyone_value')
                    ->label(Lang::get('details.everyone_value'))
                    ->state(JsonValue::summary($rule->getEveryoneValue(), 120))
                    ->fontFamily('mono')
                    ->copyable();
            }

            if ($summary !== null) {
                $entries[] = TextEntry::make('active')->label(Lang::get('columns.active'))->state($summary['active'])->numeric()->color('success');
                $entries[] = TextEntry::make('inactive')->label(Lang::get('columns.inactive'))->state($summary['inactive'])->numeric();
            }
        }

        $entries[] = TextEntry::make('overrides')
            ->label(Lang::get('columns.overrides'))
            ->state(FeatureFlagOverride::query()->where('feature', $feature)->forTenant($tenant)->count())
            ->numeric();

        if ($rule?->activate_at !== null) {
            $entries[] = TextEntry::make('activate_at')->label(Lang::get('fields.activate_at'))->state($rule->activate_at)->dateTime()->icon('calendar-clock');
        }

        if ($rule?->deactivate_at !== null) {
            $entries[] = TextEntry::make('deactivate_at')->label(Lang::get('fields.deactivate_at'))->state($rule->deactivate_at)->dateTime()->icon('calendar-x');
        }

        if ($info->class !== null) {
            $entries[] = TextEntry::make('class')->label(Lang::get('details.class'))->state($info->class)->fontFamily('mono')->copyable();
        }

        $components = [];

        if ($rule?->isKilled()) {
            $components[] = Callout::make(Lang::get('details.killed_heading'))
                ->description(Lang::get('details.killed_description', ['date' => (string) $rule->killed_at?->diffForHumans()]))
                ->danger()
                ->icon('octagon-x');
        }

        if (! FeatureFlags::enforcesRules()) {
            $components[] = Callout::make(Lang::get('details.unenforced_heading'))
                ->description(Lang::get('details.unenforced_description'))
                ->warning();
        }

        if (! $info->isDefined) {
            $components[] = Callout::make(Lang::get('details.undefined_heading'))
                ->description(Lang::get('details.undefined_description'))
                ->warning();
        }

        $components[] = Section::make(Lang::get('details.overview'))
            ->schema([Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema($entries)]);

        return $schema->components($components);
    }

    // ---------------------------------------------------------------- Targeting

    /**
     * The scopes with their own value: users, teams, tenants…
     */
    public function table(Table $table): Table
    {
        $feature = $this->getFeature();
        $info = $this->getInfo();
        $tenant = static::getPlugin()->getManagedTenant();
        $canTarget = static fn (): bool => FeatureFlagsPlugin::get()->can(FeatureFlagsPlugin::ABILITY_TARGET, $feature);

        return $table
            ->heading(Lang::get('targeting.heading'))
            ->description(Lang::get('targeting.description'))
            ->query(FeatureFlagOverride::query()->where('feature', $feature)->forTenant($tenant))
            ->columns([
                TextColumn::make('scope')
                    ->label(Lang::get('columns.scope'))
                    ->weight('medium')
                    ->formatStateUsing(static fn (FeatureFlagOverride $record): string => Scopes::label($record->scope))
                    ->description(static fn (FeatureFlagOverride $record): ?string => Scopes::typeLabel($record->scope_type))
                    ->searchable(['scope', 'scope_id'])
                    ->wrap(),
                TextColumn::make('value')
                    ->label(Lang::get('columns.value'))
                    ->badge()
                    ->formatStateUsing(static fn (FeatureFlagOverride $record): string => static::valueLabel($record->getValue()))
                    ->color(static fn (FeatureFlagOverride $record): string => static::valueColor($record->getValue())),
                TextColumn::make('updated_by_name')
                    ->label(Lang::get('columns.updated_by'))
                    ->placeholder(Lang::get('columns.code'))
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(Lang::get('columns.updated_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(Lang::get('columns.value'))
                    ->options(['on' => Lang::get('values.active'), 'off' => Lang::get('values.off')])
                    ->query(static fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'on' => $query->where('value', '!=', 'false'),
                        'off' => $query->where('value', 'false'),
                        default => $query,
                    }),
            ])
            ->headerActions([$this->getAddScopesAction()])
            ->recordActions([
                Action::make('activateScope')
                    ->label(Lang::get('targeting.actions.activate'))
                    ->icon('toggle-right')
                    ->color('success')
                    ->authorize($canTarget)
                    ->hidden(static fn (FeatureFlagOverride $record): bool => $record->getValue() === true || $info->isRich())
                    ->action(static function (FeatureFlagOverride $record) use ($feature): void {
                        FeatureFlags::setFor($feature, $record->scope, true);
                        FeatureActions::notify(Lang::get('targeting.notifications.updated'));
                    }),
                Action::make('deactivateScope')
                    ->label(Lang::get('targeting.actions.deactivate'))
                    ->icon('toggle-left')
                    ->color('gray')
                    ->authorize($canTarget)
                    ->hidden(static fn (FeatureFlagOverride $record): bool => $record->getValue() === false)
                    ->action(static function (FeatureFlagOverride $record) use ($feature): void {
                        FeatureFlags::setFor($feature, $record->scope, false);
                        FeatureActions::notify(Lang::get('targeting.notifications.updated'));
                    }),
                Action::make('setScopeValue')
                    ->label(Lang::get('targeting.actions.set_value'))
                    ->icon('braces')
                    ->color('gray')
                    ->authorize($canTarget)
                    ->visible($info->isRich())
                    ->modalHeading(static fn (FeatureFlagOverride $record): string => Lang::get('targeting.actions.set_value_heading', ['scope' => Scopes::label($record->scope)]))
                    ->modalWidth(Width::TwoExtraLarge)
                    ->schema(static fn (FeatureFlagOverride $record): array => [ValueField::make($info, $record->getValue())])
                    ->action(static function (array $data, FeatureFlagOverride $record) use ($feature): void {
                        FeatureFlags::setFor($feature, $record->scope, ValueField::read($data['value'] ?? null));
                        FeatureActions::notify(Lang::get('targeting.notifications.updated'));
                    }),
                Action::make('forgetScope')
                    ->label(Lang::get('targeting.actions.forget'))
                    ->icon('x')
                    ->color('danger')
                    ->authorize($canTarget)
                    ->requiresConfirmation()
                    ->modalHeading(static fn (FeatureFlagOverride $record): string => Lang::get('targeting.actions.forget_heading', ['scope' => Scopes::label($record->scope)]))
                    ->modalDescription(Lang::get('targeting.actions.forget_description'))
                    ->modalSubmitActionLabel(Lang::get('targeting.actions.forget'))
                    ->action(static function (FeatureFlagOverride $record) use ($feature): void {
                        FeatureFlags::forgetFor($feature, $record->scope);
                        FeatureActions::notify(Lang::get('targeting.notifications.forgotten'));
                    }),
            ])
            ->bulkActions([
                BulkAction::make('forgetScopes')
                    ->label(Lang::get('targeting.actions.forget_selected'))
                    ->icon('x')
                    ->color('danger')
                    ->authorize($canTarget)
                    ->requiresConfirmation()
                    ->modalDescription(Lang::get('targeting.actions.forget_description'))
                    ->deselectRecordsAfterCompletion()
                    ->action(static function (Collection $records) use ($feature): void {
                        $count = 0;

                        foreach ($records as $record) {
                            if ($record instanceof FeatureFlagOverride) {
                                FeatureFlags::forgetFor($feature, $record->scope);
                                $count++;
                            }
                        }

                        FeatureActions::notify(Lang::choice('targeting.notifications.forgotten_many', $count));
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateIcon('crosshair')
            ->emptyStateHeading(Lang::get('targeting.empty.heading'))
            ->emptyStateDescription(Lang::get('targeting.empty.description'));
    }

    protected function getAddScopesAction(): Action
    {
        $feature = $this->getFeature();
        $info = $this->getInfo();

        return Action::make('addScopes')
            ->label(Lang::get('targeting.actions.add'))
            ->icon('plus')
            ->button()
            ->authorize(static fn (): bool => FeatureFlagsPlugin::get()->can(FeatureFlagsPlugin::ABILITY_TARGET, $feature))
            ->visible(static fn (): bool => FeatureFlagsPlugin::get()->getScopeResolvers() !== [] || FeatureFlagsPlugin::get()->getManagedTenant() !== null)
            ->modalHeading(Lang::get('targeting.actions.add_heading', ['feature' => $feature]))
            ->modalDescription(Lang::get('targeting.actions.add_description'))
            ->modalSubmitActionLabel(Lang::get('targeting.actions.add_submit'))
            ->modalWidth(Width::TwoExtraLarge)
            ->schema(static function () use ($info): array {
                $states = ['on' => Lang::get('values.on'), 'off' => Lang::get('values.off')];

                if ($info->isRich()) {
                    $states = ['value' => Lang::get('values.custom'), 'off' => Lang::get('values.off')];
                }

                $fields = [
                    Select::make('scopes')
                        ->label(Lang::get('fields.scopes'))
                        ->helperText(Lang::get('fields.scopes_help'))
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => static::searchScopes($search))
                        ->getOptionLabelsUsing(static fn (array $values): array => static::scopeOptionLabels($values))
                        ->required(),
                    ToggleButtons::make('state')
                        ->label(Lang::get('fields.state'))
                        ->options($states)
                        ->colors(['on' => 'success', 'off' => 'gray', 'value' => 'info'])
                        ->default(array_key_first($states))
                        ->inline()
                        ->live()
                        ->required(),
                ];

                if ($info->isRich()) {
                    $fields[] = ValueField::make($info)
                        ->required(static fn (Get $get): bool => $get('state') === 'value')
                        ->visible(static fn (Get $get): bool => $get('state') === 'value');
                }

                return $fields;
            })
            ->action(static function (array $data) use ($feature, $info): void {
                $scopes = [];

                foreach (array_values(array_filter((array) ($data['scopes'] ?? []), is_string(...))) as $option) {
                    $scope = static::resolveScopeOption($option);

                    if ($scope === null) {
                        throw ValidationException::withMessages(['scopes' => Lang::get('validation.scope_not_allowed')]);
                    }

                    $scopes[] = $scope;
                }

                $value = match ($data['state'] ?? null) {
                    'off' => false,
                    'value' => $info->isRich() ? ValueField::read($data['value'] ?? null) : true,
                    default => true,
                };

                foreach ($scopes as $scope) {
                    FeatureFlags::setFor($feature, $scope, $value);
                }

                FeatureActions::notify(Lang::choice('targeting.notifications.added', count($scopes)));
            });
    }

    /**
     * Records the current user may target, from every scope resolver (and the current
     * tenant on tenant panels): `"{type}|{key}" => "Title (Users)"`.
     *
     * @return array<string, string>
     */
    public static function searchScopes(?string $search): array
    {
        $plugin = FeatureFlagsPlugin::get();
        $tenant = $plugin->getManagedTenant();
        $options = [];

        if ($tenant !== null && $plugin->getScopeResolver($tenant::class) === null) {
            $name = static::tenantName($tenant);

            if ($search === null || $search === '' || str_contains(mb_strtolower($name), mb_strtolower($search))) {
                $options[Scopes::serialize($tenant)] = Lang::get('targeting.current_tenant', ['tenant' => $name]);
            }
        }

        foreach ($plugin->getScopeResolvers() as $resolver) {
            foreach ($resolver->getOptions($search, $tenant) as $value => $label) {
                $options[$value] = $label.' ('.$resolver->getLabel().')';
            }
        }

        return $options;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public static function scopeOptionLabels(array $values): array
    {
        $labels = [];

        foreach ($values as $value) {
            if (is_string($value) && ($scope = static::resolveScopeOption($value)) !== null) {
                $labels[$value] = Scopes::label($value, $scope);
            }
        }

        return $labels;
    }

    /**
     * The model behind an option, when the current user may target it: the current tenant,
     * or a record a scope resolver offers (in the current tenant). Null for anything else,
     * whatever the browser posted.
     */
    public static function resolveScopeOption(string $option): ?Model
    {
        $plugin = FeatureFlagsPlugin::get();
        $tenant = $plugin->getManagedTenant();

        if ($tenant !== null && $option === Scopes::serialize($tenant) && $plugin->getScopeResolver($tenant::class) === null) {
            return $tenant;
        }

        ['type' => $type, 'id' => $id] = Scopes::parse($option);
        $class = Scopes::modelClass($type);

        if ($class === null || $id === null) {
            return null;
        }

        $resolver = $plugin->getScopeResolver($class);

        return $resolver?->find($id, $tenant);
    }

    // ---------------------------------------------------------------- Audit trail

    public function auditTable(Table $table): Table
    {
        $feature = $this->getFeature();
        $tenant = static::getPlugin()->getManagedTenant();
        $actions = [];

        foreach (Audit::ACTIONS as $action) {
            $actions[$action] = Lang::get('audit.actions.'.$action);
        }

        return $table
            ->heading(Lang::get('audit.heading'))
            ->description(Lang::get('audit.description'))
            ->query(FeatureFlagAudit::query()->where('feature', $feature)->forTenant($tenant))
            ->columns([
                TextColumn::make('created_at')
                    ->label(Lang::get('audit.columns.when'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('actor_name')
                    ->label(Lang::get('audit.columns.who'))
                    ->placeholder(Lang::get('audit.system'))
                    ->description(static fn (FeatureFlagAudit $record): ?string => $record->source === FeatureFlagAudit::SOURCE_PANEL ? null : Lang::get('audit.sources.'.$record->source)),
                TextColumn::make('action')
                    ->label(Lang::get('audit.columns.what'))
                    ->badge()
                    ->formatStateUsing(static fn (FeatureFlagAudit $record): string => Audit::describe($record))
                    ->color(static fn (?string $state): string => match ($state) {
                        Audit::KILLED, Audit::PURGED, Audit::RESET => 'danger',
                        Audit::ACTIVATED_FOR_EVERYONE, Audit::SCOPE_ACTIVATED, Audit::RESTORED => 'success',
                        Audit::ROLLOUT_SET, Audit::SCHEDULED => 'warning',
                        default => 'gray',
                    })
                    ->description(static fn (FeatureFlagAudit $record): ?string => $record->scope_label),
                TextColumn::make('change')
                    ->label(Lang::get('audit.columns.change'))
                    ->state(static fn (FeatureFlagAudit $record): string => static::describeSnapshot($record->getBefore()).' → '.static::describeSnapshot($record->getAfter()))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('action')->label(Lang::get('audit.columns.what'))->options($actions),
            ])
            ->recordActions([
                Action::make('viewChange')
                    ->label(Lang::get('audit.view'))
                    ->icon('eye')
                    ->color('gray')
                    ->slideOver()
                    ->modalHeading(static fn (FeatureFlagAudit $record): string => Audit::describe($record))
                    ->modalSubmitAction(false)
                    ->modalContent(static fn (FeatureFlagAudit $record): array => [
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextEntry::make('audit_who')->label(Lang::get('audit.columns.who'))->state($record->actor_name)->placeholder(Lang::get('audit.system')),
                            TextEntry::make('audit_when')->label(Lang::get('audit.columns.when'))->state($record->created_at)->dateTime(),
                            TextEntry::make('audit_scope')->label(Lang::get('columns.scope'))->state($record->scope_label)->placeholder('—'),
                            TextEntry::make('audit_source')->label(Lang::get('audit.columns.source'))->state(Lang::get('audit.sources.'.$record->source)),
                        ]),
                        CodeEntry::make('audit_before')->label(Lang::get('audit.before'))->state(JsonValue::encode($record->getBefore(), pretty: true))->grammar('json'),
                        CodeEntry::make('audit_after')->label(Lang::get('audit.after'))->state(JsonValue::encode($record->getAfter(), pretty: true))->grammar('json'),
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('history')
            ->emptyStateHeading(Lang::get('audit.empty'));
    }

    /**
     * A short description of an audit snapshot (a feature's rules or a scope's value).
     */
    public static function describeSnapshot(mixed $snapshot): string
    {
        if (! is_array($snapshot)) {
            return '—';
        }

        if (array_key_exists('source', $snapshot)) {
            return static::valueLabel($snapshot['value'] ?? false).' ('.static::sourceLabel(is_string($snapshot['source']) ? $snapshot['source'] : null).')';
        }

        $parts = [];

        if (array_key_exists('mode', $snapshot)) {
            $parts[] = match (true) {
                ($snapshot['killed'] ?? false) === true => Lang::get('status.killed'),
                $snapshot['mode'] === 'everyone' => Lang::get('audit.snapshot.everyone', ['value' => JsonValue::summary($snapshot['value'] ?? false, 30)]),
                $snapshot['mode'] === 'rollout' => Lang::get('status.rollout_percentage', ['percentage' => FeatureActions::formatPercentage((float) (is_numeric($snapshot['percentage'] ?? null) ? $snapshot['percentage'] : 0))]),
                default => Lang::get('status.default'),
            };

            if (is_string($snapshot['activate_at'] ?? null) || is_string($snapshot['deactivate_at'] ?? null)) {
                $parts[] = Lang::get('audit.snapshot.scheduled');
            }
        }

        if (array_key_exists('overrides', $snapshot)) {
            $parts[] = Lang::get('audit.snapshot.stored', [
                'active' => is_int($snapshot['stored_active'] ?? null) ? $snapshot['stored_active'] : '—',
                'inactive' => is_int($snapshot['stored_inactive'] ?? null) ? $snapshot['stored_inactive'] : '—',
                'overrides' => is_int($snapshot['overrides']) ? $snapshot['overrides'] : 0,
            ]);
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    // ---------------------------------------------------------------- Stored values

    /**
     * What Pennant stored for each scope (database store, panels without tenancy).
     */
    public function storedTable(Table $table): Table
    {
        $feature = $this->getFeature();

        if (static::getPlugin()->getManagedTenant() !== null || ! StoredValues::isDatabase()) {
            return $table;
        }

        return $table
            ->heading(Lang::get('stored.heading'))
            ->description(Lang::get('stored.description'))
            ->records(static function (?string $search, int $page, int|string $recordsPerPage) use ($feature): LengthAwarePaginator {
                $query = StoredValues::query($feature);
                $perPage = is_int($recordsPerPage) ? $recordsPerPage : 25;

                if ($query === null) {
                    return new LengthAwarePaginator([], 0, $perPage, $page);
                }

                if ($search !== null && $search !== '') {
                    $query->where('scope', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
                }

                $paginator = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate($perPage, ['id', 'scope', 'value', 'updated_at'], 'stored_page', $page);

                return $paginator->through(static function (mixed $row): array {
                    $row = (array) $row;
                    $value = JsonValue::isValid((string) ($row['value'] ?? '')) ? JsonValue::decode((string) $row['value']) : false;

                    return [
                        'id' => (string) ($row['id'] ?? ''),
                        'scope' => Scopes::label((string) ($row['scope'] ?? '')),
                        'type' => Scopes::typeLabel(Scopes::parse((string) ($row['scope'] ?? ''))['type']),
                        'value' => static::valueLabel($value),
                        'is_active' => JsonValue::isActive($value),
                        'is_rich' => JsonValue::isRich($value),
                        'updated_at' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
                    ];
                });
            })
            ->columns([
                TextColumn::make('scope')
                    ->label(Lang::get('columns.scope'))
                    ->description(static fn (array $record): ?string => $record['type'])
                    ->searchable()
                    ->wrap(),
                TextColumn::make('value')
                    ->label(Lang::get('columns.value'))
                    ->badge()
                    ->color(static fn (array $record): string => $record['is_rich'] ? 'info' : ($record['is_active'] ? 'success' : 'gray')),
                TextColumn::make('updated_at')
                    ->label(Lang::get('columns.updated_at'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->emptyStateIcon('database')
            ->emptyStateHeading(Lang::get('stored.empty'));
    }
}
