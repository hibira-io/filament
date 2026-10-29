<?php

namespace Hibira\Filament\Chat;

use RuntimeException;

class ChatApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct("Hibira Chat API {$status}: {$message}");
    }
}
