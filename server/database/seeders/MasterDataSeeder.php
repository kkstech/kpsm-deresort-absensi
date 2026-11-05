<?php

namespace Database\Seeders;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\ShiftTemplate;
use App\Models\WorkLocation;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        LeaveType::query()->firstOrCreate(
            ['code' => 'regular'],
            ['name' => 'Cuti Reguler', 'default_quota' => 12, 'is_active' => true],
        );
        LeaveType::query()->firstOrCreate(
            ['code' => 'day_off'],
            ['name' => 'Deposit Off', 'default_quota' => 4, 'is_active' => true],
        );

        foreach (['Front Office', 'Engineering', 'Finance', 'Housekeeping', 'Security', 'F&B Service'] as $name) {
            Department::query()->firstOrCreate(['name' => $name]);
            Position::query()->firstOrCreate(['name' => $name]);
        }
        Position::query()->firstOrCreate(['name' => 'Supervisor']);

        WorkLocation::query()->firstOrCreate(
            ['name' => 'Deresort'],
            ['mode' => 'none'],
        );

        $this->template('regular', 'Reguler', [
            ['seq' => 1, 'local_start' => '08:00', 'local_end' => '17:00', 'day_offset' => 0, 'unpaid_break_minutes' => 60],
        ]);
        $this->template('regular', 'Malam', [
            ['seq' => 1, 'local_start' => '22:00', 'local_end' => '06:00', 'day_offset' => 1, 'unpaid_break_minutes' => 0],
        ]);
        $this->template('special', 'Khusus 2 sesi', [
            ['seq' => 1, 'local_start' => '06:00', 'local_end' => '09:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0],
            ['seq' => 2, 'local_start' => '15:00', 'local_end' => '18:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0],
        ]);
        $this->template('special', 'Khusus 3 sesi', [
            ['seq' => 1, 'local_start' => '06:00', 'local_end' => '09:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0],
            ['seq' => 2, 'local_start' => '11:00', 'local_end' => '14:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0],
            ['seq' => 3, 'local_start' => '16:00', 'local_end' => '19:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0],
        ]);

        AttendancePolicy::query()->firstOrCreate(
            ['scope_type' => 'global', 'department_id' => null, 'employee_id' => null],
            ['grace_minutes' => 0, 'use_location_check' => false, 'effective_from' => '2026-10-24'],
        );
    }

    private function template(string $type, string $name, array $sessions): void
    {
        $template = ShiftTemplate::query()->firstOrCreate(
            ['name' => $name],
            ['type' => $type, 'repeat_pattern' => 'weekly'],
        );

        foreach ($sessions as $session) {
            $template->sessions()->firstOrCreate(
                ['seq' => $session['seq']],
                $session,
            );
        }
    }
}
