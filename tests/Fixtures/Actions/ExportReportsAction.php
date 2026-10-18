<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\AlfakitFeatureFlags\Concerns\FeatureFlagged;

class ExportReportsAction extends Action
{
    use FeatureFlagged;

    protected static ?string $feature = 'new-billing';
}
