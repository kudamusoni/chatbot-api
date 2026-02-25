<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use App\Models\Client;
use App\Models\CatalogImport;
use App\Enums\CatalogImportStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:seed-dashboard {--client=Acme Auctions} {--reset}', function () {
    $clientName = trim((string) $this->option('client'));
    $reset = (bool) $this->option('reset');

    if ($reset && app()->environment('production')) {
        $this->error('--reset is blocked in production.');

        return 1;
    }

    $slug = Str::slug($clientName);
    $client = Client::firstOrCreate(
        ['slug' => $slug],
        ['name' => $clientName, 'settings' => []],
    );

    (new DashboardDemoSeeder())
        ->forClient($client, $reset)
        ->run();

    $this->info('Dashboard demo seed complete for client: ' . $client->name);
    $this->line('Client ID: ' . $client->id);
    $this->line('Reset mode: ' . ($reset ? 'yes' : 'no'));

    return 0;
})->purpose('Seed dashboard demo data for a client (client-scoped reset only).');

Artisan::command('catalog-imports:cleanup-abandoned {--hours=24} {--dry-run} {--force}', function () {
    $hours = max(1, (int) $this->option('hours'));
    $dryRun = (bool) $this->option('dry-run');
    $force = (bool) $this->option('force');

    if (app()->environment('production') && !$force) {
        $this->error('Refusing to run in production without --force.');

        return 1;
    }

    $cutoff = CarbonImmutable::now('UTC')->subHours($hours);
    $statuses = [
        CatalogImportStatus::CREATED->value,
        CatalogImportStatus::UPLOADED->value,
        CatalogImportStatus::VALIDATED->value,
    ];

    $candidates = CatalogImport::query()
        ->whereIn('status', $statuses)
        ->whereNull('started_at')
        ->whereNull('finished_at')
        ->where('updated_at', '<', $cutoff)
        ->get(['id', 'file_path', 'status', 'updated_at']);

    if ($candidates->isEmpty()) {
        $this->info('No abandoned catalog imports found.');

        return 0;
    }

    $this->line('Found ' . $candidates->count() . ' abandoned imports older than ' . $hours . 'h.');

    if ($dryRun) {
        foreach ($candidates as $import) {
            $this->line("- {$import->id} [{$import->status->value}] updated_at={$import->updated_at}");
        }

        $this->info('Dry run complete. No rows deleted.');

        return 0;
    }

    $disk = (string) config('catalog.import_disk', 'local');
    $deleted = 0;
    foreach ($candidates as $import) {
        if (is_string($import->file_path) && $import->file_path !== '' && Storage::disk($disk)->exists($import->file_path)) {
            Storage::disk($disk)->delete($import->file_path);
        }

        $import->delete();
        $deleted++;
    }

    $this->info("Deleted {$deleted} abandoned imports.");

    return 0;
})->purpose('Delete stale non-terminal catalog imports (created/uploaded/validated).');
