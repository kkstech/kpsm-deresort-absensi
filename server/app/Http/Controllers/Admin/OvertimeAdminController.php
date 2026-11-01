<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ManageOvertime;
use App\Actions\WorkCycleMinutes;
use App\Http\Controllers\Controller;
use App\Models\OvertimeOrder;
use App\Models\ShiftAssignment;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OvertimeAdminController extends Controller
{
    public function recommendations(Request $request, WorkCycleMinutes $workCycleMinutes): JsonResponse
    {
        Gate::authorize('admin');

        $visibleEmployeeIds = EmployeeController::visibleTo($request->user())->select('id');
        $items = ShiftAssignment::query()
            ->with(['employee.department', 'employee.position', 'sessions.attendanceRecord'])
            ->where('work_date', WorkTime::today())
            ->where('status', '!=', 'CANCELLED')
            ->whereIn('employee_id', $visibleEmployeeIds)
            ->whereHas('employee', fn ($query) => $query->where('status', 'active'))
            ->get()
            ->map(function (ShiftAssignment $assignment) use ($workCycleMinutes) {
                $workedMinutes = $workCycleMinutes->forAssignment($assignment);
                if ($workedMinutes < 11 * 60 || $workedMinutes >= 12 * 60) {
                    return null;
                }

                return [
                    'employee' => [
                        'id' => $assignment->employee_id,
                        'name' => $assignment->employee?->name,
                        'department' => $assignment->employee?->department?->name,
                        'position' => $assignment->employee?->position?->name,
                    ],
                    'work_date' => $assignment->work_date,
                    'shift' => $assignment->template_name,
                    'worked_minutes' => $workedMinutes,
                    'minutes_to_threshold' => 12 * 60 - $workedMinutes,
                ];
            })
            ->filter()
            ->sortBy('minutes_to_threshold')
            ->values();

        return response()->json([
            'data' => $items,
            'meta' => ['recommendation_minutes' => 11 * 60, 'threshold_minutes' => 12 * 60],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('admin');

        $visibleIds = EmployeeController::visibleTo($request->user())->pluck('id');

        $query = OvertimeOrder::query()
            ->with(['session', 'employee.department', 'decisionMaker'])
            ->whereIn('employee_id', $visibleIds);

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('work_date', [$request->input('from'), $request->input('to')]);
        } elseif ($request->filled('month')) {
            $start = CarbonImmutable::parse($request->input('month'))->startOfMonth()->toDateString();
            $end = CarbonImmutable::parse($request->input('month'))->endOfMonth()->toDateString();
            $query->whereBetween('work_date', [$start, $end]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $request->input('department_id')));
        }

        $items = $query->latest('work_date')->latest('id')->limit(200)->get()->map->toApi();

        return response()->json(['data' => $items]);
    }

    public function order(Request $request, ManageOvertime $action): JsonResponse
    {
        Gate::authorize('admin');

        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'is_cross_day' => ['nullable', 'boolean'],
        ]);

        $orders = $action->createOrders($data, $request->user());

        return response()->json([
            'data' => collect($orders)->map(fn (OvertimeOrder $order) => $order->load(['session', 'employee.department'])->toApi()),
        ], 201);
    }

    public function decision(Request $request, OvertimeOrder $order, ManageOvertime $action): JsonResponse
    {
        Gate::authorize('admin');

        $data = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $action->decide($order, $data['decision'], $data['notes'] ?? null, $request->user());

        return response()->json(['data' => $order->load(['session', 'employee.department'])->toApi()]);
    }

    public function cancel(Request $request, OvertimeOrder $order, ManageOvertime $action): JsonResponse
    {
        Gate::authorize('admin');

        $order = $action->cancel($order, $request->user());

        return response()->json(['data' => $order->load(['session', 'employee.department'])->toApi()]);
    }
}
