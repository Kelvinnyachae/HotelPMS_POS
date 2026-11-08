<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ClientPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Dawineks Hospitality OS password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-password-reset',
            with: ['resetUrl' => $this->resetUrl],
        );
    }
}
