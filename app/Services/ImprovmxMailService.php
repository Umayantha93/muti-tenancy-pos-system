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
     * @param  array{to: string, subject: string, text: string, html?: string, reply_to?: string, reply_name?: string}  $payload
     */
    public function send(array $payload): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Mail is not configured.');
        }

        $html = $payload['html'] ?? nl2br(e($payload['text']));

        Mail::html($html, function ($message) use ($payload) {
            $message->to($payload['to'])->subject($payload['subject']);
            if (! empty($payload['reply_to'])) {
                $message->replyTo($payload['reply_to'], $payload['reply_name'] ?? null);
            }
            $message->getSymfonyMessage()->text($payload['text']);
        });
    }

    public function sendContactEnquiry(string $name, string $email, ?string $phone, string $message): void
    {
        $inbox = (string) config('mail.contact_inbox');
        $phoneLine = $phone ? "Phone: {$phone}\n" : '';
        $text = implode("\n", [
            'New message from the IUBits website.',
            '',
            "Name: {$name}",
            "Email: {$email}",
            $phoneLine.'Message:',
            $message,
        ]);
        $html = view('mail.contact-enquiry', [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'enquiry' => $message,
        ])->render();

        $this->send([
            'to' => $inbox,
            'subject' => "Website enquiry from {$name}",
            'text' => $text,
            'html' => $html,
            'reply_to' => $email,
            'reply_name' => $name,
        ]);
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
