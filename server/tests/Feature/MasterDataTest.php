<?php

namespace Tests\Feature;

use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\Position;
use App\Models\ShiftTemplate;
use App\Models\WorkLocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeds_without_employees(): void
    {
        $this->seed();

        $this->assertSame(6, Department::query()->count());
        $this->assertSame(7, Position::query()->count());
        $this->assertSame(0, Employee::query()->count());
        $this->assertSame('none', WorkLocation::query()->where('name', 'Deresort')->value('mode'));

        $regular = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();
        $session = $regular->sessions()->firstOrFail();
        $this->assertSame('regular', $regular->type);
        $this->assertSame('08:00', substr($session->local_start, 0, 5));
        $this->assertSame(60, $session->unpaid_break_minutes);

        $night = ShiftTemplate::query()->where('name', 'Malam')->firstOrFail()->sessions()->firstOrFail();
        $this->assertSame(1, $night->day_offset);
        $this->assertSame('06:00', substr($night->local_end, 0, 5));

        $this->assertSame(3, ShiftTemplate::query()->where('name', 'Khusus 3 sesi')->firstOrFail()->sessions()->count());

        $policy = AttendancePolicy::query()->firstOrFail();
        $this->assertSame('global', $policy->scope_type);
        $this->assertSame(0, $policy->grace_minutes);
        $this->assertFalse($policy->use_location_check);
    }

    public function test_employee_code_and_placement_date_are_unique(): void
    {
        $this->seed();
        $department = Department::query()->firstOrFail();
        $position = Position::query()->firstOrFail();
        $employee = Employee::query()->create([
            'code' => 'EMP-001',
            'name' => 'Karyawan uji',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'started_on' => '2026-10-24',
        ]);
        EmployeePlacement::query()->create([
            'employee_id' => $employee->id,
            'department_id' => $department->id,
            'effective_from' => '2026-10-24',
        ]);

        try {
            EmployeePlacement::query()->create([
                'employee_id' => $employee->id,
                'department_id' => $department->id,
                'effective_from' => '2026-10-24',
            ]);
            $this->fail('Penempatan tanggal yang sama harus ditolak.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        Employee::query()->create([
            'code' => 'EMP-001',
            'name' => 'Duplikat',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'started_on' => '2026-10-24',
        ]);
    }
}
