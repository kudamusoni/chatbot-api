<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\AppraisalQuestion;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\Valuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HistoryController extends Controller
{
    /**
     * Default message limit.
     */
    private const DEFAULT_LIMIT = 200;

    /**
     * Maximum message limit.
     */
    private const MAX_LIMIT = 500;

    /**
     * Get conversation history for fast initial load.
     *
     * GET /api/widget/history
     *
     * This endpoint provides immediate transcript render via HTTP,
     * then the widget can connect to SSE with after_id=last_event_id for live updates.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid',
            'session_token' => 'required|string|size:64',
            'limit' => 'sometimes|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $clientId = $request->query('client_id');
        $sessionToken = $request->query('session_token');
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);

        // Find conversation by token + client (tenant-scoped)
        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        // Get messages ordered by event_id
        $messages = ConversationMessage::where('conversation_id', $conversation->id)
            ->orderBy('event_id', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($msg) => [
                'event_id' => $msg->event_id,
                'role' => $msg->role,
                'content' => $msg->content,
                'created_at' => $msg->created_at->toIso8601String(),
            ]);

        // Get latest valuation if exists
        $valuation = Valuation::where('conversation_id', $conversation->id)
            ->orderByDesc('updated_at')
            ->first();

        $currentQuestion = null;
        if ($conversation->state->value === 'APPRAISAL_INTAKE' && is_string($conversation->appraisal_current_key) && $conversation->appraisal_current_key !== '') {
            $question = AppraisalQuestion::query()
                ->where('client_id', $conversation->client_id)
                ->where('key', $conversation->appraisal_current_key)
                ->first();

            if ($question) {
                $currentQuestion = [
                    'key' => $question->key,
                    'question' => $question->label,
                    'type' => $question->input_type,
                    'is_required' => (bool) $question->required,
                    'help_text' => $question->help_text,
                    'examples' => is_array($question->options) ? $question->options : null,
                ];
            }
        }

        $lastPreflight = null;
        $preflightMeta = is_array($conversation->normalization_meta)
            && is_array($conversation->normalization_meta['__preflight'] ?? null)
            ? $conversation->normalization_meta['__preflight']
            : [];

        $latestPreflightEvent = ConversationEvent::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('type', [
                'appraisal.preflight.failed',
                'appraisal.preflight.passed',
            ])
            ->latest('id')
            ->first();

        if ($latestPreflightEvent || $preflightMeta !== []) {
            $payload = is_array($latestPreflightEvent?->payload) ? $latestPreflightEvent->payload : [];
            $detailsFromMeta = is_array($preflightMeta['details'] ?? null) ? $preflightMeta['details'] : [];

            $lastPreflight = [
                'status' => $payload['preflight_status']
                    ?? $preflightMeta['status']
                    ?? null,
                'missing_fields' => is_array($payload['missing_fields'] ?? null)
                    ? array_values($payload['missing_fields'])
                    : (is_array($preflightMeta['missing_fields'] ?? null) ? array_values($preflightMeta['missing_fields']) : []),
                'low_confidence_fields' => is_array($payload['low_confidence_fields'] ?? null)
                    ? array_values($payload['low_confidence_fields'])
                    : (is_array($preflightMeta['low_confidence_fields'] ?? null) ? array_values($preflightMeta['low_confidence_fields']) : []),
                'confidence_cap' => isset($payload['confidence_cap'])
                    ? (float) $payload['confidence_cap']
                    : (isset($preflightMeta['confidence_cap']) ? (float) $preflightMeta['confidence_cap'] : null),
                'preflight_details' => is_array($payload['preflight_details'] ?? null)
                    ? $payload['preflight_details']
                    : $detailsFromMeta,
            ];
        }

        $contact = $this->valuationContactPrefill($conversation);
        $context = is_array($conversation->context) ? $conversation->context : [];
        $pendingIntent = $context['pending_intent'] ?? null;
        $pendingIntent = is_string($pendingIntent) && trim($pendingIntent) !== ''
            ? trim($pendingIntent)
            : null;

        return response()->json([
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id ?? 0,
            'messages' => $messages,
            // State + raw projection fields — frontend derives panel type
            'state' => $conversation->state->value,
            'appraisal_current_key' => $conversation->appraisal_current_key,
            'appraisal_snapshot' => $conversation->appraisal_snapshot,
            'lead_current_key' => $conversation->lead_current_key,
            'lead_answers' => $conversation->lead_answers,
            'lead_identity_candidate' => $conversation->lead_identity_candidate,
            'lead_id' => $contact['lead_id'],
            'valuation_contact_required' => $conversation->state->value === 'VALUATION_CONTACT_CAPTURE',
            'valuation_contact_prefill' => $contact['valuation_contact_prefill'],
            'pending_intent' => $pendingIntent,
            'current_question' => $currentQuestion,
            'last_preflight' => $lastPreflight,
            'valuation' => $valuation ? [
                'status' => $valuation->status->value,
                'result' => $valuation->result,
            ] : null,
        ]);
    }

    /**
     * @return array{lead_id:?string, valuation_contact_prefill:?array{name:?string,email:?string,phone:?string}}
     */
    private function valuationContactPrefill(Conversation $conversation): array
    {
        $lead = null;
        $capturedLeadId = is_string($conversation->valuation_contact_lead_id)
            ? trim($conversation->valuation_contact_lead_id)
            : '';

        if ($capturedLeadId !== '') {
            $lead = Lead::query()
                ->where('id', $capturedLeadId)
                ->where('client_id', $conversation->client_id)
                ->first();
        }

        if (!$lead) {
            $lead = Lead::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_id', $conversation->client_id)
                ->latest('created_at')
                ->first();
        }

        if (!$lead) {
            return [
                'lead_id' => null,
                'valuation_contact_prefill' => null,
            ];
        }

        $email = is_string($lead->email) && trim($lead->email) !== ''
            ? trim($lead->email)
            : null;
        $name = is_string($lead->name) && trim($lead->name) !== ''
            ? trim($lead->name)
            : null;
        $phone = is_string($lead->phone_normalized) && trim($lead->phone_normalized) !== ''
            ? trim($lead->phone_normalized)
            : null;

        return [
            'lead_id' => (string) $lead->id,
            'valuation_contact_prefill' => [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
            ],
        ];
    }
}
