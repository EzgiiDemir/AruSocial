<?php

namespace App\Services;

final class FcmSendResult
{
    private function __construct(
        public bool $ok,
        public bool $invalidToken,
        public ?string $error = null,
    ) {}

    public static function ok(): self
    {
        return new self(true, false);
    }

    public static function invalidToken(): self
    {
        return new self(false, true, 'invalid_token');
    }

    public static function failed(string $error): self
    {
        return new self(false, false, $error);
    }
}
