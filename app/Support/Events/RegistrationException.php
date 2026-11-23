<?php

namespace App\Support\Events;

use RuntimeException;

class RegistrationException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self((string) __('events.errors.'.$reason));
    }
}
