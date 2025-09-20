<?php

namespace App\Domain\Rules;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class AlphaRules
{
    protected array $data = [];

    protected string $checksum = '';

    protected ?\DateTimeImmutable $loadedAt = null;

    protected array $marketOverrides = [];

    protected array $emergencyOverrides = [];

    protected ?string $activeTag = null;

    public function __construct(
        protected ?string $path = null,
        protected ?RuleResolver $resolver = null,
    ) {}

    public function reload(): void
    {
        if ($this->resolver !== null) {
            $resolved = $this->resolver->getActive();
            if ($resolved !== null) {
                $this->applyResolvedRules($resolved);

                return;
            }
        }

        $path = $this->resolvePath();

        if (! file_exists($path)) {
            throw new RuntimeException("AlphaRules: rules file not found at {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("AlphaRules: unable to read file at {$path}");
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (\Throwable $e) {
            throw new RuntimeException('AlphaRules: invalid YAML - '.$e->getMessage());
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('AlphaRules: YAML did not parse to an array');
        }

        $this->assertStructure($parsed);

        $this->data = $parsed;
        $this->checksum = hash('sha256', $contents);
        $this->loadedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->marketOverrides = [];
        $this->emergencyOverrides = [];
        $this->activeTag = null;
        $this->path = $path;
    }

    public function applyResolvedRules(ResolvedRules $resolved): void
    {
        $base = $resolved->base;
        if (! is_array($base)) {
            throw new RuntimeException('AlphaRules: resolved base rules must be an array');
        }

        $this->assertStructure($base);

        $this->data = $base;
        $this->marketOverrides = $resolved->marketOverrides;
        $this->emergencyOverrides = $resolved->emergencyOverrides;
        $this->checksum = $resolved->checksum();
        $this->activeTag = $resolved->tag;
        $this->loadedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function get(string $dotPath, $default = null)
    {
        $parts = explode('.', $dotPath);
        $ref = $this->data;
        foreach ($parts as $p) {
            if (! is_array($ref) || ! array_key_exists($p, $ref)) {
                return $default;
            }
            $ref = $ref[$p];
        }

        return $ref;
    }

    public function getGate(string $key, $default = null)
    {
        return $this->get('gates.'.$key, $default);
    }

    public function getRisk(string $key, $default = null)
    {
        return $this->get('risk.'.$key, $default);
    }

    public function getExecution(string $key, $default = null)
    {
        return $this->get('execution.'.$key, $default);
    }

    public function getConfluence(string $key, $default = null)
    {
        return $this->get('confluence.'.$key, $default);
    }

    public function getCooldown(string $key, $default = null)
    {
        return $this->get('cooldowns.'.$key, $default);
    }

    public function getSessionFilter(string $key, $default = null)
    {
        return $this->get('session_filters.'.$key, $default);
    }

    public function getSentimentGate(string $key, $default = null)
    {
        return $this->get('gates.sentiment.'.$key, $default);
    }

    public function meta(): array
    {
        return [
            'checksum' => $this->checksum,
            'loaded_at' => $this->loadedAt ? $this->loadedAt->format(DATE_ATOM) : null,
            'schema_version' => (string) ($this->data['schema_version'] ?? 'unknown'),
            'tag' => $this->activeTag,
        ];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function getMarketOverrides(): array
    {
        return $this->marketOverrides;
    }

    public function getEmergencyOverrides(): array
    {
        return $this->emergencyOverrides;
    }

    public function getActiveTag(): ?string
    {
        return $this->activeTag;
    }

    private function assertStructure(array $rules): void
    {
        $required = ['gates', 'confluence', 'risk', 'execution', 'cooldowns', 'overrides'];
        foreach ($required as $k) {
            if (! array_key_exists($k, $rules)) {
                throw new RuntimeException("AlphaRules: missing required top-level key '{$k}'");
            }
        }

        $risk = $rules['risk'] ?? [];
        $perTradePct = $risk['per_trade_pct'] ?? [];
        foreach (['default', 'strong', 'medium_strong', 'moderate', 'weak'] as $fld) {
            if (isset($perTradePct[$fld]) && ! is_numeric($perTradePct[$fld])) {
                throw new RuntimeException("AlphaRules: risk.per_trade_pct.{$fld} must be numeric");
            }
        }

        if (isset($risk['per_trade_cap_pct']) && ! is_numeric($risk['per_trade_cap_pct'])) {
            throw new RuntimeException('AlphaRules: risk.per_trade_cap_pct must be numeric');
        }

        if (isset($rules['pair_exposure_pct']) && ! is_numeric($rules['pair_exposure_pct'])) {
            throw new RuntimeException('AlphaRules: pair_exposure_pct must be numeric');
        }
    }

    private function resolvePath(): string
    {
        $path = $this->path ?? env('RULES_YAML_PATH', storage_path('app/alpha_rules.yaml'));

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('AlphaRules: invalid path and no resolver available');
        }

        return $path;
    }
}
