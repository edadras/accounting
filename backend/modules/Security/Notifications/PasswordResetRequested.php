<?php

declare(strict_types=1);

namespace Modules\Security\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries the one and only copy of the plaintext reset token out of the system.
 */
final class PasswordResetRequested extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject(config('app.name').' — password reset')
            ->line('We received a request to reset your password.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line("This link expires in {$minutes} minutes and can be used once.")
            ->line('If you did not ask for this, nothing has changed and you can ignore this message.');
    }

    private function resetUrl(object $notifiable): string
    {
        return rtrim((string) config('app.url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
