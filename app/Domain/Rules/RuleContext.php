<?php

declare(strict_types=1);

namespace App\Domain\Rules;

final class RuleContext
{
    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $marketOverrides
     * @param  array<string, mixed>  $emergencyOverrides
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $base,
        public readonly array $marketOverrides,
        public readonly array $emergencyOverrides,
        public readonly array $metadata,
        public readonly ?string $tag,
    ) {}

    public function forMarket(string $market): array
    {
        $layered = $this->base;

        if (isset($this->marketOverrides[$market]) && is_array($this->marketOverrides[$market])) {
            $layered = array_replace_recursive($layered, $this->marketOverrides[$market]);
        }

        if ($this->emergencyOverrides !== []) {
            $layered = array_replace_recursive($layered, $this->emergencyOverrides);
        }

        return $layered;
    }

    public static function fromResolved(ResolvedRules $resolved): self
    {
        return new self(
            base: $resolved->base,
            marketOverrides: $resolved->marketOverrides,
            emergencyOverrides: $resolved->emergencyOverrides,
            metadata: $resolved->metadata,
            tag: $resolved->tag,
        );
    }
}
