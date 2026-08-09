<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plan 09 step: password recovery email.
 *
 * Root cause fixed: `ForgotPasswordController` used to `Log::warning`
 * the reset URL only. Now the same URL is delivered via the standard
 * Laravel mailer so the recovery loop is real. Kept as a plain
 * synchronous Mailable (no queue driver assumption yet).
 */
final class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $ResetUrl,
        public readonly string $ExpiresAtIso,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Licensing Portal password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: [
                'resetUrl' => $this->ResetUrl,
                'expiresAtIso' => $this->ExpiresAtIso,
            ],
        );
    }
}
