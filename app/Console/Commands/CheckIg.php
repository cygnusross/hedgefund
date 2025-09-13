<?php

namespace App\Console\Commands;

use App\Services\IG\Client as IgClient;
use Illuminate\Console\Command;

final class CheckIg extends Command
{
    protected $signature = 'ig:check {epic} {--bypass-cache}';

    protected $description = 'Check IG markets/{epic} endpoint and print the response (for debugging credentials).';

    public function handle(IgClient $ig): int
    {
        $epic = (string) $this->argument('epic');
        // Try to ensure a session exists (use demo or live credentials from config if available)
        $cfg = config('services.ig', []);
        $demoActive = data_get($cfg, 'demo.active', true);

        // Diagnostics: show which credential mode and whether critical tokens/keys are present (masked)
        $apiKey = data_get($cfg, 'key') ?: data_get($cfg, 'demo.key');
        $this->line('IG demo active: '.($demoActive ? 'true' : 'false'));
        $this->line('IG api key: '.$this->mask($apiKey));

        $cachedCst = $ig->getCachedCst();
        $cachedX = $ig->getCachedXSecurityToken();
        $this->line('Cached CST: '.$this->mask($cachedCst));
        $this->line('Cached X-SECURITY-TOKEN: '.$this->mask($cachedX));

        $creds = null;
        if ($demoActive) {
            $demo = data_get($cfg, 'demo', []);
            if (! empty($demo['username']) && ! empty($demo['password'])) {
                $creds = ['identifier' => $demo['username'], 'password' => $demo['password']];
            }
        } else {
            if (! empty($cfg['username']) && ! empty($cfg['password'])) {
                $creds = ['identifier' => $cfg['username'], 'password' => $cfg['password']];
            }
        }

        if ($creds !== null) {
            $this->line('Ensuring IG session using credentials from config...');
            try {
                $ok = $ig->ensureSession($creds);
                $this->line('ensureSession => '.($ok ? 'true' : 'false'));
                $this->line('Cached CST now: '.$this->mask($ig->getCachedCst()));
                $this->line('Cached X-SECURITY-TOKEN now: '.$this->mask($ig->getCachedXSecurityToken()));
            } catch (\Throwable $e) {
                $this->line('ensureSession failed: '.$e->getMessage());
            }
        } else {
            $this->line('No IG credentials found in config; proceeding without ensureSession.');
        }

        try {
            $this->line('Calling IG for epic: '.$epic);
            $resp = $ig->get('/markets/'.urlencode($epic));
            $this->line(json_encode($resp, JSON_PRETTY_PRINT));

            return 0;
        } catch (\Throwable $e) {
            $this->error('IG request failed: '.$e->getMessage());

            // If the exception message contains a JSON body, show a truncated version for diagnostics
            $msg = $e->getMessage();
            if ($msg) {
                $body = $msg;
                // Truncate long messages
                if (strlen($body) > 1000) {
                    $body = substr($body, 0, 1000).'...';
                }
                $this->line('Response snippet: '.$body);
            }

            return 1;
        }
    }

    private function mask(?string $value): string
    {
        if (empty($value)) {
            return '<none>';
        }

        // If short, hide entirely
        if (strlen($value) <= 8) {
            return '****';
        }

        return substr($value, 0, 4).'...'.substr($value, -4);
    }
}
