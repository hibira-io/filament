<?php

namespace Hibira\Filament\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Hibira\Filament\Concerns\InteractsWithHibiraLock;

class EditPost extends EditRecord
{
    use InteractsWithHibiraLock;

    protected static string $resource = PostResource::class;
}
