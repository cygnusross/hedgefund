<?php

namespace App\Domain\Rules;

use App\Models\RuleSet;
use Illuminate\Support\Collection;

class RuleSetRepository
{
    public function findActive(): ?RuleSet
    {
        return RuleSet::query()->where('is_active', true)->first();
    }

    public function findByTag(string $tag): ?RuleSet
    {
        return RuleSet::query()->where('tag', $tag)->first();
    }

    public function latest(int $limit = 5): Collection
    {
        return RuleSet::query()->orderByDesc('created_at')->limit($limit)->get();
    }

    public function recentRegimes(int $limit = 8): Collection
    {
        return RuleSet::query()
            ->with(['regimes' => fn ($query) => $query->orderByDesc('week_tag')->limit($limit)])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
