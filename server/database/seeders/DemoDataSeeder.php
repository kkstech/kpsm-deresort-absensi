<?php

namespace Database\Seeders;

use App\Actions\PublishSchedule;
use App\Actions\RecordAttendance;
use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ShiftAssignment;
use App\Models\ShiftSession;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Data contoh untuk lokal/UAT. Jalankan manual: php artisan db:seed --class=DemoDataSeeder */
class DemoDataSeeder extends Seeder
{
    public function run(PublishSchedule $schedules): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoDataSeeder tidak boleh jalan di production.');

            return;
        }

        $people = [
            ['FO-001', 'Nadia Anjani', 'Front Office', 'nadia.anjani@gmail.com', '081234567801'],
            ['EN-001', 'Dimas Saputra', 'Engineering', 'dimas.saputra@gmail.com', '081234567802'],
            ['FI-001', 'Lina Marlina', 'Finance', 'lina.marlina@gmail.com', '081234567803'],
            ['HK-001', 'Rizky Kurnia', 'Housekeeping', 'rizky.kurnia@gmail.com', '081234567804'],
            ['SE-001', 'Bagus Pratama', 'Security', 'bagus.pratama@gmail.com', '081234567805'],
            ['FB-001', 'Siti Maemunah', 'F&B Service', 'siti.maemunah@gmail.com', '081234567806'],
            ['EN-002', 'Reza Akbar', 'Engineering', 'reza.akbar@gmail.com', '081234567807'],
        ];

        $employees = collect($people)->map(function ($p) {
            [$code, $name, $department, $email, $phone] = $p;
            $departmentId = Department::query()->where('name', $department)->value('id');
            $user = $email ? User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'username' => Str::lower($code), 'phone' => $phone, 'password' => 'password123', 'role' => 'employee']
            ) : null;
            if ($user) {
                $userUpdates = [];
                if (! $user->phone) {
                    $userUpdates['phone'] = $phone;
                }
                if (! $user->username) {
                    $userUpdates['username'] = Str::lower($code);
                }
                if ($userUpdates) {
                    $user->update($userUpdates);
                }
            }
            $employee = Employee::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'department_id' => $departmentId,
                'user_id' => $user?->id,
                'status' => 'active',
                'started_on' => '2026-01-01',
                'email' => $email,
                'phone' => $phone,
                'position_id' => Position::query()->where('name', $department)->value('id') ?? Position::query()->value('id'),
            ]);
            $employee->update(['email' => $email, 'phone' => $phone]);
            $employee->placements()->firstOrCreate(['effective_from' => '2026-01-01'], ['department_id' => $departmentId]);

            return $employee;
        });

        $admin = User::query()->where('role', 'super_admin')->first() ?? User::query()->firstOrFail();
        $templates = ShiftTemplate::query()->with('sessions')->get()->keyBy('name');
        $plan = ['Reguler', 'Reguler', 'Khusus 2 sesi', 'Malam', 'Reguler', 'Khusus 3 sesi', null];
        $start = CarbonImmutable::parse(WorkTime::today())->addDay();

        foreach (range(0, 13) as $day) {
            $date = $start->addDays($day)->toDateString();
            foreach ($employees as $i => $employee) {
                $name = $plan[($i + $day) % count($plan)];
                if ($name && ! ShiftAssignment::query()->where('employee_id', $employee->id)->where('work_date', $date)->exists()) {
                    $schedules->upsert($employee, $date, $templates[$name], $admin);
                }
            }
        }
        $schedules->publish($start->toDateString(), $start->addDays(6)->toDateString(), $employees->pluck('id')->all());

        $this->seedAttendanceExamples($employees, $templates['Reguler'], $admin);
    }

    private function seedAttendanceExamples($employees, ShiftTemplate $template, User $admin): void
    {
        $today = WorkTime::today();

        foreach ($employees as $index => $employee) {
            $date = CarbonImmutable::parse($today)->subDays($index + 1)->toDateString();
            $this->seedAttendanceFor($employee, $template, $admin, $date, false, $index);
        }

        foreach ($employees as $index => $employee) {
            if ($index < 5) {
                $this->seedAttendanceFor($employee, $template, $admin, $today, false, $index);
            } elseif ($index === 5) {
                $this->seedAttendanceFor($employee, $template, $admin, $today, true, $index);
            } else {
                $this->sampleSession($employee, $template, $admin, $today);
            }
        }
    }

    private function seedAttendanceFor(Employee $employee, ShiftTemplate $template, User $admin, string $date, bool $review, int $index): void
    {
        $session = $this->sampleSession($employee, $template, $admin, $date);
        if (! $session || $session->attendanceEvents()->exists()) {
            return;
        }

        $checkIn = WorkTime::at($date, $review || $index % 2 ? '08:15' : '08:00');
        $checkOut = WorkTime::at($date, '16:50');
        $action = app(RecordAttendance::class);

        try {
            if ($review) {
                Carbon::setTestNow($checkIn->addMinutes(2));
                CarbonImmutable::setTestNow($checkIn->addMinutes(2));
                $action->handle($employee, $this->eventInput($session, 'CHECK_IN', true, $checkIn));

                return;
            }

            Carbon::setTestNow($checkIn);
            CarbonImmutable::setTestNow($checkIn);
            $action->handle($employee, $this->eventInput($session, 'CHECK_IN', false, $checkIn));

            Carbon::setTestNow($checkOut);
            CarbonImmutable::setTestNow($checkOut);
            $action->handle($employee, $this->eventInput($session, 'CHECK_OUT', false, $checkOut));
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    private function eventInput(ShiftSession $session, string $type, bool $offline, CarbonImmutable $time): array
    {
        return [
            'event_uuid' => (string) Str::uuid(),
            'shift_session_id' => $session->id,
            'event_type' => $type,
            'captured_offline' => $offline,
            'device_at' => $offline ? WorkTime::iso($time) : null,
            'device_timezone' => $offline ? WorkTime::tz() : null,
        ];
    }

    private function sampleSession(Employee $employee, ShiftTemplate $template, User $admin, string $date): ?ShiftSession
    {
        $existing = ShiftAssignment::query()->with('sessions')->where('employee_id', $employee->id)->where('work_date', $date)->first();
        if ($existing) {
            return str_starts_with($existing->template_name, 'Demo · ')
                ? $existing->sessions->first()
                : null;
        }

        $source = $template->sessions->first();
        $policy = AttendancePolicy::resolveFor($employee, $date);
        $location = $employee->workLocation;
        $policy['location'] = $policy['use_location_check'] && $location?->mode === 'radius' ? [
            'mode' => 'radius',
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'radius_meters' => $location->radius_meters,
            'max_accuracy_meters' => $location->max_accuracy_meters,
        ] : null;

        return DB::transaction(function () use ($employee, $template, $admin, $date, $source, $policy) {
            $assignment = ShiftAssignment::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'shift_template_id' => $template->id,
                'template_name' => "Demo · {$template->name}",
                'template_type' => $template->type,
                'status' => 'PUBLISHED',
                'revision' => 1,
                'published_at' => now(),
                'created_by' => $admin->id,
            ]);
            $session = $assignment->sessions()->create([
                'seq' => $source->seq,
                'start_at' => WorkTime::at($date, substr($source->local_start, 0, 5)),
                'end_at' => WorkTime::at($date, substr($source->local_end, 0, 5), $source->day_offset),
                'unpaid_break_minutes' => $source->unpaid_break_minutes,
                'policy_snapshot' => $policy,
            ]);
            Audit::log('schedule.created', $assignment, null, $assignment->load('sessions')->toArray());

            return $session;
        });
    }
}
