<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkLocation;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => WorkLocation::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $location = WorkLocation::query()->create($this->validated($request));
        Audit::log('locations.created', $location);

        return response()->json(['data' => $location], 201);
    }

    public function update(Request $request, WorkLocation $location): JsonResponse
    {
        Gate::authorize('admin');
        $before = $location->attributesToArray();
        $location->update($this->validated($request));
        Audit::log('locations.updated', $location, $before);

        return response()->json(['data' => $location]);
    }

    public function destroy(WorkLocation $location): JsonResponse
    {
        Gate::authorize('admin');
        $before = $location->attributesToArray();
        $location->delete();
        Audit::log('locations.deleted', $location, $before, []);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        $radius = $request->input('mode') === 'radius';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'mode' => ['required', 'in:none,radius'],
            'latitude' => [$radius ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$radius ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => [$radius ? 'required' : 'nullable', 'integer', 'between:10,5000'],
            'max_accuracy_meters' => ['nullable', 'integer', 'between:5,1000'],
        ]);

        return $radius ? $data : [...$data, 'latitude' => null, 'longitude' => null, 'radius_meters' => null];
    }
}
