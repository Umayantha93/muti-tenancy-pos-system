<?php

namespace App\Services;

use App\Models\BillItem;
use App\Models\Part;
use App\Models\PartSerial;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PartSerials
{
    public static function enabled(): bool
    {
        $user = auth()->user();

        return (bool) $user?->canAccessFeature('serial_inventory');
    }

    public static function requiredFor(Part $part): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return (bool) $part->serialized
            || $part->serials()->where('status', PartSerial::IN_STOCK)->exists();
    }

    /**
     * @param  list<string>  $codes
     * @return Collection<int, PartSerial>
     */
    public static function receive(Part $part, array $codes): Collection
    {
        $created = collect();
        foreach ($codes as $raw) {
            $serial = PartSerial::normalize((string) $raw);
            if ($serial === '') {
                continue;
            }
            if (PartSerial::query()->where('serial', $serial)->exists()) {
                throw ValidationException::withMessages([
                    'serials' => ["IMEI / serial {$serial} is already in stock or sold."],
                ]);
            }
            $created->push($part->serials()->create([
                'serial' => $serial,
                'status' => PartSerial::IN_STOCK,
            ]));
        }

        if ($created->isEmpty()) {
            throw ValidationException::withMessages(['serials' => ['Enter at least one IMEI or serial.']]);
        }

        $part->update(['serialized' => true]);

        return $created;
    }

    /**
     * @param  list<string>  $codes
     */
    public static function sell(Part $part, array $codes, BillItem $item): void
    {
        $wanted = collect($codes)
            ->map(fn ($code) => PartSerial::normalize((string) $code))
            ->filter()
            ->values();

        if ($wanted->count() !== (int) $item->quantity) {
            throw ValidationException::withMessages([
                'serials' => ['Give one IMEI / serial for each unit sold.'],
            ]);
        }

        foreach ($wanted as $serial) {
            $row = $part->serials()
                ->where('serial', $serial)
                ->where('status', PartSerial::IN_STOCK)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                throw ValidationException::withMessages([
                    'serials' => ["IMEI / serial {$serial} is not in stock for this item."],
                ]);
            }
            $row->update([
                'status' => PartSerial::SOLD,
                'bill_item_id' => $item->id,
                'sold_at' => now(),
            ]);
        }
    }
}
