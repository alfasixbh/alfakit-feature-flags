<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Slider;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The actions on a feature, shared by the features table (per row) and the feature's own
 * page (header actions). Every action is authorised with the plugin's `can()`; the ones
 * that affect everyone only exist where the panel allows them (`hasGlobalActions()`), and
 * the tenant ones only on panels that manage a tenant.
 */
final class FeatureActions
{
    /**
     * @param  Closure(mixed): string  $resolveFeature  The feature's name from the action's record
     */
    private function __construct(private readonly Closure $resolveFeature) {}

    public static function forFeature(string $feature): self
    {
        return new self(static fn (): string => $feature);
    }

    /**
     * For a table of `FeatureCatalog` rows (`['name' => …]`).
     */
    public static function forRows(): self
    {
        return new self(static fn (mixed $record): string => is_array($record) && is_string($record['name'] ?? null) ? $record['name'] : '');
    }

    // ---------------------------------------------------------------- Everyone

    public function activate(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('activateForEveryone'), FeatureFlagsPlugin::ABILITY_TOGGLE)
            ->label(Lang::get('actions.activate.label'))
            ->icon('toggle-right')
            ->color('success')
            ->requiresConfirmation()
            ->modalIcon('toggle-right')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.activate.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.activate.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.activate.submit'))
            ->hidden(static fn (mixed $record = null): bool => self::everyoneValue($resolve($record)) === true)
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::activateForEveryone($feature = $resolve($record));

