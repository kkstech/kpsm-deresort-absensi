<?php

namespace Tests\Feature;

use App\Models\AttendancePeriod;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ShiftAssignment;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00'); // 10.00 WIB
        $this->employee = $this->makeEmployee('FO-1', 'Front Office');
        $this->actingAs(User::factory()->role('admin')->create());
    }

    public function test_night_shift_keeps_parent_work_date(): void
    {
        $this->assign('2026-10-25', 'Malam')
            ->assertCreated()
            ->assertJsonPath('data.work_date', '2026-10-25')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.sessions.0.start_at', '2026-10-25T15:00:00Z')
            ->assertJsonPath('data.sessions.0.end_at', '2026-10-25T23:00:00Z')
            ->assertJsonPath('data.sessions.0.local_end', '06.00 (+1)')
            ->assertJsonPath('data.sessions.0.scheduled_minutes', 480);
    }

    public function test_scheduled_minutes_follow_prd_examples(): void
    {
        $this->assign('2026-10-25', 'Reguler')->assertJsonPath('data.sessions.0.scheduled_minutes', 480);
        $two = $this->assign('2026-10-26', 'Khusus 2 sesi')->json('data.sessions');
        $this->assertCount(2, $two);
        $this->assertSame(360, array_sum(array_column($two, 'scheduled_minutes')));
    }

    public function test_overlap_across_work_dates_is_rejected(): void
    {
        $this->assign('2026-10-25', 'Malam')->assertCreated();
        $early = ShiftTemplate::query()->create(['name' => 'Subuh', 'type' => 'regular']);
        $early->sessions()->create(['seq' => 1, 'local_start' => '05:00', 'local_end' => '09:00', 'day_offset' => 0, 'unpaid_break_minutes' => 0]);

        $this->postJson('/admin/api/schedule', ['employee_id' => $this->employee->id, 'work_date' => '2026-10-26', 'shift_template_id' => $early->id])
            ->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_OVERLAP');

        $this->assign('2026-10-26', 'Khusus 2 sesi')->assertCreated();
    }

    public function test_same_date_replaces_and_bumps_revision(): void
    {
        $this->assign('2026-10-25', 'Reguler');
        $this->assign('2026-10-25', 'Khusus 3 sesi')->assertCreated()->assertJsonPath('data.revision', 2);
        $this->assertSame(1, ShiftAssignment::query()->count());
        $this->assertDatabaseCount('shift_sessions', 3);
    }

    public function test_started_schedule_is_locked(): void
    {
        $this->assign('2026-10-24', 'Reguler')->assertStatus(409)->assertJsonPath('code', 'SESSION_STARTED');

        $id = $this->assign('2026-10-25', 'Reguler')->json('data.id');
        $this->travelTo('2026-10-25 01:30:00'); // 08.30 WIB
        $this->deleteJson("/admin/api/schedule/{$id}")->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_LOCKED');
        $this->assign('2026-10-25', 'Malam')->assertStatus(409);
    }

    public function test_finalized_period_rejects_changes(): void
    {
        AttendancePeriod::query()->create(['month' => '2026-11', 'status' => 'FINALIZED']);
        $this->assign('2026-11-02', 'Reguler')->assertStatus(409)->assertJsonPath('code', 'PERIOD_LOCKED');
    }

    public function test_inactive_employee_cannot_be_scheduled(): void
    {
        $this->employee->update(['ended_on' => '2026-10-30']);
        $this->assign('2026-10-31', 'Reguler')->assertStatus(409)->assertJsonPath('code', 'EMPLOYEE_INACTIVE');
    }

    public function test_publish_cancel_and_copy_week(): void
    {
        $this->assign('2026-10-26', 'Reguler');
        $this->assign('2026-10-27', 'Malam');
        $cancelId = $this->assign('2026-10-28', 'Reguler')->json('data.id');
        $this->deleteJson("/admin/api/schedule/{$cancelId}")->assertOk();

        $this->postJson('/admin/api/schedule/publish', ['from' => '2026-10-26', 'to' => '2026-11-01'])->assertOk()->assertJsonPath('published', 2);
        $this->getJson('/admin/api/schedule?from=2026-10-26&to=2026-11-01')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'PUBLISHED');

        $this->assign('2026-11-03', 'Khusus 2 sesi');
        $this->postJson('/admin/api/schedule/copy-week', ['from' => '2026-10-26', 'to' => '2026-11-02'])
            ->assertOk()->assertJsonPath('created', 1)->assertJsonPath('skipped.0.work_date', '2026-11-03');
        $this->assertSame('DRAFT', ShiftAssignment::query()->where('work_date', '2026-11-02')->value('status'));

        $this->postJson('/admin/api/schedule/copy-week', ['from' => '2026-10-27', 'to' => '2026-11-03'])->assertStatus(422);
    }

    public function test_supervisor_reads_own_team_only_and_cannot_write(): void
    {
        $other = $this->makeEmployee('FIN-1', 'Finance');
        $this->assign('2026-10-25', 'Reguler');
        $this->postJson('/admin/api/schedule', ['employee_id' => $other->id, 'work_date' => '2026-10-25', 'shift_template_id' => ShiftTemplate::query()->value('id')])->assertCreated();

        $supervisor = User::factory()->role('supervisor')->create(['department_id' => $other->department_id]);
        $this->actingAs($supervisor)->getJson('/admin/api/schedule?from=2026-10-19&to=2026-10-25')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $other->id);
        $this->postJson('/admin/api/schedule', ['employee_id' => $other->id, 'work_date' => '2026-10-26', 'shift_template_id' => ShiftTemplate::query()->value('id')])->assertStatus(403);
    }

    private function assign(string $date, string $template, ?Employee $employee = null)
    {
        return $this->postJson('/admin/api/schedule', [
            'employee_id' => ($employee ?? $this->employee)->id,
            'work_date' => $date,
            'shift_template_id' => ShiftTemplate::query()->where('name', $template)->value('id'),
        ]);
    }

    private function makeEmployee(string $code, string $department): Employee
    {
        return Employee::query()->create([
            'code' => $code, 'name' => "Karyawan {$code}", 'status' => 'active', 'started_on' => '2026-01-01',
            'department_id' => Department::query()->where('name', $department)->value('id'), 'position_id' => Position::query()->value('id'),
        ]);
    }
}
