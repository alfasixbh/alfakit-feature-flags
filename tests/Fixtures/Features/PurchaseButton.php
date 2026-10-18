<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features;

use AlfaSix\AlfakitFeatureFlags\Attributes\Values;
use Laravel\Pennant\Attributes\Name;

#[Name('purchase-button')]
#[Values(['blue' => 'Blue button', 'green' => 'Green button'])]
class PurchaseButton
{
    public function description(): string
    {
        return 'The colour of the purchase button.';
    }

    public function resolve(mixed $scope): string
    {
        return 'blue';
    }
}
