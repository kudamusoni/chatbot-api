<?php

namespace Tests\Feature\Http\App;

use App\Models\AppraisalQuestion;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppraisalQuestionsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_active_only_by_default_with_iso_z_timestamps(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        $active = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Who made it?',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'condition',
            'label' => 'Hidden',
            'input_type' => 'text',
            'required' => false,
            'order_index' => 2,
            'is_active' => false,
        ]);

        $response = $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/appraisal-questions')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $active->id);
        $response->assertJsonPath('data.0.question', 'Who made it?');
        $response->assertJsonPath('data.0.type', 'text');
        $response->assertJsonPath('data.0.is_required', true);

        $createdAt = (string) $response->json('data.0.created_at');
        $updatedAt = (string) $response->json('data.0.updated_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $createdAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $updatedAt);
    }

    public function test_include_inactive_returns_both_active_and_inactive(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'A',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'age',
            'label' => 'B',
            'input_type' => 'text',
            'required' => false,
            'order_index' => 2,
            'is_active' => false,
        ]);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/appraisal-questions?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_settings_prefixed_route_alias_returns_questions(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Who made it?',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->getJson('/app/settings/appraisal-questions?include_inactive=false')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_viewer_cannot_write_questions(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $viewer = User::factory()->create();
        $viewer->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($viewer, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/appraisal-questions', [
                'key' => 'maker',
                'question' => 'Brand?',
                'type' => 'text',
            ])
            ->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
                'reason_code' => 'INSUFFICIENT_ROLE',
            ]);
    }

    public function test_unknown_key_is_rejected(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/appraisal-questions', [
                'key' => 'brand',
                'question' => 'Brand?',
                'type' => 'text',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_admin_can_create_and_order_is_one_based_append(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Who made it?',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/appraisal-questions', [
                'key' => 'material',
                'question' => 'Material?',
                'type' => 'text',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.order_index', 2);

        $this->assertDatabaseHas('appraisal_questions', [
            'client_id' => $client->id,
            'key' => 'material',
            'order_index' => 2,
            'is_active' => true,
        ]);
    }

    public function test_update_ignores_unknown_keys_and_prompt_fields_update(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $question = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Old',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson("/app/appraisal-questions/{$question->id}", [
                'question' => 'New Label',
                'unknown_key' => 'ignore-me',
            ])
            ->assertOk()
            ->assertJsonPath('data.question', 'New Label');

        $question->refresh();
        $this->assertSame('New Label', $question->label);
    }

    public function test_key_is_immutable(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $question = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Label',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson("/app/appraisal-questions/{$question->id}", [
                'key' => 'new_key',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_delete_hard_deletes_non_mandatory_question(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $question = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'size',
            'label' => 'Label',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->deleteJson("/app/appraisal-questions/{$question->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('appraisal_questions', [
            'id' => $question->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_delete_rejects_mandatory_default_question(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $question = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Label',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->deleteJson("/app/appraisal-questions/{$question->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }

    public function test_reorder_compacts_active_only_and_inactive_unchanged(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $q1 = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Q1',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);
        $inactive = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'age',
            'label' => 'Q2',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 99,
            'is_active' => false,
        ]);
        $q3 = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'material',
            'label' => 'Q3',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/appraisal-questions/reorder', [
                'ordered_ids' => [$q3->id, $q1->id],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $q1->refresh();
        $q3->refresh();
        $inactive->refresh();

        $this->assertSame(2, (int) $q1->order_index);
        $this->assertSame(1, (int) $q3->order_index);
        $this->assertSame(99, (int) $inactive->order_index);
    }

    public function test_reorder_requires_exact_active_ids(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $admin = User::factory()->create();
        $admin->clients()->attach($client->id, ['role' => 'admin']);

        $q1 = AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'maker',
            'label' => 'Q1',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);
        AppraisalQuestion::create([
            'client_id' => $client->id,
            'key' => 'condition',
            'label' => 'Q2',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->putJson('/app/appraisal-questions/reorder', [
                'ordered_ids' => [$q1->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ordered_ids']);
    }

    public function test_cannot_mutate_other_clients_question(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $clientB = Client::create(['name' => 'Client B', 'slug' => 'client-b', 'settings' => []]);

        $adminA = User::factory()->create();
        $adminA->clients()->attach($clientA->id, ['role' => 'admin']);

        $otherQuestion = AppraisalQuestion::create([
            'client_id' => $clientB->id,
            'key' => 'size',
            'label' => 'Other',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($adminA, 'web')
            ->withSession(['active_client_id' => $clientA->id])
            ->putJson("/app/appraisal-questions/{$otherQuestion->id}", [
                'question' => 'Nope',
            ])
            ->assertStatus(404);
    }
}
