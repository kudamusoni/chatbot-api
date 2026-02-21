<?php

namespace Tests\Feature\Auth;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class GateBeforeTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_admin_allowlist_includes_export_abilities_only(): void
    {
        $user = User::factory()->create(['platform_role' => 'support_admin']);
        $lead = new Lead();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Lead::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $lead));
        $this->assertTrue(Gate::forUser($user)->allows('export', Lead::class));
        $this->assertTrue(Gate::forUser($user)->allows('export_readonly', Lead::class));

        $this->assertFalse(Gate::forUser($user)->allows('update', $lead));
    }

    public function test_super_admin_bypasses_all_abilities(): void
    {
        $user = User::factory()->create(['platform_role' => 'super_admin']);

        $this->assertTrue(Gate::forUser($user)->allows('totally_unknown_ability', Lead::class));
    }
}
