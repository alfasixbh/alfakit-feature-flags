<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Models;

use AlfaSix\AlfakitFeatureFlags\Models\Concerns\UsesFeatureFlagsConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How the panel manages one feature: its kill switch, the value everyone gets or a
 * percentage rollout, and a schedule.
 *
 * @property int $id
 * @property string $feature
 * @property string|null $mode
 * @property string|null $value
 * @property string|null $percentage
 * @property string|null $salt
 * @property Carbon|null $killed_at
 * @property Carbon|null $activate_at
 * @property string|null $activate_value
 * @property Carbon|null $deactivate_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FeatureFlagRule extends Model
{
    use UsesFeatureFlagsConnection;

    /** Everyone gets `value`. */
    public const MODE_EVERYONE = 'everyone';

    /** `percentage` of the scopes get `true`, chosen by a stable hash. */
    public const MODE_ROLLOUT = 'rollout';

    protected $table = 'alfakit_feature_flag_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'killed_at' => 'datetime',
            'activate_at' => 'datetime',
            'deactivate_at' => 'datetime',
        ];
    }

    public function isKilled(): bool
    {
        return $this->killed_at !== null;
    }

    public function getEveryoneValue(): mixed
    {
        return static::decodeJson($this->value, false);
    }

    public function setEveryoneValue(mixed $value): static
    {
        $this->value = static::encodeJson($value);

        return $this;
    }

    public function getPercentage(): ?float
    {
        return $this->mode === self::MODE_ROLLOUT && is_numeric($this->percentage) ? (float) $this->percentage : null;
    }

    public function getSalt(): string
    {
        return is_string($this->salt) && $this->salt !== '' ? $this->salt : $this->feature;
    }

    public function getActivateValue(): mixed
    {
        return static::decodeJson($this->activate_value, true);
    }

    public function setActivateValue(mixed $value): static
    {
        $this->activate_value = $value === true || $value === null ? null : static::encodeJson($value);

        return $this;
    }

    public function hasSchedule(): bool
    {
        return $this->activate_at !== null || $this->deactivate_at !== null;
    }

    /**
     * Whether the row still means something (an empty one can be deleted).
     */
    public function isEmpty(): bool
    {
        return $this->mode === null && ! $this->isKilled() && ! $this->hasSchedule();
    }
}
