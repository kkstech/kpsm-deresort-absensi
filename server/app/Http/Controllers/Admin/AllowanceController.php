<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowancePolicy;
use App\Models\AllowanceResult;
use App\Models\AttendancePeriod;
use App\Models\EmployeeAllowanceOverride;
use App\Models\ShiftAssignment;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AllowanceController extends Controller
{
    public function policies(): JsonResponse
    {
        Gate::authorize('admin');

        return response()->json(['data' => AllowancePolicy::query()->with('level:id,code,name')->orderByDesc('effective_from')->get()]);
    }

    public function overrides(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $employeeId = $request->validate(['employee_id' => ['nullable', 'integer', 'exists:employees,id']])['employee_id'] ?? null;

        return response()->json(['data' => EmployeeAllowanceOverride::query()->with('employee:id,code,name,level_id')
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->orderByDesc('effective_from')->get()]);
    }

    public function previewPolicy(Request $request): JsonResponse
    {
        Gate::authorize('admin');

        return $this->preview($request, 'policy');
    }

    public function storePolicy(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $this->policyInput($request);
        $this->assertRetroPreview($data, 'policy');

        $policy = DB::transaction(function () use ($data, $request) {
            $this->assertNoFinalizedMonths($data['effective_from'], $data['effective_to'] ?? WorkTime::today());
            $this->closePreviousPolicy(AllowancePolicy::query()->where('level_id', $data['level_id']), $data['effective_from']);
            $item = AllowancePolicy::query()->create([...$data, 'created_by' => $request->user()->id]);
            if ($data['effective_from'] < WorkTime::today()) {
                $this->recalculateSnapshots('policy', $item, null, $data['retro_reason']);
            }
            Audit::log('allowances.policy_created', $item, null, [...$item->attributesToArray(), 'retro_reason' => $data['retro_reason'] ?? null]);

            return $item->load('level');
        });

        return response()->json(['data' => $policy], 201);
    }

    public function previewOverride(Request $request): JsonResponse
    {
        Gate::authorize('admin');

        return $this->preview($request, 'override');
    }

    public function storeOverride(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'meal_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'transport_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'retro_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'preview_token' => ['nullable', 'uuid'],
        ]);
        if ($data['meal_amount'] === null && $data['transport_amount'] === null) {
            throw ValidationException::withMessages(['meal_amount' => 'Isi minimal satu nilai tunjangan.']);
        }
        $this->assertRetroPreview($data, 'override');

        $override = DB::transaction(function () use ($data, $request) {
            $this->assertNoFinalizedMonths($data['effective_from'], $data['effective_to'] ?? WorkTime::today());
            $this->closePreviousPolicy(EmployeeAllowanceOverride::query()->where('employee_id', $data['employee_id']), $data['effective_from']);
            $item = EmployeeAllowanceOverride::query()->create([...$data, 'created_by' => $request->user()->id]);
            if ($data['effective_from'] < WorkTime::today()) {
                $this->recalculateSnapshots('override', $item, (int) $data['employee_id'], $data['retro_reason']);
            }
            Audit::log('allowances.override_created', $item, null, [...$item->attributesToArray(), 'retro_reason' => $data['retro_reason'] ?? null]);

            return $item->load('employee:id,code,name');
        });

        return response()->json(['data' => $override], 201);
    }

    public function recap(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if (CarbonImmutable::parse($range['from'])->diffInDays(CarbonImmutable::parse($range['to'])) > 62) {
            throw ValidationException::withMessages(['to' => 'Rentang paling panjang 62 hari.']);
        }
        $rows = AllowanceResult::query()->with('employee:id,code,name,department_id', 'employee.department:id,name', 'assignment:id,work_date,template_name,status')
            ->whereBetween('work_date', [$range['from'], $range['to']])->orderBy('work_date')->orderBy('employee_id')->get();

        return response()->json(['data' => $rows, 'summary' => [
            'eligible_cycles' => $rows->where('status', 'ELIGIBLE')->count(),
            'pending_cycles' => $rows->where('status', 'PENDING_VERIFICATION')->count(),
            'configuration_required' => $rows->where('status', 'CONFIGURATION_REQUIRED')->count(),
            'meal_amount' => $rows->sum('meal_amount'),
            'transport_amount' => $rows->sum('transport_amount'),
        ]]);
    }

    private function preview(Request $request, string $kind): JsonResponse
    {
        $data = $kind === 'policy' ? $this->policyInput($request, false) : $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'meal_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'], 'transport_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        if ($kind === 'override' && $data['meal_amount'] === null && $data['transport_amount'] === null) {
            throw ValidationException::withMessages(['meal_amount' => 'Isi minimal satu nilai tunjangan.']);
        }
        $ids = $this->affectedAssignments($kind, $data)->pluck('id')->all();
        $snapshots = AllowanceResult::query()->whereIn('shift_assignment_id', $ids)->get();
        $payload = $this->previewPayload($kind, $data);
        $token = (string) Str::uuid();
        Cache::put("allowance-preview:{$token}", ['payload' => $payload, 'ids' => $ids], now()->addMinutes(15));

        return response()->json(['data' => [
            'preview_token' => $token,
            'affected_cycles' => count($ids),
            'snapshots_to_recalculate' => $snapshots->count(),
            'current_meal_amount' => $snapshots->sum('meal_amount'),
            'current_transport_amount' => $snapshots->sum('transport_amount'),
            'periods_open' => $this->periodsOpen($data['effective_from'], $data['effective_to'] ?? WorkTime::today()),
        ]]);
    }

    private function policyInput(Request $request, bool $includeRetro = true): array
    {
        return $request->validate([
            'level_id' => ['required', 'integer', 'exists:employee_levels,id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'meal_amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'transport_amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            ...($includeRetro ? ['retro_reason' => ['nullable', 'string', 'min:3', 'max:1000'], 'preview_token' => ['nullable', 'uuid']] : []),
        ]);
    }

    private function assertRetroPreview(array $data, string $kind): void
    {
        if ($data['effective_from'] >= WorkTime::today()) {
            return;
        }
        if (empty($data['retro_reason']) || empty($data['preview_token'])) {
            throw ValidationException::withMessages(['retro_reason' => 'Perubahan berlaku surut memerlukan pratinjau dan alasan.']);
        }
        $cached = Cache::get("allowance-preview:{$data['preview_token']}");
        if (! $cached || $cached['payload'] !== $this->previewPayload($kind, $data)) {
            abort(409, 'Pratinjau tunjangan kedaluwarsa atau nilainya berubah. Buat pratinjau baru.');
        }
        $ids = $this->affectedAssignments($kind, $data)->pluck('id')->all();
        if ($ids !== $cached['ids']) {
            abort(409, 'Jadwal berubah sejak pratinjau dibuat. Buat pratinjau baru.');
        }
        $this->assertNoFinalizedMonths($data['effective_from'], $data['effective_to'] ?? WorkTime::today());
        Cache::forget("allowance-preview:{$data['preview_token']}");
    }

    private function previewPayload(string $kind, array $data): array
    {
        unset($data['preview_token'], $data['retro_reason']);
        ksort($data);

        return ['kind' => $kind, 'data' => $data];
    }

    private function affectedAssignments(string $kind, array $data)
    {
        $query = ShiftAssignment::query()->where('work_date', '>=', $data['effective_from'])
            ->when($data['effective_to'] ?? null, fn ($q, $to) => $q->where('work_date', '<=', $to));
        if ($kind === 'override') {
            $query->where('employee_id', $data['employee_id']);
        } else {
            $levelId = (int) $data['level_id'];
            $query->whereHas('employee', fn ($employee) => $employee->where('level_id', $levelId)
                ->orWhereHas('levelAssignments', fn ($levels) => $levels->where('level_id', $levelId)));
        }

        return $query->orderBy('id')->get();
    }

    private function closePreviousPolicy($query, string $effectiveFrom): void
    {
        $base = clone $query;
        $previous = (clone $base)->where('effective_from', '<', $effectiveFrom)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom))
            ->orderByDesc('effective_from')->lockForUpdate()->first();
        $future = (clone $base)->where('effective_from', '>=', $effectiveFrom)->exists();
        if ($future) {
            abort(409, 'Sudah ada tarif dengan tanggal berlaku yang sama atau lebih baru.');
        }
        if ($previous) {
            $previous->update(['effective_to' => CarbonImmutable::parse($effectiveFrom)->subDay()->toDateString()]);
        }
    }

    private function periodsOpen(string $from, string $to): bool
    {
        $fromMonth = substr($from, 0, 7);
        $toMonth = substr($to, 0, 7);

        return ! AttendancePeriod::query()->whereBetween('month', [$fromMonth, $toMonth])->where('status', 'FINALIZED')->exists();
    }

    private function assertNoFinalizedMonths(string $from, string $to): void
    {
        if (! $this->periodsOpen($from, $to)) {
            abort(409, 'Perubahan tarif menyentuh periode yang sudah difinalisasi.');
        }
    }

    private function recalculateSnapshots(string $kind, $policy, ?int $employeeId, string $reason): void
    {
        $from = $policy->effective_from;
        $to = $policy->effective_to ?? WorkTime::today();
        $query = AllowanceResult::query()->whereBetween('work_date', [$from, $to])->with('employee');
        if ($kind === 'policy') {
            $query->where('level_id', $policy->level_id);
        } else {
            $query->where('employee_id', $employeeId);
        }
        foreach ($query->get() as $result) {
            $before = $result->attributesToArray();
            $override = EmployeeAllowanceOverride::query()->where('employee_id', $result->employee_id)
                ->where('effective_from', '<=', $result->work_date)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $result->work_date))
                ->orderByDesc('effective_from')->first();
            $levelPolicy = AllowancePolicy::query()->where('level_id', $result->level_id)
                ->where('effective_from', '<=', $result->work_date)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $result->work_date))
                ->orderByDesc('effective_from')->first();
            $meal = $override?->meal_amount ?? $levelPolicy?->meal_amount;
            $transport = $override?->transport_amount ?? $levelPolicy?->transport_amount;
            $snapshot = [...($result->rate_snapshot ?? []), 'policy_id' => $levelPolicy?->id, 'override_id' => $override?->id,
                'effective_date' => $result->work_date, 'configured' => $levelPolicy !== null,
                'meal_amount' => (int) ($meal ?? 0), 'transport_amount' => (int) ($transport ?? 0), 'retro_reason' => $reason];
            $result->update([
                'rate_snapshot' => $snapshot,
                'status' => $levelPolicy ? ($result->verified_minutes > 0 ? 'ELIGIBLE' : $result->status) : 'CONFIGURATION_REQUIRED',
                'meal_amount' => $levelPolicy && $result->verified_minutes > 0 ? $snapshot['meal_amount'] : 0,
                'transport_amount' => $levelPolicy && $result->verified_minutes > 0 ? $snapshot['transport_amount'] : 0,
                'revision' => $result->revision + 1,
                'calculated_at' => WorkTime::now(),
            ]);
            Audit::log('allowances.retro_recalculated', $result, $before, [...$result->attributesToArray(), 'reason' => $reason]);
        }
    }
}
