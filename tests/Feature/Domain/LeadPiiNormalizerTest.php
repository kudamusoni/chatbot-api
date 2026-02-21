<?php

namespace Tests\Feature\Domain;

use App\Services\LeadPiiNormalizer;
use Tests\TestCase;

class LeadPiiNormalizerTest extends TestCase
{
    public function test_email_hash_uses_trim_lower_rules(): void
    {
        $normalizer = new LeadPiiNormalizer();

        $hashA = $normalizer->emailHash('  User@Example.COM ');
        $hashB = hash('sha256', 'user@example.com');

        $this->assertSame($hashB, $hashA);
    }

    public function test_phone_hash_uses_normalized_phone_value(): void
    {
        config()->set('leads.default_phone_region', 'GB');

        $normalizer = new LeadPiiNormalizer();
        $normalized = $normalizer->normalizePhone('07700 900123');
        $hash = $normalizer->phoneHash('07700 900123');

        $this->assertSame(hash('sha256', $normalized), $hash);
    }
}
