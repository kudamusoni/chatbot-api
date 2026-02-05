<?php

namespace App\Providers;

use App\Events\Conversation\ConversationEventRecorded;
use App\Listeners\DispatchValuationJob;
use App\Projectors\ConversationProjector;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
