<?php

namespace App\Http\Controllers;

use App\Models\JobCardAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $alerts = JobCardAlert::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(20);

        return response()->json([
            'unread' => JobCardAlert::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
            'data' => $alerts->get(['id', 'bill_id', 'bill_number', 'vehicle_label', 'read_at', 'created_at']),
        ]);
    }

    public function read(Request $request, JobCardAlert $jobCardAlert): JsonResponse
    {
        abort_unless($jobCardAlert->user_id === $request->user()->id, 404);
        if ($jobCardAlert->read_at === null) {
            $jobCardAlert->update(['read_at' => now()]);
        }

        return response()->json($jobCardAlert);
    }

    public function readAll(Request $request): JsonResponse
    {
        JobCardAlert::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->index($request);
    }
}
