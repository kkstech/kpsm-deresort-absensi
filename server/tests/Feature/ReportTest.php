<?php

namespace Tests\Feature;

use App\Actions\AutoCheckoutAttendance;
use App\Actions\PublishSchedule;
use App\Exports\AttendanceReportSpout;
use App\Models\AllowancePolicy;
use App\Models\AllowanceResult;
use App\Models\AttendanceEvent;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Models\LeaveRequest;
use App\Models\OvertimeOrder;
use App\Models\Position;
use App\Models\ShiftAssignment;
use App\Models\ShiftCategory;
use App\Models\ShiftExchangeRequest;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\ReportService;
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

    public function test_reports_filters_return_active_departments_and_employees_for_admin(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();

        $emp1 = $this->createEmployee($engineering, 'ENG-001', 'Budi Teknik');
        $emp2 = $this->createEmployee($frontOffice, 'FO-001', 'Siti Resepsionis');
        $emp2->update(['status' => 'inactive']);

        $admin = User::factory()->role('admin')->create();

        // Admin sees all
        $this->actingAs($admin)
            ->getJson('/admin/api/reports/filters')
            ->assertOk()
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $engineering->id)
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.id', $emp1->id);

        // Admin sees all departments, with inactive employees excluded.
        $this->actingAs($admin)
            ->getJson('/admin/api/reports/filters')
            ->assertOk()
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $engineering->id)
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.id', $emp1->id);
    }

    public function test_reports_exclude_inactive_employees_from_historical_data(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();
        $activeEmployee = $this->createEmployee($engineering, 'ENG-201', 'Karyawan Aktif');
        $inactiveEmployee = $this->createEmployee($frontOffice, 'FO-201', 'Karyawan Nonaktif');
        $activeSchedule = $this->schedule($activeEmployee, '2026-10-25');
        $inactiveSchedule = $this->schedule($inactiveEmployee, '2026-10-25');
        $activeSession = $activeSchedule->sessions->firstOrFail();
        $inactiveSession = $inactiveSchedule->sessions->firstOrFail();

        AttendanceRecord::query()->create([
            'shift_session_id' => $activeSession->id,
            'employee_id' => $activeEmployee->id,
            'check_in_at' => '2026-10-25 01:00:00',
            'check_out_at' => '2026-10-25 10:00:00',
            'status' => 'COMPLETE',
            'verified_minutes' => 470,
        ]);
        AttendanceRecord::query()->create([
            'shift_session_id' => $inactiveSession->id,
            'employee_id' => $inactiveEmployee->id,
            'check_in_at' => '2026-10-25 01:00:00',
            'check_out_at' => '2026-10-25 10:00:00',
            'status' => 'COMPLETE',
            'verified_minutes' => 300,
        ]);

        $admin = User::factory()->role('admin')->create();
        foreach ([[$activeEmployee, 'SPL-20261025-201', $activeSession->id, 60], [$inactiveEmployee, 'SPL-20261025-202', $inactiveSession->id, 120]] as [$employee, $orderNumber, $sessionId, $minutes]) {
            OvertimeOrder::query()->create([
                'order_number' => $orderNumber,
                'employee_id' => $employee->id,
                'work_date' => '2026-10-25',
                'shift_session_id' => $sessionId,
                'initiator_type' => 'ADMIN',
                'created_by' => $admin->id,
                'title' => 'Perbaikan operasional',
                'start_at' => '2026-10-25 10:00:00',
                'end_at' => '2026-10-25 12:00:00',
                'planned_minutes' => $minutes,
                'actual_minutes' => $minutes,
                'verified_minutes' => $minutes,
                'status' => 'APPROVED',
            ]);
        }

        $inactiveEmployee->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/filters')
            ->assertOk()
            ->assertJsonCount(1, 'employees')
            ->assertJsonPath('employees.0.id', $activeEmployee->id)
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $engineering->id);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('kpi.total_employees', 1)
            ->assertJsonPath('kpi.total_verified_minutes', 470)
            ->assertJsonPath('kpi.total_actual_overtime_minutes', 60)
            ->assertJsonPath('kpi.total_overtime_minutes', 60)
            ->assertJsonPath('rows.0.employee.id', $activeEmployee->id);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-10-01&to=2026-10-31&employee_id='.$inactiveEmployee->id)
            ->assertOk()
            ->assertJsonPath('kpi.total_employees', 0)
            ->assertJsonCount(0, 'rows');

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/details?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $activeEmployee->id)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/details?from=2026-10-01&to=2026-10-31&employee_id='.$inactiveEmployee->id)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/overtime?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $activeEmployee->id)
            ->assertJsonPath('data.0.actual_minutes', 60)
            ->assertJsonPath('data.0.verified_minutes', 60);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/overtime?from=2026-10-01&to=2026-10-31&employee_id='.$inactiveEmployee->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $exportDetails = app(ReportService::class)->getAllDetailsForExport($admin, [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]);

        $this->assertCount(1, $exportDetails);
        $this->assertSame($activeEmployee->id, $exportDetails->first()['employee']['id']);
    }

    public function test_reports_summary_aggregates_attendance_metrics_and_allowances(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-101', 'Doni Setiawan');
        $schedule = $this->schedule($employee, '2026-10-25');
        $session = $schedule->sessions->first();
        $admin = User::factory()->role('admin')->create();
        $secondTemplate = ShiftTemplate::query()->create(['name' => 'Reguler malam tambahan', 'type' => 'regular']);
        $secondTemplate->sessions()->create(['seq' => 1, 'local_start' => '18:00', 'local_end' => '22:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0]);
        app(PublishSchedule::class)->upsert($employee, '2026-10-25', $secondTemplate, $admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);

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
            ->assertJsonPath('kpi.total_scheduled_days', 1)
            ->assertJsonPath('kpi.total_scheduled_sessions', 2)
            ->assertJsonPath('kpi.total_attended_days', 1)
            ->assertJsonPath('kpi.total_attended_sessions', 1)
            ->assertJsonPath('kpi.total_verified_minutes', 470)
            ->assertJsonPath('kpi.total_actual_overtime_minutes', 120)
            ->assertJsonPath('kpi.total_overtime_minutes', 120)
            ->assertJsonPath('kpi.total_meal_allowance', 25000)
            ->assertJsonPath('kpi.total_transport_allowance', 15000)
            ->assertJsonPath('kpi.total_allowance_amount', 40000)
            ->assertJsonPath('kpi.total_late_count', 1)
            ->assertJsonPath('rows.0.employee.code', 'ENG-101')
            ->assertJsonPath('rows.0.verified_minutes', 470)
            ->assertJsonPath('rows.0.overtime_actual_minutes', 120)
            ->assertJsonPath('rows.0.overtime_minutes', 120)
            ->assertJsonPath('rows.0.total_allowance', 40000);

        $details = $this->actingAs($admin)
            ->getJson('/admin/api/reports/details?from=2026-10-01&to=2026-10-31&employee_id='.$employee->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
        $sessionDetail = collect($details->json('data'))->firstWhere('id', $session->id);
        $this->assertNotNull($sessionDetail);
        $this->assertSame(120, $sessionDetail['overtime_actual_minutes']);
        $this->assertSame(120, $sessionDetail['overtime_verified_minutes']);
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

    public function test_admin_can_finalize_ended_months_without_locking_attendance(): void
    {
        $this->travelTo('2026-11-02 03:00:00');
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)
            ->postJson('/admin/api/reports/finalize', ['from' => '2026-09-01', 'to' => '2026-10-31'])
            ->assertOk()
            ->assertJsonPath('period_status', 'FINALIZED');

        foreach (['2026-09', '2026-10'] as $month) {
            $period = AttendancePeriod::query()->where('month', $month)->firstOrFail();
            $this->assertSame('OPEN', $period->status);
            $this->assertNotNull($period->finalized_at);
            $this->assertSame($admin->id, $period->finalized_by);
        }

        AttendancePeriod::assertOpen('2026-10-25');
        $this->assertSame('FINALIZED', app(ReportService::class)->determinePeriodStatus('2026-09-01', '2026-10-31'));

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/details?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('period_status', 'FINALIZED');
        $this->actingAs($admin)
            ->getJson('/admin/api/reports/overtime?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('period_status', 'FINALIZED');
    }

    public function test_report_finalization_requires_admin_and_full_ended_months(): void
    {
        $this->travelTo('2026-11-02 03:00:00');
        $admin = User::factory()->role('admin')->create();
        $employeeUser = User::factory()->role('employee')->create();

        $this->actingAs($admin)
            ->postJson('/admin/api/reports/finalize', ['from' => '2026-10-02', 'to' => '2026-10-31'])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->postJson('/admin/api/reports/finalize', ['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertUnprocessable();
        $this->actingAs($employeeUser)
            ->postJson('/admin/api/reports/finalize', ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertForbidden();

        $this->assertDatabaseCount('attendance_periods', 0);
    }

    public function test_report_finalization_lists_global_review_blockers_but_ignores_verified_events(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();
        $blockedEmployee = $this->createEmployee($frontOffice, 'FO-301', 'Karyawan Perlu Verifikasi');
        $verifiedEmployee = $this->createEmployee($engineering, 'ENG-301', 'Karyawan Sudah Diverifikasi');
        $blockedSession = $this->schedule($blockedEmployee, '2026-10-25')->sessions->firstOrFail();
        $verifiedSession = $this->schedule($verifiedEmployee, '2026-10-26')->sessions->firstOrFail();

        $this->createReviewEvent($blockedEmployee, $blockedSession, 'report-review-blocked');
        $verifiedEvent = $this->createReviewEvent($verifiedEmployee, $verifiedSession, 'report-review-verified');
        AttendanceRecord::query()->create([
            'shift_session_id' => $verifiedSession->id,
            'employee_id' => $verifiedEmployee->id,
            'check_in_event_id' => $verifiedEvent->id,
            'check_in_at' => '2026-10-26 01:00:00',
            'check_out_at' => '2026-10-26 10:00:00',
            'status' => 'COMPLETE',
            'needs_review' => false,
            'verified_minutes' => 480,
        ]);

        $this->travelTo('2026-11-02 03:00:00');
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)
            ->postJson('/admin/api/reports/finalize', ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REPORT_HAS_PENDING_REVIEW')
            ->assertJsonPath('details.count', 1)
            ->assertJsonPath('details.blockers.0.work_date', '2026-10-25')
            ->assertJsonPath('details.blockers.0.employee.name', 'Karyawan Perlu Verifikasi');

        $this->assertDatabaseCount('attendance_periods', 0);
    }

    public function test_new_auto_checkout_review_reverts_report_to_provisional(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-302', 'Karyawan Auto Checkout');
        $session = $this->schedule($employee, '2026-10-25')->sessions->firstOrFail();
        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $employee->id,
            'check_in_at' => '2026-10-25 01:00:00',
            'status' => 'IN_PROGRESS',
            'needs_review' => false,
        ]);

        $this->travelTo('2026-11-02 03:00:00');
        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)
            ->postJson('/admin/api/reports/finalize', ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertOk();

        $this->assertSame(1, app(AutoCheckoutAttendance::class)->handle());
        $this->assertNull(AttendancePeriod::query()->where('month', '2026-10')->value('finalized_at'));
        $this->assertSame('OPEN', AttendancePeriod::query()->where('month', '2026-10')->value('status'));
        $this->assertTrue(AttendanceRecord::query()->where('shift_session_id', $session->id)->value('needs_review'));
        $this->assertSame('PROVISIONAL', app(ReportService::class)->determinePeriodStatus('2026-10-01', '2026-10-31'));
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

        OvertimeOrder::query()->create([
            'order_number' => 'SPL-20261025-002',
            'employee_id' => $employee->id,
            'work_date' => '2026-10-25',
            'initiator_type' => 'ADMIN',
            'created_by' => $admin->id,
            'title' => 'Belum dijalankan',
            'start_at' => '2026-10-25 14:00:00',
            'end_at' => '2026-10-25 15:00:00',
            'planned_minutes' => 60,
            'actual_minutes' => 0,
            'verified_minutes' => 0,
            'status' => 'APPROVED',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/overtime?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_number', 'SPL-20261025-002')
            ->assertJsonPath('data.0.title', 'Belum dijalankan')
            ->assertJsonPath('data.0.actual_minutes', 0)
            ->assertJsonPath('data.0.verified_minutes', 0)
            ->assertJsonPath('data.0.execution_status', 'NOT_REALIZED')
            ->assertJsonPath('data.1.order_number', 'SPL-20261025-001')
            ->assertJsonPath('data.1.actual_minutes', 180)
            ->assertJsonPath('data.1.execution_status', 'REALIZED');

        $pdf = $this->actingAs($admin)
            ->get('/admin/api/reports/export/pdf?from=2026-10-01&to=2026-10-31&type=overtime')
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('rekap_lembur_2026-10-01_2026-10-31.pdf', $pdf->headers->get('content-disposition'));
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

        $summary = app(ReportService::class)->getSummary($admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $details = app(ReportService::class)->getAllDetailsForExport($admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $breakdown = app(ReportService::class)->getShiftBreakdown($admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $overtime = app(ReportService::class)->getOvertime($admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $path = tempnam(sys_get_temp_dir(), 'shift-report-');
        try {
            app(AttendanceReportSpout::class)->export($summary, $details, $breakdown, $overtime, $path);
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($path));
            $workbook = $archive->getFromName('xl/workbook.xml');
            $archive->close();
            $this->assertStringContainsString('Frekuensi Shift', $workbook);
            $this->assertStringContainsString('Rekap Lembur', $workbook);
        } finally {
            @unlink($path);
        }
    }

    public function test_shift_breakdown_counts_one_per_assignment_and_groups_uncategorized_shifts(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-SHIFT-001', 'Karyawan Breakdown');
        $admin = User::factory()->role('admin')->create();
        $category = ShiftCategory::query()->create(['name' => 'Shift Split', 'is_active' => true]);
        $splitTemplate = ShiftTemplate::query()->with('sessions')->where('name', 'Khusus 2 sesi')->firstOrFail();
        $splitTemplate->update(['shift_category_id' => $category->id]);

        $splitAssignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $splitTemplate, $admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);
        foreach ($splitAssignment->fresh('sessions')->sessions as $session) {
            AttendanceRecord::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $employee->id,
                'check_in_at' => '2026-10-25 01:00:00',
                'status' => 'IN_PROGRESS',
            ]);
        }

        $legacyCategory = ShiftCategory::query()->create(['name' => 'Kategori Lama', 'is_active' => true]);
        $uncategorized = app(PublishSchedule::class)->upsertCustom($employee, '2026-10-26', [
            'name' => 'Shift khusus lama',
            'shift_category_id' => $legacyCategory->id,
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '12:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '13:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ], $admin);
        $uncategorized->update(['shift_category_id' => null]); // Simulate a legacy custom assignment from before categories existed.
        app(PublishSchedule::class)->publish('2026-10-26', '2026-10-26', [$employee->id]);
        AttendanceRecord::query()->create([
            'shift_session_id' => $uncategorized->sessions->firstOrFail()->id,
            'employee_id' => $employee->id,
            'check_in_at' => '2026-10-26 01:00:00',
            'status' => 'IN_PROGRESS',
        ]);
        $this->schedule($employee, '2026-10-27'); // Scheduled without a check-in; must not count.

        $response = $this->actingAs($admin)
            ->getJson('/admin/api/reports/shift-breakdown?from=2026-10-01&to=2026-10-31&employee_id='.$employee->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('category_name');
        $this->assertSame(1, $rows->get('Shift Split')['shift_count']);
        $this->assertSame(1, $rows->get('Belum dikategorikan')['shift_count']);
        $this->assertSame($employee->id, $rows->first()['employee']['id']);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports/shift-breakdown?from=2026-10-01&to=2026-10-31&q=Karyawan%20Breakdown')
            ->assertOk()
            ->assertJsonCount(2, 'data');
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

        $breakdownResponse = $this->actingAs($admin)
            ->get('/admin/api/reports/export/pdf?from=2026-10-01&to=2026-10-31&type=shift-breakdown');
        $breakdownResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $breakdownResponse->headers->get('Content-Type'));
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

    public function test_reports_summary_and_shift_breakdown_include_shift_type_counts(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($engineering, 'ENG-888', 'Shift Analyst');

        $templateReguler = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $templateMalam = ShiftTemplate::query()->with('sessions')->where('name', 'Malam')->firstOrFail();
        $templateSplit = ShiftTemplate::query()->with('sessions')->where('name', 'Khusus 2 sesi')->firstOrFail();

        $admin = User::factory()->role('admin')->create();
        $publisher = app(PublishSchedule::class);

        $sch1 = $publisher->upsert($employee, '2026-10-25', $templateReguler, $admin);
        $sch2 = $publisher->upsert($employee, '2026-10-26', $templateMalam, $admin);
        $sch3 = $publisher->upsert($employee, '2026-10-27', $templateSplit, $admin);

        $publisher->publish('2026-10-25', '2026-10-27', [$employee->id]);

        AttendanceRecord::query()->create([
            'shift_session_id' => $sch1->sessions->first()->id,
            'employee_id' => $employee->id,
            'check_in_at' => '2026-10-25 08:00:00',
            'check_out_at' => '2026-10-25 17:00:00',
            'status' => 'COMPLETE',
            'verified_minutes' => 480,
        ]);
        AttendanceRecord::query()->create([
            'shift_session_id' => $sch2->sessions->first()->id,
            'employee_id' => $employee->id,
            'check_in_at' => '2026-10-26 22:00:00',
            'check_out_at' => '2026-10-27 06:00:00',
            'status' => 'COMPLETE',
            'verified_minutes' => 480,
        ]);
        AttendanceRecord::query()->create([
            'shift_session_id' => $sch3->sessions->first()->id,
            'employee_id' => $employee->id,
            'check_in_at' => '2026-10-27 06:00:00',
            'check_out_at' => '2026-10-27 10:00:00',
            'status' => 'COMPLETE',
            'verified_minutes' => 240,
        ]);

        ShiftExchangeRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $admin->id,
            'shift_assignment_id' => $sch1->id,
            'target_employee_id' => $employee->id,
            'swap_mode' => 'delegation',
            'reason' => 'Tukar shift dinas',
            'status' => 'APPROVED',
            'decided_by' => $admin->id,
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/admin/api/reports/summary?from=2026-10-01&to=2026-10-31&department_id='.$engineering->id);

        $response->assertOk();
        $row = collect($response->json('rows'))->firstWhere('employee.id', $employee->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('shift_counts', $row);
        $this->assertEquals(1, $row['shift_counts']['shift_1']);
        $this->assertEquals(0, $row['shift_counts']['shift_2']);
        $this->assertEquals(1, $row['shift_counts']['shift_3']);
        $this->assertEquals(1, $row['shift_counts']['split']);
        $this->assertEquals(1, $row['shift_counts']['exchange']);
        $this->assertEquals(3, $row['shift_counts']['total_shifts']);

        $kpi = $response->json('kpi');
        $this->assertGreaterThanOrEqual(1, $kpi['total_shift_1']);
        $this->assertGreaterThanOrEqual(1, $kpi['total_shift_3']);
        $this->assertGreaterThanOrEqual(1, $kpi['total_split']);
        $this->assertGreaterThanOrEqual(1, $kpi['total_exchange']);

        $breakdown = $this->actingAs($admin)
            ->getJson('/admin/api/reports/shift-breakdown?from=2026-10-01&to=2026-10-31&department_id='.$engineering->id);
        $breakdown->assertOk();
        $this->assertNotNull($breakdown->json('summary_rows'));
        $bRow = collect($breakdown->json('summary_rows'))->firstWhere('employee.id', $employee->id);
        $this->assertNotNull($bRow);
        $this->assertEquals(1, $bRow['shift_counts']['shift_1']);
        $this->assertEquals(1, $bRow['shift_counts']['shift_3']);
        $this->assertEquals(1, $bRow['shift_counts']['split']);
        $this->assertEquals(1, $bRow['shift_counts']['exchange']);

        $excelResponse = $this->actingAs($admin)
            ->get('/admin/api/reports/export/excel?from=2026-10-01&to=2026-10-31');
        $excelResponse->assertOk();
        $this->assertStringContainsString('spreadsheet', $excelResponse->headers->get('Content-Type'));
    }

    public function test_summary_groups_rows_per_department_and_sorts_by_transport_rate_then_start_date(): void
    {
        $finance = Department::query()->where('name', 'Finance')->firstOrFail();
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $manager = EmployeeLevel::query()->create(['code' => 'MGR-T', 'name' => 'Manager Test']);
        $staff = EmployeeLevel::query()->create(['code' => 'STF-T', 'name' => 'Staff Test']);
        AllowancePolicy::query()->create(['level_id' => $manager->id, 'effective_from' => '2026-01-01', 'meal_amount' => 20000, 'transport_amount' => 30000]);
        AllowancePolicy::query()->create(['level_id' => $staff->id, 'effective_from' => '2026-01-01', 'meal_amount' => 20000, 'transport_amount' => 10000]);

        $spv = $this->createEmployee($finance, 'FIN-002', 'Andi Supervisor', '2024-03-01', $staff);
        $financeManager = $this->createEmployee($finance, 'FIN-001', 'Zaenal Manager', '2025-06-01', $manager);
        $seniorStaff = $this->createEmployee($finance, 'FIN-003', 'Budi Senior', '2020-02-10', $staff);
        $engineer = $this->createEmployee($engineering, 'ENG-900', 'Citra Teknik', '2026-01-01', $staff);
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();
        $receptionist = $this->createEmployee($frontOffice, 'FO-900', 'Dedi Resepsionis', '2026-01-01', $staff);

        $admin = User::factory()->role('admin')->create();
        $summary = app(ReportService::class)->getSummary($admin, ['from' => '2026-10-01', 'to' => '2026-10-31']);

        $this->assertSame(['Engineering', 'Finance', 'Front Office'], array_column($summary['groups'], 'department'));
        $financeIds = array_map(fn ($row) => $row['employee']['id'], $summary['groups'][1]['rows']);
        // Manager (transport terbesar) di atas; sesama staff urut tanggal mulai bekerja paling awal.
        $this->assertSame([$financeManager->id, $seniorStaff->id, $spv->id], $financeIds);
        $this->assertSame($engineer->id, $summary['groups'][0]['rows'][0]['employee']['id']);
        $this->assertSame($receptionist->id, $summary['groups'][2]['rows'][0]['employee']['id']);
        $this->assertSame('10/02/2020', $summary['groups'][1]['rows'][1]['employee']['started_on_formatted']);
        $this->assertSame(30000, $summary['groups'][1]['rows'][0]['employee']['transport_rate']);
        $this->assertSame('1 Oktober 2026 s.d. 31 Oktober 2026', $summary['period_label']);

        $pdf = $this->actingAs($admin)->get('/admin/api/reports/export/pdf?from=2026-10-01&to=2026-10-31&type=summary');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_middle_shift_is_counted_in_its_own_column(): void
    {
        $assignment = new ShiftAssignment(['template_name' => 'Middle 11-20']);
        $assignment->setRelation('sessions', collect());
        $assignment->setRelation('category', null);

        $this->assertSame('middle', app(ReportService::class)->classifyShift($assignment));
    }

    public function test_leave_outstanding_report_splits_usage_per_month_and_computes_closing_balance(): void
    {
        $finance = Department::query()->where('name', 'Finance')->firstOrFail();
        $employee = $this->createEmployee($finance, 'FIN-010', 'Karyawan Cuti', '2020-01-01');
        $newHire = $this->createEmployee($finance, 'FIN-011', 'Karyawan Baru', '2026-07-01');
        $admin = User::factory()->role('admin')->create();

        foreach ([
            ['2026-01-30', '2026-02-02', 4, 'regular', 'APPROVED'],
            ['2026-03-10', '2026-03-10', 1, 'day_off', 'APPROVED'],
            ['2026-04-10', '2026-04-11', 2, 'regular', 'PENDING'],
        ] as [$start, $end, $days, $type, $status]) {
            LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $start,
                'end_date' => $end,
                'days_count' => $days,
                'type' => $type,
                'status' => $status,
                'reason' => 'Uji laporan',
                'requested_by' => $admin->id,
            ]);
        }

        $report = app(ReportService::class)->getLeaveOutstanding($admin, 2026);
        $rows = collect($report['rows'])->keyBy(fn ($row) => $row['employee']['id']);
        $row = $rows[$employee->id];

        $this->assertSame(12, $row['opening_cuti']);
        $this->assertSame(4, $row['opening_deposit_off']);
        $this->assertSame(2, $row['months'][1]['cuti']);
        $this->assertSame(2, $row['months'][2]['cuti']);
        $this->assertSame(1, $row['months'][3]['deposit_off']);
        $this->assertSame(0, $row['months'][4]['cuti'], 'Pengajuan PENDING tidak mengurangi saldo.');
        $this->assertSame(8, $row['closing_cuti']);
        $this->assertSame(3, $row['closing_deposit_off']);
        $this->assertSame(6, $rows[$newHire->id]['opening_cuti'], 'Karyawan baru mendapat kuota prorata.');
        $this->assertSame('Januari 2026 s.d. Desember 2026', $report['period_label']);
        $this->assertSame('Finance', $report['groups'][0]['department']);

        $pdf = $this->actingAs($admin)->get('/admin/api/reports/export/pdf?type=leave-outstanding&year=2026');
        $pdf->assertOk();
        $this->assertStringContainsString('outstanding_cuti_2026.pdf', $pdf->headers->get('content-disposition'));

        $staffUser = User::factory()->create();
        $this->actingAs($staffUser)->get('/admin/api/reports/export/pdf?type=leave-outstanding&year=2026')->assertForbidden();
    }

    private function createEmployee(Department $department, string $code, string $name, string $startedOn = '2026-01-01', ?EmployeeLevel $level = null): Employee
    {
        $user = User::factory()->create(['name' => $name]);

        return Employee::query()->create([
            'code' => $code,
            'name' => $name,
            'user_id' => $user->id,
            'department_id' => $department->id,
            'position_id' => Position::query()->value('id'),
            'level_id' => $level?->id,
            'status' => 'active',
            'started_on' => $startedOn,
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

    private function createReviewEvent(Employee $employee, $session, string $suffix): AttendanceEvent
    {
        return AttendanceEvent::query()->create([
            'event_uuid' => $suffix,
            'payload_hash' => hash('sha256', $suffix),
            'employee_id' => $employee->id,
            'shift_session_id' => $session->id,
            'type' => 'CHECK_IN',
            'source' => 'OFFLINE',
            'server_received_at' => now(),
            'effective_at' => now(),
            'result' => 'REVIEW_REQUIRED',
            'created_at' => now(),
        ]);
    }
}
