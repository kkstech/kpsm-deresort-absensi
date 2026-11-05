<?php

namespace Tests\Feature;

use App\Actions\RecordAttendance;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Position;
use App\Models\ShiftAssignment;
use App\Models\ShiftCategory;
use App\Models\ShiftSession;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private int $shiftCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00'); // 10.00 WIB
        $this->shiftCategoryId = ShiftCategory::query()->create(['name' => 'Shift Testing'])->id;
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

    public function test_custom_cycle_allows_up_to_four_sessions_across_midnight(): void
    {
        $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-25',
            'name' => 'Split malam',
            'shift_category_id' => $this->shiftCategoryId,
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '22:00', 'end_date' => '2026-10-26', 'end_time' => '02:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '04:00', 'end_date' => '2026-10-26', 'end_time' => '08:00'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.rules_version', 2)
            ->assertJsonPath('data.template_type', 'special')
            ->assertJsonPath('data.sessions.0.local_end', '02.00 (+1)')
            ->assertJsonPath('data.scheduled_minutes', 480);
    }

    public function test_custom_cycle_cannot_span_more_than_24_hours(): void
    {
        $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-25',
            'name' => 'Split melewati batas siklus',
            'shift_category_id' => $this->shiftCategoryId,
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '08:00', 'end_date' => '2026-10-25', 'end_time' => '12:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '07:00', 'end_date' => '2026-10-26', 'end_time' => '08:01'],
            ],
        ])->assertStatus(409)->assertJsonPath('code', 'CYCLE_SPAN_LIMIT');
    }

    public function test_special_cycle_allows_16_work_hours_over_24_clock_hours(): void
    {
        $response = $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-25',
            'name' => 'Split penuh dengan shift malam',
            'shift_category_id' => $this->shiftCategoryId,
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '07:00', 'end_date' => '2026-10-25', 'end_time' => '15:00'],
                ['seq' => 2, 'start_date' => '2026-10-25', 'start_time' => '23:00', 'end_date' => '2026-10-26', 'end_time' => '07:00'],
            ],
        ])->assertCreated()->assertJsonPath('data.scheduled_minutes', 960);

        $this->assertSame(2, ShiftAssignment::query()->findOrFail($response->json('data.id'))->sessions()->count());
    }

    public function test_custom_cycle_rejects_more_than_16_scheduled_work_hours(): void
    {
        $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-25',
            'name' => 'Split melebihi batas kerja',
            'shift_category_id' => $this->shiftCategoryId,
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '07:00', 'end_date' => '2026-10-25', 'end_time' => '15:00'],
                ['seq' => 2, 'start_date' => '2026-10-25', 'start_time' => '22:00', 'end_date' => '2026-10-26', 'end_time' => '07:00'],
            ],
        ])->assertStatus(409)->assertJsonPath('code', 'CYCLE_WORK_LIMIT');
    }

    public function test_schedule_calendar_can_request_one_employee_page_and_caps_ids_at_ten(): void
    {
        $other = $this->makeEmployee('FO-2', 'Front Office');
        $this->assign('2026-10-25', 'Reguler');
        $this->assign('2026-10-25', 'Malam', $other);

        $query = http_build_query([
            'from' => '2026-10-25',
            'to' => '2026-10-25',
            'employee_ids' => [$other->id],
        ]);
        $this->getJson("/admin/api/schedule?{$query}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $other->id);

        $ids = range(1, 11);
        $oversized = http_build_query(['from' => '2026-10-25', 'to' => '2026-10-25', 'employee_ids' => $ids]);
        $this->getJson("/admin/api/schedule?{$oversized}")->assertUnprocessable();
    }

    public function test_running_cycle_edit_keeps_attended_session_and_retires_stale_future_session(): void
    {
        $created = $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-25',
            'name' => 'Split',
            'shift_category_id' => $this->shiftCategoryId,
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '10:00', 'end_date' => '2026-10-25', 'end_time' => '12:00'],
                ['seq' => 2, 'start_date' => '2026-10-25', 'start_time' => '13:00', 'end_date' => '2026-10-25', 'end_time' => '16:00'],
            ],
        ])->assertCreated();
        $assignmentId = $created->json('data.id');
        $firstId = $created->json('data.sessions.0.id');
        $staleId = $created->json('data.sessions.1.id');
        $this->postJson('/admin/api/schedule/publish', ['from' => '2026-10-25', 'to' => '2026-10-25'])->assertOk();
        $this->travelTo('2026-10-25 05:30:00');
        AttendanceRecord::query()->create([
            'shift_session_id' => $firstId, 'employee_id' => $this->employee->id,
            'check_in_at' => '2026-10-25 03:00:00', 'check_out_at' => '2026-10-25 05:00:00',
            'status' => 'COMPLETE', 'verified_minutes' => 120,
        ]);
        $assignment = ShiftAssignment::query()->findOrFail($assignmentId);
        $assignment->update(['status' => 'IN_PROGRESS']);

        $response = $this->patchJson("/admin/api/schedule/{$assignmentId}/sessions", [
            'expected_revision' => 1,
            'reason' => 'Operasional berubah',
            'sessions' => [
                ['id' => $firstId, 'seq' => 1, 'start_date' => '2026-10-25', 'start_time' => '10:00', 'end_date' => '2026-10-25', 'end_time' => '12:00'],
                ['id' => $staleId, 'seq' => 2, 'start_date' => '2026-10-25', 'start_time' => '14:00', 'end_date' => '2026-10-25', 'end_time' => '18:00'],
            ],
        ])->assertOk()->assertJsonPath('data.revision', 2);

        $newId = $response->json('data.sessions.1.id');
        $this->assertNotSame($staleId, $newId);
        $this->assertDatabaseHas('shift_sessions', ['id' => $firstId, 'retired_at' => null]);
        $this->assertDatabaseHas('shift_sessions', ['id' => $staleId]);
        $this->assertNotNull(ShiftSession::query()->findOrFail($staleId)->retired_at);

        $result = app(RecordAttendance::class)->handle($this->employee, [
            'event_uuid' => '44444444-4444-4444-8444-444444444444',
            'shift_session_id' => $staleId,
            'event_type' => 'CHECK_IN',
            'device_id' => 'stale-phone',
            'captured_offline' => false,
        ]);
        $this->assertSame('SCHEDULE_CHANGED', $result['data']['reason_code']);
        $this->assertSame('REJECTED', $result['data']['status']);
    }

    public function test_schedule_swap_requires_preview_and_keeps_slot_sessions_audited(): void
    {
        $other = $this->makeEmployee('FO-2', 'Front Office');
        $first = $this->assign('2026-10-26', 'Reguler')->assertCreated()->json('data');
        $second = $this->assign('2026-10-26', 'Reguler', $other)->assertCreated()->json('data');
        $moves = [
            ['assignment_id' => $first['id'], 'expected_revision' => $first['revision'], 'to_employee_id' => $other->id],
            ['assignment_id' => $second['id'], 'expected_revision' => $second['revision'], 'to_employee_id' => $this->employee->id],
        ];

        $preview = $this->postJson('/admin/api/schedule/swap/preview', ['moves' => $moves])
            ->assertOk()->assertJsonPath('can_commit', true);
        $oldSessionIds = [$first['sessions'][0]['id'], $second['sessions'][0]['id']];
        $this->postJson('/admin/api/schedule/swap', [
            'moves' => $moves, 'preview_token' => $preview->json('preview_token'), 'reason' => 'Tukar atas permintaan karyawan',
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('shift_assignments', ['id' => $first['id'], 'employee_id' => $other->id, 'revision' => 2]);
        $this->assertDatabaseHas('shift_assignments', ['id' => $second['id'], 'employee_id' => $this->employee->id, 'revision' => 2]);
        foreach ($oldSessionIds as $sessionId) {
            $this->assertNotNull(ShiftSession::query()->findOrFail($sessionId)->retired_at);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule.swap_chain_completed']);
    }

    public function test_swap_preview_requires_an_explicit_decision_for_unfilled_cycle(): void
    {
        $target = $this->makeEmployee('FIN-2', 'Finance');
        $schedule = $this->assign('2026-10-26', 'Reguler')->assertCreated()->json('data');
        $moves = [['assignment_id' => $schedule['id'], 'expected_revision' => $schedule['revision'], 'to_employee_id' => $target->id]];

        $this->postJson('/admin/api/schedule/swap/preview', ['moves' => $moves])
            ->assertOk()->assertJsonPath('can_commit', false)->assertJsonPath('vacancies.0.employee_id', $this->employee->id);
        $decision = ['employee_id' => $this->employee->id, 'work_date' => '2026-10-26', 'action' => 'roster_off', 'reason' => 'Karyawan libur sesuai roster'];
        $ready = $this->postJson('/admin/api/schedule/swap/preview', ['moves' => $moves, 'vacancy_decisions' => [$decision], 'confirm_cross_unit' => true])
            ->assertOk()->assertJsonPath('can_commit', true);

        $this->postJson('/admin/api/schedule/swap', [
            'moves' => $moves, 'vacancy_decisions' => [$decision], 'confirm_cross_unit' => true, 'preview_token' => $ready->json('preview_token'), 'reason' => 'Permintaan tukar shift',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule.roster_off_recorded']);
    }

    public function test_schedule_swap_accepts_a_dynamic_leave_type_for_a_vacancy(): void
    {
        $replacement = $this->makeEmployee('FO-DYNAMIC', 'Front Office');
        LeaveType::query()->create([
            'code' => 'birthday_leave', 'name' => 'Cuti Ulang Tahun', 'default_quota' => 1, 'is_active' => true,
        ]);
        $year = LeaveYear::query()->create(['year' => 2026, 'opening_confirmed_at' => '2026-10-24 03:00:00']);
        $year->ensureTypeQuotas();
        $schedule = $this->assign('2026-10-26', 'Reguler')->assertCreated()->json('data');
        $moves = [[
            'assignment_id' => $schedule['id'],
            'expected_revision' => $schedule['revision'],
            'to_employee_id' => $replacement->id,
        ]];
        $decision = [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-26',
            'action' => 'birthday_leave',
            'reason' => 'Cuti ulang tahun',
        ];

        $preview = $this->postJson('/admin/api/schedule/swap/preview', [
            'moves' => $moves,
            'vacancy_decisions' => [$decision],
        ])->assertOk()->assertJsonPath('can_commit', true);

        $this->postJson('/admin/api/schedule/swap', [
            'moves' => $moves,
            'vacancy_decisions' => [$decision],
            'preview_token' => $preview->json('preview_token'),
            'reason' => 'Pindah jadwal sesuai permintaan',
        ])->assertOk();

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-26',
            'type' => 'birthday_leave',
            'status' => 'APPROVED',
        ]);
    }

    public function test_schedule_swap_with_chain_replacement_to_another_employee(): void
    {
        $firstEmployee = $this->employee;
        $replacementEmployee = $this->makeEmployee('FO-B', 'Front Office');
        $thirdEmployee = $this->makeEmployee('FO-C', 'Front Office');

        $firstShift = $this->assign('2026-10-26', 'Reguler', $firstEmployee)->assertCreated()->json('data');
        $secondShift = $this->assign('2026-10-26', 'Reguler', $replacementEmployee)->assertCreated()->json('data');

        $moves = [
            ['assignment_id' => $firstShift['id'], 'expected_revision' => $firstShift['revision'], 'to_employee_id' => $replacementEmployee->id],
            ['assignment_id' => $secondShift['id'], 'expected_revision' => $secondShift['revision'], 'to_employee_id' => $thirdEmployee->id],
        ];

        $decision = ['employee_id' => $firstEmployee->id, 'work_date' => '2026-10-26', 'action' => 'roster_off', 'reason' => 'Off setelah jadwal dialihkan'];
        $ready = $this->postJson('/admin/api/schedule/swap/preview', [
            'moves' => $moves,
            'vacancy_decisions' => [$decision],
            'confirm_cross_unit' => true,
        ])->assertOk()->assertJsonPath('can_commit', true);

        $this->postJson('/admin/api/schedule/swap', [
            'moves' => $moves,
            'vacancy_decisions' => [$decision],
            'confirm_cross_unit' => true,
            'preview_token' => $ready->json('preview_token'),
            'reason' => 'Rantai tukar jadwal ke karyawan lain',
        ])->assertOk();

        $this->assertDatabaseHas('shift_assignments', ['id' => $firstShift['id'], 'employee_id' => $replacementEmployee->id, 'revision' => 2]);
        $this->assertDatabaseHas('shift_assignments', ['id' => $secondShift['id'], 'employee_id' => $thirdEmployee->id, 'revision' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule.swap_chain_completed']);
    }

    public function test_employees_endpoint_returns_position_for_dropdown_grouping(): void
    {
        $response = $this->getJson('/admin/api/employees?status=active')->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'department' => ['id', 'name'],
                    'position' => ['id', 'name'],
                ],
            ],
        ]);
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
        $this->assertDatabaseCount('shift_sessions', 4);
        $this->assertSame(3, ShiftAssignment::query()->first()->sessions()->count());
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

    public function test_copy_month(): void
    {
        $this->assign('2026-11-01', 'Reguler');
        $this->assign('2026-11-02', 'Malam');
        $this->assign('2026-11-15', 'Khusus 2 sesi');

        // Pre-existing schedule in December 2026 on the 2nd
        $this->assign('2026-12-02', 'Reguler');

        $response = $this->postJson('/admin/api/schedule/copy-month', [
            'from' => '2026-11-01',
            'to' => '2026-12-01',
        ])->assertOk();

        $response->assertJsonPath('created', 2)
            ->assertJsonPath('skipped.0.work_date', '2026-12-02');

        $this->assertSame('DRAFT', ShiftAssignment::query()->where('work_date', '2026-12-01')->value('status'));
        $this->assertSame('DRAFT', ShiftAssignment::query()->where('work_date', '2026-12-15')->value('status'));

        // Reject if same month
        $this->postJson('/admin/api/schedule/copy-month', [
            'from' => '2026-11-01',
            'to' => '2026-11-01',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_copy_month_skips_out_of_range_days(): void
    {
        // 2026-10 has 31 days. 2026-11 has 30 days.
        $this->assign('2026-10-31', 'Reguler');
        $response = $this->postJson('/admin/api/schedule/copy-month', [
            'from' => '2026-10-01',
            'to' => '2026-11-01',
        ])->assertOk();

        $response->assertJsonPath('created', 0)
            ->assertJsonPath('skipped.0.work_date', '2026-10-31');
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

    public function test_bulk_schedule_assigns_multiple_employees_and_dates(): void
    {
        $emp2 = $this->makeEmployee('FO-2', 'Front Office');
        $template = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();

        $response = $this->postJson('/admin/api/schedule/bulk', [
            'employee_ids' => [$this->employee->id, $emp2->id],
            'from' => '2026-10-26', // Senin
            'to' => '2026-10-28',   // Rabu
            'days_of_week' => [1, 2, 3],
            'shift_template_id' => $template->id,
        ])->assertOk();

        $response->assertJsonPath('created', 6)
            ->assertJsonPath('skipped', []);

        $this->assertDatabaseCount('shift_assignments', 6);
        $this->assertDatabaseHas('shift_assignments', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-26',
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseHas('shift_assignments', [
            'employee_id' => $emp2->id,
            'work_date' => '2026-10-28',
            'status' => 'DRAFT',
        ]);
    }

    public function test_bulk_schedule_skips_unselected_days(): void
    {
        $template = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();

        $response = $this->postJson('/admin/api/schedule/bulk', [
            'employee_ids' => [$this->employee->id],
            'from' => '2026-10-26', // Senin (1)
            'to' => '2026-11-01',   // Minggu (0) - 7 hari total
            'days_of_week' => [1, 5], // Hanya Senin & Jumat
            'shift_template_id' => $template->id,
        ])->assertOk();

        $response->assertJsonPath('created', 2)
            ->assertJsonPath('skipped', []);

        $this->assertDatabaseCount('shift_assignments', 2);
        $this->assertDatabaseHas('shift_assignments', ['work_date' => '2026-10-26']);
        $this->assertDatabaseHas('shift_assignments', ['work_date' => '2026-10-30']); // Jumat
        $this->assertDatabaseMissing('shift_assignments', ['work_date' => '2026-10-27']); // Selasa
    }

    public function test_bulk_schedule_skips_existing_unless_overwrite_true(): void
    {
        $templateReg = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();
        $templateMalam = ShiftTemplate::query()->where('name', 'Malam')->firstOrFail();

        // Buat jadwal manual di 2026-10-26
        $this->assign('2026-10-26', 'Reguler')->assertCreated();

        // Bulk assign dengan template Malam tanpa overwrite
        $response = $this->postJson('/admin/api/schedule/bulk', [
            'employee_ids' => [$this->employee->id],
            'from' => '2026-10-26',
            'to' => '2026-10-27',
            'days_of_week' => [1, 2],
            'shift_template_id' => $templateMalam->id,
            'overwrite' => false,
        ])->assertOk();

        $response->assertJsonPath('created', 1)
            ->assertJsonCount(1, 'skipped')
            ->assertJsonPath('skipped.0.work_date', '2026-10-26')
            ->assertJsonPath('skipped.0.reason', 'Sudah ada jadwal draft.');

        // 2026-10-26 tetap Reguler
        $this->assertDatabaseHas('shift_assignments', [
            'work_date' => '2026-10-26',
            'shift_template_id' => $templateReg->id,
        ]);

        // Bulk assign lagi dengan overwrite = true
        $responseOverwrite = $this->postJson('/admin/api/schedule/bulk', [
            'employee_ids' => [$this->employee->id],
            'from' => '2026-10-26',
            'to' => '2026-10-27',
            'days_of_week' => [1, 2],
            'shift_template_id' => $templateMalam->id,
            'overwrite' => true,
        ])->assertOk();

        // Keduanya ditimpa / di-upsert menjadi Malam
        $responseOverwrite->assertJsonPath('created', 2)
            ->assertJsonPath('skipped', []);

        $this->assertDatabaseHas('shift_assignments', [
            'work_date' => '2026-10-26',
            'shift_template_id' => $templateMalam->id,
        ]);
    }

    public function test_bulk_schedule_protects_running_schedules(): void
    {
        $templateReg = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();
        $templateMalam = ShiftTemplate::query()->where('name', 'Malam')->firstOrFail();

        $assignment = $this->assign('2026-10-26', 'Reguler');
        $assId = $assignment->json('data.id');

        ShiftAssignment::query()->where('id', $assId)->update(['status' => 'IN_PROGRESS']);

        $response = $this->postJson('/admin/api/schedule/bulk', [
            'employee_ids' => [$this->employee->id],
            'from' => '2026-10-26',
            'to' => '2026-10-26',
            'days_of_week' => [1],
            'shift_template_id' => $templateMalam->id,
            'overwrite' => true,
        ])->assertOk();

        $response->assertJsonPath('created', 0)
            ->assertJsonCount(1, 'skipped')
            ->assertJsonPath('skipped.0.work_date', '2026-10-26');

        $this->assertDatabaseHas('shift_assignments', [
            'id' => $assId,
            'status' => 'IN_PROGRESS',
            'shift_template_id' => $templateReg->id,
        ]);
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
        return Employee::query()->create($this->completeEmployeeAttrs([
            'code' => $code, 'name' => "Karyawan {$code}", 'status' => 'active', 'started_on' => '2026-01-01',
            'department_id' => Department::query()->where('name', $department)->value('id'), 'position_id' => Position::query()->value('id'),
        ]));
    }
}
