<?php

namespace Tests\Feature\Http\App;

use Tests\TestCase;

class SpaConfigSmokeTest extends TestCase
{
    public function test_sanctum_spa_config_has_stateful_domains_and_web_guard(): void
    {
        $stateful = config('sanctum.stateful', []);
        $guards = config('sanctum.guard', []);

        $this->assertIsArray($stateful);
        $this->assertNotEmpty($stateful);
        $this->assertContains('web', $guards);
    }
}
