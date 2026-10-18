<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Features;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitFeatureFlags\Attributes\Description;
use Laravel\Pennant\Attributes\Name;

#[Name('new-billing')]
#[Description('The new billing page with invoices and payment methods.')]
class NewBilling
{
    public function resolve(TenantUser|Team|null $scope): bool
    {
        return false;
    }
}
