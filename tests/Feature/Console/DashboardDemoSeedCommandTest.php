<?php

namespace Tests\Feature\Console;

use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDemoSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_command_is_idempotent_without_reset(): void
    {
        $this->artisan('demo:seed-dashboard', ['--client' => 'Acme Auctions'])
            ->assertExitCode(0);

        $client = Client::where('slug', 'acme-auctions')->firstOrFail();
        $firstCounts = [
            'conversations' => Conversation::where('client_id', $client->id)->count(),
            'valuations' => Valuation::where('client_id', $client->id)->count(),
            'leads' => Lead::where('client_id', $client->id)->count(),
            'imports' => CatalogImport::where('client_id', $client->id)->count(),
        ];

        $this->artisan('demo:seed-dashboard', ['--client' => 'Acme Auctions'])
            ->assertExitCode(0);

        $secondCounts = [
            'conversations' => Conversation::where('client_id', $client->id)->count(),
            'valuations' => Valuation::where('client_id', $client->id)->count(),
            'leads' => Lead::where('client_id', $client->id)->count(),
            'imports' => CatalogImport::where('client_id', $client->id)->count(),
        ];

        $this->assertSame($firstCounts, $secondCounts);
    }

    public function test_reset_clears_only_target_client_data_before_reseed(): void
    {
        $this->artisan('demo:seed-dashboard', ['--client' => 'Client One'])
            ->assertExitCode(0);
        $this->artisan('demo:seed-dashboard', ['--client' => 'Client Two'])
            ->assertExitCode(0);

        $clientOne = Client::where('slug', 'client-one')->firstOrFail();
        $clientTwo = Client::where('slug', 'client-two')->firstOrFail();

        $twoBefore = Conversation::where('client_id', $clientTwo->id)->count();

        $this->artisan('demo:seed-dashboard', ['--client' => 'Client One', '--reset' => true])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Conversation::where('client_id', $clientOne->id)->count());
        $this->assertSame($twoBefore, Conversation::where('client_id', $clientTwo->id)->count());
    }
}
