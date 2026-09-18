<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\NotifyLkSmsService;
use App\Support\BranchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ServiceReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'include_sent' => ['nullable', 'boolean'],
        ]);
        $days = (int) ($data['days'] ?? 14);
        $until = today()->addDays($days);

        $rows = Bill::query()
            ->with(['customer:id,name,phone,sms_opt_in', 'vehicle:id,number_plate,make,model'])
            ->where('job_kind', Bill::JOB_KIND_SERVICE)
            ->whereNotNull('next_service_due_on')
            ->whereDate('next_service_due_on', '<=', $until)
            ->when(! $request->boolean('include_sent'), fn ($query) => $query->whereNull('service_reminder_sent_at'))
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->orderBy('next_service_due_on')
            ->get();

        return $this->moneyJson(['data' => $rows, 'until' => $until->toDateString()]);
    }

    public function send(Request $request, Bill $bill, NotifyLkSmsService $sms): JsonResponse
    {
        abort_unless($bill->job_kind === Bill::JOB_KIND_SERVICE, 422, 'Reminders are only for service jobs.');
        abort_unless($bill->next_service_due_on, 422, 'Set a next-service date on this job first.');

        $bill->loadMissing(['customer', 'tenant', 'vehicle']);
        $phone = $bill->customer?->phone;
        if (! filled($phone)) {
            return response()->json(['message' => 'This bill has no customer phone number.'], 422);
        }
        if ($bill->customer && $bill->customer->sms_opt_in === false) {
            return response()->json(['message' => 'This customer has opted out of SMS.'], 422);
        }

        $message = $this->message($bill);
        $whatsapp = $this->whatsappUrl($sms, $phone, $message);
        $channel = $request->string('channel', 'sms')->toString();

        if ($channel === 'whatsapp') {
            $bill->update(['service_reminder_sent_at' => now()]);

            return response()->json([
                'message' => 'WhatsApp reminder ready.',
                'whatsapp_url' => $whatsapp,
                'service_reminder_sent_at' => $bill->service_reminder_sent_at,
            ]);
        }

        try {
            $sms->send($phone, $message);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $bill->update(['service_reminder_sent_at' => now()]);

        return response()->json([
            'message' => 'Next-service reminder sent by SMS.',
            'whatsapp_url' => $whatsapp,
            'to' => $sms->normalizePhone($phone),
            'service_reminder_sent_at' => $bill->fresh()->service_reminder_sent_at,
        ]);
    }

    private function message(Bill $bill): string
    {
        $business = $bill->tenant?->business_name ?: 'us';
        $name = trim((string) $bill->customer?->name);
        $who = $name !== '' && $name !== 'Walk-in' ? $name : 'Customer';
        $plate = $bill->vehicle?->number_plate;
        $when = $bill->next_service_due_on?->format('d M Y');
        $km = $bill->next_service_mileage ? ' at '.number_format((int) $bill->next_service_mileage).' km' : '';
        $detail = $plate ? " for {$plate}" : '';

        return "{$business}: {$who}, next service{$detail} is due on {$when}{$km}. Please book with us.";
    }

    private function whatsappUrl(NotifyLkSmsService $sms, string $phone, string $message): ?string
    {
        try {
            return 'https://wa.me/'.$sms->normalizePhone($phone).'?text='.rawurlencode($message);
        } catch (RuntimeException) {
            return null;
        }
    }
}
