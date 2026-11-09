<?php

namespace Hibira\Filament\Tests\Fixtures;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
            TextColumn::make('updated_at')->since(),
        ]);
    }

    public static function getRelations(): array
    {
        return [CommentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'view' => ViewPost::route('/{record}'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
