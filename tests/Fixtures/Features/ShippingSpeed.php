<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features;

use Laravel\Pennant\Attributes\Name;

/** Values and labels from `valueLabels()` alone. */
#[Name('shipping-speed')]
class ShippingSpeed
{
    /**
     * @return array<string, string>
     */
    public function valueLabels(): array
    {
        return ['standard' => 'Standard', 'next_day' => 'Next day'];
    }

    public function resolve(mixed $scope): string
    {
        return 'standard';
    }
}
