<?php

namespace Tests\Unit;

use App\Contracts\AiProvider;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiJsonResult;
use App\Services\Ai\AiResult;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AiClientTest extends TestCase
{
    public function test_chat_sanitizes_and_caps_output(): void
    {
        Config::set('ai.assistant_max_chars', 20);

        $provider = new class implements AiProvider {
            public function chat(array $messages, array $options = []): AiResult
            {
                return new AiResult("Hello\t\tworld\n\nthis is long", 10, 3, null);
            }

            public function json(array $messages, array $schema, array $options = []): AiJsonResult
            {
                return new AiJsonResult(['ok' => true], 10, 2, null);
            }
        };

        $client = new AiClient($provider);
        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello world this is', $result->content);
    }

    public function test_chat_uses_fallback_when_sanitized_output_empty(): void
    {
        Config::set('ai.fallback.assistant_message', 'fallback');

        $provider = new class implements AiProvider {
            public function chat(array $messages, array $options = []): AiResult
            {
                return new AiResult("\x01\x02", null, null, null);
            }

            public function json(array $messages, array $schema, array $options = []): AiJsonResult
            {
                return new AiJsonResult(['ok' => true], 1, 1, null);
            }
        };

        $client = new AiClient($provider);
        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('fallback', $result->content);
    }

    public function test_json_requires_schema_keys(): void
    {
        $provider = new class implements AiProvider {
            public function chat(array $messages, array $options = []): AiResult
            {
                return new AiResult('ok');
            }

            public function json(array $messages, array $schema, array $options = []): AiJsonResult
            {
                return new AiJsonResult(['normalized' => 'x']);
            }
        };

        $client = new AiClient($provider);

        $this->expectException(\RuntimeException::class);
        $client->json([['role' => 'user', 'content' => 'x']], [
            'normalized' => null,
            'confidence' => 0.0,
        ]);
    }
}
