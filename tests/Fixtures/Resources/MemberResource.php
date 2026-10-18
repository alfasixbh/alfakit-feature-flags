<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitFeatureFlags\Infolists\Components\FeatureFlagsEntry;

class MemberResource extends Resource
{
    protected static ?string $model = TenantUser::class;

    protected static ?string $slug = 'members';

    protected static ?string $recordTitleAttribute = 'name';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            Section::make('Features')->schema([
                FeatureFlagsEntry::make()->features(['beta-reports', 'new-billing', 'purchase-button']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => MemberResource\Pages\ListMembers::route('/'),
            'view' => MemberResource\Pages\ViewMember::route('/{record}'),
        ];
    }
}
