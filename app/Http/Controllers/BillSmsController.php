<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\NotifyLkSmsService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class BillSmsController extends Controller
{
    public function __invoke(Bill $bill, NotifyLkSmsService $sms): JsonResponse
    {
        $bill->loadMissing(['customer', 'tenant']);
        $bill->ensureShareToken();

        $phone = $bill->customer?->phone;
        if (! filled($phone)) {
            return response()->json(['message' => 'This bill has no customer phone number.'], 422);
        }

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $link = $frontend.'/share/bills/'.$bill->share_token;
        $business = $bill->tenant?->business_name ?: 'us';
        $name = $bill->customer?->name ? ' '.$bill->customer->name : '';
        $message = "Hi{$name}, here is your bill {$bill->bill_number} from {$business}: {$link}";

        try {
            $sms->send($phone, $message);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Bill link sent by SMS.',
            'to' => $sms->normalizePhone($phone),
            'share_token' => $bill->share_token,
        ]);
    }
}
