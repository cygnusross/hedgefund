<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use Illuminate\Support\Facades\Log;

final class RuleContextManager
{
    private ?RuleContext $current = null;

    private ?RuleContext $shadow = null;

    public function __construct(private readonly RuleResolver $resolver) {}

    public function current(bool $refresh = false): ?RuleContext
    {
        if ($refresh || $this->current === null) {
            $this->current = $this->resolve();
        }

        return $this->current;
    }

    public function rulesFor(string $market): array
    {
        $context = $this->current();
        if ($context === null) {
            return [];
        }

        return $context->forMarket($market);
    }

    public function shadow(bool $refresh = false): ?RuleContext
    {
        if ($refresh || $this->shadow === null) {
            $this->shadow = $this->resolveShadow();
        }

        return $this->shadow;
    }

    public function shadowRulesFor(string $market): ?array
    {
        $context = $this->shadow();
        if ($context === null) {
            return null;
        }

        return $context->forMarket($market);
    }

    private function resolve(): ?RuleContext
    {
        try {
            $resolved = $this->resolver->getActive();
        } catch (\Throwable $e) {
            Log::warning('rule_context_resolve_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($resolved === null) {
            return null;
        }

        return RuleContext::fromResolved($resolved);
    }

    private function resolveShadow(): ?RuleContext
    {
        try {
            $resolved = $this->resolver->getShadow();
        } catch (\Throwable $e) {
            Log::warning('rule_context_shadow_resolve_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($resolved === null) {
            return null;
        }

        return RuleContext::fromResolved($resolved);
    }
}
