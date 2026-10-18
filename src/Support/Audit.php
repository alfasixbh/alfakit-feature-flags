<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagAudit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Records every change to a feature in the audit trail (who, what, before and after,
 * when), and in the activity log when the activity log plugin is installed.
 */
final class Audit
{
    public const ACTIVATED_FOR_EVERYONE = 'activated_for_everyone';

    public const DEACTIVATED_FOR_EVERYONE = 'deactivated_for_everyone';

    public const VALUE_SET_FOR_EVERYONE = 'value_set_for_everyone';

    public const DEFINITION_RESTORED = 'definition_restored';

    public const ROLLOUT_SET = 'rollout_set';

    public const KILLED = 'killed';

    public const RESTORED = 'restored';

    public const SCHEDULED = 'scheduled';

    public const PURGED = 'purged';

    public const RESET = 'reset';

    public const SCOPE_ACTIVATED = 'scope_activated';

    public const SCOPE_DEACTIVATED = 'scope_deactivated';

    public const SCOPE_VALUE_SET = 'scope_value_set';

    public const SCOPE_FORGOTTEN = 'scope_forgotten';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTIVATED_FOR_EVERYONE, self::DEACTIVATED_FOR_EVERYONE, self::VALUE_SET_FOR_EVERYONE, self::DEFINITION_RESTORED,
        self::ROLLOUT_SET, self::KILLED, self::RESTORED, self::SCHEDULED, self::PURGED, self::RESET,
        self::SCOPE_ACTIVATED, self::SCOPE_DEACTIVATED, self::SCOPE_VALUE_SET, self::SCOPE_FORGOTTEN,
    ];

    public static function record(
        string $feature,
        string $action,
        mixed $before,
        mixed $after,
        ?string $scope = null,
        ?string $scopeLabel = null,
        string $source = FeatureFlagAudit::SOURCE_PANEL,
    ): ?FeatureFlagAudit {
        $actor = $source === FeatureFlagAudit::SOURCE_SCHEDULE ? null : self::actor();
        $tenant = FeatureFlags::currentTenant();
        $panel = alfakit()->getCurrentPanel();

        try {
            $audit = new FeatureFlagAudit;
            $audit->forceFill([
                'feature' => $feature,
                'action' => $action,
                'scope' => $scope,
                'scope_label' => $scopeLabel === null ? null : mb_substr($scopeLabel, 0, 255),
                'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
                'actor_id' => $actor === null ? null : (string) $actor->getAuthIdentifier(),
                'actor_name' => $actor === null ? null : mb_substr(self::actorName($actor), 0, 255),
                'tenant_type' => $tenant?->getMorphClass(),
                'tenant_id' => $tenant === null ? null : (string) $tenant->getKey(),
                'source' => $source,
                'panel' => $panel?->getId(),
            ]);
            $audit->setSnapshots($before, $after);
            $audit->save();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        self::logActivity($audit, $actor);

        return $audit;
    }

    public static function describe(FeatureFlagAudit $audit): string
    {
        $key = 'alfakit-feature-flags::feature-flags.audit.actions.'.$audit->action;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $audit->action;
    }

    public static function actor(): ?Authenticatable
    {
        try {
            return alfakit()->auth() ?? auth()->user();
        } catch (Throwable) {
            return null;
        }
    }

    public static function actorName(Authenticatable $actor): string
    {
        try {
            return alfakit()->getCurrentOrDefaultPanel()->getUserName($actor);
        } catch (Throwable) {
            $name = $actor instanceof Model ? $actor->getAttribute('name') : null;

            return is_string($name) ? $name : (string) $actor->getAuthIdentifier();
        }
    }

    private static function logActivity(FeatureFlagAudit $audit, ?Authenticatable $actor): void
    {
        if (! FeatureFlagsPlugin::get()->logsToActivityLog() || ! function_exists('activity')) {
            return;
        }

        try {
            $logger = activity('feature-flags')
                ->performedOn($audit)
                ->event($audit->action)
                ->withProperties([
                    'feature' => $audit->feature,
                    'scope' => $audit->scope,
                    'old' => $audit->getBefore(),
                    'attributes' => $audit->getAfter(),
                ]);

            if ($actor instanceof Model) {
                $logger->causedBy($actor);
            }

            $logger->log((string) __('alfakit-feature-flags::feature-flags.audit.activity', [
                'action' => self::describe($audit),
                'feature' => $audit->feature,
            ]));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
