<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

final readonly class IgRulesSnapshot
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function __construct(private array $rules) {}

    /**
     * @param  array<string, mixed>  $rules
     */
    public static function fromArray(array $rules): self
    {
        return new self($rules);
    }

    public function pipValue(): ?float
    {
        return isset($this->rules['pip_value']) ? (float) $this->rules['pip_value'] : null;
    }

    public function sizeStep(): ?float
    {
        return isset($this->rules['size_step']) ? (float) $this->rules['size_step'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->rules;
    }
}
