<?php

namespace App\Game\Support;

final class ValidationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $message = null,
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
