<?php

declare(strict_types=1);

namespace App\Domain\Decision\DTO;

use App\Domain\Decision\Contracts\DecisionContextContract;

final readonly class DecisionRequest
{
    public function __construct(private ContextSnapshot $context)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(ContextSnapshot::fromArray($payload));
    }

    public static function fromContext(DecisionContextContract $context): self
    {
        if (method_exists($context, 'toRequest')) {
            return $context->toRequest();
        }

        return self::fromArray($context->toArray());
    }

    public function context(): ContextSnapshot
    {
        return $this->context;
    }
}
