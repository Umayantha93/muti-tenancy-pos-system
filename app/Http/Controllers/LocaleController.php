<?php

namespace App\Http\Controllers;

use App\Support\AppLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $locale = AppLocale::normalize($request->query('locale', app()->getLocale()));
        app()->setLocale($locale);

        return response()->json($this->payload($locale));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(AppLocale::codes())],
        ]);
        $locale = AppLocale::normalize($data['locale']);
        $request->user()->update(['locale' => $locale]);
        app()->setLocale($locale);

        return response()->json($this->payload($locale));
    }

    /** @return array{locale: string, available: list<string>, messages: mixed} */
    private function payload(string $locale): array
    {
        return [
            'locale' => $locale,
            'available' => AppLocale::codes(),
            'messages' => trans('ui'),
        ];
    }
}
