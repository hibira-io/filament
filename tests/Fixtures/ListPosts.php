<?php

namespace Hibira\Filament\Tests\Fixtures;

use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()->importer(PostImporter::class),
        ];
    }
}
