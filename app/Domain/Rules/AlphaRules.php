<?php

namespace App\Domain\Rules;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class AlphaRules
{
    protected array $data = [];

    protected string $checksum = '';

    protected ?\DateTimeImmutable $loadedAt = null;

    public function __construct(protected string $path)
    {
        // Path stored; explicit reload required to avoid IO during construction in some contexts
    }

    public function reload(): void
    {
        if (! is_string($this->path) || $this->path === '') {
            throw new RuntimeException('AlphaRules: invalid path');
        }

        if (! file_exists($this->path)) {
            throw new RuntimeException("AlphaRules: rules file not found at {$this->path}");
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException("AlphaRules: unable to read file at {$this->path}");
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (\Throwable $e) {
            throw new RuntimeException('AlphaRules: invalid YAML - '.$e->getMessage());
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('AlphaRules: YAML did not parse to an array');
        }

        // Minimal schema requirements - session_filters is optional for backward compatibility
        $required = ['gates', 'confluence', 'risk', 'execution', 'cooldowns', 'overrides'];
        foreach ($required as $k) {
            if (! array_key_exists($k, $parsed)) {
                throw new RuntimeException("AlphaRules: missing required top-level key '{$k}'");
            }
        }

        // Basic validation examples (extend as needed)
        $gates = $parsed['gates'] ?? [];
        $newsThreshold = $gates['news_threshold'] ?? [];
        foreach (['strong', 'moderate', 'deadband'] as $fld) {
            if (isset($newsThreshold[$fld]) && ! is_numeric($newsThreshold[$fld])) {
                throw new RuntimeException("AlphaRules: gates.news_threshold.{$fld} must be numeric");
            }
        }

        $risk = $parsed['risk'] ?? [];
        $perTradePct = $risk['per_trade_pct'] ?? [];
        foreach (['default', 'strong', 'medium_strong', 'moderate', 'weak'] as $fld) {
            if (isset($perTradePct[$fld]) && ! is_numeric($perTradePct[$fld])) {
                throw new RuntimeException("AlphaRules: risk.per_trade_pct.{$fld} must be numeric");
            }
        }

        if (isset($risk['per_trade_cap_pct']) && ! is_numeric($risk['per_trade_cap_pct'])) {
            throw new RuntimeException('AlphaRules: risk.per_trade_cap_pct must be numeric');
        }

        if (isset($parsed['pair_exposure_pct']) && ! is_numeric($parsed['pair_exposure_pct'])) {
            throw new RuntimeException('AlphaRules: pair_exposure_pct must be numeric');
        }

        // Store
        $this->data = $parsed;
        $this->checksum = hash('sha256', $contents);
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

    /**
     * Sentiment-related gates. Examples:
     * - sentiment.mode => 'contrarian'|'follow' (default 'contrarian')
     * - sentiment.contrarian_threshold_pct => 65 (default)
     * - sentiment.neutral_band_pct => [45,55] (default)
     * - sentiment.weight => float (default 1.0)
     */
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
        ];
    }
}
