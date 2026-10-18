<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources\MemberResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources\MemberResource;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;
}
