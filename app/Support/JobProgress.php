<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\JobProgressPhoto;

class JobProgress
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public static function tracks(string $businessType): array
    {
        $type = BusinessTypes::normalizeLegacy($businessType);

        return $type === BusinessTypes::PAINT ? self::paintTracks() : self::garageTracks();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function tracksForBill(Bill $bill): array
    {
        $bill->loadMissing('tenant:id,business_type');
        $type = BusinessTypes::normalizeLegacy((string) ($bill->tenant?->business_type ?? ''));
        if ($type === BusinessTypes::PAINT || $bill->job_kind === Bill::JOB_KIND_PAINT) {
            return self::paintTracks();
        }

        return self::garageTracks();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private static function paintTracks(): array
    {
        return [
            ['key' => 'received', 'label' => 'Received'],
            ['key' => 'preparing', 'label' => 'Preparing'],
            ['key' => 'painting', 'label' => 'Painting'],
            ['key' => 'drying', 'label' => 'Drying'],
            ['key' => 'finishing', 'label' => 'Finishing'],
            ['key' => 'complete', 'label' => 'Complete'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private static function garageTracks(): array
    {
        return [
            ['key' => 'received', 'label' => 'Received'],
            ['key' => 'inspecting', 'label' => 'Inspecting'],
            ['key' => 'working', 'label' => 'Working'],
            ['key' => 'checking', 'label' => 'Checking'],
            ['key' => 'complete', 'label' => 'Complete'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(string $businessType): array
    {
        return array_column(self::tracks($businessType), 'key');
    }

    /**
     * @return list<string>
     */
    public static function keysForBill(Bill $bill): array
    {
        return array_column(self::tracksForBill($bill), 'key');
    }

    /**
     * @return array{current_step: string, complete: bool, share_token: string, steps: list<array<string, mixed>>}
     */
    public static function present(Bill $bill): array
    {
        $bill->loadMissing(['tenant:id,business_type,business_name', 'items']);
        $tracks = self::tracksForBill($bill);
        $keys = array_column($tracks, 'key');
        $current = in_array($bill->progress_step, $keys, true) ? (string) $bill->progress_step : $tracks[0]['key'];
        $currentIndex = (int) array_search($current, $keys, true);
        $done = $current === 'complete';
        $photos = JobProgressPhoto::query()
            ->where('bill_id', $bill->id)
            ->orderBy('id')
            ->get()
            ->groupBy('step_key');

        $steps = [];
        foreach ($tracks as $index => $track) {
            $state = $done || $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming');
            $steps[] = [
                'key' => $track['key'],
                'label' => $track['label'],
                'state' => $state,
                'photos' => ($photos[$track['key']] ?? collect())->map(fn (JobProgressPhoto $photo) => [
                    'id' => $photo->id,
                    'original_name' => $photo->original_name,
                    'size_bytes' => $photo->size_bytes,
                ])->values(),
            ];
        }

        return [
            'current_step' => $current,
            'complete' => $done,
            'share_token' => $bill->ensureShareToken(),
            'steps' => $steps,
            'labor' => self::laborLines($bill),
        ];
    }

    /**
     * Work charged on the job, such as a labor line for repairing brake pads.
     *
     * @return list<array{id: int, description: string}>
     */
    private static function laborLines(Bill $bill): array
    {
        $lines = [];
        $seenPanels = [];
        foreach ($bill->items as $item) {
            if (! in_array($item->type, ['labor', 'service', 'service_addon', 'charge'], true)) {
                continue;
            }
            $panel = (string) ($item->panel_group_id ?? '');
            if ($panel !== '') {
                if (isset($seenPanels[$panel])) {
                    continue;
                }
                $seenPanels[$panel] = true;
            }
            $description = trim((string) ($item->panel_name ?: $item->description));
            if ($description === '') {
                continue;
            }
            $lines[] = [
                'id' => $item->id,
                'description' => $description,
            ];
        }

        return $lines;
    }
}
