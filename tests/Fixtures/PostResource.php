<?php

namespace Hibira\Filament\Tests\Fixtures;

use Filament\Resources\Resource;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
