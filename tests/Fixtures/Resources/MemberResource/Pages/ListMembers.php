<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources\MemberResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources\MemberResource;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;
}
