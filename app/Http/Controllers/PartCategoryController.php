<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $stored = PartCategory::query()->with('subcategories')->orderBy('name')->get();
        $byName = [];
        foreach ($stored as $category) {
            $byName[mb_strtolower($category->name)] = [
                'id' => $category->id,
                'name' => $category->name,
                'subcategories' => $category->subcategories->map(fn (PartSubcategory $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                ])->values()->all(),
            ];
        }

        $used = Part::query()->select('type', 'subcategory')->where('type', '!=', '')->distinct()->get();
        foreach ($used as $part) {
            $name = trim((string) $part->type);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $byName[$key] ??= ['id' => null, 'name' => $name, 'subcategories' => []];
            $sub = trim((string) $part->subcategory);
            if ($sub === '') {
                continue;
            }
            $exists = collect($byName[$key]['subcategories'])->contains(
                fn (array $row) => mb_strtolower($row['name']) === mb_strtolower($sub)
            );
            if (! $exists) {
                $byName[$key]['subcategories'][] = ['id' => null, 'name' => $sub];
            }
        }

        $data = collect($byName)->sortBy(fn (array $row) => mb_strtolower($row['name']))->values()->map(function (array $row) {
            $row['subcategories'] = collect($row['subcategories'])
                ->sortBy(fn (array $sub) => mb_strtolower($sub['name']))
                ->values()
                ->all();

            return $row;
        })->values();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('part_categories', 'name')->where('tenant_id', $request->user()->tenant_id),
            ],
        ]);

        $category = PartCategory::query()->create(['name' => trim($data['name'])]);

        return response()->json([
            'id' => $category->id,
            'name' => $category->name,
            'subcategories' => [],
        ], 201);
    }

    public function storeSubcategory(Request $request, PartCategory $partCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('part_subcategories', 'name')->where('part_category_id', $partCategory->id),
            ],
        ]);

        $sub = $partCategory->subcategories()->create(['name' => trim($data['name'])]);

        return response()->json([
            'id' => $sub->id,
            'name' => $sub->name,
            'part_category_id' => $partCategory->id,
        ], 201);
    }
}
