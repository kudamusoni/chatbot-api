<?php

namespace Tests\Feature\Http\App;

use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppMicroLocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_me_returns_boot_permissions_context(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'admin']);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/auth/me')
            ->assertOk();

        $response->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'platform_role'],
            'active_client_id',
            'active_client' => ['id', 'name'],
            'tenant_role',
            'permissions' => [
                'can_manage_settings',
                'can_manage_questions',
                'can_export_leads',
                'can_manage_imports',
            ],
        ]);

        $response->assertJsonPath('active_client_id', $client->id);
        $response->assertJsonPath('active_client.id', $client->id);
        $response->assertJsonPath('tenant_role', 'admin');
        $response->assertJsonPath('permissions.can_manage_settings', true);
        $response->assertJsonPath('permissions.can_manage_questions', true);
        $response->assertJsonPath('permissions.can_export_leads', true);
        $response->assertJsonPath('permissions.can_manage_imports', true);

        $permissions = $response->json('permissions');
        $this->assertIsBool($permissions['can_manage_settings']);
        $this->assertIsBool($permissions['can_manage_questions']);
        $this->assertIsBool($permissions['can_export_leads']);
        $this->assertIsBool($permissions['can_manage_imports']);
    }

    public function test_leads_list_uses_default_per_page_sort_and_utc_z_timestamps(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        [$conversation] = Conversation::createWithToken($client->id);

        for ($i = 0; $i < 25; $i++) {
            $lead = Lead::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'name' => "Lead {$i}",
                'email' => "lead{$i}@example.com",
                'phone_raw' => '+441234567890',
                'phone_normalized' => '+441234567890',
                'status' => 'NEW',
            ]);

            DB::table('leads')
                ->where('id', $lead->id)
                ->update([
                    'created_at' => Carbon::parse("2026-02-01 00:00:00")->addMinutes($i),
                    'updated_at' => Carbon::parse("2026-02-01 00:00:00")->addMinutes($i),
                ]);
        }

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads')
            ->assertOk();

        $this->assertSame(20, $response->json('per_page'));
        $this->assertCount(20, $response->json('data'));
        $this->assertSame('created_at:desc', $response->json('meta.default_sort'));

        $first = $response->json('data.0.created_at');
        $second = $response->json('data.1.created_at');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertGreaterThan($second, $first);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $first);
    }

    public function test_leads_list_supports_page_per_page_and_ignores_unsupported_sort(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);
        [$conversation] = Conversation::createWithToken($client->id);

        for ($i = 0; $i < 12; $i++) {
            $lead = Lead::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'name' => "Lead {$i}",
                'email' => "lead{$i}@example.com",
                'phone_raw' => '+441234567890',
                'phone_normalized' => '+441234567890',
                'status' => 'NEW',
            ]);
            DB::table('leads')
                ->where('id', $lead->id)
                ->update([
                    'created_at' => Carbon::parse('2026-02-01 00:00:00')->addMinutes($i),
                    'updated_at' => Carbon::parse('2026-02-01 00:00:00')->addMinutes($i),
                ]);
        }

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads?per_page=5&page=2&sort=name.asc')
            ->assertOk();

        $this->assertSame(5, $response->json('per_page'));
        $this->assertSame(2, $response->json('current_page'));
        $this->assertCount(5, $response->json('data'));
        $this->assertSame('created_at:desc', $response->json('meta.default_sort'));

        // Default created_at desc should remain in effect even with unsupported sort param.
        $this->assertSame('Lead 6', $response->json('data.0.name'));
    }

    public function test_catalog_imports_list_uses_default_per_page_and_desc_sort(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        for ($i = 0; $i < 25; $i++) {
            $import = CatalogImport::create([
                'client_id' => $client->id,
                'created_by' => $user->id,
                'status' => 'CREATED',
                'attempt' => 1,
            ]);

            DB::table('catalog_imports')
                ->where('id', $import->id)
                ->update([
                    'created_at' => Carbon::parse('2026-02-01 00:00:00')->addMinutes($i),
                    'updated_at' => Carbon::parse('2026-02-01 00:00:00')->addMinutes($i),
                ]);
        }

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/catalog-imports')
            ->assertOk();

        $this->assertSame(20, $response->json('per_page'));
        $this->assertCount(20, $response->json('data'));
        $this->assertSame('created_at:desc', $response->json('meta.default_sort'));

        $first = (string) $response->json('data.0.created_at');
        $second = (string) $response->json('data.1.created_at');
        $this->assertGreaterThan($second, $first);
        $this->assertStringEndsWith('Z', $first);
    }

    public function test_catalog_imports_list_supports_page_per_page_and_ignores_unsupported_sort(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        for ($i = 0; $i < 12; $i++) {
            $import = CatalogImport::create([
                'client_id' => $client->id,
                'created_by' => $user->id,
                'status' => 'CREATED',
                'attempt' => 1,
            ]);

            DB::table('catalog_imports')
                ->where('id', $import->id)
                ->update([
                    'created_at' => Carbon::parse('2026-02-01 00:00:00')->addMinutes($i),
                    'updated_at' => Carbon::parse('2026-02-01 00:00:00')->addMinutes($i),
                ]);
        }

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/catalog-imports?per_page=5&page=2&sort=status.asc')
            ->assertOk();

        $this->assertSame(5, $response->json('per_page'));
        $this->assertSame(2, $response->json('current_page'));
        $this->assertCount(5, $response->json('data'));
        $this->assertSame('created_at:desc', $response->json('meta.default_sort'));
    }

    public function test_leads_list_applies_status_and_range_filters(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);
        [$conversation] = Conversation::createWithToken($client->id);

        $recentRequested = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Recent Requested',
            'email' => 'recent-requested@example.com',
            'phone_raw' => '+441234567890',
            'phone_normalized' => '+441234567890',
            'status' => 'REQUESTED',
        ]);

        $oldRequested = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Old Requested',
            'email' => 'old-requested@example.com',
            'phone_raw' => '+441234567890',
            'phone_normalized' => '+441234567890',
            'status' => 'REQUESTED',
        ]);

        $recentContacted = Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Recent Contacted',
            'email' => 'recent-contacted@example.com',
            'phone_raw' => '+441234567890',
            'phone_normalized' => '+441234567890',
            'status' => 'CONTACTED',
        ]);

        DB::table('leads')->where('id', $recentRequested->id)->update([
            'created_at' => Carbon::now('UTC')->subDays(2),
            'updated_at' => Carbon::now('UTC')->subDays(2),
        ]);
        DB::table('leads')->where('id', $oldRequested->id)->update([
            'created_at' => Carbon::now('UTC')->subDays(45),
            'updated_at' => Carbon::now('UTC')->subDays(45),
        ]);
        DB::table('leads')->where('id', $recentContacted->id)->update([
            'created_at' => Carbon::now('UTC')->subDays(1),
            'updated_at' => Carbon::now('UTC')->subDays(1),
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads?page=1&per_page=20&status=REQUESTED&range=30d')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Recent Requested', $data[0]['name']);
        $this->assertSame('REQUESTED', $data[0]['status']);
    }

    public function test_leads_list_applies_q_search_filter(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);
        [$conversation] = Conversation::createWithToken($client->id);

        Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Lead 9',
            'email' => 'lead9@example.com',
            'phone_raw' => '+441234567890',
            'phone_normalized' => '+441234567890',
            'status' => 'REQUESTED',
            'notes' => 'Important note',
        ]);

        Lead::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'name' => 'Another Lead',
            'email' => 'lead10@example.com',
            'phone_raw' => '+441234567890',
            'phone_normalized' => '+441234567890',
            'status' => 'CONTACTED',
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/leads?page=1&per_page=20&q=Lead+9&range=all')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Lead 9', $data[0]['name']);
    }

    public function test_catalog_imports_list_applies_range_filter(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        $todayImport = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'attempt' => 1,
        ]);

        $oldImport = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'CREATED',
            'attempt' => 1,
        ]);

        DB::table('catalog_imports')->where('id', $todayImport->id)->update([
            'created_at' => Carbon::now('UTC')->startOfDay(),
            'updated_at' => Carbon::now('UTC')->startOfDay(),
        ]);
        DB::table('catalog_imports')->where('id', $oldImport->id)->update([
            'created_at' => Carbon::now('UTC')->subDays(2),
            'updated_at' => Carbon::now('UTC')->subDays(2),
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/catalog-imports?page=1&per_page=20&range=today')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($todayImport->id, $data[0]['id']);
    }

    public function test_catalog_imports_list_applies_status_and_range_filters(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($client->id, ['role' => 'viewer']);

        $todayRunning = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'RUNNING',
            'attempt' => 1,
        ]);

        $todayCompleted = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'COMPLETED',
            'attempt' => 1,
        ]);

        $oldRunning = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'status' => 'RUNNING',
            'attempt' => 1,
        ]);

        DB::table('catalog_imports')->where('id', $todayRunning->id)->update([
            'created_at' => Carbon::now('UTC')->startOfDay(),
            'updated_at' => Carbon::now('UTC')->startOfDay(),
        ]);
        DB::table('catalog_imports')->where('id', $todayCompleted->id)->update([
            'created_at' => Carbon::now('UTC')->startOfDay(),
            'updated_at' => Carbon::now('UTC')->startOfDay(),
        ]);
        DB::table('catalog_imports')->where('id', $oldRunning->id)->update([
            'created_at' => Carbon::now('UTC')->subDays(3),
            'updated_at' => Carbon::now('UTC')->subDays(3),
        ]);

        $response = $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/catalog-imports?page=1&per_page=20&status=RUNNING&range=today')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($todayRunning->id, $data[0]['id']);
        $this->assertSame('RUNNING', $data[0]['status']);
    }
}
