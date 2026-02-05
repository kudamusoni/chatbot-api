<?php

namespace App\Listeners;

use App\Enums\ConversationEventType;
use App\Enums\ValuationStatus;
use App\Events\Conversation\ConversationEventRecorded;
use App\Jobs\RunValuationJob;
use App\Models\Valuation;

/**
 * Listens for valuation.requested events and dispatches the valuation job.
 *
 * Includes dispatch guard to prevent duplicate dispatching on replays.
 */
class DispatchValuationJob
{
    /**
     * Handle the event.
     */
    public function handle(ConversationEventRecorded $eventRecorded): void
    {
        $event = $eventRecorded->event;

        // Only handle valuation.requested events
        if ($event->type !== ConversationEventType::VALUATION_REQUESTED) {
            return;
        }

        // Extract snapshot_hash from event payload
        $snapshotHash = $event->payload['snapshot_hash'] ?? null;

        if (!$snapshotHash) {
            // Legacy event without snapshot_hash - generate it from input_snapshot
            $inputSnapshot = $event->payload['input_snapshot'] ?? $event->payload;
            $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);
        }

        // Dispatch guard: only dispatch if valuation exists and is PENDING
        $valuation = Valuation::where('conversation_id', $event->conversation_id)
            ->where('snapshot_hash', $snapshotHash)
            ->first();

        if (!$valuation) {
            // Valuation not yet created by projector - this can happen if
            // the projector runs after this listener. Skip for now;
            // the projector will create it and the next event will trigger the job.
            return;
        }

        if ($valuation->status !== ValuationStatus::PENDING) {
            // Already running or completed, skip dispatch
            return;
        }

        // Dispatch the job
        RunValuationJob::dispatch($valuation->id);
    }
}
