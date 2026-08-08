<?php

namespace App\Http\Controllers;

use App\Services\MonetaryView;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function moneyJson(mixed $payload, int $status = 200): JsonResponse
    {
        return response()->json(MonetaryView::for()->transform($payload), $status);
    }
}
