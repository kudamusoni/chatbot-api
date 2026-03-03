<?php

namespace App\Services;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\AppraisalQuestion;
use App\Models\ClientSetting;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Lead;
use App\Services\LeadPiiNormalizer;

class ConversationOrchestrator
{
    private LeadPiiNormalizer $leadPiiNormalizer;
    private WidgetIntroMessageResolver $introMessageResolver;

    private const LEAD_IDENTITY_PROMPT = 'I found your previous contact details. Is this you?';

    private const LEAD_PROMPT_BY_KEY = [
        'name' => 'Great, please share your full name.',
        'email' => 'Thanks. What is the best email for our expert to contact you?',
        'phone' => 'Finally, what phone number should we use?',
    ];

    private const LEAD_INVALID_PROMPT_BY_KEY = [
        'name' => 'Please share your full name so we can submit the lead request.',
        'email' => 'That email does not look valid. Please provide a valid email address.',
        'phone' => 'That phone number looks invalid. Please provide a valid phone number including area code.',
    ];

    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        ?LeadPiiNormalizer $leadPiiNormalizer = null,
        ?WidgetIntroMessageResolver $introMessageResolver = null
    ) {
        $this->leadPiiNormalizer = $leadPiiNormalizer ?? new LeadPiiNormalizer();
        $this->introMessageResolver = $introMessageResolver ?? new WidgetIntroMessageResolver();
    }

    /**
     * Shared assistant message sequence after a lead request is captured.
     *
     * @return array<int, string>
     */
    public static function leadSubmissionAssistantMessages(): array
    {
        return [
            'Thanks. Your lead request has been submitted. Our team will contact you soon.',
            'Do you have any other questions?',
        ];
    }

    public static function leadPromptForQuestion(string $questionKey): ?string
    {
        return self::LEAD_PROMPT_BY_KEY[$questionKey] ?? null;
    }

    /**
     * Handle a user message event and emit follow-up events.
     */
    public function handleUserMessage(Conversation $conversation, ConversationEvent $userMessageEvent): void
    {
        $text = trim((string) ($userMessageEvent->payload['content'] ?? ''));

        if ($conversation->state === ConversationState::VALUATION_RUNNING) {
            $this->recordAssistantMessage(
                $conversation,
                'Your valuation is running. I can answer after it finishes.',
                $userMessageEvent,
                suffix: 'valuation.running'
            );

            return;
        }

        if ($conversation->state === ConversationState::LEAD_IDENTITY_CONFIRM) {
            $this->recordAssistantMessage(
                $conversation,
                'Please use the Yes or No buttons to confirm your contact details.',
                $userMessageEvent,
                suffix: 'lead.identity.awaiting_decision'
            );

            return;
        }

        if ($conversation->state === ConversationState::VALUATION_CONTACT_CAPTURE) {
            $this->recordAssistantMessage(
                $conversation,
                'Please share your email in the contact step so I can run your valuation.',
                $userMessageEvent,
                suffix: 'valuation.contact.capture'
            );

            return;
        }

        if ($conversation->state === ConversationState::LEAD_INTAKE) {
            $this->handleLeadAnswer($conversation, $userMessageEvent, $text);

            return;
        }

        if ($this->shouldStartLead($text)) {
            if ($conversation->state === ConversationState::VALUATION_READY) {
                $linkedLead = $this->findLinkedValuationContactLead($conversation);
                if ($linkedLead) {
                    $this->submitExpertReviewRequest($conversation, $userMessageEvent, $linkedLead);

                    return;
                }

                $prefill = $this->findLatestLeadForConversation($conversation);
                $this->requestValuationContactForExpertReview($conversation, $userMessageEvent, $prefill);

                return;
            }

            $this->recordAssistantMessage(
                $conversation,
                'Lead capture is available after your valuation result is ready.',
                $userMessageEvent,
                suffix: 'lead.unavailable'
            );
            $this->recordAssistantMessage(
                $conversation,
                'Do you have any more questions?',
                $userMessageEvent,
                suffix: 'lead.unavailable.followup'
            );
            $conversation->update([
                'state' => ConversationState::CHAT,
                'lead_current_key' => null,
                'lead_answers' => null,
                'lead_identity_candidate' => null,
            ]);

            return;
        }

        if ($conversation->state === ConversationState::APPRAISAL_CONFIRM) {
            $this->recordAssistantMessage(
                $conversation,
                'Please confirm or cancel the appraisal using the buttons.',
                $userMessageEvent
            );

            return;
        }

        if ($conversation->state === ConversationState::APPRAISAL_INTAKE) {
            if ($this->isUnrelatedQuestionWhileInAppraisal($text)) {
                $this->recordAssistantMessage(
                    $conversation,
                    'I can answer after we finish these valuation questions.',
                    $userMessageEvent,
                    suffix: 'appraisal.strict.defer'
                );
                $this->repeatCurrentAppraisalQuestion($conversation, $userMessageEvent);

                return;
            }

            $this->handleAppraisalAnswer($conversation, $userMessageEvent, $text);

            return;
        }

        if ($this->shouldStartAppraisal($text)) {
            $this->startAppraisal($conversation, $userMessageEvent);

            return;
        }

        if (!$this->isAiChatEnabled((string) $conversation->client_id)) {
            $this->recordAssistantMessage(
                $conversation,
                $this->introMessageResolver->forFallback((string) $conversation->client_id),
                $userMessageEvent
            );

            return;
        }

        $turnId = $this->turnIdForEvent($userMessageEvent);
        if ($turnId === null) {
            $this->recordAssistantMessage(
                $conversation,
                $this->introMessageResolver->forFallback((string) $conversation->client_id),
                $userMessageEvent
            );

            return;
        }

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::ASSISTANT_RESPONSE_REQUESTED,
            [
                'turn_id' => $turnId,
                'request_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'assistant.response.requested'),
            correlationId: $userMessageEvent->correlation_id
        );
    }

    private function shouldStartAppraisal(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\b(worth|value|appraise|appraisal|valuation|how much|sell)\b/i', $text);
    }

    private function shouldStartLead(string $text): bool
    {
        $normalized = strtolower(trim($text));

        if ($normalized === '') {
            return false;
        }

        // Explicit fast-path phrases
        if ((bool) preg_match('/\b(manual review|expert review|lead)\b/', $normalized)) {
            return true;
        }

        // Looser intent detection using keyword groups
        $hasReviewIntent = (bool) preg_match('/\b(review|apprais(e|al)|look at|check)\b/', $normalized);
        $hasEscalationIntent = (bool) preg_match('/\b(expert|specialist|human|someone|team)\b/', $normalized);
        $hasContactIntent = (bool) preg_match('/\b(contact|call|email|reach out|follow up)\b/', $normalized);

        return ($hasReviewIntent && $hasEscalationIntent) || ($hasEscalationIntent && $hasContactIntent);
    }

    private function startLead(Conversation $conversation, ConversationEvent $userMessageEvent): void
    {
        $correlationId = $userMessageEvent->correlation_id;

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::LEAD_STARTED,
            [
                'reason' => 'lead_intent_detected',
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'lead.started'),
            correlationId: $correlationId
        );

        $this->askLeadQuestion($conversation, 'name', $userMessageEvent);
    }

    private function handleLeadAnswer(Conversation $conversation, ConversationEvent $userMessageEvent, string $text): void
    {
        $correlationId = $userMessageEvent->correlation_id;
        $currentKey = $conversation->lead_current_key;
        $answers = $conversation->lead_answers ?? [];

        if (!$currentKey || !array_key_exists($currentKey, self::LEAD_PROMPT_BY_KEY)) {
            $this->askLeadQuestion($conversation, 'name', $userMessageEvent);

            return;
        }

        [$valid, $normalized] = $this->validateLeadAnswer($currentKey, $text);

        if (!$valid) {
            $this->recordAssistantMessage(
                $conversation,
                self::LEAD_INVALID_PROMPT_BY_KEY[$currentKey],
                $userMessageEvent,
                suffix: "lead.invalid.{$currentKey}"
            );
            $this->askLeadQuestion($conversation, $currentKey, $userMessageEvent);

            return;
        }

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::LEAD_ANSWER_RECORDED,
            [
                'question_key' => $currentKey,
                'value' => $normalized,
                'raw_text' => $text,
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, "lead.answer.{$currentKey}"),
            correlationId: $correlationId
        );

        $answers[$currentKey] = $normalized;

        $nextKey = match ($currentKey) {
            'name' => 'email',
            'email' => 'phone',
            default => null,
        };

        if ($nextKey !== null) {
            $this->askLeadQuestion($conversation, $nextKey, $userMessageEvent);

            return;
        }

        $phoneRaw = trim((string) $text);
        $phoneNormalized = $this->leadPiiNormalizer->normalizePhone($phoneRaw);

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::LEAD_REQUESTED,
            [
                'name' => $answers['name'] ?? '',
                'email' => $answers['email'] ?? '',
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $phoneNormalized,
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'lead.requested'),
            correlationId: $correlationId
        );

        $this->emitLeadSubmittedAssistantMessages($conversation, $userMessageEvent);
        $conversation->update([
            'state' => ConversationState::CHAT,
            'lead_current_key' => null,
            'lead_answers' => null,
            'lead_identity_candidate' => null,
        ]);
    }

    private function askLeadQuestion(
        Conversation $conversation,
        string $questionKey,
        ConversationEvent $userMessageEvent
    ): void {
        $correlationId = $userMessageEvent->correlation_id;
        $label = self::LEAD_PROMPT_BY_KEY[$questionKey] ?? null;

        if (!$label) {
            return;
        }

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::LEAD_QUESTION_ASKED,
            [
                'question_key' => $questionKey,
                'label' => $label,
                'required' => true,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, "lead.question.{$questionKey}"),
            correlationId: $correlationId
        );

        $this->recordAssistantMessage(
            $conversation,
            $label,
            $userMessageEvent,
            suffix: "lead.question.{$questionKey}"
        );
    }

    public function emitLeadSubmittedAssistantMessages(
        Conversation $conversation,
        ConversationEvent $userMessageEvent
    ): void {
        $messages = self::leadSubmissionAssistantMessages();

        $this->recordAssistantMessage(
            $conversation,
            $messages[0],
            $userMessageEvent,
            suffix: 'lead.confirmed'
        );
        $this->recordAssistantMessage(
            $conversation,
            $messages[1],
            $userMessageEvent,
            suffix: 'lead.confirmed.followup'
        );
    }

    private function findLatestLeadForConversation(Conversation $conversation): ?Lead
    {
        return Lead::where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->first();
    }

    private function findLinkedValuationContactLead(Conversation $conversation): ?Lead
    {
        $leadId = is_string($conversation->valuation_contact_lead_id)
            ? trim($conversation->valuation_contact_lead_id)
            : '';

        if ($leadId === '') {
            return null;
        }

        return Lead::query()
            ->where('id', $leadId)
            ->where('conversation_id', $conversation->id)
            ->where('client_id', $conversation->client_id)
            ->first();
    }

    private function requestValuationContactForExpertReview(
        Conversation $conversation,
        ConversationEvent $userMessageEvent,
        ?Lead $prefillLead
    ): void {
        $prefill = null;
        $leadId = null;

        if ($prefillLead) {
            $leadId = (string) $prefillLead->id;
            $prefill = [
                'name' => $prefillLead->name !== '' ? $prefillLead->name : null,
                'email' => $prefillLead->email !== '' ? $prefillLead->email : null,
                'phone' => $prefillLead->phone_normalized !== '' ? $prefillLead->phone_normalized : null,
            ];
        }

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::VALUATION_CONTACT_REQUESTED,
            [
                'reason' => 'VALUATION_REQUIRES_EMAIL',
                'pending_intent' => 'expert_review',
                'lead_id' => $leadId,
                'valuation_contact_prefill' => $prefill,
                'fields_required' => ['email'],
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'valuation.contact.requested.expert_review'),
            correlationId: $userMessageEvent->correlation_id
        );
    }

    private function submitExpertReviewRequest(
        Conversation $conversation,
        ConversationEvent $userMessageEvent,
        Lead $lead
    ): void {
        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::LEAD_REQUESTED,
            [
                'lead_id' => (string) $lead->id,
                'name' => $lead->name ?? '',
                'email' => $lead->email ?? '',
                'phone_raw' => $lead->phone_raw ?? '',
                'phone_normalized' => $lead->phone_normalized ?? '',
                'source' => 'EXPERT_REVIEW_BUTTON',
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'lead.requested.expert_review'),
            correlationId: $userMessageEvent->correlation_id
        );

        $this->emitLeadSubmittedAssistantMessages($conversation, $userMessageEvent);
    }

    private function requestLeadIdentityConfirmation(
        Conversation $conversation,
        ConversationEvent $userMessageEvent,
        Lead $lead
    ): void {
        $correlationId = $userMessageEvent->correlation_id;

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::LEAD_IDENTITY_CONFIRMATION_REQUESTED,
            [
                'previous_lead_id' => $lead->id,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone_raw' => $lead->phone_raw,
                'phone_normalized' => $lead->phone_normalized,
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'lead.identity.confirmation.requested'),
            correlationId: $correlationId
        );

        $this->recordAssistantMessage(
            $conversation,
            self::LEAD_IDENTITY_PROMPT,
            $userMessageEvent,
            suffix: 'lead.identity.confirmation.prompt'
        );
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function validateLeadAnswer(string $questionKey, string $text): array
    {
        $trimmed = trim($text);

        return match ($questionKey) {
            'name' => [strlen($trimmed) > 0, $trimmed],
            'email' => [filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false, $this->leadPiiNormalizer->normalizeEmail($trimmed)],
            'phone' => [$this->isValidPhone($trimmed), $this->leadPiiNormalizer->normalizePhone($trimmed)],
            default => [false, $trimmed],
        };
    }

    private function isValidPhone(string $value): bool
    {
        $normalized = $this->leadPiiNormalizer->normalizePhone($value);
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        $digitCount = strlen($digits);

        return $digitCount >= 7 && $digitCount <= 15;
    }

    private function startAppraisal(Conversation $conversation, ConversationEvent $userMessageEvent): void
    {
        $question = $this->firstRequiredQuestion($conversation);

        if (!$question) {
            $this->recordAssistantMessage(
                $conversation,
                'I can help with an appraisal, but no questions are configured yet.',
                $userMessageEvent
            );

            $conversation->update([
                'state' => ConversationState::CHAT,
                'appraisal_current_key' => null,
                'appraisal_answers' => null,
                'appraisal_snapshot' => null,
            ]);

            return;
        }

        $correlationId = $userMessageEvent->correlation_id;

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::APPRAISAL_STARTED,
            [
                'reason' => 'valuation_intent_detected',
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'appraisal.started'),
            correlationId: $correlationId
        );

        $this->askQuestion($conversation, $question, $userMessageEvent);
    }

    private function handleAppraisalAnswer(Conversation $conversation, ConversationEvent $userMessageEvent, string $text): void
    {
        $correlationId = $userMessageEvent->correlation_id;
        $currentKey = $conversation->appraisal_current_key;

        if (!$currentKey) {
            $question = $this->firstRequiredQuestion($conversation);

            if ($question) {
                $this->askQuestion($conversation, $question, $userMessageEvent);
            }

            return;
        }

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::APPRAISAL_ANSWER_RECORDED,
            [
                'question_key' => $currentKey,
                'value' => $text,
                'raw_text' => $text,
                'source_message_event_id' => $userMessageEvent->id,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, "appraisal.answer.{$currentKey}"),
            correlationId: $correlationId
        );

        $nextQuestion = $this->nextRequiredQuestion($conversation, $currentKey, $text);

        if ($nextQuestion) {
            $this->askQuestion($conversation, $nextQuestion, $userMessageEvent);

            return;
        }

        $snapshot = $this->buildSnapshot($conversation, [$currentKey => $text]);
        $missing = $this->missingRequiredKeys($conversation, $snapshot);

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED,
            [
                'snapshot' => $snapshot,
                'missing_required' => $missing,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, 'appraisal.confirmation.requested'),
            correlationId: $correlationId
        );

        $this->recordAssistantMessage(
            $conversation,
            'Please review the appraisal details and confirm when ready.',
            $userMessageEvent,
            suffix: 'confirm'
        );
    }

    private function askQuestion(Conversation $conversation, AppraisalQuestion $question, ConversationEvent $userMessageEvent): void
    {
        $correlationId = $userMessageEvent->correlation_id;

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::APPRAISAL_QUESTION_ASKED,
            [
                'question_key' => $question->key,
                'label' => $question->label,
                'help_text' => $question->help_text,
                'input_type' => $question->input_type,
                'required' => $question->required,
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, "appraisal.question.{$question->key}"),
            correlationId: $correlationId
        );

        $prompt = $question->label;
        if ($question->help_text) {
            $prompt .= " {$question->help_text}";
        }

        $this->recordAssistantMessage(
            $conversation,
            $prompt,
            $userMessageEvent,
            suffix: "question.{$question->key}"
        );
    }

    private function recordAssistantMessage(
        Conversation $conversation,
        string $content,
        ConversationEvent $userMessageEvent,
        ?string $suffix = null
    ): void {
        $suffixKey = $suffix ? ":{$suffix}" : '';

        $this->eventRecorder->record(
            $conversation,
            ConversationEventType::ASSISTANT_MESSAGE_CREATED,
            [
                'content' => $content,
                'turn_id' => $this->turnIdForEvent($userMessageEvent),
            ],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, "assistant{$suffixKey}"),
            correlationId: $userMessageEvent->correlation_id
        );
    }

    private function isAiChatEnabled(string $clientId): bool
    {
        if (!config('ai.enabled')) {
            return false;
        }

        $settings = ClientSetting::forClientOrCreate($clientId);

        return (bool) $settings->ai_enabled;
    }

    private function turnIdForEvent(ConversationEvent $event): ?string
    {
        $turnId = $event->payload['turn_id'] ?? null;
        if (!is_string($turnId) || trim($turnId) === '') {
            return null;
        }

        return trim($turnId);
    }

    private function isUnrelatedQuestionWhileInAppraisal(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->isSkipResponse($trimmed)) {
            return false;
        }

        return str_contains($trimmed, '?');
    }

    private function repeatCurrentAppraisalQuestion(Conversation $conversation, ConversationEvent $userMessageEvent): void
    {
        $currentKey = $conversation->appraisal_current_key;
        if (!is_string($currentKey) || $currentKey === '') {
            return;
        }

        $question = AppraisalQuestion::query()
            ->where('client_id', $conversation->client_id)
            ->where('key', $currentKey)
            ->first();

        if (!$question) {
            return;
        }

        $prompt = $question->label;
        if ($question->help_text) {
            $prompt .= " {$question->help_text}";
        }

        $this->recordAssistantMessage(
            $conversation,
            $prompt,
            $userMessageEvent,
            suffix: "appraisal.repeat.{$currentKey}"
        );
    }

    private function isSkipResponse(string $text): bool
    {
        $value = strtolower(trim($text));
        $tokens = config('appraisal.skip_tokens', ['unknown', 'not sure', 'skip']);
        if (!is_array($tokens)) {
            $tokens = ['unknown', 'not sure', 'skip'];
        }

        $normalized = array_map(
            static fn ($token) => strtolower(trim((string) $token)),
            $tokens
        );

        return in_array($value, $normalized, true);
    }

    private function firstRequiredQuestion(Conversation $conversation): ?AppraisalQuestion
    {
        return AppraisalQuestion::where('client_id', $conversation->client_id)
            ->where('is_active', true)
            ->where('required', true)
            ->orderBy('order_index')
            ->first();
    }

    private function nextRequiredQuestion(Conversation $conversation, string $currentKey, string $currentValue): ?AppraisalQuestion
    {
        $answers = $this->buildSnapshot($conversation, [$currentKey => $currentValue]);
        $answeredKeys = array_keys($answers);

        return AppraisalQuestion::where('client_id', $conversation->client_id)
            ->where('is_active', true)
            ->where('required', true)
            ->whereNotIn('key', $answeredKeys)
            ->orderBy('order_index')
            ->first();
    }

    private function buildSnapshot(Conversation $conversation, array $newAnswers): array
    {
        $answers = $conversation->appraisal_answers ?? [];

        foreach ($newAnswers as $key => $value) {
            $answers[$key] = $value;
        }

        return $answers;
    }

    private function missingRequiredKeys(Conversation $conversation, array $snapshot): array
    {
        $requiredKeys = AppraisalQuestion::where('client_id', $conversation->client_id)
            ->where('is_active', true)
            ->where('required', true)
            ->orderBy('order_index')
            ->pluck('key')
            ->all();

        return array_values(array_diff($requiredKeys, array_keys($snapshot)));
    }

    private function orchestratorKey(ConversationEvent $userMessageEvent, string $suffix): string
    {
        return "orchestrator:{$userMessageEvent->id}:{$suffix}";
    }
}
