<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features;

use AlfaSix\AlfakitFeatureFlags\Attributes\Values;
use Laravel\Pennant\Attributes\Name;

/** Values from the attribute, labels (translatable) from `valueLabels()`. */
#[Name('checkout-layout')]
#[Values(['single', 'steps', 'express'])]
class CheckoutLayout
{
    /**
     * @return array<string, string>
     */
    public static function valueLabels(): array
    {
        return app()->getLocale() === 'de'
            ? ['single' => 'Eine Seite', 'steps' => 'Schritte']
            : ['single' => 'Single page', 'steps' => 'Steps'];
    }

    public function resolve(mixed $scope): string
    {
        return 'single';
    }
}
