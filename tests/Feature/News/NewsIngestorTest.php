<?php

use App\Application\News\NewsStatIngestor;
use App\Models\NewsStat;
use App\Services\News\ForexNewsApiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use Illuminate\Support\Facades\Artisan;

it('ingests a stat from provider and upserts into DB', function () {
    Artisan::call('migrate');
    $pair = 'EUR/USD';
    $date = '2025-09-12';

    // Fake the provider by creating a partial stub
    $fakeResp = [
        'pair' => 'EUR-USD',
        'date' => $date,
        'raw_score' => 0.3,
        'strength' => 0.2,
        'counts' => ['pos' => 3, 'neg' => 2, 'neu' => 0],
    ];

    // Create a real provider but we'll mock its fetchStats method via a partial mock
    $provider = new ForexNewsApiProvider(['token' => 'fake', 'base_url' => 'https://api.example']);

    // Use closure binding to replace method (simple approach for this project)
    $ingestor = new NewsStatIngestor($provider);

    // Monkey-patch by creating an anonymous class that extends provider for test
    $mockProv = new class($fakeResp) extends ForexNewsApiProvider
    {
        private $resp;

        public function __construct($resp)
        {
            parent::__construct(['token' => 'fake']);
            $this->resp = $resp;
        }

        public function fetchStats(string $pair, string $date = 'today'): array
        {
            return $this->resp;
        }
    };

    $ingestor = new NewsStatIngestor($mockProv);

    $result = $ingestor->ingest($pair, $date);

    expect($result)->toBeInstanceOf(NewsStat::class);
    expect(round((float) $result->strength, 3))->toBe(0.2);
    expect($result->pos)->toBe(3);
    expect($result->neg)->toBe(2);
    expect($result->neu)->toBe(0);
});
