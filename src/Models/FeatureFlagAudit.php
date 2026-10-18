<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Models;

use AlfaSix\AlfakitFeatureFlags\Models\Concerns\UsesFeatureFlagsConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One change to a feature: who made it, what changed, the state before and after.
 *
 * @property int $id
 * @property string $feature
 * @property string $action
 * @property string|null $scope
 * @property string|null $scope_label
 * @property string|null $before
 * @property string|null $after
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string $source
 * @property string|null $panel
 * @property Carbon|null $created_at
 */
class FeatureFlagAudit extends Model
{
    use UsesFeatureFlagsConnection;

    public const UPDATED_AT = null;

    public const SOURCE_PANEL = 'panel';

    public const SOURCE_SCHEDULE = 'schedule';

    public const SOURCE_CODE = 'code';

    protected $table = 'alfakit_feature_flag_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function getBefore(): mixed
    {
        return static::decodeJson($this->before);
    }

    public function getAfter(): mixed
    {
        return static::decodeJson($this->after);
    }

    public function setSnapshots(mixed $before, mixed $after): static
    {
        $this->before = $before === null ? null : static::encodeJson($before);
        $this->after = $after === null ? null : static::encodeJson($after);

        return $this;
    }

    /**
     * Entries made in `$tenant` (every entry with null).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, ?Model $tenant): Builder
    {
        if ($tenant === null) {
            return $query;
        }

        return $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', (string) $tenant->getKey());
    }
}
