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
    protected $signature = 'calendar:import-csv
        {--dry-run}
        {--keep-files}
        {--min-impact=Medium}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import calendar CSV files from a directory';

    public function __construct(protected CalendarCsvImporter $importer)
    {
        parent::__construct();

        $help = <<<'HELP'
Usage examples:
    php artisan calendar:import-csv
    php artisan calendar:import-csv --min-impact=Low
    php artisan calendar:import-csv --dry-run
HELP;

        $this->setHelp($help);
    }

    public function handle(): int
    {
        // Fixed import path per new policy
        $path = 'storage/app/calendar_csv';

        $dryRun = (bool) $this->option('dry-run');
        $keepFiles = (bool) $this->option('keep-files');
        $minImpactOpt = $this->option('min-impact') ?? 'Medium';
        $minImpactOpt = is_array($minImpactOpt) ? (string) current($minImpactOpt) : (string) $minImpactOpt;
        $minImpact = ucfirst(strtolower(trim($minImpactOpt ?: 'Medium')));
        if (! in_array($minImpact, ['Low', 'Medium', 'High'], true)) {
            $this->warn("Invalid --min-impact '{$minImpactOpt}', defaulting to 'Medium'.");
            $minImpact = 'Medium';
        }

        $pPath = is_array($path) ? json_encode($path) : (string) $path;
        $this->info(sprintf('Importing CSVs from: %s pattern: %s min-impact: %s', $pPath, '*.csv', $minImpact));

        // If using Postgres and not a dry-run, ensure sequence is synced to avoid duplicate PKs.
        if (! $dryRun && DB::getDriverName() === 'pgsql') {
            try {
                // Set the sequence to the current max(id) and mark it as called so nextval() returns max(id)+1.
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
                $this->warn('Failed to sync calendar_events sequence: ' . $e->getMessage());
            }
        }

        $result = $this->importer->importDirectory($path, $dryRun, $keepFiles, $minImpact);

        // Pretty-print clean summary (first-level keys)
        $filesTotal = $result['files_total'] ?? 0;
        $rowsRead = $result['rows_read'] ?? 0;
        $parsed = $result['parsed'] ?? 0;
        $created = $result['created'] ?? 0;
        $updated = $result['updated'] ?? 0;
        $skipped = $result['skipped'] ?? 0;
        $errors = $result['errors'] ?? 0;

        $byTz = $result['by_timezone_source'] ?? ['utc_field' => 0, 'local_with_region' => 0, 'assumed_utc' => 0];
        $utcCount = (int) ($byTz['utc_field'] ?? 0);
        $localCount = (int) ($byTz['local_with_region'] ?? 0);
        $assumedCount = (int) ($byTz['assumed_utc'] ?? 0);

        if ($dryRun) {
            // Print one-line compact summary for dry-run per user's requested format
            $oneLine = sprintf(
                'files=%d rows=%d parsed=%d create=%d update=%d skipped=%d errors=%d (by_tz: utc=%d, local=%d, assumed=%d)',
                $filesTotal,
                $rowsRead,
                $parsed,
                $created,
                $updated,
                $skipped,
                $errors,
                $utcCount,
                $localCount,
                $assumedCount
            );

            $this->line($oneLine);
        } else {
            $summary = [
                'files_total' => $filesTotal,
                'files_processed' => $result['files_processed'] ?? 0,
                'rows_read' => $rowsRead,
                'parsed' => $parsed,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'by_timezone_source' => $byTz,
            ];

            $this->line(json_encode($summary, JSON_PRETTY_PRINT));
        }

        return 0;
    }
}
