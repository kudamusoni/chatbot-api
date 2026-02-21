<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Client;
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
