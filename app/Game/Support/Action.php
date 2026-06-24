<?php

namespace App\Game\Support;

final class Action
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {}
}
