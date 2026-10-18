<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Attributes;

use Attribute;

/**
 * The values a rich feature can take: the panel offers them in a select instead of a JSON
 * editor.
 *
 * ```php
 * #[Values(['blue' => 'Blue button', 'green' => 'Green button'])]
 * class PurchaseButton
 * {
 *     public function resolve(User $user): string { … }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Values
{
    /**
     * @param  array<array-key, string|int|float>  $values  A list of values, or `value => label`
     */
    public function __construct(public array $values) {}
}
