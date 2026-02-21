<?php

namespace Tests\Feature\Http\Widget;

use App\Models\Client;
use App\Models\ClientSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OriginSettingsSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_origin_uses_client_settings_as_primary_source(): void
    {
        config()->set('widget.security.bypass_local_origin_checks', false);

        $client = Client::create([
            'name' => 'Client',
            'slug' => 'client',
            'settings' => [
                'allowed_origins' => ['https://legacy.example.com'],
                'widget_security_version' => 1,
            ],
        ]);

        ClientSetting::create([
            'client_id' => $client->id,
            'allowed_origins' => ['https://primary.example.com'],
            'widget_security_version' => 2,
        ]);

        $this->withHeader('Origin', 'https://primary.example.com')
            ->postJson('/api/widget/bootstrap', ['client_id' => $client->id])
            ->assertOk();
    }
}
