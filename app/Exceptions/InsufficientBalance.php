<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalance extends RuntimeException
{
    public function __construct(public readonly int $requiredNanos, public readonly int $availableNanos)
    {
        parent::__construct('Insufficient credit balance.');
    }
}
