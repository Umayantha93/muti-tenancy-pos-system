<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ImprovmxMailService
{
    public function configured(): bool
    {
        return config('mail.default') !== 'log'
            && filled(config('mail.from.address'));
    }

    /**
     * @param  array{to: string, subject: string, text: string, html?: string, from_name?: string}  $payload
     */
    public function send(array $payload): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Mail is not configured.');
        }

        $html = $payload['html'] ?? nl2br(e($payload['text']));

        Mail::html($html, function ($message) use ($payload) {
            $message->to($payload['to'])->subject($payload['subject']);
            $message->getSymfonyMessage()->text($payload['text']);
        });
    }

    public function sendTenantWelcome(
        string $toEmail,
        string $ownerName,
        string $businessName,
        string $loginEmail,
        string $plainPassword,
    ): void {
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';
        $subject = "Your {$businessName} account is ready";
        $text = implode("\n", [
            "Hi {$ownerName},",
            '',
            "Your business account for {$businessName} has been created. Use the details below to sign in, then change the temporary password.",
            '',
            "Email: {$loginEmail}",
            "Temporary password: {$plainPassword}",
            "Sign in: {$loginUrl}",
            '',
            'This password is temporary. Change it after your first sign-in.',
            '',
            '— IUBits',
        ]);
        $html = view('mail.tenant-welcome', [
            'subjectLine' => $subject,
            'ownerName' => $ownerName,
            'businessName' => $businessName,
            'loginEmail' => $loginEmail,
            'plainPassword' => $plainPassword,
            'loginUrl' => $loginUrl,
        ])->render();

        $this->send([
            'to' => $toEmail,
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ]);
    }

    public function sendTenantWelcomeSafely(
        string $toEmail,
        string $ownerName,
        string $businessName,
        string $loginEmail,
        string $plainPassword,
    ): bool {
        try {
            $this->sendTenantWelcome($toEmail, $ownerName, $businessName, $loginEmail, $plainPassword);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Tenant welcome email failed.', [
                'to' => $toEmail,
                'business' => $businessName,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
