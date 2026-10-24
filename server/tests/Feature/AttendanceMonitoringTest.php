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

    public function test_admin_screen_uses_dashboard_and_monitoring_controls_without_demo_data(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();

        $this->actingAs($supervisor)->get('/admin')->assertOk()
            ->assertSee('id="dashboard-filters"', false)
            ->assertSee('id="monitoring-filters"', false)
            ->assertSee('id="dashboard-activity"', false)
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
