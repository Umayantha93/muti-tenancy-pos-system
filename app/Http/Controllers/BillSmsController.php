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

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $link = $frontend.'/share/bills/'.$bill->share_token;
        $business = $bill->tenant?->business_name ?: 'us';
        $name = trim((string) $bill->customer?->name);
        $message = $this->smsMessage($bill, $name, $business, $link);
        $status = $this->smsStatus($bill);

        try {
            $sms->send($phone, $message);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $owner = $this->sendOwnerCopies($bill, $sms, $phone, $name, $business, $link);

        $customerLabel = match ($status) {
            'paid' => 'Paid bill link sent by SMS.',
            'repair_note' => 'Repair note sent by SMS.',
            'partial' => 'Bill link sent by SMS.',
            default => 'Quotation link sent by SMS.',
        };

        $messageOut = $customerLabel;
        if ($owner['sent'] > 0) {
            $messageOut = match ($status) {
                'paid' => 'Paid bill sent to customer and owner.',
                'repair_note' => 'Repair note sent to customer and owner.',
                default => 'Sent to customer and owner.',
            };
        } elseif ($owner['skipped_no_phone']) {
            $messageOut .= ' Owner copy skipped — add an owner phone on the shop.';
        } elseif ($owner['skipped_same']) {
            $messageOut .= ' Owner copy skipped — same number as the customer.';
        }

        return response()->json([
            'message' => $messageOut,
            'document' => $status === 'paid' ? 'paid_bill' : ($status === 'repair_note' ? 'repair_note' : 'quotation'),
            'sender_id' => $sms->normalizeSenderId(config('services.notify_lk.sender_id')),
            'to' => $sms->normalizePhone($phone),
            'owner_sent' => $owner['sent'],
            'owner_skipped' => $owner['skipped_no_phone'] ? 'no_phone' : ($owner['skipped_same'] ? 'same_number' : null),
            'share_token' => $bill->share_token,
        ]);
    }

    /**
     * @return array{sent: int, skipped_no_phone: bool, skipped_same: bool}
     */
    private function sendOwnerCopies(Bill $bill, NotifyLkSmsService $sms, string $customerPhone, string $customerName, string $business, string $link): array
    {
        $tenant = $bill->tenant;
        $isGarage = (string) $tenant?->business_type === BusinessTypes::GARAGE;
        $enabled = $isGarage && (bool) $tenant?->features()
            ->where('features.key', 'owner_bill_sms')
            ->wherePivot('is_enabled', true)
            ->exists();

        if (! $enabled) {
            return ['sent' => 0, 'skipped_no_phone' => false, 'skipped_same' => false];
        }

        $numbers = $this->ownerPhones($tenant);
        if ($numbers === []) {
            return ['sent' => 0, 'skipped_no_phone' => true, 'skipped_same' => false];
        }

        $customerNorm = $this->phoneKey($customerPhone);
        $unique = [];
        foreach ($numbers as $number) {
            $key = $this->phoneKey($number);
            if ($key === '' || isset($unique[$key]) || $key === $customerNorm) {
                continue;
            }
            $unique[$key] = $number;
        }

        if ($unique === []) {
            return ['sent' => 0, 'skipped_no_phone' => false, 'skipped_same' => true];
        }

        $ownerMessage = $this->ownerSmsMessage($bill, $customerName, $business, $link);
        $sent = 0;
        foreach ($unique as $number) {
            try {
                $sms->send($number, $ownerMessage);
                $sent++;
            } catch (RuntimeException) {
                continue;
            }
        }

        return ['sent' => $sent, 'skipped_no_phone' => false, 'skipped_same' => false];
    }

    /**
     * @return list<string>
     */
    private function ownerPhones($tenant): array
    {
        $listed = collect($tenant?->owner_phones ?? [])
            ->map(fn ($entry) => is_array($entry) ? ($entry['number'] ?? '') : (string) $entry)
            ->filter()
            ->values()
            ->all();

        if ($listed !== []) {
            return $listed;
        }

        return filled($tenant?->owner_phone) ? [(string) $tenant->owner_phone] : [];
    }

    private function phoneKey(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '94') && strlen($digits) >= 11) {
            return substr($digits, -9);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return substr($digits, -9);
        }

        return $digits;
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
        if ($bill->isRepairNote()) {
            return 'repair_note';
        }

        return 'quote';
    }

    private function garageVehiclePlate(Bill $bill): ?string
    {
        $businessType = $bill->tenant?->business_type ?? BusinessTypes::GARAGE;
        if (! BusinessTypes::usesVehicleJobs($businessType)) {
            return null;
        }

        $plate = trim((string) $bill->vehicle?->number_plate);

        return $plate !== '' ? $plate : null;
    }

    private function documentPhrase(Bill $bill): string
    {
        return match ($this->smsStatus($bill)) {
            'paid' => 'paid bill',
            'partial' => 'bill (partly paid)',
            'repair_note' => 'repair note',
            default => 'quotation',
        };
    }

    private function smsMessage(Bill $bill, string $name, string $business, string $link): string
    {
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';
        $plate = $this->garageVehiclePlate($bill);
        $detail = $plate !== null ? " for {$plate}" : '';
        $document = $this->documentPhrase($bill);
        $extra = $this->smsStatus($bill) === 'repair_note' ? ' work list, no prices' : '';
        $next = $this->nextServicePhrase($bill);

        return "{$link} \n{$greeting} {$business} {$document}{$detail}{$extra}.{$next}";
    }

    private function ownerSmsMessage(Bill $bill, string $customerName, string $business, string $link): string
    {
        $plate = $this->garageVehiclePlate($bill);
        $detail = $plate !== null ? " for {$plate}" : '';
        $document = $this->documentPhrase($bill);
        $who = $customerName !== '' ? $customerName : 'customer';
        $next = $this->nextServicePhrase($bill);

        return "{$link} \nOwner copy — {$business} {$document}{$detail} sent to {$who}.{$next}";
    }

    private function nextServicePhrase(Bill $bill): string
    {
        if ($bill->job_kind !== Bill::JOB_KIND_SERVICE || $bill->next_service_mileage === null) {
            return '';
        }

        return ' Next service at '.number_format((int) $bill->next_service_mileage).' km.';
    }
}
