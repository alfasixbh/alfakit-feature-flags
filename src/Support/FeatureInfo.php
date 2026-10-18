<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

/**
 * What the panel knows about a feature: its name, the class that defines it, a
 * description, whether it takes rich values and the values it can take.
 */
final readonly class FeatureInfo
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_RICH = 'rich';

    /**
     * @param  array<string, string>  $options  Known values, keyed by their JSON (`"blue"`) => label
     */
    public function __construct(
        public string $name,
        public ?string $class,
        public ?string $description,
        public string $type,
        public array $options,
        public bool $isDefined,
    ) {}

    public function isRich(): bool
    {
        return $this->type === self::TYPE_RICH;
    }

    public function hasOptions(): bool
    {
        return $this->options !== [];
    }
}
