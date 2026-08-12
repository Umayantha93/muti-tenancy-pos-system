<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NotifyLkSmsService
{
    public function configured(): bool
    {
        return filled(config('services.notify_lk.user_id'))
            && filled(config('services.notify_lk.api_key'));
    }

    public function send(string $to, string $message, ?string $senderId = null): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Notify.lk SMS is not configured. Add NOTIFYLK_USER_ID and NOTIFYLK_API_KEY to the server .env.');
        }

        $sender = $this->normalizeSenderId($senderId) ?: $this->normalizeSenderId(config('services.notify_lk.sender_id'));
        if (! $sender) {
            throw new RuntimeException('SMS Sender ID is missing. Set an approved Sender ID for this business (or NOTIFYLK_SENDER_ID in .env).');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post(config('services.notify_lk.endpoint'), [
                'user_id' => config('services.notify_lk.user_id'),
                'api_key' => config('services.notify_lk.api_key'),
                'sender_id' => $sender,
                'to' => $this->normalizePhone($to),
                'message' => mb_substr($message, 0, 621),
            ]);

        $payload = $response->json();
        $status = data_get($payload, 'status');

        if (! $response->successful() || $status !== 'success') {
            $error = data_get($payload, 'data')
                ?? data_get($payload, 'message')
                ?? $response->body()
                ?? 'SMS could not be sent.';

            if (is_array($error)) {
                $error = collect($error)->flatten()->filter()->implode(' ');
            }

            throw new RuntimeException(is_string($error) && $error !== '' ? $error : 'SMS could not be sent.');
        }
    }

    public function normalizeSenderId(?string $raw): ?string
    {
        $value = preg_replace('/[^A-Za-z0-9]/', '', trim((string) $raw)) ?? '';

        if ($value === '') {
            return null;
        }

        return substr($value, 0, 11);
    }

    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '94') && strlen($digits) >= 11) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '94'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '94'.$digits;
        }

        throw new RuntimeException('Customer phone must be a valid Sri Lanka mobile number (e.g. 07XXXXXXXX).');
    }
}
