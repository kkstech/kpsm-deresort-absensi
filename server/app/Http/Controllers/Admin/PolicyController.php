<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendancePolicy;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PolicyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => AttendancePolicy::query()
            ->with('department:id,name', 'employee:id,code,name')
            ->orderByRaw("case scope_type when 'global' then 0 when 'department' then 1 else 2 end")
            ->orderByDesc('effective_from')
            ->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $policy = AttendancePolicy::query()->create($this->validated($request));
        Audit::log('policies.created', $policy);

        return response()->json(['data' => $policy], 201);
    }

    public function update(Request $request, AttendancePolicy $policy): JsonResponse
    {
        Gate::authorize('admin');
        $before = $policy->attributesToArray();
        $policy->update($this->validated($request, $policy));
        Audit::log('policies.updated', $policy, $before);

        return response()->json(['data' => $policy]);
    }

    public function destroy(AttendancePolicy $policy): JsonResponse
    {
        Gate::authorize('admin');
        $before = $policy->attributesToArray();
        $policy->delete();
        Audit::log('policies.deleted', $policy, $before, []);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?AttendancePolicy $policy = null): array
    {
        $scope = $request->input('scope_type');
        $data = $request->validate([
            'scope_type' => ['required', 'in:global,department,employee'],
            'department_id' => [$scope === 'department' ? 'required' : 'prohibited', 'nullable', 'integer', 'exists:departments,id'],
            'employee_id' => [$scope === 'employee' ? 'required' : 'prohibited', 'nullable', 'integer', 'exists:employees,id'],
            'grace_minutes' => ['required', 'integer', 'between:0,120'],
            'use_location_check' => ['required', 'boolean'],
            'late_permission_proof_required' => ['sometimes', 'boolean'],
            'correction_proof_required' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        return [
            'department_id' => null,
            'employee_id' => null,
            'effective_to' => null,
            'late_permission_proof_required' => $policy?->late_permission_proof_required ?? false,
            'correction_proof_required' => $policy?->correction_proof_required ?? false,
            ...$data,
        ];
    }
}
