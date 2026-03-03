<?php

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Events\Conversation\ConversationEventRecorded;
use App\Listeners\DispatchAssistantResponseJob;
use App\Listeners\DispatchValuationJob;
use App\Projectors\ConversationProjector;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, OpenAiProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register event listeners
        // Note: Order matters! Projector runs first to create valuation row,
        // then DispatchValuationJob dispatches the job.
        Event::listen(
            ConversationEventRecorded::class,
            [ConversationProjector::class, 'handle']
        );

        Event::listen(
            ConversationEventRecorded::class,
            [DispatchValuationJob::class, 'handle']
        );

        Event::listen(
            ConversationEventRecorded::class,
            [DispatchAssistantResponseJob::class, 'handle']
        );

    }
}
