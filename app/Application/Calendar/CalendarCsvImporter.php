<?php

namespace App\Application\Calendar;

use App\Models\CalendarEvent;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// nnjeim/world removed — importer now assumes naive dates are UTC

/**
 * CSV importer scaffolding for calendar files.
 *
 * Provides `importDirectory` and `importFile` methods used by the
 * `calendar:import-csv` artisan command. Implement domain-specific
 * parsing and persistence inside `importFile`.
 */
class CalendarCsvImporter
{
    public function __construct(protected Filesystem $files) {}

    // No country->timezone lookup — CSV dates are assumed to be UTC or have offsets.

    /**
     * Find an existing CalendarEvent by the (currency, event_time_utc, title) tuple
     * with normalized time comparison to handle DB storage format differences.
     */
    protected function findExistingEventByTuple(array $payload): ?CalendarEvent
    {
        $title = $payload['title'] ?? null;
        $currency = $payload['currency'] ?? null;
        $time = $payload['event_time_utc'] ?? null;

        if (empty($title) || empty($currency) || empty($time)) {
            return null;
        }

        try {
            $candidates = CalendarEvent::where('currency', $currency)
                ->where('title', $title)
                ->get();

            $payloadTime = null;
            try {
                $payloadTime = new CarbonImmutable($time);
            } catch (\Throwable $e) {
                $payloadTime = null;
            }

            foreach ($candidates as $cand) {
                if (empty($cand->event_time_utc)) {
                    continue;
                }

                try {
                    $candTime = new CarbonImmutable($cand->event_time_utc);
                } catch (\Throwable $e) {
                    continue;
                }

                if ($payloadTime instanceof CarbonImmutable && $candTime->equalTo($payloadTime)) {
                    return $cand;
                }
            }
        } catch (\Throwable $e) {
            // ignore and return null
        }

        return null;
    }

