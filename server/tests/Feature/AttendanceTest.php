<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Actions\RecordAttendance;
use App\Actions\RefreshOpenPolicySnapshots;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceEvent;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ShiftSession;
use App\Models\ShiftTemplate;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\AttendanceWindow;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private DeviceRegistration $device;

    private string $token;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');
        $user = User::factory()->create();
        $this->employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'ATT-001',
            'name' => $user->name,
            'user_id' => $user->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
        ]));
        $this->device = DeviceRegistration::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'attendance-phone',
            'name' => 'Test phone',
        ]);
        $this->token = $user->createToken("device:{$this->device->id}")->plainTextToken;

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $schedules = app(PublishSchedule::class);
        $assignment = $schedules->upsert($this->employee, '2026-10-25', $template, $admin);
        $schedules->publish('2026-10-25', '2026-10-25', [$this->employee->id]);
        $this->sessionId = $assignment->sessions()->value('id');
    }

    public function test_mobile_schedule_event_sync_and_history_are_scoped_to_authenticated_employee(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/me/schedule?from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'PUBLISHED');

        $this->travelTo('2026-10-25 01:10:00');
        $event = $this->event('11111111-1111-4111-8111-111111111111', [
            'device_at' => '2026-10-24T03:00:00Z',
        ]);
        $invalid = $this->event('22222222-2222-4222-8222-222222222222', [
            'captured_offline' => true,
            'device_at' => null,
            'local_sequence' => 2,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/sync', [
                'batch_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'events' => [$event, $invalid],
            ])
            ->assertOk()->assertJsonPath('results.0.status', 'ACCEPTED')
            ->assertJsonPath('results.0.effective_at', '2026-10-25T01:10:00Z')
            ->assertJsonPath('results.1.status', 'REJECTED')
            ->assertJsonPath('results.1.reason_code', 'OFFLINE_ATTENDANCE_DISABLED');

        $this->getJson('/api/v1/attendance/me?from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonPath('data.0.sessions.0.attendance.status', 'IN_PROGRESS')
            ->assertJsonCount(2, 'data.0.sessions.0.events');

        $this->assertDatabaseHas('sync_batches', ['batch_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'event_count' => 2]);
        $this->assertDatabaseHas('attendance_events', ['event_uuid' => $event['event_uuid'], 'device_registration_id' => $this->device->id]);
    }

    public function test_online_event_endpoint_uses_server_time_and_retries_without_duplicate_records(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        $input = $this->event('abababab-abab-4bab-8bab-abababababab', [
            'device_at' => '2026-10-24T03:00:00Z',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $input)
            ->assertCreated()->assertJsonPath('data.effective_at', '2026-10-25T01:10:00Z');
        $this->postJson('/api/v1/attendance/events', $input)->assertOk()->assertJsonPath('data.status', 'DUPLICATE');
        $this->postJson('/api/v1/attendance/events', $input)->assertOk()->assertJsonPath('data.status', 'DUPLICATE');

        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_offline_attendance_is_rejected_without_using_device_time(): void
    {
        $this->travelTo('2026-10-25 12:00:00');
        $input = $this->event('33333333-3333-4333-8333-333333333333', [
            'device_at' => '2026-10-25T01:05:00Z',
            'captured_offline' => true,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $input)
            ->assertCreated()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.reason_code', 'OFFLINE_ATTENDANCE_DISABLED')
            ->assertJsonPath('data.effective_at', '2026-10-25T12:00:00Z');

        $this->assertDatabaseHas('attendance_events', [
            'event_uuid' => $input['event_uuid'],
            'result' => 'REJECTED',
            'reason_code' => 'OFFLINE_ATTENDANCE_DISABLED',
            'effective_at' => '2026-10-25 12:00:00',
        ]);
        $this->assertDatabaseMissing('attendance_records', ['shift_session_id' => $this->sessionId]);
    }

    public function test_late_checkout_is_recorded_with_original_time_and_requires_review(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a7777777-7777-4777-8777-777777777777'), $this->device->id);
        $this->travelTo('2026-10-25 11:01:00');
        $result = app(RecordAttendance::class)->handle($this->employee, $this->event('a8888888-8888-4888-8888-888888888888', ['event_type' => 'CHECK_OUT']), $this->device->id);

        $this->assertSame('REVIEW_REQUIRED', $result['data']['status']);
        $this->assertSame('CHECKOUT_LATE', $result['data']['reason_code']);
        $this->assertDatabaseHas('attendance_records', ['shift_session_id' => $this->sessionId, 'check_out_at' => '2026-10-25 11:01:00', 'provisional_out_at' => null, 'needs_review' => 1, 'review_reason' => 'CHECKOUT_LATE']);
    }

    public function test_checkout_within_grace_is_accepted_normally(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a9999999-9999-4999-8999-999999999999'), $this->device->id);
        $this->travelTo('2026-10-25 10:50:00');
        $result = app(RecordAttendance::class)->handle($this->employee, $this->event('b9999999-9999-4999-8999-999999999999', ['event_type' => 'CHECK_OUT']), $this->device->id);
        $this->assertSame('ACCEPTED', $result['data']['status']);
        $this->assertDatabaseHas('attendance_records', ['shift_session_id' => $this->sessionId, 'check_out_at' => '2026-10-25 10:50:00', 'needs_review' => 0]);
    }

    public function test_checkout_three_hours_after_shift_is_recorded_for_review(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('c7777777-7777-4777-8777-777777777777'), $this->device->id);
        $this->travelTo('2026-10-25 13:00:00');
        $result = app(RecordAttendance::class)->handle($this->employee, $this->event('c8888888-8888-4888-8888-888888888888', ['event_type' => 'CHECK_OUT']), $this->device->id);
        $this->assertSame('REVIEW_REQUIRED', $result['data']['status']);
        $this->assertDatabaseHas('attendance_records', ['shift_session_id' => $this->sessionId, 'check_out_at' => '2026-10-25 13:00:00', 'review_reason' => 'CHECKOUT_LATE']);
    }

    public function test_admin_can_configure_checkout_cutoff_and_grace(): void
    {
        $this->actingAs(User::factory()->role('admin')->create())
            ->putJson('/admin/api/attendance-settings', ['checkin_early_minutes' => 180, 'checkout_grace_minutes' => 45, 'checkout_cutoff_time' => '20:00'])
            ->assertOk()->assertJsonPath('data.checkout_grace_minutes', 45)->assertJsonPath('data.checkout_cutoff_time', '20:00');
        $session = ShiftSession::query()->findOrFail($this->sessionId);
        $this->assertSame('2026-10-25 13:00:00', AttendanceWindow::checkoutDeadline($session)->format('Y-m-d H:i:s'));
    }

    public function test_old_auto_checkout_can_be_replaced_before_the_new_deadline(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('b7777777-7777-4777-8777-777777777777'), $this->device->id);
        AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->update(['provisional_out_at' => '2026-10-25 10:00:00', 'status' => 'PROVISIONAL', 'needs_review' => true, 'review_reason' => 'AUTO_CHECKOUT']);
        $this->travelTo('2026-10-25 11:01:00');
        $result = app(RecordAttendance::class)->handle($this->employee, $this->event('b8888888-8888-4888-8888-888888888888', ['event_type' => 'CHECK_OUT']), $this->device->id);
        $this->assertSame('REVIEW_REQUIRED', $result['data']['status']);
        $this->assertDatabaseHas('attendance_records', ['shift_session_id' => $this->sessionId, 'check_out_at' => '2026-10-25 11:01:00', 'provisional_out_at' => null, 'review_reason' => 'CHECKOUT_LATE']);
    }

    public function test_night_shift_cutoff_uses_the_calendar_day_when_the_session_ends(): void
    {
        $session = ShiftSession::query()->findOrFail($this->sessionId);
        $session->update(['start_at' => '2026-10-25 15:00:00', 'end_at' => '2026-10-25 19:00:00']);

        $this->assertSame('2026-10-26 16:59:00', AttendanceWindow::checkoutDeadline($session->fresh())->format('Y-m-d H:i:s'));
    }

    public function test_auto_checkout_is_provisional_and_can_be_retried_without_duplicate_audit(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a1111111-1111-4111-8111-111111111111'), $this->device->id);
        $this->travelTo('2026-10-25 17:00:00');

        $this->artisan('attendance:auto-checkout')->assertExitCode(0);
        $this->artisan('attendance:auto-checkout')->assertExitCode(0);

        $record = AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->firstOrFail();
        $this->assertNull($record->check_out_at);
        $this->assertSame('2026-10-25 10:00:00', $record->provisional_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $record->verified_minutes);
        $this->assertTrue($record->needs_review);
        $this->assertSame('AUTO_CHECKOUT', $record->review_reason);
        $this->assertSame('COMPLETED', $record->session->assignment->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance.auto_checkout')->count());

        $admin = User::factory()->role('admin')->create();
        $this->actingAs($admin)
            ->getJson('/admin/api/attendance?from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonPath('data.0.attendance.state', 'NEEDS_REVIEW')
            ->assertJsonPath('data.0.attendance.review_reason', 'AUTO_CHECKOUT')
            ->assertJsonPath('data.0.attendance.verified_minutes', 0);
    }

    public function test_shift_three_checkin_closes_the_previous_session_without_blocking_the_next_shift(): void
    {
        $firstSession = ShiftSession::query()->findOrFail($this->sessionId);
        $firstSession->update(['start_at' => '2026-10-25 00:00:00', 'end_at' => '2026-10-25 08:00:00']);
        $third = ShiftTemplate::query()->create(['name' => 'Shift 3 23-07', 'type' => 'regular']);
        $third->sessions()->create(['seq' => 1, 'local_start' => '23:00', 'local_end' => '07:00', 'day_offset' => 1, 'unpaid_break_minutes' => 0]);
        $thirdAssignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-25', $third, User::factory()->role('admin')->create());
        app(PublishSchedule::class)->publish('2026-10-25', '2026-10-25', [$this->employee->id]);
        $thirdSession = $thirdAssignment->sessions->firstOrFail();
        $this->assertTrue(AttendanceWindow::checkoutDeadline($firstSession->fresh())->equalTo($thirdSession->start_at));

        $this->travelTo('2026-10-25 00:00:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a4444444-4444-4444-8444-444444444444'), $this->device->id);
        $this->travelTo('2026-10-25 16:00:00');
        $lateOldCheckout = app(RecordAttendance::class)->handle($this->employee, $this->event('a6666666-6666-4666-8666-666666666666', ['event_type' => 'CHECK_OUT']), $this->device->id);
        $this->assertSame('OUTSIDE_WINDOW', $lateOldCheckout['data']['reason_code']);
        $result = app(RecordAttendance::class)->handle($this->employee, $this->event('a5555555-5555-4555-8555-555555555555', [
            'shift_session_id' => $thirdSession->id,
        ]), $this->device->id);

        $this->assertSame('ACCEPTED', $result['data']['status']);
        $this->assertDatabaseHas('attendance_records', [
            'shift_session_id' => $this->sessionId,
            'provisional_out_at' => '2026-10-25 08:00:00',
        ]);
        $this->assertSame(0, AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->value('verified_minutes'));
        $this->assertNotNull(AttendanceRecord::query()->where('shift_session_id', $thirdSession->id)->value('check_in_at'));
    }

    public function test_offline_checkout_cannot_change_an_existing_attendance_record(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a2222222-2222-4222-8222-222222222222'), $this->device->id);
        $this->travelTo('2026-10-25 17:00:00');
        $this->artisan('attendance:auto-checkout')->assertExitCode(0);

        $checkout = $this->event('a3333333-3333-4333-8333-333333333333', [
            'event_type' => 'CHECK_OUT',
            'device_at' => '2026-10-25T10:00:00Z',
            'captured_offline' => true,
        ]);
        $result = app(RecordAttendance::class)->handle($this->employee, $checkout, $this->device->id);

        $this->assertSame('REJECTED', $result['data']['status']);
        $this->assertSame('OFFLINE_ATTENDANCE_DISABLED', $result['data']['reason_code']);
        $this->assertDatabaseHas('attendance_records', [
            'shift_session_id' => $this->sessionId,
            'provisional_out_at' => '2026-10-25 10:00:00',
            'check_out_at' => null,
            'verified_minutes' => 0,
            'needs_review' => 1,
            'review_reason' => 'AUTO_CHECKOUT',
        ]);
    }

    public function test_checkout_processed_before_auto_checkout_is_not_overwritten(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a4444444-4444-4444-8444-444444444444'), $this->device->id);
        $this->travelTo('2026-10-25 10:00:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a5555555-5555-4555-8555-555555555555', [
            'event_type' => 'CHECK_OUT',
        ]), $this->device->id);
        $this->travelTo('2026-10-25 10:31:00');

        $this->artisan('attendance:auto-checkout')->assertExitCode(0);

        $record = AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->firstOrFail();
        $this->assertSame('2026-10-25 10:00:00', $record->check_out_at->format('Y-m-d H:i:s'));
        $this->assertNull($record->provisional_out_at);
        $this->assertSame(480, $record->verified_minutes);
        $this->assertSame(0, AuditLog::query()->where('action', 'attendance.auto_checkout')->count());
    }

    public function test_auto_checkout_skips_a_finalized_attendance_period(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        app(RecordAttendance::class)->handle($this->employee, $this->event('a6666666-6666-4666-8666-666666666666'), $this->device->id);
        AttendancePeriod::query()->create(['month' => '2026-10', 'status' => 'FINALIZED']);
        $this->travelTo('2026-10-25 14:11:00');

        $this->artisan('attendance:auto-checkout')->assertExitCode(0);

        $record = AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->firstOrFail();
        $this->assertNull($record->provisional_out_at);
        $this->assertSame('IN_PROGRESS', $record->status);
    }

    public function test_late_minutes_use_policy_snapshot_and_work_minutes_stay_inside_session_limit(): void
    {
        $session = ShiftSession::query()->findOrFail($this->sessionId);
        $session->update(['policy_snapshot' => [...$session->policy_snapshot, 'grace_minutes' => 10]]);
        $this->travelTo('2026-10-25 01:15:00');
        $action = app(RecordAttendance::class);
        $action->handle($this->employee, $this->event('12121212-1212-4121-8121-121212121212'), $this->device->id);

        $this->travelTo('2026-10-25 10:00:00');
        $action->handle($this->employee, $this->event('13131313-1313-4131-8131-131313131313', ['event_type' => 'CHECK_OUT']), $this->device->id);

        $record = AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->firstOrFail();
        $this->assertSame(15, $record->raw_late_minutes);
        $this->assertTrue($record->is_late);
        $this->assertSame(480, $record->verified_minutes);
        $this->assertSame(120, AttendanceRecord::paidMinutes($session, $session->start_at, $session->start_at->addHours(2)));
    }

    public function test_night_shift_attendance_keeps_parent_work_date(): void
    {
        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Malam')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $schedules = app(PublishSchedule::class);
        $assignment = $schedules->upsert($this->employee, '2026-10-26', $template, $admin);
        $schedules->publish('2026-10-26', '2026-10-26', [$this->employee->id]);
        $session = $assignment->sessions()->firstOrFail();
        $action = app(RecordAttendance::class);

        $this->travelTo('2026-10-26 15:05:00');
        $action->handle($this->employee, $this->event('14141414-1414-4141-8141-141414141414', ['shift_session_id' => $session->id]), $this->device->id);
        $this->travelTo('2026-10-26 23:00:00');
        $action->handle($this->employee, $this->event('15151515-1515-4151-8151-151515151515', ['shift_session_id' => $session->id, 'event_type' => 'CHECK_OUT']), $this->device->id);

        $record = AttendanceRecord::query()->where('shift_session_id', $session->id)->firstOrFail();
        $this->assertSame('2026-10-26', $assignment->work_date);
        $this->assertSame('2026-10-26 23:00:00', $record->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(475, $record->verified_minutes);
    }

    public function test_zero_checkin_early_setting_allows_late_checkin_and_counts_from_shift_start(): void
    {
        $setting = SiteSetting::current();
        $originalValue = $setting->checkin_early_minutes;
        $setting->update(['checkin_early_minutes' => 0]);
        Cache::forget('attendance.checkin_early_minutes');

        try {
            $session = ShiftSession::query()->findOrFail($this->sessionId);
            $this->travelTo($session->start_at->addHours(2)->toDateTimeString());
            $result = app(RecordAttendance::class)->handle(
                $this->employee,
                $this->event('16161616-1616-4161-8161-161616161616'),
                $this->device->id,
            );

            $this->assertSame('ACCEPTED', $result['data']['status']);
            $setting->update(['checkin_early_minutes' => 60]);
            Cache::forget('attendance.checkin_early_minutes');
            $this->travelTo($session->end_at->toDateTimeString());
            $checkout = $this->event('17171717-1717-4171-8171-171717171717', ['event_type' => 'CHECK_OUT']);
            $recorded = app(RecordAttendance::class)->handle($this->employee, $checkout, $this->device->id);

            $this->assertSame('ACCEPTED', $recorded['data']['status']);
            $this->assertSame($session->scheduledMinutes(), $recorded['data']['record']['verified_minutes']);
        } finally {
            $setting->update(['checkin_early_minutes' => $originalValue]);
            Cache::forget('attendance.checkin_early_minutes');
        }
    }

    public function test_all_four_sessions_can_be_recorded_independently(): void
    {
        $template = ShiftTemplate::query()->create(['name' => 'Empat sesi', 'type' => 'special']);
        foreach ([['07:00', '09:00'], ['10:00', '12:00'], ['13:00', '15:00'], ['16:00', '18:00']] as $index => [$start, $end]) {
            $template->sessions()->create([
                'seq' => $index + 1,
                'local_start' => $start,
                'local_end' => $end,
                'day_offset' => 0,
                'unpaid_break_minutes' => 0,
            ]);
        }
        $admin = User::factory()->role('admin')->create();
        $schedules = app(PublishSchedule::class);
        $assignment = $schedules->upsert($this->employee, '2026-10-27', $template, $admin);
        $schedules->publish('2026-10-27', '2026-10-27', [$this->employee->id]);
        $action = app(RecordAttendance::class);

        foreach ($assignment->sessions()->get() as $session) {
            $this->travelTo($session->start_at->addMinutes(5));
            $action->handle($this->employee, $this->event(Str::uuid()->toString(), ['shift_session_id' => $session->id]), $this->device->id);
            $this->travelTo($session->end_at);
            $action->handle($this->employee, $this->event(Str::uuid()->toString(), ['shift_session_id' => $session->id, 'event_type' => 'CHECK_OUT']), $this->device->id);
        }

        $this->assertSame(4, AttendanceRecord::query()->count());
        $this->assertSame('COMPLETED', $assignment->fresh()->status);
    }

    public function test_offline_batch_is_rejected_without_projecting_attendance(): void
    {
        $this->travelTo('2026-10-25 12:00:00');
        $checkin = $this->event('77777777-7777-4777-8777-777777777777', [
            'device_at' => '2026-10-25T01:05:00Z',
            'captured_offline' => true,
            'local_sequence' => 1,
        ]);
        $checkout = $this->event('88888888-8888-4888-8888-888888888888', [
            'event_type' => 'CHECK_OUT',
            'device_at' => '2026-10-25T10:00:00Z',
            'captured_offline' => true,
            'local_sequence' => 2,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/sync', [
                'batch_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'events' => [$checkout, $checkin],
            ])
            ->assertOk()->assertJsonPath('results.0.event_uuid', $checkin['event_uuid'])
            ->assertJsonPath('results.0.status', 'REJECTED')
            ->assertJsonPath('results.0.reason_code', 'OFFLINE_ATTENDANCE_DISABLED')
            ->assertJsonPath('results.1.event_uuid', $checkout['event_uuid'])
            ->assertJsonPath('results.1.status', 'REJECTED')
            ->assertJsonPath('results.1.reason_code', 'OFFLINE_ATTENDANCE_DISABLED');

        $this->assertDatabaseMissing('attendance_records', ['shift_session_id' => $this->sessionId]);
        $this->assertSame(2, AttendanceEvent::query()->where('shift_session_id', $this->sessionId)->where('result', 'REJECTED')->count());
    }

    public function test_identical_event_retry_is_idempotent_and_conflicting_payload_is_rejected(): void
    {
        $this->travelTo('2026-10-25 01:10:00');
        $input = $this->event('66666666-6666-4666-8666-666666666666', [
            'location' => ['latitude' => -6.2, 'longitude' => 106.8, 'accuracy_meters' => 20],
        ]);
        $action = app(RecordAttendance::class);
        $action->handle($this->employee, $input, $this->device->id);
        $duplicate = $action->handle($this->employee, $input, $this->device->id);

        $this->assertSame('DUPLICATE', $duplicate['data']['status']);
        $this->assertSame(1, AttendanceEvent::query()->count());
        $this->assertDatabaseHas('attendance_events', ['event_uuid' => $input['event_uuid'], 'latitude' => null, 'longitude' => null]);

        $input['device_timezone'] = 'Asia/Jakarta';
        try {
            $action->handle($this->employee, $input, $this->device->id);
            $this->fail('Payload berbeda dengan UUID yang sama harus ditolak.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('UUID_PAYLOAD_CONFLICT', $exception->errorCode);
        }
    }

    public function test_location_validation_uses_schedule_snapshot_after_master_location_changes(): void
    {
        $location = WorkLocation::query()->create([
            'name' => 'Kantor lama', 'mode' => 'radius', 'latitude' => -6.2, 'longitude' => 106.8,
            'radius_meters' => 100, 'max_accuracy_meters' => 50,
        ]);
        $this->employee->update(['work_location_id' => $location->id]);
        AttendancePolicy::query()->where('scope_type', 'global')->update(['use_location_check' => true]);

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $assignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-26', $template, $admin);
        app(PublishSchedule::class)->publish('2026-10-26', '2026-10-26', [$this->employee->id]);
        $session = $assignment->sessions()->firstOrFail();
        $location->update(['latitude' => 10, 'longitude' => 10]);
        $this->travelTo('2026-10-26 01:10:00');

        $input = $this->event('99999999-9999-4999-8999-999999999999', [
            'shift_session_id' => $session->id,
            'location' => ['latitude' => -6.2, 'longitude' => 106.8, 'accuracy_meters' => 10],
        ]);
        $result = app(RecordAttendance::class)->handle($this->employee, $input, $this->device->id);

        $this->assertSame('REJECTED', $result['data']['status']);
        $this->assertSame('OUTSIDE_RADIUS', $result['data']['reason_code']);
    }

    public function test_open_session_uses_updated_max_accuracy_from_website(): void
    {
        $location = WorkLocation::query()->create([
            'name' => 'Lobi', 'mode' => 'radius', 'latitude' => -6.2, 'longitude' => 106.8,
            'radius_meters' => 100, 'max_accuracy_meters' => 10,
        ]);
        $this->employee->update(['work_location_id' => $location->id]);
        AttendancePolicy::query()->where('scope_type', 'global')->update(['use_location_check' => true]);

        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $assignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-27', $template, $admin);
        app(PublishSchedule::class)->publish('2026-10-27', '2026-10-27', [$this->employee->id]);
        $session = $assignment->sessions()->firstOrFail();
        $this->assertSame(10, $session->policy_snapshot['location']['max_accuracy_meters']);

        $location->update(['max_accuracy_meters' => 50]);
        app(RefreshOpenPolicySnapshots::class)->forLocation($location->fresh());

        $this->withToken($this->token)
            ->getJson('/api/v1/attendance/me?from=2026-10-27&to=2026-10-27')
            ->assertOk()
            ->assertJsonPath('data.0.sessions.0.attendance_policy.max_accuracy_meters', 50);

        $this->travelTo('2026-10-27 01:10:00');
        $result = app(RecordAttendance::class)->handle($this->employee, $this->event('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', [
            'shift_session_id' => $session->id,
            'location' => ['latitude' => -6.2, 'longitude' => 106.8, 'accuracy_meters' => 25],
        ]), $this->device->id);

        $this->assertSame('ACCEPTED', $result['data']['status']);
        $this->assertSame(50, $session->fresh()->policy_snapshot['location']['max_accuracy_meters']);
    }

    public function test_revoked_device_cannot_use_mobile_api(): void
    {
        $this->device->update(['revoked_at' => now()]);

        $this->withToken($this->token)
            ->getJson('/api/v1/me/schedule?from=2026-10-25&to=2026-10-25')
            ->assertUnauthorized()->assertJsonPath('code', 'UNREGISTERED_DEVICE');
    }

    public function test_api_rejects_another_employees_session_and_a_mismatched_device(): void
    {
        $otherUser = User::factory()->create();
        $otherEmployee = Employee::query()->create([
            'code' => 'ATT-002',
            'name' => $otherUser->name,
            'user_id' => $otherUser->id,
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'status' => 'active',
            'started_on' => '2026-01-01',
        ]);
        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $schedules = app(PublishSchedule::class);
        $otherAssignment = $schedules->upsert($otherEmployee, '2026-10-25', $template, $admin);
        $schedules->publish('2026-10-25', '2026-10-25', [$otherEmployee->id]);
        $this->travelTo('2026-10-25 01:10:00');

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $this->event('16161616-1616-4161-8161-161616161616', [
                'shift_session_id' => $otherAssignment->sessions()->value('id'),
            ]))
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->postJson('/api/v1/attendance/events', $this->event('17171717-1717-4171-8171-171717171717', [
            'device_id' => 'some-other-device',
        ]))->assertForbidden()->assertJsonPath('code', 'DEVICE_MISMATCH');

        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_attendance_event_rejects_fake_gps(): void
    {
        $this->travelTo('2026-10-25 01:10:00');

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $this->event('55555555-5555-4555-8555-555555555555', [
                'location' => [
                    'latitude' => -6.2,
                    'longitude' => 106.8,
                    'accuracy_meters' => 10,
                    'is_mocked' => true,
                ],
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.reason_code', 'FAKE_GPS_DETECTED');

        $this->assertDatabaseHas('attendance_events', [
            'event_uuid' => '55555555-5555-4555-8555-555555555555',
            'result' => 'REJECTED',
            'reason_code' => 'FAKE_GPS_DETECTED',
            'is_mocked' => true,
        ]);

        // Record should not be marked as checked in
        $this->assertDatabaseMissing('attendance_records', [
            'shift_session_id' => $this->sessionId,
            'state' => 'COMPLETE',
        ]);
    }

    public function test_attendance_event_with_photo_saves_path_and_projects(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-25 01:10:00');

        // Tiny valid 1x1 PNG as base64 data URI
        $base64Png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $this->event('66666666-6666-4666-8666-666666666666', [
                'photo_base64' => $base64Png,
            ]));

        $response->assertCreated()->assertJsonPath('data.status', 'ACCEPTED');

        $event = AttendanceEvent::query()->where('event_uuid', '66666666-6666-4666-8666-666666666666')->firstOrFail();
        $this->assertNotNull($event->photo_path);
        $this->assertStringEndsWith('.jpg', $event->photo_path);
        Storage::disk('local')->assertExists($event->photo_path);

        $record = AttendanceRecord::query()->where('shift_session_id', $this->sessionId)->firstOrFail();
        $this->assertSame($event->photo_path, $record->check_in_photo_path);
        $this->assertNotNull($record->toApi()['check_in_photo_url']);
    }

    public function test_attendance_rejects_incomplete_employee_profile_and_exposes_block_on_schedule(): void
    {
        $this->employee->update(['work_location_id' => null, 'level_id' => null]);

        $this->withToken($this->token)
            ->getJson('/api/v1/me/schedule?from=2026-10-25&to=2026-10-25')
            ->assertOk()
            ->assertJsonPath(
                'data.0.attendance_block_message',
                'Absen belum bisa dicatat. Lengkapi level tunjangan dan lokasi penempatan kerja. Hubungi HRD.'
            );

        $this->travelTo('2026-10-25 01:10:00');
        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $this->event('18181818-1818-4181-8181-181818181818'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_PROFILE_INCOMPLETE')
            ->assertJsonPath('message', 'Absen belum bisa dicatat. Lengkapi level tunjangan dan lokasi penempatan kerja. Hubungi HRD.');

        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_attendance_rejects_missing_level(): void
    {
        $this->employee->update(['level_id' => null]);
        $this->travelTo('2026-10-25 01:10:00');

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $this->event('19191919-1919-4191-8191-191919191919'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_PROFILE_INCOMPLETE')
            ->assertJsonPath('message', 'Absen belum bisa dicatat. Lengkapi level tunjangan. Hubungi HRD.');

        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_attendance_rejects_missing_work_location(): void
    {
        $this->employee->update(['work_location_id' => null]);
        $this->travelTo('2026-10-25 01:10:00');

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/events', $this->event('20202020-2020-4202-8202-202020202020'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_PROFILE_INCOMPLETE')
            ->assertJsonPath('message', 'Absen belum bisa dicatat. Lengkapi lokasi penempatan kerja. Hubungi HRD.');

        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_sync_rejects_incomplete_employee_profile(): void
    {
        $this->employee->update(['work_location_id' => null]);
        $this->travelTo('2026-10-25 01:10:00');

        $this->withToken($this->token)
            ->postJson('/api/v1/attendance/sync', [
                'batch_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'events' => [$this->event('21212121-2121-4212-8212-212121212121')],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED')
            ->assertJsonPath('results.0.reason_code', 'EMPLOYEE_PROFILE_INCOMPLETE')
            ->assertJsonPath('results.0.message', 'Absen belum bisa dicatat. Lengkapi lokasi penempatan kerja. Hubungi HRD.');

        $this->assertDatabaseCount('attendance_events', 0);
    }

    private function event(string $uuid, array $overrides = []): array
    {
        return array_replace([
            'event_uuid' => $uuid,
            'shift_session_id' => $this->sessionId,
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
            'device_at' => null,
            'captured_offline' => false,
            'local_sequence' => 1,
            'location' => null,
        ], $overrides);
    }
}
