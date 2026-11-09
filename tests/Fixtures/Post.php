<?php

namespace Hibira\Filament\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $guarded = [];

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
