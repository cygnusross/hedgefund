<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$seqRow = DB::select("SELECT pg_get_serial_sequence('calendar_events','id') as seq");
$seq = $seqRow[0]->seq ?? null;
if (! $seq) {
    echo "Could not determine sequence name\n";
    exit(1);
}
echo "sequence name: $seq\n";
DB::statement("SELECT setval('$seq', (SELECT COALESCE(MAX(id),0) FROM calendar_events));");
echo "sequence synced to MAX(id)\n";
