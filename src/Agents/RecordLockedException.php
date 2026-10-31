<?php

namespace Hibira\Filament\Agents;

use RuntimeException;

/** Thrown when an agent can't edit a record because someone else holds its lock. */
class RecordLockedException extends RuntimeException
{
    /** @param  array{id: string, name: string}  $holder */
    public function __construct(public readonly array $holder)
    {
        parent::__construct("{$holder['name']} is editing this record.");
    }
}
