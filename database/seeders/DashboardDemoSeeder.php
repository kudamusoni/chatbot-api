<?php

namespace Database\Seeders;

use App\Enums\CatalogImportStatus;
use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Models\CatalogImport;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\User;
use App\Models\Valuation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DashboardDemoSeeder extends Seeder
{
    private const SEED_VERSION = 1;

    private ?Client $client = null;

    private bool $reset = false;

    public function forClient(Client $client, bool $reset): self
    {
        $this->client = $client;
        $this->reset = $reset;

        return $this;
    }

    public function run(): void
    {
        $client = $this->client;
        if (!$client) {
            return;
        }

        $settings = ClientSetting::forClientOrCreate($client->id);
        $urls = is_array($settings->urls) ? $settings->urls : [];

        if ($this->reset) {
            $this->resetClientData($client->id);
        } elseif (($urls['demo_seed_version'] ?? null) === self::SEED_VERSION) {
            // Locked behavior: without --reset seeding is idempotent.
            return;
        }

        $owner = User::firstOrCreate(
            ['email' => 'owner+' . $client->slug . '@example.com'],
            ['name' => 'Owner ' . $client->name, 'password' => bcrypt('password'), 'platform_role' => 'none']
        );
        $viewer = User::firstOrCreate(
            ['email' => 'viewer+' . $client->slug . '@example.com'],
            ['name' => 'Viewer ' . $client->name, 'password' => bcrypt('password'), 'platform_role' => 'none']
        );
        $support = User::firstOrCreate(
            ['email' => 'support+' . $client->slug . '@example.com'],
            ['name' => 'Support ' . $client->name, 'password' => bcrypt('password'), 'platform_role' => 'support_admin']
        );

        $client->users()->syncWithoutDetaching([
            $owner->id => ['role' => 'owner'],
            $viewer->id => ['role' => 'viewer'],
        ]);

        $now = CarbonImmutable::now('UTC');

        for ($i = 1; $i <= 12; $i++) {
            [$conversation] = Conversation::createWithToken($client->id, [
                'state' => $i % 4 === 0 ? ConversationState::VALUATION_READY : ConversationState::CHAT,
            ]);

            $createdAt = $now->subDays($i)->setTime(10, 0);
            $lastActivity = $createdAt->addHours(($i % 5) + 1);

            DB::table('conversations')->where('id', $conversation->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $lastActivity,
                'last_activity_at' => $lastActivity,
            ]);

            $userEvent = ConversationEvent::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'type' => ConversationEventType::USER_MESSAGE_CREATED,
                'payload' => ['content' => 'Demo user message ' . $i],
            ]);
            $assistantEvent = ConversationEvent::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'type' => ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                'payload' => ['content' => 'Demo assistant reply ' . $i],
            ]);

            DB::table('conversation_events')->whereIn('id', [$userEvent->id, $assistantEvent->id])->update([
                'created_at' => $lastActivity,
            ]);

            ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'event_id' => $userEvent->id,
                'role' => 'user',
                'content' => 'Demo user message ' . $i,
            ]);
            ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'event_id' => $assistantEvent->id,
                'role' => 'assistant',
                'content' => 'Demo assistant reply ' . $i,
            ]);

            DB::table('conversation_messages')->where('conversation_id', $conversation->id)->update([
                'created_at' => $lastActivity,
            ]);

            $valuationStatus = match ($i % 3) {
                0 => ValuationStatus::FAILED,
                1 => ValuationStatus::COMPLETED,
                default => ValuationStatus::RUNNING,
            };

            $valuation = Valuation::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'status' => $valuationStatus,
                'snapshot_hash' => hash('sha256', 'demo-' . $conversation->id),
                'input_snapshot' => ['maker' => 'Demo Maker', 'currency' => $i % 2 === 0 ? 'GBP' : 'USD'],
                'result' => [
                    'count' => 5 + $i,
                    'median' => 10000 + ($i * 500),
                    'range' => ['low' => 8000 + ($i * 300), 'high' => 15000 + ($i * 700)],
                    'confidence' => $i % 4,
                    'signals_used' => ['sold' => 1 + ($i % 3), 'asking' => 3 + ($i % 4), 'estimates' => 0],
                ],
            ]);

            DB::table('valuations')->where('id', $valuation->id)->update([
                'created_at' => $lastActivity,
                'updated_at' => $lastActivity,
            ]);

            $leadStatus = ['REQUESTED', 'CONTACTED', 'QUALIFIED', 'WON', 'LOST'][$i % 5];
            $lead = Lead::create([
                'conversation_id' => $conversation->id,
                'client_id' => $client->id,
                'name' => 'Demo Lead ' . $i,
                'email' => 'lead' . $i . '@example.com',
                'email_hash' => hash('sha256', strtolower(trim('lead' . $i . '@example.com'))),
                'phone_raw' => '+447700900' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'phone_normalized' => '+447700900' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'phone_hash' => hash('sha256', '+447700900' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)),
                'status' => $leadStatus,
                'notes' => $i % 2 === 0 ? 'Follow-up scheduled' : null,
                'updated_by' => $owner->id,
            ]);

            DB::table('leads')->where('id', $lead->id)->update([
                'created_at' => $lastActivity,
                'updated_at' => $lastActivity,
            ]);
        }

        $completedImport = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $owner->id,
            'status' => CatalogImportStatus::COMPLETED,
            'attempt' => 1,
            'file_path' => 'catalog-imports/' . $client->id . '/demo-completed.csv',
            'totals' => ['processed' => 100, 'inserted' => 95, 'updated' => 5, 'invalid' => 0],
            'errors_count' => 0,
        ]);

        $failedImport = CatalogImport::create([
            'client_id' => $client->id,
            'created_by' => $owner->id,
            'status' => CatalogImportStatus::FAILED,
            'attempt' => 2,
            'file_path' => 'catalog-imports/' . $client->id . '/demo-failed.csv',
            'totals' => ['processed' => 20, 'inserted' => 12, 'updated' => 3, 'invalid' => 5],
            'errors_count' => 5,
            'errors_sample' => [
                ['row_number' => 3, 'column' => 'price', 'message' => 'Invalid price'],
            ],
        ]);

        DB::table('catalog_import_errors')->insert([
            [
                'import_id' => $failedImport->id,
                'row_number' => 3,
                'column' => 'price',
                'message' => 'Invalid price',
                'raw' => json_encode(['price' => 'bad']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'import_id' => $failedImport->id,
                'row_number' => 7,
                'column' => 'currency',
                'message' => 'Invalid currency',
                'raw' => json_encode(['currency' => 'XX']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $settings->bot_name = 'Demo Assistant';
        $settings->allowed_origins = ['https://demo.example.com'];
        $settings->urls = array_merge($urls, ['demo_seed_version' => self::SEED_VERSION]);
        $settings->widget_security_version = max(1, (int) $settings->widget_security_version);
        $settings->save();

        // Keep support user reachable in demo runs while preserving lock decisions.
        $support->refresh();
    }

    private function resetClientData(string $clientId): void
    {
        $importIds = CatalogImport::query()->where('client_id', $clientId)->pluck('id');

        if ($importIds->isNotEmpty()) {
            DB::table('catalog_import_errors')->whereIn('import_id', $importIds)->delete();
        }

        CatalogImport::query()->where('client_id', $clientId)->delete();
        Lead::query()->where('client_id', $clientId)->delete();
        Valuation::query()->where('client_id', $clientId)->delete();
        ConversationMessage::query()->where('client_id', $clientId)->delete();
        ConversationEvent::query()->where('client_id', $clientId)->delete();
        Conversation::query()->where('client_id', $clientId)->delete();
    }
}
