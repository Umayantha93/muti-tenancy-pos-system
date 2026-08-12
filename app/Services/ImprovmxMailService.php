<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ImprovmxMailService
{
    public function configured(): bool
    {
        return filled(config('services.improvmx.api_key'))
            && filled(config('services.improvmx.domain'))
            && filled(config('services.improvmx.from'));
    }

    /**
     * @param  array{to: string, subject: string, text: string, html?: string, from_name?: string}  $payload
     */
    public function send(array $payload): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Improvmx mail is not configured.');
        }

        $domain = config('services.improvmx.domain');
        $response = Http::withBasicAuth('api', (string) config('services.improvmx.api_key'))
            ->acceptJson()
            ->timeout(20)
            ->post("https://api.improvmx.com/v3/domains/{$domain}/emails/outbound", [
                'from' => config('services.improvmx.from'),
                'to' => $payload['to'],
                'subject' => $payload['subject'],
                'text' => $payload['text'],
                'html' => $payload['html'] ?? nl2br(e($payload['text'])),
            ]);

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error')
                ?? data_get($response->json(), 'message')
                ?? $response->body()
                ?: 'Email could not be sent.';

            throw new RuntimeException(is_string($message) ? $message : 'Email could not be sent.');
        }
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
            "Your business account for {$businessName} has been created.",
            '',
            "Login link: {$loginUrl}",
            "Email: {$loginEmail}",
            "Temporary password: {$plainPassword}",
            '',
            'Please sign in and change your password after your first login.',
            '',
            '— '.config('app.name', 'Business Operations'),
        ]);

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#20221f">'
            .'<p>Hi '.e($ownerName).',</p>'
            .'<p>Your business account for <strong>'.e($businessName).'</strong> has been created.</p>'
            .'<p><strong>Login link:</strong> <a href="'.e($loginUrl).'">'.e($loginUrl).'</a><br>'
            .'<strong>Email:</strong> '.e($loginEmail).'<br>'
            .'<strong>Temporary password:</strong> '.e($plainPassword).'</p>'
            .'<p>Please sign in and change your password after your first login.</p>'
            .'<p>— '.e((string) config('app.name', 'Business Operations')).'</p>'
            .'</div>';

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
