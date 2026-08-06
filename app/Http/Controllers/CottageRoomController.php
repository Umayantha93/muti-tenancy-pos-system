<?php

namespace App\Http\Controllers;

use App\Models\CottageRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CottageRoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rooms = CottageRoom::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->get();

        return response()->json($rooms);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'nightly_rate' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:available,maintenance,occupied'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(CottageRoom::create($data), 201);
    }

    public function update(Request $request, CottageRoom $room): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'nightly_rate' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:available,maintenance,occupied'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $room->update($data);

        return response()->json($room->refresh());
    }

    public function destroy(CottageRoom $room): JsonResponse
    {
        $room->delete();

        return response()->json(null, 204);
    }
}
