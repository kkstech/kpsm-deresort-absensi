<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Departemen dan jabatan: tabel nama saja, CRUD-nya identik. */
class CatalogController extends Controller
{
    private const MODELS = ['departments' => Department::class, 'positions' => Position::class];

    public function index(string $catalog): JsonResponse
    {
        $model = $this->model($catalog);

        return response()->json(['data' => $model::query()->withCount('employees')->orderBy('name')->get()]);
    }

    public function store(Request $request, string $catalog): JsonResponse
    {
        Gate::authorize('admin');
        $model = $this->model($catalog);
        $item = $model::query()->create($this->validated($request, $catalog));
        Audit::log("{$catalog}.created", $item);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, string $catalog, int $id): JsonResponse
    {
        Gate::authorize('admin');
        $item = $this->model($catalog)::query()->findOrFail($id);
        $before = $item->attributesToArray();
        $item->update($this->validated($request, $catalog, $item));
        Audit::log("{$catalog}.updated", $item, $before);

        return response()->json(['data' => $item]);
    }

    public function destroy(string $catalog, int $id): JsonResponse
    {
        Gate::authorize('admin');
        $item = $this->model($catalog)::query()->findOrFail($id);
        $before = $item->attributesToArray();
        $item->delete();
        Audit::log("{$catalog}.deleted", $item, $before, []);

        return response()->json(['ok' => true]);
    }

    /** @return class-string<Model> */
    private function model(string $catalog): string
    {
        return self::MODELS[$catalog] ?? abort(404);
    }

    private function validated(Request $request, string $catalog, ?Model $item = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique($catalog, 'name')->ignore($item?->getKey())],
        ]);
    }
}
