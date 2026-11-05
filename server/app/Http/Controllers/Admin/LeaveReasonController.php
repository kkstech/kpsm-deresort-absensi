<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveReason;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeaveReasonController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json(['data' => LeaveReason::query()->orderBy('id')->get()]);
    }

    public function mobileIndex(): JsonResponse
    {
        return response()->json([
            'data' => LeaveReason::query()->where('is_active', true)->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('leave_reasons', 'name')],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['is_active'] ??= true;

        $reason = LeaveReason::query()->create($data);
        Audit::log('leave.reasons_created', $reason, null, $data);

        return response()->json(['data' => $reason], 201);
    }

    public function update(Request $request, LeaveReason $leaveReason): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('leave_reasons', 'name')->ignore($leaveReason->id)],
            'is_active' => ['required', 'boolean'],
        ]);

        $before = $leaveReason->attributesToArray();
        $leaveReason->update($data);
        Audit::log('leave.reasons_updated', $leaveReason, $before, $data);

        return response()->json(['data' => $leaveReason->fresh()]);
    }
}
