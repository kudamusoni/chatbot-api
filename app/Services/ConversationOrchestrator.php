<?php

namespace App\Services;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\AppraisalQuestion;
use App\Models\Conversation;
use App\Models\ConversationEvent;

class ConversationOrchestrator
{
    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder
    ) {}

    /**
     * Handle a user message event and emit follow-up events.
     */
    public function handleUserMessage(Conversation $conversation, ConversationEvent $userMessageEvent): void
    {
        $text = trim((string) ($userMessageEvent->payload['content'] ?? ''));

        if ($conversation->state === ConversationState::APPRAISAL_CONFIRM) {
            $this->recordAssistantMessage(
                $conversation,
                'Please confirm or cancel the appraisal using the buttons.',
                $userMessageEvent
            );

            return;
        }

        if ($conversation->state === ConversationState::APPRAISAL_INTAKE) {
            $this->handleAppraisalAnswer($conversation, $userMessageEvent, $text);

            return;
        }

        if ($this->shouldStartAppraisal($text)) {
            $this->startAppraisal($conversation, $userMessageEvent);

            return;
        }

        $this->recordAssistantMessage(
            $conversation,
            'Thank you for your message. How can I help you today?',
            $userMessageEvent
        );
    }

    private function shouldStartAppraisal(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\b(worth|value|appraise|appraisal|valuation|how much)\b/i', $text);
    }

    private function startAppraisal(Conversation $conversation, ConversationEvent $userMessageEvent): void
    {
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

        $question = $this->firstRequiredQuestion($conversation);

        if (!$question) {
            $this->recordAssistantMessage(
                $conversation,
                'I can help with an appraisal, but no questions are configured yet.',
                $userMessageEvent
            );

            return;
        }

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
            ['content' => $content],
            idempotencyKey: $this->orchestratorKey($userMessageEvent, "assistant{$suffixKey}"),
            correlationId: $userMessageEvent->correlation_id
        );
    }

    private function firstRequiredQuestion(Conversation $conversation): ?AppraisalQuestion
    {
        return AppraisalQuestion::where('client_id', $conversation->client_id)
            ->where('required', true)
            ->orderBy('order_index')
            ->first();
    }

    private function nextRequiredQuestion(Conversation $conversation, string $currentKey, string $currentValue): ?AppraisalQuestion
    {
        $answers = $this->buildSnapshot($conversation, [$currentKey => $currentValue]);
        $answeredKeys = array_keys($answers);

        return AppraisalQuestion::where('client_id', $conversation->client_id)
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
