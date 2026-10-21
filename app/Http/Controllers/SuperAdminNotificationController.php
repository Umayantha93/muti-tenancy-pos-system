<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;

class SuperAdminNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'unread' => $this->unreadCount(),
            'data' => ContactMessage::query()
                ->latest()
                ->limit(200)
                ->get(['id', 'name', 'email', 'phone', 'message', 'opened_at', 'created_at']),
        ]);
    }

    public function open(ContactMessage $contactMessage): JsonResponse
    {
        if ($contactMessage->opened_at === null) {
            $contactMessage->update(['opened_at' => now()]);
        }

        return response()->json([
            'message' => $contactMessage->fresh(),
            'unread' => $this->unreadCount(),
        ]);
    }

    private function unreadCount(): int
    {
        return ContactMessage::query()->whereNull('opened_at')->count();
    }
}
