<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SwapScheduleCycles;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\ShiftAssignment;
use App\Models\ShiftExchangeRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftExchangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $scope = $request->query('scope', 'me');
        $status = $request->query('status');

        $query = ShiftExchangeRequest::query()
            ->with([
                'employee.department',
                'employee.position',
                'targetEmployee.department',
                'targetEmployee.position',
                'shiftAssignment.sessions',
                'targetAssignment.sessions',
                'requestedBy',
                'decidedBy',
            ]);

        if ($scope === 'admin' || $scope === 'all') {
            if (! $user->isAdmin()) {
                throw new BusinessRuleException('FORBIDDEN', 'Hanya Admin atau Super Admin yang dapat melihat daftar pengajuan HRD.', 403);
            }
            if (! $user->isSuperAdmin()) {
                $query->where(function ($q) {
                    $q->where('status', '!=', 'PENDING')->orWhere('approval_tier', 'admin');
                });
            }
        } else {
            if (! $employee) {
                return response()->json(['data' => []]);
            }
            $query->where(function ($q) use ($employee) {
                $q->where('employee_id', $employee->id)
                    ->orWhere('target_employee_id', $employee->id);
            });
        }

        if ($status && in_array(strtoupper($status), ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
            $query->where('status', strtoupper($status));
        }

        $items = $query->latest()->limit(100)->get()
            ->map(fn (ShiftExchangeRequest $req) => $this->format($req, $employee?->id));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'shift_assignment_id' => ['required', 'integer', 'exists:shift_assignments,id'],
            'target_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'swap_mode' => ['required', 'in:direct,delegation'],
            'target_assignment_id' => ['nullable', 'required_if:swap_mode,direct', 'integer', 'exists:shift_assignments,id'],
            'vacancy_action' => ['nullable', Rule::in($this->vacancyActionCodes())],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $requesterEmployee = null;
        if (! empty($data['employee_id'])) {
            if (! $user->isAdmin()) {
                throw new BusinessRuleException('FORBIDDEN', 'Hanya admin yang dapat mengajukan tukar shift untuk karyawan lain.', 403);
            }
            $requesterEmployee = Employee::query()->findOrFail($data['employee_id']);
        } else {
            $requesterEmployee = $user->employee;
            if (! $requesterEmployee || $requesterEmployee->status !== 'active') {
                throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Akun tidak terhubung ke karyawan aktif.', 403);
            }
        }

        if ((int) $data['target_employee_id'] === (int) $requesterEmployee->id) {
            throw new BusinessRuleException('INVALID_TARGET', 'Karyawan pengganti harus berbeda dari pemohon.', 422);
        }

        $targetEmployee = Employee::query()->findOrFail($data['target_employee_id']);
        if ($targetEmployee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Karyawan pengganti tidak dalam status aktif.', 422);
        }

        $sourceAssignment = ShiftAssignment::query()->with('sessions')->findOrFail($data['shift_assignment_id']);
        if ((int) $sourceAssignment->employee_id !== (int) $requesterEmployee->id) {
            throw new BusinessRuleException('SCHEDULE_NOT_OWNED', 'Jadwal yang ingin ditukar bukan milik pemohon.', 403);
        }
        if (in_array($sourceAssignment->status, ['CANCELLED'], true)) {
            throw new BusinessRuleException('SCHEDULE_CANCELLED', 'Jadwal yang dipilih sudah dibatalkan.', 422);
        }
        $firstSession = $sourceAssignment->sessions->sortBy('seq')->first();
        if (! $firstSession || $firstSession->start_at->lte(WorkTime::now())) {
            throw new BusinessRuleException('SCHEDULE_PAST', 'Hanya jadwal shift mendatang yang belum dimulai yang dapat ditukar.', 422);
        }

        $targetAssignment = null;
        if ($data['swap_mode'] === 'direct') {
            $targetAssignment = ShiftAssignment::query()->with('sessions')->findOrFail($data['target_assignment_id']);
            if ((int) $targetAssignment->employee_id !== (int) $targetEmployee->id) {
                throw new BusinessRuleException('SCHEDULE_NOT_OWNED', 'Jadwal rekan yang dipilih bukan milik karyawan pengganti.', 422);
            }
            if (in_array($targetAssignment->status, ['CANCELLED'], true)) {
                throw new BusinessRuleException('SCHEDULE_CANCELLED', 'Jadwal rekan pengganti sudah dibatalkan.', 422);
            }
            $firstTargetSession = $targetAssignment->sessions->sortBy('seq')->first();
            if (! $firstTargetSession || $firstTargetSession->start_at->lte(WorkTime::now())) {
                throw new BusinessRuleException('SCHEDULE_PAST', 'Jadwal rekan pengganti harus jadwal mendatang yang belum dimulai.', 422);
            }
        }

        // Check for pending exchange on same source assignment
        $pendingExists = ShiftExchangeRequest::query()
            ->where('status', 'PENDING')
            ->where(function ($q) use ($sourceAssignment, $targetAssignment) {
                $q->where('shift_assignment_id', $sourceAssignment->id)
                    ->orWhere('target_assignment_id', $sourceAssignment->id);
                if ($targetAssignment) {
                    $q->orWhere('shift_assignment_id', $targetAssignment->id)
                        ->orWhere('target_assignment_id', $targetAssignment->id);
                }
            })
            ->exists();

        if ($pendingExists) {
            throw new BusinessRuleException('EXCHANGE_PENDING', 'Salah satu jadwal yang dipilih sudah memiliki pengajuan tukar shift yang masih menunggu persetujuan.', 409);
        }

        $item = ShiftExchangeRequest::query()->create([
            'employee_id' => $requesterEmployee->id,
            'requested_by' => $user->id,
            'shift_assignment_id' => $sourceAssignment->id,
            'target_employee_id' => $targetEmployee->id,
            'swap_mode' => $data['swap_mode'],
            'target_assignment_id' => $targetAssignment?->id,
            'vacancy_action' => $data['swap_mode'] === 'delegation' ? ($data['vacancy_action'] ?? 'roster_off') : null,
            'reason' => trim($data['reason']),
            'status' => 'PENDING',
            'approval_tier' => $requesterEmployee->approvalTierFor($sourceAssignment->work_date),
        ]);

        Audit::log('shift_exchange.requested', $item, null, [
            ...$item->attributesToArray(),
            'source_work_date' => $sourceAssignment->work_date,
            'target_work_date' => $targetAssignment?->work_date,
        ]);

        return response()->json(['data' => $this->format($item, $requesterEmployee->id)], 201);
    }

    public function cancel(Request $request, ShiftExchangeRequest $shiftExchange): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $canCancel = $user->isAdmin()
            || ($employee && $shiftExchange->employee_id === $employee->id)
            || ($employee && $shiftExchange->target_employee_id === $employee->id);

        if (! $canCancel) {
            throw new BusinessRuleException('FORBIDDEN', 'Anda tidak memiliki hak untuk membatalkan pengajuan ini.', 403);
        }

        $shiftExchange = DB::transaction(function () use ($shiftExchange, $user): ShiftExchangeRequest {
            $shiftExchange = ShiftExchangeRequest::query()->lockForUpdate()->findOrFail($shiftExchange->id);
            if ($shiftExchange->status !== 'PENDING') {
                throw new BusinessRuleException('STATE_CONFLICT', 'Hanya pengajuan berstatus PENDING yang dapat dibatalkan.', 409);
            }

            $before = $shiftExchange->attributesToArray();
            $shiftExchange->update([
                'status' => 'CANCELLED',
                'decided_by' => $user->id,
                'decided_at' => WorkTime::now(),
                'decision_reason' => 'Dibatalkan oleh pemohon/karyawan',
            ]);

            Audit::log('shift_exchange.cancelled', $shiftExchange, $before, [
                'cancelled_by' => $user->id,
            ]);

            return $shiftExchange;
        });

        return response()->json(['data' => $this->format($shiftExchange->fresh(), $employee?->id)]);
    }

    public function decide(Request $request, ShiftExchangeRequest $shiftExchange, SwapScheduleCycles $swaps): JsonResponse
    {
        $user = $request->user();
        if (! $user->canApproveTier($shiftExchange->approval_tier)) {
            throw new BusinessRuleException('FORBIDDEN', 'Pengajuan ini membutuhkan persetujuan Admin atau Super Admin.', 403);
        }

        if ($shiftExchange->status !== 'PENDING') {
            throw new BusinessRuleException('STATE_CONFLICT', 'Pengajuan ini sudah pernah diproses sebelumnya.', 409);
        }

        $allowedVacancyActions = $this->vacancyActionCodes();
        if ($shiftExchange->vacancy_action) {
            $allowedVacancyActions[] = $shiftExchange->vacancy_action;
        }
        $data = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'vacancy_action' => ['nullable', Rule::in(array_unique($allowedVacancyActions))],
            'confirm_cross_unit' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($shiftExchange, $user, $data, $swaps): JsonResponse {
            $shiftExchange = ShiftExchangeRequest::query()->lockForUpdate()->findOrFail($shiftExchange->id);
            if ($shiftExchange->status !== 'PENDING') {
                throw new BusinessRuleException('STATE_CONFLICT', 'Pengajuan ini sudah pernah diproses sebelumnya.', 409);
            }

            return $this->applyDecision($shiftExchange, $user, $data, $swaps);
        });
    }

    /** @param array{decision: string, reason?: ?string, vacancy_action?: ?string, confirm_cross_unit?: bool} $data */
    private function applyDecision(ShiftExchangeRequest $shiftExchange, User $user, array $data, SwapScheduleCycles $swaps): JsonResponse
    {
        if ($data['decision'] === 'REJECTED') {
            $before = $shiftExchange->attributesToArray();
            $shiftExchange->update([
                'status' => 'REJECTED',
                'decided_by' => $user->id,
                'decided_at' => WorkTime::now(),
                'decision_reason' => trim($data['reason'] ?? 'Ditolak oleh HRD'),
            ]);

            Audit::log('shift_exchange.rejected', $shiftExchange, $before, [
                'decided_by' => $user->id,
                'reason' => $shiftExchange->decision_reason,
            ]);

            return response()->json(['data' => $this->format($shiftExchange->fresh())]);
        }

        // APPROVED: Execute swap via SwapScheduleCycles
        $sourceAssignment = $shiftExchange->shiftAssignment()->with('sessions')->lockForUpdate()->first();
        if (! $sourceAssignment || in_array($sourceAssignment->status, ['CANCELLED'], true)) {
            throw new BusinessRuleException('SCHEDULE_NOT_FOUND', 'Jadwal shift pemohon sudah tidak aktif atau telah dibatalkan.', 409);
        }

        $moves = [
            [
                'assignment_id' => $sourceAssignment->id,
                'expected_revision' => $sourceAssignment->revision,
                'to_employee_id' => $shiftExchange->target_employee_id,
            ],
        ];
        $decisions = [];

        if ($shiftExchange->swap_mode === 'direct') {
            $targetAssignment = $shiftExchange->targetAssignment()->with('sessions')->lockForUpdate()->first();
            if (! $targetAssignment || in_array($targetAssignment->status, ['CANCELLED'], true)) {
                throw new BusinessRuleException('SCHEDULE_NOT_FOUND', 'Jadwal shift rekan pengganti sudah tidak aktif atau telah dibatalkan.', 409);
            }
            $moves[] = [
                'assignment_id' => $targetAssignment->id,
                'expected_revision' => $targetAssignment->revision,
                'to_employee_id' => $shiftExchange->employee_id,
            ];
            if ($sourceAssignment->work_date !== $targetAssignment->work_date) {
                $action = $data['vacancy_action'] ?? $shiftExchange->vacancy_action ?? 'roster_off';
                $decisions[] = [
                    'employee_id' => $shiftExchange->employee_id,
                    'work_date' => $sourceAssignment->work_date,
                    'action' => $action,
                    'reason' => trim($data['reason'] ?? 'Tukar jadwal tanggal berbeda'),
                ];
                $decisions[] = [
                    'employee_id' => $shiftExchange->target_employee_id,
                    'work_date' => $targetAssignment->work_date,
                    'action' => $action,
                    'reason' => trim($data['reason'] ?? 'Tukar jadwal tanggal berbeda'),
                ];
            }
        } else {
            // Delegation: Source employee loses shift cycle
            $action = $data['vacancy_action'] ?? $shiftExchange->vacancy_action ?? 'roster_off';
            $decisions[] = [
                'employee_id' => $shiftExchange->employee_id,
                'work_date' => $sourceAssignment->work_date,
                'action' => $action,
                'reason' => trim($data['reason'] ?? 'Pelimpahan shift disetujui HRD'),
            ];
        }

        $confirmCrossUnit = (bool) ($data['confirm_cross_unit'] ?? false);
        $preview = $swaps->preview($moves, $decisions, $confirmCrossUnit);

        if (! $preview['can_commit']) {
            throw new BusinessRuleException('SWAP_BLOCKED', 'Pertukaran shift belum dapat disetujui.', 422, [
                'blockers' => $preview['blockers'],
                'vacancies' => $preview['vacancies'],
                'cross_unit_confirmation_required' => $preview['cross_unit_confirmation_required'] ?? false,
            ]);
        }

        $swapReason = trim($data['reason'] ?? "Persetujuan pengajuan tukar shift #{$shiftExchange->id}");
        $swapResult = $swaps->commit(
            $moves,
            $decisions,
            $confirmCrossUnit,
            $preview['preview_token'],
            $swapReason,
            $user,
        );

        $before = $shiftExchange->attributesToArray();
        $shiftExchange->update([
            'status' => 'APPROVED',
            'decided_by' => $user->id,
            'decided_at' => WorkTime::now(),
            'decision_reason' => $swapReason,
        ]);

        Audit::log('shift_exchange.approved', $shiftExchange, $before, [
            'decided_by' => $user->id,
            'swap_id' => $swapResult['swap_id'] ?? null,
        ]);

        return response()->json([
            'data' => $this->format($shiftExchange->fresh()),
            'swap' => $swapResult,
        ]);
    }

    public function colleagues(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'offset' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $offset = (int) ($filters['offset'] ?? 0);
        $limit = (int) ($filters['limit'] ?? 5);
        $search = trim($filters['search'] ?? '');
        $user = $request->user();
        $currentEmployeeId = $user->employee?->id;

        $query = Employee::query()
            ->with('department:id,name', 'position:id,name')
            ->where('status', 'active');

        if (! $user->isAdmin()) {
            $currentEmployee = $user->employee;
            $departmentId = $currentEmployee?->department_id;
            if (! $currentEmployee || $currentEmployee->status !== 'active' || ! $departmentId) {
                return response()->json([
                    'data' => [],
                    'meta' => ['offset' => $offset, 'limit' => $limit, 'next_offset' => $offset, 'has_more' => false],
                ]);
            }
            $query->where('department_id', $departmentId)->where('id', '!=', $currentEmployeeId);
        }

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where('name', 'like', $like)
                    ->orWhereHas('department', fn ($department) => $department->where('name', 'like', $like))
                    ->orWhereHas('position', fn ($position) => $position->where('name', 'like', $like));
            });
        }

        $employees = $query->orderBy('name')->orderBy('id')->offset($offset)->limit($limit + 1)->get();
        $hasMore = $employees->count() > $limit;
        $employees = $employees->take($limit)->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->name,
            'department' => $e->department?->name,
            'position' => $e->position?->name,
        ]);

        return response()->json([
            'data' => $employees->values(),
            'meta' => [
                'offset' => $offset,
                'limit' => $limit,
                'next_offset' => $offset + $employees->count(),
                'has_more' => $hasMore,
            ],
        ]);
    }

    public function employeeSchedules(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        if ($employee->status !== 'active') {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', 'Karyawan tidak aktif.', 422);
        }
        $currentEmployee = $user->employee;
        if (! $user->isAdmin() && (! $currentEmployee || $currentEmployee->status !== 'active' || ! $currentEmployee->department_id || $employee->department_id !== $currentEmployee->department_id)) {
            throw new BusinessRuleException('FORBIDDEN', 'Jadwal hanya tersedia untuk rekan satu departemen.', 403);
        }

        $from = $data['from'] ?? WorkTime::today();
        $to = $data['to'] ?? CarbonImmutable::parse($from)->addDays(30)->toDateString();

        $assignments = ShiftAssignment::query()
            ->with('sessions')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->where('status', 'PUBLISHED')
            ->orderBy('work_date')
            ->get()
            ->filter(function (ShiftAssignment $ass) {
                $first = $ass->sessions->sortBy('seq')->first();

                return $first && $first->start_at->gt(WorkTime::now());
            })
            ->values()
            ->map(fn (ShiftAssignment $ass) => [
                'id' => $ass->id,
                'work_date' => $ass->work_date,
                'template_name' => $ass->template_name,
                'status' => $ass->status,
                'revision' => $ass->revision,
                'scheduled_minutes' => $ass->scheduled_minutes,
                'sessions' => $ass->sessions->sortBy('seq')->map(fn ($s) => [
                    'id' => $s->id,
                    'seq' => $s->seq,
                    'local_start' => $s->local_start,
                    'local_end' => $s->local_end,
                ])->values(),
            ]);

        return response()->json(['data' => $assignments]);
    }

    private function vacancyActionCodes(): array
    {
        return ['roster_off', ...LeaveType::query()->where('is_active', true)->pluck('code')->all()];
    }

    private function format(ShiftExchangeRequest $req, ?int $currentEmployeeId = null): array
    {
        $req->loadMissing(
            'employee.department',
            'employee.position',
            'targetEmployee.department',
            'targetEmployee.position',
            'shiftAssignment.sessions',
            'targetAssignment.sessions',
            'requestedBy',
            'decidedBy',
        );

        return [
            'id' => $req->id,
            'status' => $req->status,
            'approval_tier' => $req->approval_tier,
            'swap_mode' => $req->swap_mode,
            'vacancy_action' => $req->vacancy_action,
            'reason' => $req->reason,
            'decision_reason' => $req->decision_reason,
            'decided_by_name' => $req->decidedBy?->name,
            'decided_at' => $req->decided_at?->toIso8601String(),
            'created_at' => $req->created_at?->toIso8601String(),
            'is_requester' => $currentEmployeeId !== null && $req->employee_id === $currentEmployeeId,
            'employee' => $req->employee ? [
                'id' => $req->employee->id,
                'code' => $req->employee->code,
                'name' => $req->employee->name,
                'department' => $req->employee->department?->name,
                'position' => $req->employee->position?->name,
            ] : null,
            'target_employee' => $req->targetEmployee ? [
                'id' => $req->targetEmployee->id,
                'code' => $req->targetEmployee->code,
                'name' => $req->targetEmployee->name,
                'department' => $req->targetEmployee->department?->name,
                'position' => $req->targetEmployee->position?->name,
            ] : null,
            'assignment' => $req->shiftAssignment ? [
                'id' => $req->shiftAssignment->id,
                'work_date' => $req->shiftAssignment->work_date,
                'template_name' => $req->shiftAssignment->template_name,
                'status' => $req->shiftAssignment->status,
                'revision' => $req->shiftAssignment->revision,
                'scheduled_minutes' => $req->shiftAssignment->scheduled_minutes,
                'sessions' => $req->shiftAssignment->sessions->sortBy('seq')->map(fn ($s) => [
                    'id' => $s->id,
                    'seq' => $s->seq,
                    'local_start' => $s->local_start,
                    'local_end' => $s->local_end,
                ])->values(),
            ] : null,
            'target_assignment' => $req->targetAssignment ? [
                'id' => $req->targetAssignment->id,
                'work_date' => $req->targetAssignment->work_date,
                'template_name' => $req->targetAssignment->template_name,
                'status' => $req->targetAssignment->status,
                'revision' => $req->targetAssignment->revision,
                'scheduled_minutes' => $req->targetAssignment->scheduled_minutes,
                'sessions' => $req->targetAssignment->sessions->sortBy('seq')->map(fn ($s) => [
                    'id' => $s->id,
                    'seq' => $s->seq,
                    'local_start' => $s->local_start,
                    'local_end' => $s->local_end,
                ])->values(),
            ] : null,
        ];
    }
}
