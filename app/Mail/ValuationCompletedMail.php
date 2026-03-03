<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\Valuation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ValuationCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public readonly string $clientName,
        public readonly Lead $lead,
        public readonly Valuation $valuation,
        public readonly array $result,
    ) {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your valuation is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.valuation-completed',
        );
    }
}

