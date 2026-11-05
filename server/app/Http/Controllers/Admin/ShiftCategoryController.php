<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShiftCategory;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ShiftCategory::query()
                ->withCount('templates')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shift_categories', 'name')],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['is_active'] ??= true;

        $category = ShiftCategory::query()->create($data);
        Audit::log('shift_categories.created', $category, null, $data);

        return response()->json(['data' => $category->loadCount('templates')], 201);
    }

    public function update(Request $request, ShiftCategory $shiftCategory): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('shift_categories', 'name')->ignore($shiftCategory->id)],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $data['is_active'] && $shiftCategory->is_active && $shiftCategory->templates()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Pindahkan kategori dari semua template shift sebelum menonaktifkannya.',
            ]);
        }

        $before = $shiftCategory->attributesToArray();
        $shiftCategory->update($data);
        Audit::log('shift_categories.updated', $shiftCategory, $before, $data);

        return response()->json(['data' => $shiftCategory->fresh()->loadCount('templates')]);
    }
}
