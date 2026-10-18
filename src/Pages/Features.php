<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Pages\Concerns\InteractsWithFeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\Decision;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureActions;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureCatalog;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureInfo;
use AlfaSix\AlfakitFeatureFlags\Support\Lang;
use AlfaSix\AlfakitFeatureFlags\Support\StoredValues;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Every feature: what it does, its type, its status (killed, on or off for everyone, a
 * rollout, the definition), how many scopes have it active and inactive and when it last
 * changed, with quick actions per row. On panels with tenancy: the current tenant's value
 * and where it comes from instead of the counts.
 *
 * @phpstan-type FeatureRow array{name: string, description: ?string, type: string, status: string, status_label: string, schedule: ?string, active: ?int, inactive: ?int, overrides: int, last_changed: ?string, value: mixed, value_label: ?string, source: ?string}
 */
class Features extends Page
{
    use InteractsWithFeatureFlags;

    protected static ?string $slug = 'feature-flags';

    protected static bool $isDiscovered = false;

    public static function shouldRegisterNavigation(): bool
    {
        return static::getPlugin()->shouldRegisterNavigation();
    }

    public function getTitle(): string
    {
        return Lang::get('features.title');
    }

    public function getSubheading(): ?string
    {
        $tenant = static::getPlugin()->getManagedTenant();

        if ($tenant !== null) {
            return Lang::get('features.tenant_subheading', ['tenant' => static::tenantName($tenant)]);
        }

        return FeatureFlags::enforcesRules() ? Lang::get('features.subheading') : Lang::get('features.subheading_unenforced');
    }