    /**
     * Import CSV files from the given directory matching the pattern.
     * Returns a summary array with totals and per-file results.
     *
     * @return array{
     *   total_files:int,
     *   total_rows:int,
     *   files: array<string,array>
     * }
     */
    public function importDirectory(string $path, bool $dryRun = false, bool $keepFiles = false, string $minImpact = 'Medium'): array
    {
        $fullPath = Str::startsWith($path, ['/', './']) ? $path : base_path($path);

        if (! $this->files->isDirectory($fullPath)) {
            return [
                'total_files' => 0,
                'total_rows' => 0,
                'files' => [],
            ];
        }

        // Pattern is fixed to '*.csv' per new policy
        $patternPath = rtrim($fullPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.csv';
        $matches = glob($patternPath) ?: [];

        $summary = [
            'total_files' => 0,
            'total_rows' => 0,
            'files' => [],
        ];

        // Aggregates for clean summary
        $aggFilesTotal = count($matches);
        $aggFilesProcessed = 0;
        $aggRowsRead = 0;
        $aggCreated = 0;
        $aggUpdated = 0;
        $aggWouldCreate = 0;
        $aggWouldUpdate = 0;
        $aggParsed = 0;
        $aggErrorsCount = 0;
        $aggByTz = [
            'utc_field' => 0,
            'local_with_region' => 0,
            'assumed_utc' => 0,
        ];

        foreach ($matches as $file) {
            if (! is_readable($file)) {
                $summary['files'][$file] = ['status' => 'unreadable'];

                continue;
            }

            $result = $this->importFile($file, $dryRun, $minImpact);

            $summary['files'][$file] = $result;
            $summary['total_files']++;
            $summary['total_rows'] += $result['rows'] ?? 0;

            // aggregate
            $aggFilesProcessed++;
            $aggRowsRead += $result['rows'] ?? 0;
            $aggCreated += $result['created'] ?? 0;
            $aggUpdated += $result['updated'] ?? 0;
            $aggWouldCreate += $result['would_create'] ?? 0;
            $aggWouldUpdate += $result['would_update'] ?? 0;
            $aggParsed += $result['parsed'] ?? 0;
            $aggErrorsCount += is_array($result['errors'] ?? null) ? count($result['errors']) : 0;
            // aggregate timezone source counts from file result
            if (isset($result['by_timezone_source']) && is_array($result['by_timezone_source'])) {
                foreach ($result['by_timezone_source'] as $k => $v) {
                    if (isset($aggByTz[$k])) {
                        $aggByTz[$k] += (int) $v;
                    } else {
                        $aggByTz[$k] = (int) $v;
                    }
                }
            }

            // Emit an info-level log per file with totals and first 3 errors. Tag with source_file and import_id.
            try {
                $importId = (string) Str::uuid();
                $firstErrors = [];
                if (! empty($result['errors']) && is_array($result['errors'])) {
                    $firstErrors = array_slice($result['errors'], 0, 3);
                }

                Log::info('calendar.import.file_summary', [
                    'source_file' => basename($file),
                    'import_id' => $importId,
                    'rows' => $result['rows'] ?? 0,
                    'parsed' => $result['parsed'] ?? 0,
                    'created' => $result['created'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                    'skipped' => $result['skipped'] ?? 0,
                    'errors_preview' => $firstErrors,
                ]);
            } catch (\Throwable $e) {
                // swallowing logging errors to avoid breaking import
            }

            // File handling: processed/ and failed/ subfolders next to the file.
            // Do not move/delete files on dry-run.
            if ($dryRun) {
                continue;
            }

            $dir = dirname($file);
            $base = basename($file);
            $processedDir = $dir.DIRECTORY_SEPARATOR.'processed';
            $failedDir = $dir.DIRECTORY_SEPARATOR.'failed';

            // Ensure directories exist when needed
            if (! $this->files->exists($processedDir)) {
                $this->files->makeDirectory($processedDir, 0755, true, true);
            }
            if (! $this->files->exists($failedDir)) {
                $this->files->makeDirectory($failedDir, 0755, true, true);
            }

            // If there are no parse errors and status is imported -> success
            $hasErrors = ! empty($result['errors']);
            $isImported = (($result['status'] ?? '') === 'imported');

            if ($isImported && ! $hasErrors) {
                if ($keepFiles) {
                    // move to processed/filename.csv
                    $dest = $processedDir.DIRECTORY_SEPARATOR.$base;
                    try {
                        $this->files->move($file, $dest);
                    } catch (\Exception $e) {
                        // if move fails, attempt delete to avoid reprocessing
                        $this->files->delete($file);
                    }
                } else {
                    // delete source file
                    $this->files->delete($file);
                }
            } else {
                // Partial or parse errors -> move to failed and write errors file
                $dest = $failedDir.DIRECTORY_SEPARATOR.$base;
                try {
                    $this->files->move($file, $dest);
                } catch (\Exception $e) {
                    // fallback: if move fails, ensure source is deleted to avoid reprocessing
                    // but keep original if delete fails
                }

                // write errors with line numbers + reasons
                $errorFile = $failedDir.DIRECTORY_SEPARATOR.$base.'.errors.txt';
                $errorLines = [];
                foreach ($result['errors'] ?? [] as $err) {
                    if (is_array($err)) {
                        $lineNo = $err['line'] ?? '?';
                        $reason = $err['error'] ?? json_encode($err);
                        $errorLines[] = "Line {$lineNo}: {$reason}";
                    } else {
                        $errorLines[] = (string) $err;
                    }
                }

                if (! empty($errorLines)) {
                    $this->files->put($errorFile, implode(PHP_EOL, $errorLines));
                }
            }
        }

        // Build clean per-run summary
        if ($dryRun) {
            $skipped = $aggWouldCreate + $aggWouldUpdate;
        } else {
            $skipped = max(0, $aggParsed - ($aggCreated + $aggUpdated));
        }

        $clean = [
            'files_total' => $aggFilesTotal,
            'files_processed' => $aggFilesProcessed,
            'rows_read' => $aggRowsRead,
            'created' => $aggCreated,
            'updated' => $aggUpdated,
            'skipped' => $skipped,
            'errors' => $aggErrorsCount,
            'by_timezone_source' => $aggByTz,
        ];

        // Return both clean summary and per-file details
        return array_merge($clean, ['files' => $summary['files']]);
    }

    /**
     * Import a single CSV file. Returns details about the import.
     *
     * @return array{status:string, rows:int, message?:string}
     */
    public function importFile(string $file, bool $dryRun = false, string $minImpact = 'Medium'): array
    {
        try {
            $contents = $this->files->get($file);
        } catch (\Exception $e) {
            return ['status' => 'error', 'rows' => 0, 'message' => $e->getMessage()];
        }

        // Split into non-empty lines and preserve order
        $allLines = preg_split('/\\r?\\n/', $contents) ?: [];
        $lines = array_values(array_filter($allLines, fn ($l) => trim((string) $l) !== ''));

        // Derive default source from file basename
        $fileBase = basename($file);
        $defaultSource = 'csv:'.$fileBase;

        if (empty($lines)) {
            return ['status' => 'imported', 'rows' => 0, 'parsed' => 0, 'errors' => []];
        }

        // First non-empty line is header
        $headerLine = array_shift($lines);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv($headerLine));

        // Map header name -> index for case-insensitive lookups
        $headerMap = [];
        foreach ($headers as $idx => $name) {
            $headerMap[$name] = $idx;
        }

        $rowCount = 0;
        $parsedCount = 0;
        $errors = [];
        $parsedRows = [];
        // timezone source counters for this file
        $tzCounts = [
            'utc_field' => 0,
            'local_with_region' => 0,
            'assumed_utc' => 0,
        ];

        $wouldCreate = 0;
        $wouldUpdate = 0;
        $created = 0;
        $updated = 0;

        $upsertRows = [];
        $hashes = [];

        foreach ($lines as $ln => $line) {
            $rowCount++;

            $cols = str_getcsv($line);

            // Build associative row using header map (case-insensitive)
            $row = [];
            foreach ($headerMap as $name => $idx) {
                $row[$name] = isset($cols[$idx]) ? trim((string) $cols[$idx]) : null;
            }

            // Extract expected columns (case-insensitive names handled by map)
            // Support alternate header names: 'event' -> title, 'date' -> date, 'currency' -> currency
            $title = $row['title'] ?? $row['event'] ?? null;
            $currency = $row['currency'] ?? null;
            // allow header named 'currencies' as well
            if (($currency === null) && array_key_exists('currencies', $row)) {
                $currency = $row['currencies'];
            }
            $impact = $row['impact'] ?? null;
            $date = $row['date'] ?? $row['Date'] ?? null;
            $source = $row['source'] ?? null;
            $src = $source ?? $defaultSource;

            // Trim strings already applied, validate required fields
            if (empty($title) && empty($date)) {
                $errors[] = ['line' => $ln + 2, 'error' => 'missing title and/or date'];

                continue;
            }

            // Initialize date placeholders (used later even if impact filtering skips row)
            $dateIso = null;
            $eventTimeUtc = null;
            $dtUtc = null; // CarbonImmutable instance in UTC when available

            // Handle currencies that may be array-like: "[EUR,USD]" or "EUR,USD"
            $currencyToken = null;
            if (! empty($currency)) {
                // strip surrounding brackets and quotes
                $c = trim($currency);
                $c = preg_replace('/^[\[\(\"\']+|[\]\)\"\']+$/', '', $c);
                // split on non-alphanumeric separators
                $parts = preg_split('/[^A-Za-z0-9]+/', $c);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') {
                        $currencyToken = strtoupper($p);
                        break;
                    }
                }
            }

            // Validate currency token: must be 3 uppercase letters (e.g. USD) or the special 'ALL'
            if ($currencyToken === null) {
                $errors[] = ['line' => $ln + 2, 'error' => 'invalid currency: '.($currency ?? '')];

                continue;
            }

            if (strtoupper($currencyToken) !== 'ALL' && ! preg_match('/^[A-Z]{3}$/', $currencyToken)) {
                $errors[] = ['line' => $ln + 2, 'error' => 'invalid currency: '.$currencyToken];

                continue;
            }

            // Normalize impact to Low|Medium|High (ucfirst). Treat 'None' as 'Low'. Reject others.
            $normalizedImpact = null;
            if (! empty($impact)) {
                $impactClean = ucfirst(strtolower(trim((string) $impact)));
                if ($impactClean === 'None') {
                    $impactClean = 'Low';
                }

                if (! in_array($impactClean, ['Low', 'Medium', 'High'], true)) {
                    // invalid impact value -> skip row and record error
                    $errors[] = ['line' => $ln + 2, 'error' => 'invalid impact: '.($impact ?? '')];

                    continue;
                }

                $normalizedImpact = $impactClean;
            }

            // Filter by minImpact threshold
            $levels = ['Low' => 1, 'Medium' => 2, 'High' => 3];
            $minLevel = $levels[$minImpact] ?? 2;
            if ($normalizedImpact === null) {
                // treat missing impact as Low
                $normalizedImpact = 'Low';
            }

            if (($levels[$normalizedImpact] ?? 0) < $minLevel) {
                // skip lower-impact rows
                $parsedRows[] = [
                    'line' => $ln + 2,
                    'title' => $title,
                    'currency' => $currencyToken,
                    'impact' => $normalizedImpact,
                    'event_time_utc' => $eventTimeUtc,
                    'source' => $src,
                    'hash' => null,
                    'action' => 'skipped-low-impact',
                ];

                continue;
            }

            // Parse date - support ISO with offset OR local datetime with provided tz, then convert to UTC
            // If Date has no offset, parse using strict format 'Y, F d, H:i' with the command tz and convert to UTC.
            $dateIso = null;
            $eventTimeUtc = null;
            $timezoneSource = null; // one of: utc_field | local_with_region | assumed_utc
            if (! empty($date)) {
                try {
                    if (preg_match('/[Zz]|[+\-]\d{2}:?\d{2}/', $date)) {
                        // has offset -> parse directly
                        $dt = CarbonImmutable::parse($date);
                        $dtUtc = $dt->setTimezone('UTC');
                        $dateIso = $dtUtc->format(DATE_ATOM);
                        $eventTimeUtc = $dateIso;
                        $timezoneSource = 'utc_field';
                    } else {
                        // No offset present. All uploaded CSVs are UTC — parse as UTC and mark as utc_field.
                        try {
                            $dtUtc = false;
                            $formats = [
                                'Y, F d, H:i', // e.g. 2025, September 07, 05:15
                                'Y-m-d H:i:s',
                                'Y-m-d H:i',
                            ];

                            foreach ($formats as $fmt) {
                                try {
                                    $candidate = CarbonImmutable::createFromFormat($fmt, $date, new \DateTimeZone('UTC'));
                                } catch (\Throwable $inner) {
                                    $candidate = false;
                                }

                                if ($candidate instanceof CarbonImmutable) {
                                    $dtUtc = $candidate->setTimezone('UTC');
                                    break;
                                }
                            }

                            if (! $dtUtc instanceof CarbonImmutable) {
                                // fallback: parse liberally but assume UTC
                                $dt = CarbonImmutable::parse($date, 'UTC');
                                $dtUtc = $dt->setTimezone('UTC');
                            }

                            $dateIso = $dtUtc->format(DATE_ATOM);
                            $eventTimeUtc = $dateIso;
                            $timezoneSource = 'utc_field';
                        } catch (\Throwable $e) {
                            $errors[] = ['line' => $ln + 2, 'error' => 'invalid date (could not parse as UTC): '.$date];

                            continue;
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = ['line' => $ln + 2, 'error' => 'invalid date: '.$e->getMessage()];

                    continue; // skip this row on parse failure
                }
            }

            // At this point we have sanitized row values: $title, $currencyToken, $normalizedImpact, $dateIso, $source
            // Determine target currencies. If currencyToken === 'ALL', expand into unique currencies
            // from active markets (preserving the same title/date/impact/source). Otherwise use
            // the single parsed currency token (which may be empty string).

            $targets = [];
            if (strtoupper((string) $currencyToken) === 'ALL') {
                // Collect unique currencies from active markets. Markets may store 'currencies' as
                // arrays or as bracketed strings; handle both.
                $raw = Market::query()->where('is_active', true)->pluck('currencies')->all();
                $set = [];
                foreach ($raw as $r) {
                    if (is_array($r)) {
                        foreach ($r as $cc) {
                            $cc = trim((string) $cc);
                            if ($cc !== '') {
                                $set[strtoupper($cc)] = true;
                            }
                        }
                    } elseif (is_string($r)) {
                        // strip surrounding brackets/quotes and split on non-alphanum
                        $s = preg_replace('/^[\[\(\"\']+|[\]\)\"\']+$/', '', $r);
                        $parts = preg_split('/[^A-Za-z0-9]+/', $s) ?: [];
                        foreach ($parts as $cc) {
                            $cc = trim((string) $cc);
                            if ($cc !== '') {
                                $set[strtoupper($cc)] = true;
                            }
                        }
                    }
                }

                $targets = array_keys($set);
                sort($targets);
            } else {
                $targets = [strtoupper((string) ($currencyToken ?? ''))];
            }

            // Increment parsed count by number of target currencies
            $parsedCount += count($targets);

            foreach ($targets as $targetCurrency) {
                // Build deterministic idempotency hash so re-imports don't duplicate the same event.
                // Use the same hash generation method as CalendarEvent::makeHash() for consistency
                $eventTimeImmutable = null;
                if ($dtUtc instanceof \Carbon\CarbonImmutable) {
                    $eventTimeImmutable = new \DateTimeImmutable($dtUtc->toDateTimeString(), new \DateTimeZone('UTC'));
                } elseif (! empty($eventTimeUtc)) {
                    try {
                        $eventTimeImmutable = new \DateTimeImmutable($eventTimeUtc, new \DateTimeZone('UTC'));
                    } catch (\Exception $e) {
                        $eventTimeImmutable = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    }
                } else {
                    $eventTimeImmutable = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                }

                $hash = CalendarEvent::makeHash((string) $title, (string) $targetCurrency, $eventTimeImmutable);

                $payload = [
                    'title' => $title,
                    'currency' => strtoupper((string) ($targetCurrency ?? '')),
                    'impact' => $normalizedImpact,
                    'event_time_utc' => $eventTimeUtc,
                    'source' => $src,
                    'hash' => $hash,
                ];

                $action = null;

                // collect for batch upsert
                $upsertRows[] = array_merge($payload, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $hashes[] = $hash;

                // dry-run counters will be computed after we know existing hashes
                $action = $dryRun ? 'would-create' : 'queued';

                $parsedRows[] = [
                    'line' => $ln + 2,
                    'title' => $title,
                    'currency' => $targetCurrency,
                    'impact' => $normalizedImpact,
                    'event_time_utc' => $eventTimeUtc,
                    'timezone_source' => $timezoneSource,
                    'source' => $source,
                    'hash' => $hash,
                    'action' => $action,
                ];
                // count timezone source
                if (! empty($timezoneSource) && array_key_exists($timezoneSource, $tzCounts)) {
                    $tzCounts[$timezoneSource]++;
                }
            }
        }

        // Determine existing hashes
        $existingHashes = [];
        if (! empty($hashes)) {
            try {
                $existingHashes = CalendarEvent::whereIn('hash', array_values(array_unique($hashes)))->pluck('hash')->all();
            } catch (\Throwable $e) {
                // ignore
                $existingHashes = [];
            }
        }

        // Reconcile rows whose hash does not match existing DB rows but whose tuple
        // (currency, title, event_time_utc) matches an existing record. This handles
        // cases where impact changed and the idempotency hash would differ.
        try {
            $existingMap = array_fill_keys($existingHashes, true);
            foreach ($upsertRows as $i => $r) {
                $h = $r['hash'] ?? null;
                if ($h && isset($existingMap[$h])) {
                    continue; // already matches existing
                }

                // attempt to find an existing event by tuple
                $candidate = $this->findExistingEventByTuple($r);
                if ($candidate instanceof CalendarEvent) {
                    $candidateHash = $candidate->hash;
                    if (! empty($candidateHash)) {
                        // update upsert row to use the existing hash so upsert will update
                        $upsertRows[$i]['hash'] = $candidateHash;
                        // also update corresponding parsedRows entries to reflect update
                        foreach ($parsedRows as $pi => $pr) {
                            if (($pr['hash'] ?? null) === $h && ($pr['currency'] ?? null) === ($r['currency'] ?? null) && ($pr['event_time_utc'] ?? null) === ($r['event_time_utc'] ?? null)) {
                                $parsedRows[$pi]['hash'] = $candidateHash;
                                $parsedRows[$pi]['action'] = $dryRun ? 'would-update' : 'queued';
                            }
                        }

                        // mark this hash as existing to avoid duplicate work
                        $existingMap[$candidateHash] = true;
                        $existingHashes[] = $candidateHash;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore reconciliation failures
        }

        // Compute dry-run counts
        if ($dryRun) {
            $wouldCreate = 0;
            $wouldUpdate = 0;
            foreach ($hashes as $h) {
                if (in_array($h, $existingHashes, true)) {
                    $wouldUpdate++;
                } else {
                    $wouldCreate++;
                }
            }
        } else {
            // Perform chunked upsert inside a transaction
            try {
                DB::transaction(function () use ($upsertRows) {
                    foreach (array_chunk($upsertRows, 500) as $chunk) {
                        CalendarEvent::upsert($chunk, ['hash'], ['title', 'currency', 'impact', 'event_time_utc', 'source', 'updated_at']);
                    }
                });

                // After upsert, compute created vs updated as best-effort using earlier existingHashes
                $total = count($upsertRows);
                $existingCount = count($existingHashes);
                // existingCount may include rows in DB that we updated; assume updated = existingMatches
                $updated = $existingCount;
                $created = max(0, $total - $existingCount);
            } catch (\Throwable $e) {
                $errors[] = ['line' => '?', 'error' => 'upsert failed: '.$e->getMessage()];
            }
        }

        return [
            'status' => 'imported',
            'rows' => $rowCount,
            'parsed' => $parsedCount,
            'errors' => $errors,
            'parsed_rows' => $parsedRows,
            'would_create' => $wouldCreate,
            'would_update' => $wouldUpdate,
            'created' => $created,
            'updated' => $updated,
            'by_timezone_source' => $tzCounts,
        ];
    }
}
