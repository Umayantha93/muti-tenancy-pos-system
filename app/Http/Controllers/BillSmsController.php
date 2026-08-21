<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\NotifyLkSmsService;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class BillSmsController extends Controller
{
    public function __invoke(Bill $bill, NotifyLkSmsService $sms): JsonResponse
    {
        $bill->loadMissing(['customer', 'tenant', 'vehicle']);
        $bill->ensureShareToken();

        $phone = $bill->customer?->phone;
        if (! filled($phone)) {
            return response()->json(['message' => 'This bill has no customer phone number.'], 422);
        }

        $paid = $this->isPaid($bill);
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $link = $frontend.'/share/bills/'.$bill->share_token;
        $business = $bill->tenant?->business_name ?: 'us';
        $name = trim((string) $bill->customer?->name);
        $message = $this->smsMessage($bill, $name, $business, $link);

        try {
            $sms->send($phone, $message);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $paid ? 'Paid bill link sent by SMS.' : 'Quotation link sent by SMS.',
            'document' => $paid ? 'paid_bill' : 'quotation',
            'sender_id' => $sms->normalizeSenderId(config('services.notify_lk.sender_id')),
            'to' => $sms->normalizePhone($phone),
            'share_token' => $bill->share_token,
        ]);
    }

    private function isPaid(Bill $bill): bool
    {
        return (float) $bill->amount_paid > 0 && (float) $bill->balance_due <= 0;
    }

    private function smsStatus(Bill $bill): string
    {
        $paid = (float) $bill->amount_paid;
        $due = (float) $bill->balance_due;
        if ($paid > 0 && $due <= 0) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }

        return 'quote';
    }

    private function garageVehiclePlate(Bill $bill): ?string
    {
        $businessType = $bill->tenant?->business_type ?? BusinessTypes::GARAGE;
        if ($businessType !== BusinessTypes::GARAGE) {
            return null;
        }

        $plate = trim((string) $bill->vehicle?->number_plate);

        return $plate !== '' ? $plate : null;
    }

    private function smsMessage(Bill $bill, string $name, string $business, string $link): string
    {
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';
        $status = $this->smsStatus($bill);
        $plate = $this->garageVehiclePlate($bill);
        $detail = $plate !== null ? " for {$plate}" : '';
        $document = match ($status) {
            'paid' => 'paid bill',
            'partial' => 'bill (partly paid)',
            default => 'quotation',
        };

        return "{$link} \n{$greeting} {$business} {$document}{$detail}.";
    }
}
