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

        return (bool) ($part->serialized || self::requiredMap(collect([$part]))[$part->id]);
    }

    /**
     * @param  Collection<int, Part>|iterable<Part>  $parts
     * @return array<int, bool>
     */
    public static function requiredMap(iterable $parts): array
    {
        $parts = collect($parts);
        if (! self::enabled() || $parts->isEmpty()) {
            return [];
        }

        $withStock = PartSerial::query()
            ->whereIn('part_id', $parts->pluck('id')->all())
            ->where('status', PartSerial::IN_STOCK)
            ->distinct()
            ->pluck('part_id')
            ->flip();

        $required = [];
        foreach ($parts as $part) {
            $required[$part->id] = (bool) $part->serialized || $withStock->has($part->id);
        }

        return $required;
    }

    /**
     * @param  list<string>  $codes
     * @return Collection<int, PartSerial>
     */
    public static function receive(Part $part, array $codes): Collection
    {
        $created = collect();
        $normalized = [];
        foreach ($codes as $raw) {
            $serial = PartSerial::normalize((string) $raw);
            if ($serial === '') {
                continue;
            }
            if (isset($normalized[$serial])) {
                throw ValidationException::withMessages([
                    'serials' => ["IMEI / serial {$serial} is already in stock or sold."],
                ]);
            }
            $normalized[$serial] = $serial;
        }

        $existing = $normalized === []
            ? collect()
            : PartSerial::query()->whereIn('serial', array_values($normalized))->pluck('serial');
        foreach ($normalized as $serial) {
            if ($existing->contains($serial)) {
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

        $rows = $part->serials()
            ->whereIn('serial', $wanted->all())
            ->where('status', PartSerial::IN_STOCK)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial');

        foreach ($wanted as $serial) {
            $row = $rows->pull($serial);
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
