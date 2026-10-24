<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PublishSchedule
{
    /** Buat atau ganti jadwal satu karyawan di satu tanggal kerja. */
    public function upsert(Employee $employee, string $workDate, ShiftTemplate $template, User $actor): ShiftAssignment
    {
        return DB::transaction(function () use ($employee, $workDate, $template, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            AttendancePeriod::assertOpen($workDate);
            $this->assertEmployed($employee, $workDate);

            $sessions = $this->buildSessions($employee, $workDate, $template);
            if (CarbonImmutable::parse($sessions[0]['start_at'])->lte(WorkTime::now())) {
                throw new BusinessRuleException('SESSION_STARTED', 'Jadwal yang sudah mulai tidak bisa dibuat atau diubah. Pakai koreksi.');
            }

            $assignment = ShiftAssignment::query()->where('employee_id', $employee->id)->where('work_date', $workDate)->first();
            if ($assignment && $assignment->status !== 'CANCELLED') {
                $this->assertEditable($assignment);
            }
            $this->assertNoOverlap($employee, $workDate, $sessions, $assignment?->id);

            $before = $assignment?->load('sessions')->toArray();
            $assignment ??= new ShiftAssignment(['employee_id' => $employee->id, 'work_date' => $workDate, 'created_by' => $actor->id, 'revision' => 0]);
            $wasPublished = $assignment->exists && $assignment->status === 'PUBLISHED';
            $assignment->fill([
                'shift_template_id' => $template->id,
                'template_name' => $template->name,
                'template_type' => $template->type,
                'status' => $wasPublished ? 'PUBLISHED' : 'DRAFT',
                'revision' => $assignment->revision + 1,
            ])->save();

            $assignment->sessions()->delete();
            $assignment->sessions()->createMany($sessions);
            Audit::log($before ? 'schedule.updated' : 'schedule.created', $assignment, $before, $assignment->load('sessions')->toArray());

            return $assignment;
        });
    }

    public function cancel(ShiftAssignment $assignment): ShiftAssignment
    {
        return DB::transaction(function () use ($assignment) {
            Employee::query()->lockForUpdate()->findOrFail($assignment->employee_id);
            $assignment = ShiftAssignment::query()->with('sessions')->findOrFail($assignment->id);
            AttendancePeriod::assertOpen($assignment->work_date);
            $this->assertEditable($assignment);

            $before = $assignment->attributesToArray();
            $assignment->update(['status' => 'CANCELLED', 'revision' => $assignment->revision + 1]);
            Audit::log('schedule.cancelled', $assignment, $before);

            return $assignment;
        });
    }

    /** Draft dalam rentang menjadi PUBLISHED. Mengembalikan jumlah jadwal yang dipublikasi. */
    public function publish(string $from, string $to, array $employeeIds): int
    {
        return DB::transaction(function () use ($from, $to, $employeeIds) {
            $drafts = ShiftAssignment::query()
                ->whereBetween('work_date', [$from, $to])
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'DRAFT')
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $assignment) {
                AttendancePeriod::assertOpen($assignment->work_date);
                $assignment->update(['status' => 'PUBLISHED', 'published_at' => now()]);
                Audit::log('schedule.published', $assignment, ['status' => 'DRAFT']);
            }

            return $drafts->count();
        });
    }

    /** Salin jadwal satu minggu ke minggu tujuan. Tanggal yang sudah terisi atau bentrok dilewati. */
    public function copyWeek(string $fromMonday, string $toMonday, array $employeeIds, User $actor): array
    {
        $shift = CarbonImmutable::parse($fromMonday)->diffInDays(CarbonImmutable::parse($toMonday));
        $source = ShiftAssignment::query()
            ->with('template.sessions', 'employee')
            ->whereBetween('work_date', [$fromMonday, CarbonImmutable::parse($fromMonday)->addDays(6)->toDateString()])
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '!=', 'CANCELLED')
            ->whereNotNull('shift_template_id')
            ->get();

        $created = 0;
        $skipped = [];
        foreach ($source as $assignment) {
            $target = CarbonImmutable::parse($assignment->work_date)->addDays($shift)->toDateString();
            $taken = ShiftAssignment::query()->where('employee_id', $assignment->employee_id)->where('work_date', $target)->where('status', '!=', 'CANCELLED')->exists();
            if ($taken) {
                $skipped[] = ['employee' => $assignment->employee->name, 'work_date' => $target, 'reason' => 'Sudah ada jadwal.'];

                continue;
            }
            try {
                $this->upsert($assignment->employee, $target, $assignment->template, $actor);
                $created++;
            } catch (BusinessRuleException $e) {
                $skipped[] = ['employee' => $assignment->employee->name, 'work_date' => $target, 'reason' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function buildSessions(Employee $employee, string $workDate, ShiftTemplate $template): array
    {
        $policy = AttendancePolicy::resolveFor($employee, $workDate);
        $location = $employee->workLocation;
        $policy['location'] = $policy['use_location_check'] && $location?->mode === 'radius' ? [
            'mode' => 'radius',
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'radius_meters' => $location->radius_meters,
            'max_accuracy_meters' => $location->max_accuracy_meters,
        ] : null;

        return $template->sessions->map(fn ($s) => [
            'seq' => $s->seq,
            'start_at' => WorkTime::at($workDate, substr($s->local_start, 0, 5)),
            'end_at' => WorkTime::at($workDate, substr($s->local_end, 0, 5), $s->day_offset),
            'unpaid_break_minutes' => $s->unpaid_break_minutes,
            'policy_snapshot' => $policy,
        ])->all();
    }

    private function assertEmployed(Employee $employee, string $workDate): void
    {
        $outside = $workDate < $employee->started_on || ($employee->ended_on && $workDate > $employee->ended_on);
        if ($employee->status !== 'active' || $outside) {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', "{$employee->name} tidak aktif bekerja pada {$workDate}.");
        }
    }

    public function assertEditable(ShiftAssignment $assignment): void
    {
        $first = $assignment->sessions->first();
        $locked = in_array($assignment->status, ['IN_PROGRESS', 'COMPLETED'], true)
            || ($first && $first->start_at->lte(WorkTime::now()))
            || DB::table('attendance_events')->whereIn('shift_session_id', $assignment->sessions->pluck('id'))->exists();

        if ($locked) {
            throw new BusinessRuleException('SCHEDULE_LOCKED', 'Jadwal sudah mulai. Perubahan lewat koreksi.');
        }
    }

    private function assertNoOverlap(Employee $employee, string $workDate, array $sessions, ?int $ignoreId): void
    {
        $date = CarbonImmutable::parse($workDate);
        $others = ShiftAssignment::query()
            ->with('sessions')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$date->subDay()->toDateString(), $date->addDay()->toDateString()])
            ->where('status', '!=', 'CANCELLED')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($others as $other) {
            foreach ($other->sessions as $existing) {
                foreach ($sessions as $new) {
                    if ($new['start_at']->lt($existing->end_at) && $existing->start_at->lt($new['end_at'])) {
                        throw new BusinessRuleException('SCHEDULE_OVERLAP', "Bentrok dengan jadwal {$other->template_name} tanggal {$other->work_date}.", 409, [
                            'assignment_id' => $other->id,
                        ]);
                    }
                }
            }
        }
    }
}
