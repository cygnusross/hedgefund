<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

use function data_get;

final class RulesSnapshot
{
    /**
     * @param  array<string, mixed>  $layered
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private readonly array $layered,
        private readonly ?string $tag = null,
        private readonly array $metadata = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        $layered = $payload['layered'] ?? $payload['rules'] ?? $payload;
        $tag = $payload['tag'] ?? $payload['metadata']['tag'] ?? null;
        $metadata = $payload['metadata'] ?? [];

        return new self(
            is_array($layered) ? $layered : [],
            is_string($tag) ? $tag : null,
            $metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'layered' => $this->layered,
            'tag' => $this->tag,
            'metadata' => $this->metadata,
        ];
    }

    public function tag(): ?string
    {
        return $this->tag;
    }

    /**
     * @return array<string, mixed>
     */
    public function layered(): array
    {
        return $this->layered;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->layered, $path, $default);
    }
}
