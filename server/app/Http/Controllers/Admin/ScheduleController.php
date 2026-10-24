<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PublishSchedule;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleController extends Controller
{
    public function __construct(private PublishSchedule $schedules) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->range($request, 62);
        $ids = EmployeeController::visibleTo($request->user())->pluck('id');

        $assignments = ShiftAssignment::query()
            ->with('sessions')
            ->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$data['from'], $data['to']])
            ->where('status', '!=', 'CANCELLED')
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->orderBy('work_date')
            ->get();

        return response()->json(['data' => $assignments->map->toApi()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'shift_template_id' => ['required', 'integer', 'exists:shift_templates,id'],
        ]);

        $assignment = $this->schedules->upsert(
            Employee::query()->findOrFail($data['employee_id']),
            $data['work_date'],
            ShiftTemplate::query()->with('sessions')->findOrFail($data['shift_template_id']),
            $request->user(),
        );

        return response()->json(['data' => $assignment->load('sessions')->toApi()], 201);
    }

    public function destroy(ShiftAssignment $assignment): JsonResponse
    {
        Gate::authorize('admin');
        $this->schedules->cancel($assignment);

        return response()->json(['ok' => true]);
    }

    public function publish(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $this->range($request, 62);
        $count = $this->schedules->publish($data['from'], $data['to'], $this->targetIds($request, $data));

        return response()->json(['published' => $count]);
    }

    public function copyWeek(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'different:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        foreach (['from', 'to'] as $key) {
            if (! CarbonImmutable::parse($data[$key])->isMonday()) {
                throw new BusinessRuleException('VALIDATION_ERROR', 'Tanggal awal minggu harus hari Senin.', 422);
            }
        }

        return response()->json($this->schedules->copyWeek($data['from'], $data['to'], $this->targetIds($request, $data), $request->user()));
    }

    private function targetIds(Request $request, array $data): array
    {
        return EmployeeController::visibleTo($request->user())
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->pluck('id')->all();
    }

    private function range(Request $request, int $maxDays): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > $maxDays) {
            throw new BusinessRuleException('VALIDATION_ERROR', "Rentang paling panjang {$maxDays} hari.", 422);
        }

        return $data;
    }
}
