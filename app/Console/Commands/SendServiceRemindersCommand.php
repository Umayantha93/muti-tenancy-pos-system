<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\Tenant;
use App\Services\NotifyLkSmsService;
use Illuminate\Console\Command;
use RuntimeException;

class SendServiceRemindersCommand extends Command
{
    protected $signature = 'service-reminders:send {--days=3}';

    protected $description = 'SMS next-service reminders for jobs due within the coming days.';

    public function handle(NotifyLkSmsService $sms): int
    {
        if (! $sms->configured()) {
            $this->warn('Notify.lk SMS is not configured.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $until = today()->addDays($days);
        $sent = 0;

        $bills = Bill::withoutGlobalScopes()
            ->with(['customer', 'tenant', 'vehicle'])
            ->where('job_kind', Bill::JOB_KIND_SERVICE)
            ->whereNotNull('next_service_due_on')
            ->whereNull('service_reminder_sent_at')
            ->whereDate('next_service_due_on', '>=', today())
            ->whereDate('next_service_due_on', '<=', $until)
            ->get();

        foreach ($bills as $bill) {
            $tenant = $bill->tenant;
            if (! $tenant instanceof Tenant) {
                continue;
            }
            $hasReminders = $tenant->features()->where('features.key', 'service_reminders')->wherePivot('is_enabled', true)->exists();
            $hasSms = $tenant->features()->where('features.key', 'bill_sms')->wherePivot('is_enabled', true)->exists();
            if (! $hasReminders || ! $hasSms) {
                continue;
            }

            $phone = $bill->customer?->phone;
            if (! filled($phone) || $bill->customer?->sms_opt_in === false) {
                continue;
            }

            $business = $tenant->business_name ?: 'us';
            $name = trim((string) $bill->customer?->name);
            $who = $name !== '' && $name !== 'Walk-in' ? $name : 'Customer';
            $plate = $bill->vehicle?->number_plate;
            $when = $bill->next_service_due_on?->format('d M Y');
            $km = $bill->next_service_mileage ? ' at '.number_format((int) $bill->next_service_mileage).' km' : '';
            $detail = $plate ? " for {$plate}" : '';
            $message = "{$business}: {$who}, next service{$detail} is due on {$when}{$km}. Please book with us.";

            try {
                $sms->send($phone, $message);
                $bill->update(['service_reminder_sent_at' => now()]);
                $sent++;
                $this->info("Sent reminder for bill {$bill->bill_number}");
            } catch (RuntimeException $exception) {
                $this->warn("Skipped {$bill->bill_number}: {$exception->getMessage()}");
            }
        }

        $this->info($sent.' reminder(s) sent.');

        return self::SUCCESS;
    }
}
