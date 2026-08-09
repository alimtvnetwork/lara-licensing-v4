<?php

declare(strict_types=1);

namespace App\Support;

readonly class QueryResult
{
    public function __construct(
        public mixed $data,
        public bool $isFailed
    ) {}

    public function isSuccess(): bool
    {
        return !$this->isFailed;
    }
}
