<?php

namespace App\Console\Commands;

use App\Application\Calendar\CalendarCsvImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalendarImportCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:import-csv';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import calendar CSV files from storage/app/calendar_csv directory';

    public function __construct(protected CalendarCsvImporter $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Importing CSVs from: storage/app/calendar_csv');

        $this->syncPostgresSequence();

        $result = $this->importer->importDirectory('storage/app/calendar_csv', false, false, 'Low');

        $this->displayResults($result);

        return 0;
    }

    /**
     * Sync Postgres sequence to avoid primary key conflicts.
     */
    private function syncPostgresSequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement(
                <<<'SQL'
                SELECT setval(
                    pg_get_serial_sequence('calendar_events','id'),
                    COALESCE((SELECT MAX(id) FROM calendar_events), 0),
                    true
                )
                SQL
            );
            $this->info('Synced calendar_events id sequence to MAX(id)');
        } catch (\Throwable $e) {
            $this->warn('Failed to sync calendar_events sequence: '.$e->getMessage());
        }
    }

    /**
     * Display import results.
     */
    private function displayResults(array $result): void
    {
        $this->line(json_encode([
            'files_total' => $result['files_total'] ?? 0,
            'files_processed' => $result['files_processed'] ?? 0,
            'rows_read' => $result['rows_read'] ?? 0,
            'parsed' => $result['parsed'] ?? 0,
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'errors' => $result['errors'] ?? 0,
        ], JSON_PRETTY_PRINT));
    }
}
