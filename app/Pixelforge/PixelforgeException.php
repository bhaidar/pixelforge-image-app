<?php

namespace App\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** Worth retrying: transient limits and overload (but not the daily quota). */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true)
            || $this->status >= 500;
    }
}
