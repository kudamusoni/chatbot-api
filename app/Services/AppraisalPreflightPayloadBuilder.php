<?php

namespace App\Services;

class AppraisalPreflightPayloadBuilder
{
    /**
     * @param array<string, mixed> $preflight
     * @return array{
     *   missing_fields: list<string>,
     *   low_confidence_fields: list<array{key:string,confidence:float,candidates:list<string>,normalized:mixed}>,
     *   next_question_key: ?string,
     *   message: string,
     *   preflight_status: string,
     *   preflight_details: array<string,mixed>
     * }
     */
    public function failedPayload(array $preflight): array
    {
        $message = trim((string) ($preflight['message'] ?? ''));
        if ($message === '') {
            $message = 'Before I can value it, I need one more required detail.';
        }

        return [
            'missing_fields' => is_array($preflight['missing_fields'] ?? null) ? array_values($preflight['missing_fields']) : [],
            'low_confidence_fields' => is_array($preflight['low_confidence_fields'] ?? null) ? array_values($preflight['low_confidence_fields']) : [],
            'next_question_key' => is_string($preflight['next_question_key'] ?? null) ? $preflight['next_question_key'] : null,
            'message' => $message,
            'preflight_status' => 'FAILED',
            'preflight_details' => is_array($preflight['preflight_details'] ?? null) ? $preflight['preflight_details'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $preflight
     * @return array{
     *   preflight_status: string,
     *   confidence_cap: ?float,
     *   low_confidence_fields: list<array{key:string,confidence:float,candidates:list<string>,normalized:mixed}>,
     *   preflight_details: array<string,mixed>
     * }
     */
    public function passedPayload(array $preflight): array
    {
        $cap = $preflight['confidence_cap'] ?? null;

        return [
            'preflight_status' => (string) ($preflight['preflight_status'] ?? 'PASSED'),
            'confidence_cap' => is_numeric($cap) ? (float) $cap : null,
            'low_confidence_fields' => is_array($preflight['low_confidence_fields'] ?? null) ? array_values($preflight['low_confidence_fields']) : [],
            'preflight_details' => is_array($preflight['preflight_details'] ?? null) ? $preflight['preflight_details'] : [],
        ];
    }

    /**
     * @return array{ok: true, blocked: true, reason_code: 'PREFLIGHT_FAILED', last_event_id: int}
     */
    public function blockedResponse(int $lastEventId): array
    {
        return [
            'ok' => true,
            'blocked' => true,
            'reason_code' => 'PREFLIGHT_FAILED',
            'last_event_id' => $lastEventId,
        ];
    }
}
