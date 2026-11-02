<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');
    }

    public function test_dashboard_counts_real_schedules_and_attendance_records(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();
        $first = $this->schedule($engineering, 'MON-001', 'Mona Sari');
        $second = $this->schedule($frontOffice, 'MON-002', 'Rudi Aman');
        AttendanceRecord::query()->create([
            'shift_session_id' => $first->sessions->first()->id,
            'employee_id' => $first->employee_id,
            'check_in_at' => '2026-10-25 01:05:00',
            'check_out_at' => '2026-10-25 10:00:00',
            'status' => 'COMPLETE',
            'verified_minutes' => 465,
        ]);
        AttendanceRecord::query()->create([
            'shift_session_id' => $second->sessions->first()->id,
            'employee_id' => $second->employee_id,
            'check_in_at' => '2026-10-25 01:10:00',
            'provisional_out_at' => '2026-10-25 10:00:00',
            'status' => 'PROVISIONAL',
            'needs_review' => true,
            'review_reason' => 'AUTO_CHECKOUT',
            'verified_minutes' => 0,
        ]);
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)
            ->getJson('/admin/api/dashboard/attendance?from=2026-10-25&to=2026-10-25')
            ->assertOk()
            ->assertJsonPath('summary.scheduled_employees', 2)
            ->assertJsonPath('summary.scheduled_sessions', 2)
            ->assertJsonPath('summary.checked_in_sessions', 2)
            ->assertJsonPath('summary.review_sessions', 1)
            ->assertJsonPath('summary.verified_minutes', 465)
            ->assertJsonCount(2, 'recent')
            ->assertJsonFragment(['review_reason' => 'AUTO_CHECKOUT']);
    }

    public function test_supervisor_monitoring_and_filter_options_stay_in_their_department(): void
    {
        $engineering = Department::query()->where('name', 'Engineering')->firstOrFail();
        $frontOffice = Department::query()->where('name', 'Front Office')->firstOrFail();
        $visible = $this->schedule($engineering, 'MON-003', 'Nia Utami');
        $hidden = $this->schedule($frontOffice, 'MON-004', 'Doni Pratama');
        $supervisor = User::factory()->role('supervisor')->create(['department_id' => $engineering->id]);

        $this->actingAs($supervisor)
            ->getJson('/admin/api/attendance?from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.name', 'Nia Utami');

        $this->getJson('/admin/api/attendance/filters')
            ->assertOk()->assertJsonCount(1, 'employees')->assertJsonPath('employees.0.id', $visible->employee_id);

        $this->getJson('/admin/api/dashboard/attendance?from=2026-10-25&to=2026-10-25&department_id='.$frontOffice->id)
            ->assertOk()->assertJsonPath('summary.scheduled_employees', 0)->assertJsonCount(0, 'recent');

        $this->assertNotSame($visible->employee_id, $hidden->employee_id);
    }

    public function test_monitoring_status_filter_matches_the_summary_cards(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();
        $cases = [
            ['code' => 'MON-COMP', 'name' => 'Hadir Selesai', 'state' => 'complete', 'expected' => 'COMPLETE', 'record' => [
                'check_in_at' => '2026-10-25 01:00:00', 'check_out_at' => '2026-10-25 10:00:00', 'is_late' => false, 'needs_review' => false,
            ]],
            ['code' => 'MON-PROG', 'name' => 'Sedang Bekerja', 'state' => 'in_progress', 'expected' => 'IN_PROGRESS', 'record' => [
                'check_in_at' => '2026-10-25 01:00:00', 'check_out_at' => null, 'is_late' => false, 'needs_review' => false,
            ]],
            ['code' => 'MON-LATE', 'name' => 'Terlambat', 'state' => 'late', 'expected' => 'PRESENT_LATE', 'record' => [
                'check_in_at' => '2026-10-25 01:30:00', 'check_out_at' => null, 'is_late' => true, 'needs_review' => false,
            ]],
            ['code' => 'MON-REV', 'name' => 'Perlu Review', 'state' => 'review', 'expected' => 'NEEDS_REVIEW', 'record' => [
                'check_in_at' => '2026-10-25 01:00:00', 'check_out_at' => null, 'is_late' => false, 'needs_review' => true,
            ]],
        ];

        foreach ($cases as $case) {
            $schedule = $this->schedule($department, $case['code'], $case['name']);
            AttendanceRecord::query()->create([
                'shift_session_id' => $schedule->sessions->first()->id,
                'employee_id' => $schedule->employee_id,
                'status' => 'IN_PROGRESS',
                'verified_minutes' => 0,
                ...$case['record'],
            ]);
        }

        $admin = User::factory()->role('admin')->create();
        foreach ($cases as $case) {
            $this->actingAs($admin)
                ->getJson('/admin/api/attendance?from=2026-10-25&to=2026-10-25&state='.$case['state'])
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.attendance.state', $case['expected'])
                ->assertJsonPath('summary.total', 4);
        }

        $this->getJson('/admin/api/attendance?from=2026-10-25&to=2026-10-25&state=unknown')->assertUnprocessable();
    }

    public function test_dashboard_recent_attendance_list_is_paginated(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();
        for ($index = 1; $index <= 9; $index++) {
            $schedule = $this->schedule($department, sprintf('MON-PAGE-%02d', $index), sprintf('Karyawan %02d', $index));
            AttendanceRecord::query()->create([
                'shift_session_id' => $schedule->sessions->first()->id,
                'employee_id' => $schedule->employee_id,
                'check_in_at' => '2026-10-25 01:00:00',
                'status' => 'IN_PROGRESS',
                'is_late' => false,
                'needs_review' => false,
            ]);
        }

        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)
            ->getJson('/admin/api/dashboard/attendance?from=2026-10-25&to=2026-10-25&recent_page=1')
            ->assertOk()
            ->assertJsonCount(8, 'recent')
            ->assertJsonPath('recent_meta.total', 9)
            ->assertJsonPath('recent_meta.last_page', 2);

        $this->getJson('/admin/api/dashboard/attendance?from=2026-10-25&to=2026-10-25&recent_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'recent')
            ->assertJsonPath('recent_meta.current_page', 2);
    }

    public function test_admin_screen_uses_dashboard_and_monitoring_controls_without_demo_data(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();

        $this->actingAs($supervisor)->get('/admin')->assertOk()
            ->assertSee('id="dashboard-filters"', false)
            ->assertSee('id="monitoring-filters"', false)
            ->assertSee('id="dashboard-activity"', false)
            ->assertSee('id="dashboard-activity-pagination"', false)
            ->assertSee('data-monitoring-state="review"', false)
            ->assertSee('data-user-id="'.$supervisor->id.'"', false)
            ->assertDontSee('Data contoh');
    }

    private function schedule(Department $department, string $code, string $name)
    {
        $user = User::factory()->create(['name' => $name]);
        $employee = Employee::query()->create([
            'code' => $code,
            'name' => $name,
            'user_id' => $user->id,
            'department_id' => $department->id,
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
        ]);
        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $schedule = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $admin);
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$employee->id]);

        return $schedule->fresh(['sessions', 'employee']);
    }
}
