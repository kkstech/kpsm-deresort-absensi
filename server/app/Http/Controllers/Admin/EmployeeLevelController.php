<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeLevelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => EmployeeLevel::query()->withCount('employees')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:employee_levels,code'], 'name' => ['required', 'string', 'max:100', 'unique:employee_levels,name'], 'requires_super_admin_approval' => ['sometimes', 'boolean']]);
        $level = EmployeeLevel::query()->create([
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'requires_super_admin_approval' => $data['requires_super_admin_approval'] ?? false,
        ]);
        Audit::log('employee_levels.created', $level);

        return response()->json(['data' => $level], 201);
    }

    public function update(Request $request, EmployeeLevel $level): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate(['code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('employee_levels', 'code')->ignore($level->id)], 'name' => ['required', 'string', 'max:100', Rule::unique('employee_levels', 'name')->ignore($level->id)], 'requires_super_admin_approval' => ['sometimes', 'boolean']]);
        $before = $level->attributesToArray();
        $level->update([
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'requires_super_admin_approval' => $data['requires_super_admin_approval'] ?? $level->requires_super_admin_approval,
        ]);
        Audit::log('employee_levels.updated', $level, $before);

        return response()->json(['data' => $level]);
    }

    public function destroy(EmployeeLevel $level): JsonResponse
    {
        Gate::authorize('admin');
        $before = $level->attributesToArray();
        $level->delete();
        Audit::log('employee_levels.deleted', $level, $before, []);

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'level_id' => ['required', 'integer', 'exists:employee_levels,id'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WorkTime::today()],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $assignment = DB::transaction(function () use ($employee, $data, $request) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($locked->levelAssignments()->where('effective_from', '>=', $data['effective_from'])->exists()) {
                abort(409, 'Sudah ada perubahan level pada tanggal tersebut atau sesudahnya.');
            }
            $previous = $locked->levelAssignments()->where('effective_from', '<', $data['effective_from'])->orderByDesc('effective_from')->lockForUpdate()->first();
            $before = $previous?->attributesToArray();
            if ($previous && (! $previous->effective_to || $previous->effective_to >= $data['effective_from'])) {
                $previous->update(['effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()]);
            }
            $assignment = $locked->levelAssignments()->create([
                'level_id' => $data['level_id'], 'effective_from' => $data['effective_from'],
                'reason' => $data['reason'], 'created_by' => $request->user()->id,
            ]);
            if ($data['effective_from'] <= WorkTime::today()) {
                $locked->update(['level_id' => $data['level_id']]);
            }
            Audit::log('employee.level_assigned', $assignment, $before, [...$assignment->attributesToArray(), 'reason' => $data['reason']]);

            return $assignment->load('level');
        });

        return response()->json(['data' => $assignment]);
    }

    public function history(Employee $employee): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json(['data' => $employee->levelAssignments()->with('level:id,code,name')->get()]);
    }
}