                self::notify(Lang::get('actions.activate.notification', ['feature' => $feature]));
            });
    }

    public function deactivate(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('deactivateForEveryone'), FeatureFlagsPlugin::ABILITY_TOGGLE)
            ->label(Lang::get('actions.deactivate.label'))
            ->icon('toggle-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon('toggle-left')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.deactivate.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.deactivate.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.deactivate.submit'))
            ->hidden(static fn (mixed $record = null): bool => self::everyoneValue($resolve($record)) === false)
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::deactivateForEveryone($feature = $resolve($record));

                self::notify(Lang::get('actions.deactivate.notification', ['feature' => $feature]));
            });
    }

    /**
     * A rich value for everyone (validated JSON, or one of the feature's known values).
     */
    public function setValue(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('setValueForEveryone'), FeatureFlagsPlugin::ABILITY_TOGGLE)
            ->label(Lang::get('actions.set_value.label'))
            ->icon('braces')
            ->color('gray')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.set_value.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.set_value.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.set_value.submit'))
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(static fn (mixed $record = null): bool => FeatureCatalog::info($resolve($record))->isRich())
            ->schema(static fn (mixed $record = null): array => [
                ValueField::make(FeatureCatalog::info($name = $resolve($record)), self::everyoneValue($name)),
            ])
            ->action(static function (array $data, mixed $record = null) use ($resolve): void {
                FeatureFlags::activateForEveryone($feature = $resolve($record), ValueField::read($data['value'] ?? null));

                self::notify(Lang::get('actions.set_value.notification', ['feature' => $feature]));
            });
    }

    /**
     * Stop forcing a value on everyone, or stop the rollout.
     */
    public function useDefinition(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('useDefinition'), FeatureFlagsPlugin::ABILITY_TOGGLE)
            ->label(Lang::get('actions.use_definition.label'))
            ->icon('code')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.use_definition.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.use_definition.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.use_definition.submit'))
            ->visible(static fn (mixed $record = null): bool => FeatureFlags::rules()->rule($resolve($record))?->mode !== null)
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::useDefinition($feature = $resolve($record));

                self::notify(Lang::get('actions.use_definition.notification', ['feature' => $feature]));
            });
    }

    public function rollout(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('rollout'), FeatureFlagsPlugin::ABILITY_ROLLOUT, requiresRules: true)
            ->label(Lang::get('actions.rollout.label'))
            ->icon('chart-no-axes-column-increasing')
            ->color('gray')
            ->modalIcon('chart-no-axes-column-increasing')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.rollout.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.rollout.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.rollout.submit'))
            ->visible(static fn (mixed $record = null): bool => ! FeatureCatalog::info($resolve($record))->isRich())
            ->fillForm(static fn (mixed $record = null): array => [
                'percentage' => FeatureFlags::rules()->rule($resolve($record))?->getPercentage() ?? 0,
            ])
            ->schema([
                Slider::make('percentage')
                    ->label(Lang::get('fields.percentage'))
                    ->range(0, 100)
                    ->step(1)
                    ->tooltips()
                    ->pips(density: 25)
                    ->fillTrack()
                    ->helperText(Lang::get('fields.percentage_help'))
                    ->required(),
            ])
            ->action(static function (array $data, mixed $record = null) use ($resolve): void {
                $percentage = Rollout::normalise($data['percentage'] ?? 0);
                FeatureFlags::rollout($feature = $resolve($record), $percentage);

                self::notify(Lang::get('actions.rollout.notification', ['feature' => $feature, 'percentage' => self::formatPercentage($percentage)]));
            });
    }

    public function schedule(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('schedule'), FeatureFlagsPlugin::ABILITY_SCHEDULE)
            ->label(Lang::get('actions.schedule.label'))
            ->icon('calendar-clock')
            ->color('gray')
            ->modalIcon('calendar-clock')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.schedule.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.schedule.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.schedule.submit'))
            ->modalWidth(Width::Large)
            ->fillForm(static function (mixed $record = null) use ($resolve): array {
                $rule = FeatureFlags::rules()->rule($resolve($record));

                return [
                    'activate_at' => $rule?->activate_at?->toDateTimeString(),
                    'deactivate_at' => $rule?->deactivate_at?->toDateTimeString(),
                    'value' => $rule?->activate_value === null ? null : JsonValue::encode($rule->getActivateValue(), pretty: true),
                ];
            })
            ->schema(static function (mixed $record = null) use ($resolve): array {
                $info = FeatureCatalog::info($resolve($record));
                $fields = [
                    DateTimePicker::make('activate_at')
                        ->label(Lang::get('fields.activate_at'))
                        ->helperText(Lang::get('fields.activate_at_help'))
                        ->seconds(false)
                        ->live()
                        ->nullable(),
                    DateTimePicker::make('deactivate_at')
                        ->label(Lang::get('fields.deactivate_at'))
                        ->helperText(Lang::get('fields.deactivate_at_help'))
                        ->seconds(false)
                        ->after('activate_at', static fn (Get $get): bool => filled($get('activate_at')))
                        ->nullable(),
                ];

                if ($info->isRich()) {
                    $fields[] = ValueField::make($info)
                        ->label(Lang::get('fields.activate_value'))
                        ->required(static fn (Get $get): bool => filled($get('activate_at')))
                        ->visible(static fn (Get $get): bool => filled($get('activate_at')));
                }

                return $fields;
            })
            ->action(static function (array $data, mixed $record = null) use ($resolve): void {
                $feature = $resolve($record);
                $activateAt = self::parseDate($data['activate_at'] ?? null);
                $deactivateAt = self::parseDate($data['deactivate_at'] ?? null);
                $value = FeatureCatalog::info($feature)->isRich() ? ValueField::read($data['value'] ?? null) : true;

                FeatureFlags::schedule($feature, $activateAt, $deactivateAt, $value);

                self::notify($activateAt === null && $deactivateAt === null
                    ? Lang::get('actions.schedule.cleared', ['feature' => $feature])
                    : Lang::get('actions.schedule.notification', ['feature' => $feature]));
            });
    }

    /**
     * The kill switch: off for everyone right away, whatever else says.
     */
    public function kill(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('kill'), FeatureFlagsPlugin::ABILITY_KILL, isDestructive: true, requiresRules: true)
            ->label(Lang::get('actions.kill.label'))
            ->icon('octagon-x')
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon('octagon-x')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.kill.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.kill.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.kill.submit'))
            ->hidden(static fn (mixed $record = null): bool => FeatureFlags::rules()->isKilled($resolve($record)))
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::kill($feature = $resolve($record));

                Notification::make()->warning()->title(Lang::get('actions.kill.notification', ['feature' => $feature]))->send();
            });
    }

    public function restore(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('restore'), FeatureFlagsPlugin::ABILITY_KILL, requiresRules: true)
            ->label(Lang::get('actions.restore.label'))
            ->icon('rotate-ccw')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.restore.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.restore.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.restore.submit'))
            ->visible(static fn (mixed $record = null): bool => FeatureFlags::rules()->isKilled($resolve($record)))
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::restore($feature = $resolve($record));

                self::notify(Lang::get('actions.restore.notification', ['feature' => $feature]));
            });
    }

    public function purge(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('purge'), FeatureFlagsPlugin::ABILITY_PURGE, isDestructive: true)
            ->label(Lang::get('actions.purge.label'))
            ->icon('eraser')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.purge.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.purge.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.purge.submit'))
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::purge($feature = $resolve($record));

                self::notify(Lang::get('actions.purge.notification', ['feature' => $feature]));
            });
    }

    public function reset(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->global(Action::make('reset'), FeatureFlagsPlugin::ABILITY_PURGE, isDestructive: true)
            ->label(Lang::get('actions.reset.label'))
            ->icon('history')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.reset.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.reset.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.reset.submit'))
            ->action(static function (mixed $record = null) use ($resolve): void {
                FeatureFlags::reset($feature = $resolve($record));

                self::notify(Lang::get('actions.reset.notification', ['feature' => $feature]));
            });
    }

    // ---------------------------------------------------------------- Current tenant

    public function activateForTenant(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->tenant(Action::make('activateForTenant'))
            ->label(Lang::get('actions.activate_tenant.label'))
            ->icon('toggle-right')
            ->color('success')
            ->hidden(static fn (mixed $record = null): bool => self::tenantDecision($resolve($record))?->value === true)
            ->action(static function (mixed $record = null) use ($resolve): void {
                $tenant = self::managedTenantOrFail();
                FeatureFlags::setFor($feature = $resolve($record), $tenant, true);

                self::notify(Lang::get('actions.activate_tenant.notification', ['feature' => $feature]));
            });
    }

    public function deactivateForTenant(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->tenant(Action::make('deactivateForTenant'))
            ->label(Lang::get('actions.deactivate_tenant.label'))
            ->icon('toggle-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.deactivate_tenant.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.deactivate_tenant.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.deactivate_tenant.submit'))
            ->hidden(static fn (mixed $record = null): bool => self::tenantDecision($resolve($record))?->value === false)
            ->action(static function (mixed $record = null) use ($resolve): void {
                $tenant = self::managedTenantOrFail();
                FeatureFlags::setFor($feature = $resolve($record), $tenant, false);

                self::notify(Lang::get('actions.deactivate_tenant.notification', ['feature' => $feature]));
            });
    }

    public function setValueForTenant(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->tenant(Action::make('setValueForTenant'))
            ->label(Lang::get('actions.set_value_tenant.label'))
            ->icon('braces')
            ->color('gray')
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.set_value_tenant.modal_heading', ['feature' => $resolve($record)]))
            ->modalSubmitActionLabel(Lang::get('actions.set_value.submit'))
            ->modalWidth(Width::TwoExtraLarge)
            ->visible(static fn (mixed $record = null): bool => FeatureCatalog::info($resolve($record))->isRich())
            ->schema(static fn (mixed $record = null): array => [
                ValueField::make(FeatureCatalog::info($name = $resolve($record)), self::tenantDecision($name)?->value),
            ])
            ->action(static function (array $data, mixed $record = null) use ($resolve): void {
                $tenant = self::managedTenantOrFail();
                FeatureFlags::setFor($feature = $resolve($record), $tenant, ValueField::read($data['value'] ?? null));

                self::notify(Lang::get('actions.set_value.notification', ['feature' => $feature]));
            });
    }

    public function forgetForTenant(): Action
    {
        $resolve = $this->resolveFeature;

        return $this->tenant(Action::make('forgetForTenant'))
            ->label(Lang::get('actions.forget_tenant.label'))
            ->icon('undo-2')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(static fn (mixed $record = null): string => Lang::get('actions.forget_tenant.modal_heading', ['feature' => $resolve($record)]))
            ->modalDescription(Lang::get('actions.forget_tenant.modal_description'))
            ->modalSubmitActionLabel(Lang::get('actions.forget_tenant.submit'))
            ->visible(static fn (mixed $record = null): bool => self::tenantDecision($resolve($record))?->source === Decision::SOURCE_OVERRIDE)
            ->action(static function (mixed $record = null) use ($resolve): void {
                $tenant = self::managedTenantOrFail();
                FeatureFlags::forgetFor($feature = $resolve($record), $tenant);

                self::notify(Lang::get('actions.forget_tenant.notification', ['feature' => $feature]));
            });
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * The value forced on everyone (`mode = everyone`), or null without one.
     */
    public static function everyoneValue(string $feature): mixed
    {
        $rule = FeatureFlags::rules()->rule($feature);

        return $rule?->mode === FeatureFlagRule::MODE_EVERYONE ? $rule->getEveryoneValue() : null;
    }

    public static function tenantDecision(string $feature): ?Decision
    {
        $tenant = FeatureFlagsPlugin::get()->getManagedTenant();

        return $tenant === null || $feature === '' ? null : FeatureFlags::evaluate($feature, $tenant);
    }

    public static function formatPercentage(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.');
    }

    public static function notify(string $title): void
    {
        Notification::make()->success()->title($title)->send();
    }

    private function global(Action $action, string $ability, bool $isDestructive = false, bool $requiresRules = false): Action
    {
        $resolve = $this->resolveFeature;
        $plugin = FeatureFlagsPlugin::get();

        $action->authorize(static function (mixed $record = null) use ($resolve, $ability, $requiresRules): bool {
            $plugin = FeatureFlagsPlugin::get();
            $feature = $resolve($record);

            return $feature !== ''
                && $plugin->hasGlobalActions()
                && (! $requiresRules || FeatureFlags::enforcesRules())
                && $plugin->can($ability, $feature);
        });

        if ($isDestructive && $plugin->shouldRequirePasswordConfirmation()) {
            $action->requiresPasswordConfirmation();
        }

        return $action;
    }

    private function tenant(Action $action): Action
    {
        $resolve = $this->resolveFeature;

        return $action->authorize(static function (mixed $record = null) use ($resolve): bool {
            $feature = $resolve($record);

            return $feature !== ''
                && FeatureFlagsPlugin::get()->getManagedTenant() !== null
                && FeatureFlagsPlugin::get()->can(FeatureFlagsPlugin::ABILITY_TOGGLE, $feature);
        });
    }

    private static function managedTenantOrFail(): Model
    {
        $tenant = FeatureFlagsPlugin::get()->getManagedTenant();

        abort_if($tenant === null, 403);

        return $tenant;
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
