<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        try {
            ContactMessage::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => filled($data['phone'] ?? null) ? $data['phone'] : null,
                'message' => $data['message'],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The message could not be saved just now. Call or WhatsApp us instead.',
            ], 503);
        }

        return response()->json(['message' => 'Message received. We will reply using the details you entered.']);
    }
}