    public function table(Table $table): Table
    {
        $tenant = static::getPlugin()->getManagedTenant();
        $actions = FeatureActions::forRows();

        return $table
            ->records(static fn (): array => static::rows())
            ->resolveRecordUsing(static fn (string $key): ?array => FeatureCatalog::exists($key) ? (static::rows([$key])[$key] ?? null) : null)
            ->columns($tenant === null ? static::globalColumns() : static::tenantColumns())
            ->filters([
                SelectFilter::make('type')
                    ->label(Lang::get('columns.type'))
                    ->options([
                        FeatureInfo::TYPE_BOOLEAN => Lang::get('types.boolean'),
                        FeatureInfo::TYPE_RICH => Lang::get('types.rich'),
                    ]),
                SelectFilter::make($tenant === null ? 'status' : 'source')
                    ->label(Lang::get($tenant === null ? 'columns.status' : 'columns.source'))
                    ->options($tenant === null ? static::statusOptions() : static::sourceOptions()),
            ])
            ->recordUrl(static fn (array $record): string => ManageFeature::urlFor($record['name']))
            ->recordActions([
                Action::make('manage')
                    ->label(Lang::get('actions.manage.label'))
                    ->icon('sliders-horizontal')
                    ->color('gray')
                    ->url(static fn (array $record): string => ManageFeature::urlFor($record['name'])),
                ActionGroup::make([
                    ...($tenant === null ? [] : [
                        $actions->activateForTenant(),
                        $actions->deactivateForTenant(),
                        $actions->forgetForTenant(),
                    ]),
                    ...(static::getPlugin()->hasGlobalActions() ? [
                        $actions->activate(),
                        $actions->deactivate(),
                        $actions->useDefinition(),
                        $actions->kill(),
                        $actions->restore(),
                        $actions->purge(),
                    ] : []),
                ])->label(Lang::get('actions.more'))->icon('ellipsis-vertical'),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('flag')
            ->emptyStateHeading(Lang::get('features.empty.heading'))
            ->emptyStateDescription(Lang::get('features.empty.description'));
    }

    /**
     * @return array<TextColumn>
     */
    protected static function globalColumns(): array
    {
        $listable = StoredValues::isListable();

        return [
            static::nameColumn(),
            static::typeColumn(),
            TextColumn::make('status')
                ->label(Lang::get('columns.status'))
                ->badge()
                ->formatStateUsing(static fn (array $record): string => $record['status_label'])
                ->color(static fn (?string $state): string => static::statusColor($state))
                ->icon(static fn (?string $state): string => static::statusIcon($state))
                ->description(static fn (array $record): ?string => $record['schedule'])
                ->sortable(),
            TextColumn::make('active')
                ->label(Lang::get('columns.active'))
                ->numeric()
                ->color('success')
                ->tooltip(Lang::get('columns.active_tooltip'))
                ->placeholder('—')
                ->visible($listable)
                ->sortable(),
            TextColumn::make('inactive')
                ->label(Lang::get('columns.inactive'))
                ->numeric()
                ->tooltip(Lang::get('columns.inactive_tooltip'))
                ->placeholder('—')
                ->visible($listable)
                ->sortable(),
            TextColumn::make('overrides')
                ->label(Lang::get('columns.overrides'))
                ->numeric()
                ->tooltip(Lang::get('columns.overrides_tooltip'))
                ->toggleable()
                ->sortable(),
            static::lastChangedColumn(),
        ];
    }

    /**
     * @return array<TextColumn>
     */
    protected static function tenantColumns(): array
    {
        return [
            static::nameColumn(),
            static::typeColumn(),
            TextColumn::make('value_label')
                ->label(Lang::get('columns.value'))
                ->badge()
                ->color(static fn (?array $record): string => static::valueColor($record['value'] ?? false))
                ->fontFamily(static fn (?array $record): ?string => $record === null || is_bool($record['value']) ? null : 'mono'),
            TextColumn::make('source')
                ->label(Lang::get('columns.source'))
                ->badge()
                ->color('gray')
                ->formatStateUsing(static fn (?string $state): string => static::sourceLabel($state))
                ->sortable(),
            static::lastChangedColumn(),
        ];
    }

    protected static function nameColumn(): TextColumn
    {
        return TextColumn::make('name')
            ->label(Lang::get('columns.name'))
            ->weight('medium')
            ->fontFamily('mono')
            ->description(static fn (array $record): ?string => $record['description'])
            ->wrap()
            ->searchable(['name', 'description'])
            ->sortable();
    }

    protected static function typeColumn(): TextColumn
    {
        return TextColumn::make('type')
            ->label(Lang::get('columns.type'))
            ->badge()
            ->color(static fn (?string $state): string => $state === FeatureInfo::TYPE_RICH ? 'info' : 'gray')
            ->formatStateUsing(static fn (?string $state): string => Lang::get('types.'.($state ?? FeatureInfo::TYPE_BOOLEAN)))
            ->toggleable();
    }

    protected static function lastChangedColumn(): TextColumn
    {
        return TextColumn::make('last_changed')
            ->label(Lang::get('columns.last_changed'))
            ->since()
            ->dateTimeTooltip()
            ->placeholder(Lang::get('columns.never'))
            ->sortable();
    }

    /**
     * @return array<string, string>
     */
    protected static function sourceOptions(): array
    {
        $options = [];

        foreach ([Decision::SOURCE_OVERRIDE, Decision::SOURCE_EVERYONE, Decision::SOURCE_ROLLOUT, Decision::SOURCE_KILLED, Decision::SOURCE_BEFORE, Decision::SOURCE_DEFAULT] as $source) {
            $options[$source] = static::sourceLabel($source);
        }

        return $options;
    }

    /**
     * The table's rows, keyed by feature name.
     *
     * @param  list<string>|null  $only
     * @return array<string, FeatureRow>
     */
    public static function rows(?array $only = null): array
    {
        $plugin = static::getPlugin();
        $tenant = $plugin->getManagedTenant();
        $names = $only ?? FeatureCatalog::names();
        $summaries = $tenant === null ? StoredValues::summaries() : [];
        $listable = $tenant === null && StoredValues::isListable();
        $overrides = static::overrideCounts($tenant);
        $audits = static::lastAudits($tenant);
        $rows = [];

        foreach ($names as $name) {
            $summary = $summaries[$name] ?? null;
            $info = FeatureCatalog::info($name, $tenant === null ? ($summary['rich'] ?? false) : null);
            $rule = FeatureFlags::rules()->rule($name);
            $status = static::statusOf($rule);
            $decision = $tenant === null ? null : FeatureFlags::evaluate($name, $tenant);

            $lastChanged = $audits[$name] ?? null;
            $stored = $summary['updated_at'] ?? null;

            if ($stored !== null && ($lastChanged === null || $stored->greaterThan($lastChanged))) {
                $lastChanged = $stored;
            }

            $rows[$name] = [
                'name' => $name,
                'description' => $info->description,
                'type' => $info->type,
                'status' => $status,
                'status_label' => static::statusLabel($status, $rule),
                'schedule' => static::scheduleSummary($rule),
                'active' => $listable ? ($summary['active'] ?? 0) : null,
                'inactive' => $listable ? ($summary['inactive'] ?? 0) : null,
                'overrides' => $overrides[$name] ?? 0,
                'last_changed' => $lastChanged?->toIso8601String(),
                'value' => $decision?->value,
                'value_label' => $decision === null ? null : static::valueLabel($decision->value),
                'source' => $decision?->source,
            ];
        }

        return $rows;
    }

    public static function scheduleSummary(?\AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule $rule): ?string
    {
        if ($rule === null || ! $rule->hasSchedule()) {
            return null;
        }

        $parts = [];

        if ($rule->activate_at !== null) {
            $parts[] = Lang::get('schedule.activates', ['date' => $rule->activate_at->diffForHumans()]);
        }

        if ($rule->deactivate_at !== null) {
            $parts[] = Lang::get('schedule.deactivates', ['date' => $rule->deactivate_at->diffForHumans()]);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, int>
     */
    protected static function overrideCounts(?Model $tenant): array
    {
        try {
            return FeatureFlagOverride::query()
                ->forTenant($tenant)
                ->selectRaw('feature, count(*) as aggregate')
                ->groupBy('feature')
                ->pluck('aggregate', 'feature')
                ->map(static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    protected static function lastAudits(?Model $tenant): array
    {
        try {
            return FeatureFlagAudit::query()
                ->forTenant($tenant)
                ->selectRaw('feature, max(created_at) as last_created_at')
                ->groupBy('feature')
                ->pluck('last_created_at', 'feature')
                ->filter(static fn (mixed $date): bool => is_string($date) && $date !== '')
                ->map(static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
