<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\LeaveYearQuota;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json(['data' => LeaveType::query()->orderBy('name')->get()]);
    }

    public function mobileIndex(): JsonResponse
    {
        return response()->json(['data' => LeaveType::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name')],
            'default_quota' => ['required', 'integer', 'between:0,60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['code'] = $this->makeCode($data['name']);
        $data['is_active'] ??= true;

        $type = DB::transaction(function () use ($data): LeaveType {
            $type = LeaveType::query()->create($data);
            foreach (LeaveYear::query()->lockForUpdate()->pluck('id') as $yearId) {
                LeaveYearQuota::query()->firstOrCreate(
                    ['leave_year_id' => $yearId, 'leave_type_id' => $type->id],
                    ['quota' => 0],
                );
            }
            Audit::log('leave.types_created', $type, null, $data);

            return $type;
        });

        return response()->json(['data' => $type], 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name')->ignore($leaveType->id)],
            'default_quota' => ['required', 'integer', 'between:0,60'],
            'is_active' => ['required', 'boolean'],
        ]);

        $before = $leaveType->attributesToArray();
        $leaveType->update($data);
        Audit::log('leave.types_updated', $leaveType, $before, $data);

        return response()->json(['data' => $leaveType->fresh()]);
    }

    private function makeCode(string $name): string
    {
        $base = Str::slug($name, '_');
        $base = substr($base !== '' ? $base : 'cuti', 0, 16);
        $candidate = $base;
        $suffix = 1;

        while (LeaveType::query()->where('code', $candidate)->exists()) {
            $suffix++;
            $ending = '_'.$suffix;
            $candidate = substr($base, 0, 16 - strlen($ending)).$ending;
        }

        return $candidate;
    }
}
