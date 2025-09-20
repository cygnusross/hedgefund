<?php

namespace App\Domain\Rules;

use App\Models\RuleSet;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RuleResolver
{
    private const ACTIVE_POINTER_KEY = 'rules:active_period';

    private const CACHE_NAMESPACE = 'rules:current:';

    private const SHADOW_POINTER_KEY = 'rules:shadow_period';

    private const SHADOW_NAMESPACE = 'rules:shadow:';

    private const POINTER_TTL_SECONDS = 604800; // 7 days

    private const PAYLOAD_TTL_SECONDS = 604800; // 7 days

    private const SHADOW_TTL_SECONDS = 86400; // 1 day

    public function __construct(
        private readonly RuleSetRepository $repository,
        private readonly CacheRepository $cache,
    ) {}

    public function getActive(bool $allowFallback = true): ?ResolvedRules
    {
        $tag = $this->cache->get(self::ACTIVE_POINTER_KEY);
        if (is_string($tag)) {
            $resolved = $this->getByTag($tag, false);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (! $allowFallback) {
            return null;
        }

        try {
            $model = $this->repository->findActive() ?? $this->repository->latest(1)->first();
        } catch (\Throwable $e) {
            Log::warning('rule_resolver_active_lookup_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $model instanceof RuleSet) {
            return null;
        }

        $resolved = ResolvedRules::fromModel($model);
        $this->writeCache($resolved);

        return $resolved;
    }

    public function getByTag(string $tag, bool $fallback = true): ?ResolvedRules
    {
        $cacheKey = self::CACHE_NAMESPACE.$tag;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['base'], $cached['checksum'])) {
            return new ResolvedRules(
                base: $cached['base'],
                marketOverrides: $cached['market_overrides'] ?? [],
                emergencyOverrides: $cached['emergency_overrides'] ?? [],
                metadata: $cached['metadata'] ?? [],
                tag: $tag,
            );
        }

        try {
            $model = $this->repository->findByTag($tag);
        } catch (\Throwable $e) {
            Log::warning('rule_resolver_tag_lookup_failed', ['tag' => $tag, 'error' => $e->getMessage()]);

            $model = null;
        }

        if (! $model instanceof RuleSet) {
            if (! $fallback) {
                return null;
            }

            return $this->getActive(false);
        }

        $resolved = ResolvedRules::fromModel($model);
        $this->writeCache($resolved);

        return $resolved;
    }

    public function activate(ResolvedRules $rules): void
    {
        $cached = $this->writeCache($rules);
        if (! $cached) {
            throw new RuntimeException('Failed to cache ruleset');
        }

        $this->cache->put(self::ACTIVE_POINTER_KEY, $rules->tag, self::POINTER_TTL_SECONDS);
        $this->clearShadow();
    }

    public function activateShadow(ResolvedRules $rules): void
    {
        $cached = $this->writeCache($rules, self::SHADOW_NAMESPACE, self::SHADOW_TTL_SECONDS);
        if (! $cached) {
            throw new RuntimeException('Failed to cache shadow ruleset');
        }

        if ($rules->tag !== null) {
            $this->cache->put(self::SHADOW_POINTER_KEY, $rules->tag, self::SHADOW_TTL_SECONDS);
        }
    }

    public function clearShadow(): void
    {
        $this->cache->forget(self::SHADOW_POINTER_KEY);
    }

    public function getShadow(): ?ResolvedRules
    {
        $tag = $this->cache->get(self::SHADOW_POINTER_KEY);
        if (! is_string($tag)) {
            return null;
        }

        return $this->getShadowByTag($tag);
    }

    private function getShadowByTag(string $tag): ?ResolvedRules
    {
        $cacheKey = self::SHADOW_NAMESPACE.$tag;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['base'], $cached['checksum'])) {
            return new ResolvedRules(
                base: $cached['base'],
                marketOverrides: $cached['market_overrides'] ?? [],
                emergencyOverrides: $cached['emergency_overrides'] ?? [],
                metadata: $cached['metadata'] ?? [],
                tag: $tag,
            );
        }

        try {
            $model = $this->repository->findByTag($tag);
        } catch (\Throwable $e) {
            Log::warning('rule_resolver_shadow_lookup_failed', ['tag' => $tag, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $model instanceof RuleSet) {
            return null;
        }

        $resolved = ResolvedRules::fromModel($model);
        $this->writeCache($resolved, self::SHADOW_NAMESPACE, self::SHADOW_TTL_SECONDS);

        return $resolved;
    }

    private function writeCache(ResolvedRules $rules, string $namespace = self::CACHE_NAMESPACE, int $ttl = self::PAYLOAD_TTL_SECONDS): bool
    {
        if ($rules->tag === null) {
            Log::warning('rule_resolver_missing_tag', ['metadata' => $rules->metadata]);

            return false;
        }

        try {
            $cacheKey = $namespace.$rules->tag;
            $payload = $rules->toCachePayload();

            return $this->cache->put($cacheKey, $payload, $ttl);
        } catch (\Throwable $e) {
            Log::warning('rule_resolver_cache_write_failed', ['tag' => $rules->tag, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
