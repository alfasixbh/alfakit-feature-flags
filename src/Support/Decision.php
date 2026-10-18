<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

/**
 * A feature's value for one scope and where it came from.
 */
final readonly class Decision
{
    /** The kill switch is on: inactive for everyone. */
    public const SOURCE_KILLED = 'killed';

    /** The class feature's `before()` hook decided. */
    public const SOURCE_BEFORE = 'before';

    /** An explicit value for the scope (targeting). */
    public const SOURCE_OVERRIDE = 'override';

    /** The value set for everyone in the panel. */
    public const SOURCE_EVERYONE = 'everyone';

    /** The percentage rollout. */
    public const SOURCE_ROLLOUT = 'rollout';

    /** Pennant: the stored value, or the feature's definition. */
    public const SOURCE_DEFAULT = 'default';

    public function __construct(
        public mixed $value,
        public string $source,
    ) {}

    public function isActive(): bool
    {
        return $this->value !== false;
    }
}
