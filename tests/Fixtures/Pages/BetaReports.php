<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Pages;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\AlfakitFeatureFlags\Concerns\FeatureFlagged;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Actions\ExportReportsAction;

class BetaReports extends Page
{
    use FeatureFlagged;

    protected static ?string $feature = 'beta-reports';

    protected static ?string $slug = 'beta-reports';

    protected static bool $isDiscovered = false;

    protected function getHeaderActions(): array
    {
        return [ExportReportsAction::make('exportReports')->action(static fn () => null)];
    }
}
