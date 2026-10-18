<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Models;

use AlfaSix\AlfakitFeatureFlags\Models\Concerns\UsesFeatureFlagsConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An explicit value of a feature for one scope (targeting).
 *
 * @property int $id
 * @property string $feature
 * @property string $scope
 * @property string|null $scope_type
 * @property string|null $scope_id
 * @property string $value
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string|null $updated_by_type
 * @property string|null $updated_by_id
 * @property string|null $updated_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FeatureFlagOverride extends Model
{
    use UsesFeatureFlagsConnection;

    protected $table = 'alfakit_feature_flag_overrides';

    protected $guarded = [];

    public function getValue(): mixed
    {
        return static::decodeJson($this->value, false);
    }

    public function setValue(mixed $value): static
    {
        $this->value = static::encodeJson($value);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->getValue() !== false;
    }

    /**
     * Entries made in `$tenant` (or outside any tenant with null).
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
