<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Pages\Concerns;

use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use AlfaSix\AlfakitFeatureFlags\Support\Decision;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureActions;
use AlfaSix\AlfakitFeatureFlags\Support\JsonValue;
use AlfaSix\AlfakitFeatureFlags\Support\Lang;

/**
 * Shared by the feature flag pages: access through the plugin's authorisation, and how a
 * feature's status and a value's source are named and coloured.
 *
 * @phpstan-require-extends \AlfaSix\Alfakit\Pages\Page
 */
trait InteractsWithFeatureFlags
{
    public const STATUS_KILLED = 'killed';

    public const STATUS_ON = 'on';

    public const STATUS_OFF = 'off';

    public const STATUS_VALUE = 'value';

    public const STATUS_ROLLOUT = 'rollout';

    public const STATUS_DEFAULT = 'default';

    public static function getPlugin(): FeatureFlagsPlugin
    {
        return FeatureFlagsPlugin::get();
    }

    public static function canAccess(): bool
    {
        return static::getPlugin()->isAuthorized();
    }

    public static function getNavigationLabel(): string
    {
        return static::getPlugin()->getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function tenantName(\Illuminate\Database\Eloquent\Model $tenant): string
    {
        $name = alfakit()->getCurrentOrDefaultPanel()->getTenantName($tenant);

        return $name instanceof \Illuminate\Contracts\Support\Htmlable ? strip_tags($name->toHtml()) : $name;
    }

    public static function statusOf(?FeatureFlagRule $rule): string
    {
        return match (true) {
            $rule === null => self::STATUS_DEFAULT,
            $rule->isKilled() => self::STATUS_KILLED,
            $rule->mode === FeatureFlagRule::MODE_EVERYONE => match (true) {
                $rule->getEveryoneValue() === true => self::STATUS_ON,
                $rule->getEveryoneValue() === false => self::STATUS_OFF,
                default => self::STATUS_VALUE,
            },
            $rule->getPercentage() !== null => self::STATUS_ROLLOUT,
            default => self::STATUS_DEFAULT,
        };
    }

    public static function statusLabel(string $status, ?FeatureFlagRule $rule = null): string
    {
        if ($status === self::STATUS_ROLLOUT && ($percentage = $rule?->getPercentage()) !== null) {
            return Lang::get('status.rollout_percentage', ['percentage' => FeatureActions::formatPercentage($percentage)]);
        }

        return Lang::get('status.'.$status);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach ([self::STATUS_ON, self::STATUS_OFF, self::STATUS_VALUE, self::STATUS_ROLLOUT, self::STATUS_KILLED, self::STATUS_DEFAULT] as $status) {
            $options[$status] = Lang::get('status.'.$status);
        }

        return $options;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_KILLED => 'danger',
            self::STATUS_ON => 'success',
            self::STATUS_VALUE => 'info',
            self::STATUS_ROLLOUT => 'warning',
            default => 'gray',
        };
    }

    public static function statusIcon(?string $status): string
    {
        return match ($status) {
            self::STATUS_KILLED => 'octagon-x',
            self::STATUS_ON => 'toggle-right',
            self::STATUS_OFF => 'toggle-left',
            self::STATUS_VALUE => 'braces',
            self::STATUS_ROLLOUT => 'chart-no-axes-column-increasing',
            default => 'code',
        };
    }

    public static function sourceLabel(?string $source): string
    {
        return Lang::get('sources.'.($source ?? Decision::SOURCE_DEFAULT));
    }

    public static function valueColor(mixed $value): string
    {
        return match (true) {
            $value === true => 'success',
            $value === false => 'gray',
            default => 'info',
        };
    }

    public static function valueLabel(mixed $value): string
    {
        return JsonValue::summary($value);
    }
}
