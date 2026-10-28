<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ManageOvertime;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OvertimeOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => []]);
        }

        $items = OvertimeOrder::query()
            ->with(['session', 'employee.department', 'decisionMaker'])
            ->where('employee_id', $employee->id)
            ->latest('work_date')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map->toApi();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, ManageOvertime $action): JsonResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'is_cross_day' => ['nullable', 'boolean'],
        ]);

        $order = $action->submitRequest($employee, $data);

        return response()->json(['data' => $order->load(['session', 'employee.department'])->toApi()], 201);
    }

    public function consent(Request $request, OvertimeOrder $order, ManageOvertime $action): JsonResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $action->submitConsent($order, (bool) $data['accepted'], $data['notes'] ?? null, $employee);

        return response()->json(['data' => $order->load(['session', 'employee.department'])->toApi()]);
    }

    public function cancel(Request $request, OvertimeOrder $order, ManageOvertime $action): JsonResponse
    {
        $employee = $this->employee($request);
        if ($order->employee_id !== $employee->id) {
            throw new BusinessRuleException('FORBIDDEN', 'Bukan pengajuan lembur Anda.', 403);
        }

        $order = $action->cancel($order, $request->user());

        return response()->json(['data' => $order->load(['session', 'employee.department'])->toApi()]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        if (! $employee || $employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
        }

        return $employee;
    }
}
