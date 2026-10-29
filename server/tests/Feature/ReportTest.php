<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Models\AllowanceResult;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeOrder;
use App\Models\Position;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');
    }

    public function test_reports_filters_returns_scoped_departments_and_employees(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();

        $emp1 = $this->createEmployee($engineering, 'ENG-001', 'Budi Teknik');
        $emp2 = $this->createEmployee($frontOffice, 'FO-001', 'Siti Resepsionis');

        $admin = User::factory()->role('admin')->create();
        $supervisor = User::factory()->role('supervisor')->create(['department_id' => $engineering->id]);

        // Admin sees all
        $this->actingAs($admin)
            ->getJson('/admin/api/reports/filters')
            ->assertOk()
            ->assertJsonCount(2, 'departments')
            ->assertJsonCount(2, 'employees');

        // Supervisor sees only engineering
        $this->actingAs($supervisor)
            ->getJson('/admin/api/reports/filters')
            ->assertOk()
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $engineering->id)
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.id', $emp1->id);
    }

    public function test_reports_summary_aggregates_attendance_metrics_and_allowances(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-101', 'Doni Setiawan');
        $schedule = $this->schedule($employee, '2026-10-25');
        $session = $schedule->sessions->first();

        // Create attendance record
        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $employee->id,
            'check_in_at' => '2026-10-25 01:10:00',
            'check_out_at' => '2026-10-25 10:00:00',
            'is_late' => true,
            'raw_late_minutes' => 10,
            'status' => 'COMPLETE',
            'verified_minutes' => 470,
        ]);

        $admin = User::factory()->role('admin')->create();

        // Create approved overtime order
        OvertimeOrder::query()->create([
            'order_number' => 'SPL-20261025-001',
            'employee_id' => $employee->id,
            'work_date' => '2026-10-25',
            'shift_session_id' => $session->id,
            'initiator_type' => 'ADMIN',
            'created_by' => $admin->id,
            'title' => 'Perbaikan Generator',
            'start_at' => '2026-10-25 10:00:00',
            'end_at' => '2026-10-25 12:00:00',
            'planned_minutes' => 120,
            'actual_minutes' => 120,
            'verified_minutes' => 120,
            'status' => 'APPROVED',
        ]);

        // Create allowance result
        AllowanceResult::query()->create([
            'shift_assignment_id' => $schedule->id,
            'employee_id' => $employee->id,
            'work_date' => '2026-10-25',
            'rate_snapshot' => ['meal' => 25000, 'transport' => 15000],
            'meal_amount' => 25000,
            'transport_amount' => 15000,
            'status' => 'ELIGIBLE',
            'verified_minutes' => 470,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('period_status', 'PROVISIONAL')
            ->assertJsonPath('kpi.total_employees', 1)
            ->assertJsonPath('kpi.total_attended_days', 1)
            ->assertJsonPath('kpi.total_verified_minutes', 470)
            ->assertJsonPath('kpi.total_overtime_minutes', 120)
            ->assertJsonPath('kpi.total_meal_allowance', 25000)
            ->assertJsonPath('kpi.total_transport_allowance', 15000)
            ->assertJsonPath('kpi.total_allowance_amount', 40000)
            ->assertJsonPath('kpi.total_late_count', 1)
            ->assertJsonPath('rows.0.employee.code', 'ENG-101')
            ->assertJsonPath('rows.0.verified_minutes', 470)
            ->assertJsonPath('rows.0.overtime_minutes', 120)
            ->assertJsonPath('rows.0.total_allowance', 40000);
    }

    public function test_reports_period_status_becomes_finalized_when_marked_final(): void
    {
        $admin = User::factory()->role('admin')->create();

        AttendancePeriod::query()->create([
            'month' => '2026-10',
            'status' => 'FINALIZED',
            'finalized_at' => now(),
            'finalized_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('period_status', 'FINALIZED');
    }

    public function test_reports_details_returns_paginated_session_data(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-102', 'Hendra Wijaya');
        $this->schedule($employee, '2026-10-25');

        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/details?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.code', 'ENG-102')
            ->assertJsonPath('data.0.work_date', '2026-10-25')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_reports_overtime_lists_orders(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-103', 'Agus Prayogo');
        $admin = User::factory()->role('admin')->create();

        OvertimeOrder::query()->create([
            'order_number' => 'SPL-20261025-001',
            'employee_id' => $employee->id,
            'work_date' => '2026-10-25',
            'initiator_type' => 'ADMIN',
            'created_by' => $admin->id,
            'title' => 'Lembur Event Wedding',
            'start_at' => '2026-10-25 11:00:00',
            'end_at' => '2026-10-25 14:00:00',
            'planned_minutes' => 180,
            'actual_minutes' => 180,
            'verified_minutes' => 180,
            'status' => 'APPROVED',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/overtime?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'SPL-20261025-001')
            ->assertJsonPath('data.0.title', 'Lembur Event Wedding');
    }

    public function test_reports_export_excel_returns_xlsx_file_download(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-104', 'Eko Saputro');
        $this->schedule($employee, '2026-10-25');

        $admin = User::factory()->role('admin')->create();

        $response = $this->actingAs($admin)
            ->get('/admin/api/reports/export/excel?from=2026-10-01&to=2026-10-31');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('laporan_kehadiran_2026-10-01_2026-10-31.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_reports_export_pdf_returns_pdf_file_download(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-105', 'Fajar Ramadhan');
        $this->schedule($employee, '2026-10-25');

        $admin = User::factory()->role('admin')->create();

        // Summary PDF
        $summaryResponse = $this->actingAs($admin)
            ->get('/admin/api/reports/export/pdf?from=2026-10-01&to=2026-10-31&type=summary');

        $summaryResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $summaryResponse->headers->get('Content-Type'));

        // Detail PDF
        $detailResponse = $this->actingAs($admin)
            ->get('/admin/api/reports/export/pdf?from=2026-10-01&to=2026-10-31&type=detail');

        $detailResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $detailResponse->headers->get('Content-Type'));
    }

    public function test_reports_validation_rejects_invalid_date_range(): void
    {
        $admin = User::factory()->role('admin')->create();

        // Reversed dates
        $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-10-31&to=2026-10-01')
            ->assertStatus(422);

        // Range exceeds 62 days
        $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-01-01&to=2026-05-01')
            ->assertStatus(422);
    }

    private function createEmployee(Department $department, string $code, string $name): Employee
    {
        $user = User::factory()->create(['name' => $name]);

        return Employee::query()->create([
            'code' => $code,
            'name' => $name,
            'user_id' => $user->id,
            'department_id' => $department->id,
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
        ]);
    }

    private function schedule(Employee $employee, string $workDate)
    {
        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $schedule = app(PublishSchedule::class)->upsert($employee, $workDate, $template, $admin);
        app(PublishSchedule::class)->publish($workDate, $workDate, [$employee->id]);

        return $schedule->fresh(['sessions', 'employee']);
    }
}
