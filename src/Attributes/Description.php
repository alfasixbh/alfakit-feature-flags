<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Attributes;

use Attribute;

/**
 * What a class-based feature does, shown in the panel:
 *
 * ```php
 * #[Description('The new billing page with invoices and payment methods.')]
 * class NewBilling
 * {
 *     public function resolve(User $user): bool { … }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Description
{
    public function __construct(public string $description) {}
}
