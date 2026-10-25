<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PublishSchedule;
use App\Actions\SwapScheduleCycles;
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

    public function previewSwap(Request $request, SwapScheduleCycles $swaps): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'moves' => ['required', 'array', 'min:1', 'max:20'],
            'moves.*.assignment_id' => ['required', 'integer', 'distinct', 'exists:shift_assignments,id'],
            'moves.*.expected_revision' => ['required', 'integer', 'min:1'],
            'moves.*.to_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'vacancy_decisions' => ['sometimes', 'array', 'max:20'],
            'vacancy_decisions.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'vacancy_decisions.*.work_date' => ['required', 'date_format:Y-m-d'],
            'vacancy_decisions.*.action' => ['required', 'in:roster_off,regular,day_off'],
            'vacancy_decisions.*.reason' => ['required', 'string', 'max:1000'],
            'confirm_cross_unit' => ['sometimes', 'boolean'],
        ]);

        return response()->json($swaps->preview($data['moves'], $data['vacancy_decisions'] ?? [], (bool) ($data['confirm_cross_unit'] ?? false)));
    }

    public function swap(Request $request, SwapScheduleCycles $swaps): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'moves' => ['required', 'array', 'min:1', 'max:20'],
            'moves.*.assignment_id' => ['required', 'integer', 'distinct', 'exists:shift_assignments,id'],
            'moves.*.expected_revision' => ['required', 'integer', 'min:1'],
            'moves.*.to_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'vacancy_decisions' => ['sometimes', 'array', 'max:20'],
            'vacancy_decisions.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'vacancy_decisions.*.work_date' => ['required', 'date_format:Y-m-d'],
            'vacancy_decisions.*.action' => ['required', 'in:roster_off,regular,day_off'],
            'vacancy_decisions.*.reason' => ['required', 'string', 'min:3', 'max:1000'],
            'confirm_cross_unit' => ['sometimes', 'boolean'],
            'preview_token' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return response()->json($swaps->commit(
            $data['moves'], $data['vacancy_decisions'] ?? [], (bool) ($data['confirm_cross_unit'] ?? false),
            $data['preview_token'], $data['reason'], $request->user(),
        ));
    }

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
            'shift_template_id' => ['nullable', 'integer', 'exists:shift_templates,id', 'required_without:sessions'],
            'name' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
            'sessions' => ['required_without:shift_template_id', 'array', 'min:2', 'max:4'],
            'sessions.*.id' => ['nullable', 'integer', 'exists:shift_sessions,id'],
            'sessions.*.seq' => ['required_with:sessions', 'integer', 'between:1,4'],
            'sessions.*.start_date' => ['required_with:sessions', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'sessions.*.start_time' => ['required_with:sessions', 'date_format:H:i'],
            'sessions.*.end_date' => ['required_with:sessions', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'sessions.*.end_time' => ['required_with:sessions', 'date_format:H:i'],
            'sessions.*.unpaid_break_minutes' => ['nullable', 'integer', 'between:0,720'],
        ]);

        if (isset($data['sessions'])) {
            $assignment = $this->schedules->upsertCustom(
                Employee::query()->findOrFail($data['employee_id']),
                $data['work_date'],
                $data,
                $request->user(),
            );

            return response()->json(['data' => $assignment->toApi()], 201);
        }

        $assignment = $this->schedules->upsert(
            Employee::query()->findOrFail($data['employee_id']),
            $data['work_date'],
            ShiftTemplate::query()->with('sessions')->findOrFail($data['shift_template_id']),
            $request->user(),
        );

        return response()->json(['data' => $assignment->load('sessions')->toApi()], 201);
    }

    public function updateSessions(Request $request, ShiftAssignment $assignment): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'sessions' => ['required', 'array', 'min:1', 'max:4'],
            'sessions.*.id' => ['nullable', 'integer'],
            'sessions.*.seq' => ['required', 'integer', 'between:1,4'],
            'sessions.*.start_date' => ['required', 'date_format:Y-m-d'],
            'sessions.*.start_time' => ['required', 'date_format:H:i'],
            'sessions.*.end_date' => ['required', 'date_format:Y-m-d'],
            'sessions.*.end_time' => ['required', 'date_format:H:i'],
            'sessions.*.unpaid_break_minutes' => ['nullable', 'integer', 'between:0,720'],
        ]);

        $assignment = $this->schedules->upsertCustom(
            $assignment->employee,
            $assignment->work_date,
            $data,
            $request->user(),
            $data['expected_revision'],
            $data['reason'],
        );

        return response()->json(['data' => $assignment->toApi()]);
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
