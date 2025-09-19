<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class DecisionMetadata
{
    public function __construct(private array $attributes = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
