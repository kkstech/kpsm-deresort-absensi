<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DeviceRegistration;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Models\OvertimeOrder;
use App\Models\Position;
use App\Models\ShiftSession;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employeeUser;

    private Employee $employee;

    private DeviceRegistration $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-25 03:00:00'); // 10.00 WIB

        $this->admin = User::factory()->role('admin')->create();
        $this->employeeUser = User::factory()->create();
        $this->employee = Employee::query()->create($this->completeEmployeeAttrs([
            'code' => 'FO-001',
            'name' => 'Budi Santoso',
            'status' => 'active',
            'started_on' => '2026-01-01',
            'user_id' => $this->employeeUser->id,
            'department_id' => Department::query()->first()->id,
            'position_id' => Position::query()->first()->id,
        ]));

        $this->device = DeviceRegistration::query()->create([
            'user_id' => $this->employeeUser->id,
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'Test Mobile Device',
            'status' => 'active',
        ]);
        $this->token = $this->employeeUser->createToken("device:{$this->device->id}")->plainTextToken;
    }

    public function test_admin_recommendations_appear_two_hours_before_active_last_session_ends(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Reguler Sore',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '12:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ], $this->admin);
        $session = $assignment->sessions->firstOrFail();
        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->start_at,
            'status' => 'IN_PROGRESS',
            'needs_review' => false,
        ]);
        $this->travelTo('2026-10-26 07:59:00'); // 14.59 WIB, satu menit di luar jendela
        $this->actingAs($this->admin)->getJson('/admin/api/overtimes/recommendations')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->travelTo('2026-10-26 08:00:00'); // 15.00 WIB, tepat 2 jam sebelum shift berakhir

        $this->getJson('/admin/api/overtimes/recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $this->employee->id)
            ->assertJsonPath('data.0.shift_end_time', '17:00')
            ->assertJsonPath('data.0.suggested_start_time', '17:00')
            ->assertJsonPath('data.0.suggested_end_time', '19:00')
            ->assertJsonPath('data.0.start_day_offset', 0)
            ->assertJsonPath('data.0.end_day_offset', 0)
            ->assertJsonPath('meta.window_minutes', 120);

        $this->travelTo('2026-10-26 10:01:00'); // 17.01 WIB, shift sudah berakhir
        $this->getJson('/admin/api/overtimes/recommendations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recommendations_use_final_split_session_and_prefill_cross_date_spl_offsets(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Split Malam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '16:00', 'end_date' => '2026-10-26', 'end_time' => '20:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '20:30', 'end_date' => '2026-10-27', 'end_time' => '00:30'],
            ],
        ], $this->admin);
        $lastSession = $assignment->sessions->sortBy(fn ($session) => $session->end_at->getTimestamp())->last();
        AttendanceRecord::query()->create([
            'shift_session_id' => $lastSession->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $lastSession->start_at,
            'status' => 'IN_PROGRESS',
            'needs_review' => false,
        ]);
        $this->travelTo('2026-10-26 15:30:00'); // 22.30 WIB, tepat 2 jam sebelum sesi terakhir berakhir

        $recommendation = $this->actingAs($this->admin)->getJson('/admin/api/overtimes/recommendations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame('00:30', $recommendation['suggested_start_time']);
        $this->assertSame('02:30', $recommendation['suggested_end_time']);
        $this->assertSame(1, $recommendation['start_day_offset']);
        $this->assertSame(1, $recommendation['end_day_offset']);
        $this->assertFalse($recommendation['is_cross_day']);

        $orderResponse = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => $recommendation['work_date'],
            'start_time' => $recommendation['suggested_start_time'],
            'end_time' => $recommendation['suggested_end_time'],
            'title' => 'Perpanjangan shift malam',
            'session_targets' => [['employee_id' => $this->employee->id, 'shift_session_id' => $lastSession->id]],
            'is_cross_day' => $recommendation['is_cross_day'],
            'start_day_offset' => $recommendation['start_day_offset'],
            'end_day_offset' => $recommendation['end_day_offset'],
        ])->assertCreated();

        $order = OvertimeOrder::query()->findOrFail($orderResponse->json('data.0.id'));
        $this->assertSame('2026-10-26 17:30:00', $order->start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 19:30:00', $order->end_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($lastSession->id, $order->shift_session_id);

        $this->getJson('/admin/api/overtimes/recommendations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_recommendations_require_an_open_check_in_on_the_last_session(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Reguler Sore',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '12:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ], $this->admin);
        $session = $assignment->sessions->firstOrFail();
        $this->travelTo('2026-10-26 08:00:00');

        $this->actingAs($this->admin)->getJson('/admin/api/overtimes/recommendations')
            ->assertOk()->assertJsonCount(0, 'data');

        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->start_at,
            'provisional_out_at' => $session->end_at,
            'status' => 'PROVISIONAL',
            'needs_review' => true,
        ]);

        $this->getJson('/admin/api/overtimes/recommendations')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_two_hour_overtime_recommendation_is_recorded_for_admins_once_per_session(): void
    {
        $superAdmin = User::factory()->role('super_admin')->create();

        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Reguler Sore',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '12:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ], $this->admin);
        $session = $assignment->sessions->firstOrFail();
        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->start_at,
            'status' => 'IN_PROGRESS',
            'needs_review' => false,
        ]);
        $this->travelTo('2026-10-26 08:00:00'); // 15.00 WIB, tepat 2 jam sebelum shift berakhir

        $this->artisan('overtime:recommendations')->assertExitCode(0);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertTrue($this->admin->notifications()
            ->where('data->kind', 'overtime_recommendation')
            ->where('data->context->shift_session_id', $session->id)
            ->exists());
        $this->assertTrue($superAdmin->notifications()
            ->where('data->kind', 'overtime_recommendation')
            ->where('data->context->shift_session_id', $session->id)
            ->exists());
        $this->assertFalse($this->employeeUser->notifications()
            ->where('data->kind', 'overtime_recommendation')
            ->exists());
        $this->artisan('overtime:recommendations')->assertExitCode(0);

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_admin_can_issue_overtime_order_and_employee_can_give_digital_consent(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'title' => 'Lembur Event Wedding',
        ])->assertCreated();

        $orderId = $response->json('data.0.id');
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'employee_id' => $this->employee->id,
            'title' => 'Lembur Event Wedding',
            'status' => 'APPROVED',
            'consent_status' => 'PENDING',
            'planned_minutes' => 180,
        ]);

        // Clear web admin session before mobile API call
        auth()->forgetGuards();
        $this->flushSession();

        // Karyawan melihat perintah lembur di mobile dan memberikan consent
        $list = $this->withToken($this->token)->getJson('/api/v1/me/overtimes')->assertOk();
        $list->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Lembur Event Wedding')
            ->assertJsonPath('data.0.consent_status', 'PENDING');

        $consentResponse = $this->withToken($this->token)->postJson("/api/v1/overtimes/{$orderId}/consent", [
            'accepted' => true,
            'notes' => 'Siap bertugas',
        ])->assertOk();

        $consentResponse->assertJsonPath('data.consent_status', 'ACCEPTED');
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'consent_status' => 'ACCEPTED',
            'consent_notes' => 'Siap bertugas',
        ]);
    }

    public function test_manager_overtime_request_is_reserved_for_super_admin(): void
    {
        $level = EmployeeLevel::query()->create([
            'code' => 'MGR', 'name' => 'Manager', 'requires_super_admin_approval' => true,
        ]);
        $this->employee->update(['level_id' => $level->id]);
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Split 12 Jam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '14:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '15:00', 'end_date' => '2026-10-26', 'end_time' => '21:00'],
            ],
        ], $this->admin);

        foreach ($assignment->sessions as $session) {
            AttendanceRecord::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $this->employee->id,
                'check_in_at' => $session->start_at,
                'check_out_at' => $session->end_at,
                'status' => 'VERIFIED',
                'verified_minutes' => $session->scheduledMinutes(),
            ]);
        }

        $req = $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            'work_date' => '2026-10-26',
            'shift_session_id' => $assignment->sessions->last()->id,
            'start_time' => '21:00',
            'end_time' => '23:00',
            'title' => 'Pembersihan Banquet Tambahan',
        ])->assertCreated()->assertJsonPath('data.approval_tier', 'super_admin');

        $orderId = $req->json('data.id');
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'employee_id' => $this->employee->id,
            'status' => 'PENDING',
            'consent_status' => 'ACCEPTED',
            'planned_minutes' => 120,
        ]);

        // Admin memeriksa dan menyetujui
        $this->actingAs($this->admin);
        $adminList = $this->getJson('/admin/api/overtimes?status=PENDING')->assertOk();
        $adminList->assertJsonCount(0, 'data');

        $this->postJson("/admin/api/overtimes/{$orderId}/decision", [
            'decision' => 'APPROVED',
            'notes' => 'Disetujui untuk banquet',
        ])->assertForbidden();

        $superAdmin = User::factory()->role('super_admin')->create();
        $this->actingAs($superAdmin)->getJson('/admin/api/overtimes?status=PENDING')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/admin/api/overtimes/{$orderId}/decision", [
            'decision' => 'APPROVED',
            'notes' => 'Disetujui untuk banquet',
        ])->assertOk();

        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'status' => 'APPROVED',
            'decision_notes' => 'Disetujui untuk banquet',
        ]);
    }

    public function test_employee_can_request_overtime_after_completing_regular_shift_under_twelve_hours(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Reguler 8 Jam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '16:00'],
            ],
        ], $this->admin);
        $session = $assignment->sessions->firstOrFail();
        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->start_at,
            'check_out_at' => $session->end_at,
            'status' => 'COMPLETE',
            'needs_review' => false,
            'verified_minutes' => 8 * 60,
        ]);

        $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            'work_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '18:00',
            'title' => 'Pembersihan area event',
        ])->assertCreated()->assertJsonPath('data.is_day_off', false);
    }

    public function test_approved_employee_overtime_is_calculated_when_checkout_happens_later(): void
    {
        $template = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-26', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-26', '2026-10-26', [$this->employee->id]);
        $session = $assignment->sessions->firstOrFail();

        $this->travelTo('2026-10-26 01:00:00');
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'event_uuid' => (string) Str::uuid(),
            'shift_session_id' => $session->id,
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
        ])->assertCreated();

        $request = $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            'work_date' => '2026-10-26',
            'start_date' => '2026-10-26',
            'end_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'title' => 'Pembersihan area event',
        ])->assertCreated();
        $orderId = $request->json('data.id');

        $this->actingAs($this->admin)->postJson("/admin/api/overtimes/{$orderId}/decision", [
            'decision' => 'APPROVED',
        ])->assertOk();

        auth()->forgetGuards();
        $this->flushSession();
        $this->travelTo('2026-10-26 11:30:00');
        $checkout = $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'event_uuid' => (string) Str::uuid(),
            'shift_session_id' => $session->id,
            'event_type' => 'CHECK_OUT',
            'device_id' => $this->device->device_uuid,
        ])->assertCreated();
        $this->assertSame('ACCEPTED', $checkout->json('data.status'), json_encode($checkout->json('data')));

        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'actual_minutes' => 90,
            'verified_minutes' => 90,
        ]);
    }

    public function test_approved_overtime_extends_checkout_past_thirteen_hours_before_daily_cutoff(): void
    {
        $template = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-26', $template, $this->admin);
        app(PublishSchedule::class)->publish('2026-10-26', '2026-10-26', [$this->employee->id]);
        $session = $assignment->sessions->firstOrFail();
        $overtimeEnd = $session->end_at->addHours(6);
        OvertimeOrder::query()->create([
            'order_number' => 'SPL-20261026-EXT01',
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-26',
            'shift_session_id' => $session->id,
            'initiator_type' => 'admin',
            'created_by' => $this->admin->id,
            'title' => 'Perpanjangan operasional',
            'start_at' => $session->end_at,
            'end_at' => $overtimeEnd,
            'planned_minutes' => 360,
            'status' => 'APPROVED',
            'consent_status' => 'ACCEPTED',
        ]);

        $this->travelTo($session->start_at);
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'event_uuid' => (string) Str::uuid(),
            'shift_session_id' => $session->id,
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
        ])->assertCreated();

        $this->travelTo($overtimeEnd->addMinutes(58));
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'event_uuid' => (string) Str::uuid(),
            'shift_session_id' => $session->id,
            'event_type' => 'CHECK_OUT',
            'device_id' => $this->device->device_uuid,
        ])->assertCreated()->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertDatabaseHas('attendance_records', [
            'shift_session_id' => $session->id,
            'check_out_at' => $overtimeEnd->addMinutes(58)->format('Y-m-d H:i:s'),
            'verified_overtime_minutes' => 360,
        ]);
    }

    public function test_employee_can_request_overtime_for_a_selected_split_session_before_checkout(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Split 8 Jam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '12:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '13:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ], $this->admin);
        $sessions = $assignment->sessions->values();
        AttendanceRecord::query()->create([
            'shift_session_id' => $sessions[0]->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $sessions[0]->start_at,
            'check_out_at' => $sessions[0]->end_at,
            'status' => 'COMPLETE',
            'needs_review' => false,
            'verified_minutes' => 4 * 60,
        ]);
        $requestData = [
            'work_date' => '2026-10-26',
            'shift_session_id' => $sessions[1]->id,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'title' => 'Pembersihan area event',
        ];

        $this->withToken($this->token)->postJson('/api/v1/overtimes', $requestData)
            ->assertUnprocessable()->assertJsonPath('code', 'SHIFT_NOT_STARTED');
        $this->assertDatabaseCount('overtime_orders', 0);

        AttendanceRecord::query()->create([
            'shift_session_id' => $sessions[1]->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $sessions[1]->start_at,
            'status' => 'IN_PROGRESS',
            'needs_review' => false,
        ]);

        $this->withToken($this->token)->postJson('/api/v1/overtimes', $requestData)
            ->assertCreated()->assertJsonPath('data.status', 'PENDING');
    }

    public function test_employee_can_request_overtime_with_a_provisional_checkout_after_last_session_checkin(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Reguler 8 Jam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '16:00'],
            ],
        ], $this->admin);
        $session = $assignment->sessions->firstOrFail();
        $record = AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->start_at,
            'provisional_out_at' => $session->end_at,
            'status' => 'PROVISIONAL',
            'needs_review' => true,
        ]);
        $requestData = [
            'work_date' => '2026-10-26',
            'start_time' => '16:00',
            'end_time' => '18:00',
            'title' => 'Pembersihan area event',
        ];

        $this->withToken($this->token)->postJson('/api/v1/overtimes', $requestData)
            ->assertCreated()->assertJsonPath('data.status', 'PENDING');
    }

    public function test_approval_after_checkout_recalculates_only_actual_minutes_in_approved_window(): void
    {
        $assignment = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-26', [
            'name' => 'Reguler 8 Jam',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ], $this->admin);
        $session = $assignment->sessions->firstOrFail();
        $record = AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->end_at->addHour(),
            'check_out_at' => $session->end_at->addHours(2),
            'status' => 'COMPLETE',
            'needs_review' => false,
        ]);

        $request = $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            'work_date' => '2026-10-26',
            'start_date' => '2026-10-26',
            'end_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'title' => 'Pembersihan area event',
        ])->assertCreated();
        $orderId = $request->json('data.id');

        $this->actingAs($this->admin)->postJson("/admin/api/overtimes/{$orderId}/decision", [
            'decision' => 'APPROVED',
        ])->assertOk();

        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'status' => 'APPROVED',
            'actual_minutes' => 60,
            'verified_minutes' => 60,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'overtime_order_id' => $orderId,
            'verified_overtime_minutes' => 60,
        ]);
    }

    public function test_night_shift_overtime_uses_the_final_session_local_date(): void
    {
        $template = ShiftTemplate::query()->where('name', 'Malam')->firstOrFail();
        $assignment = app(PublishSchedule::class)->upsert($this->employee, '2026-10-26', $template, $this->admin);
        $session = $assignment->sessions->firstOrFail();
        AttendanceRecord::query()->create([
            'shift_session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'check_in_at' => $session->start_at,
            'status' => 'IN_PROGRESS',
            'needs_review' => false,
        ]);

        $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            'work_date' => '2026-10-26',
            'start_date' => '2026-10-27',
            'end_date' => '2026-10-27',
            'start_time' => '06:00',
            'end_time' => '08:00',
            'title' => 'Operasional pagi',
        ])->assertCreated()
            ->assertJsonPath('data.start_at', '2026-10-26T23:00:00Z')
            ->assertJsonPath('data.end_at', '2026-10-27T01:00:00Z');
    }

    public function test_employee_overtime_request_requires_a_non_cancelled_assignment_with_sessions(): void
    {
        $requestData = [
            'start_time' => '16:00',
            'end_time' => '18:00',
            'title' => 'Pembersihan area event',
        ];

        $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            ...$requestData,
            'work_date' => '2026-10-26',
        ])->assertUnprocessable()->assertJsonPath('code', 'SHIFT_NOT_STARTED');

        $cancelled = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-27', [
            'name' => 'Shift dibatalkan',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-27', 'start_time' => '08:00', 'end_date' => '2026-10-27', 'end_time' => '16:00'],
            ],
        ], $this->admin);
        $cancelled->update(['status' => 'CANCELLED']);
        $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            ...$requestData,
            'work_date' => '2026-10-27',
        ])->assertUnprocessable()->assertJsonPath('code', 'SHIFT_NOT_STARTED');

        $empty = app(PublishSchedule::class)->upsertCustom($this->employee, '2026-10-28', [
            'name' => 'Shift tanpa sesi aktif',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-28', 'start_time' => '08:00', 'end_date' => '2026-10-28', 'end_time' => '16:00'],
            ],
        ], $this->admin);
        $empty->sessions()->delete();
        $this->withToken($this->token)->postJson('/api/v1/overtimes', [
            ...$requestData,
            'work_date' => '2026-10-28',
        ])->assertUnprocessable()->assertJsonPath('code', 'SHIFT_NOT_STARTED');
    }

    public function test_checkout_after_session_end_calculates_verified_overtime_minutes_capped_by_spl(): void
    {
        $template = ShiftTemplate::query()->where('name', 'Reguler')->firstOrFail();

        // 1. Buat jadwal 2026-10-26 (08:00 - 17:00 = 01:00 - 10:00 UTC)
        $this->actingAs($this->admin);
        $this->postJson('/admin/api/schedule', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-26',
            'shift_template_id' => $template->id,
        ])->assertCreated();
        $this->postJson('/admin/api/schedule/publish', ['from' => '2026-10-26', 'to' => '2026-10-26'])->assertOk();

        // 2. Buat SPL lembur (17:00 - 19:00 WIB = 120 menit planned)
        $orderRes = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => '2026-10-26',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'title' => 'Lembur Regular Closing',
        ])->assertCreated();
        $orderId = $orderRes->json('data.0.id');

        // Consent diterima
        OvertimeOrder::query()->where('id', $orderId)->update(['consent_status' => 'ACCEPTED', 'consent_at' => now()]);

        auth()->forgetGuards();
        $this->flushSession();

        // 3. Karyawan Check-in jam 08:00 WIB (01:00 UTC)
        $session = ShiftSession::query()->whereHas('assignment', fn ($q) => $q->where('employee_id', $this->employee->id))->firstOrFail();

        $this->travelTo('2026-10-26 01:00:00');
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-26T08:00:00+07:00',
        ])->assertCreated();

        // 4. Karyawan Check-out jam 18:30 WIB (11:30 UTC = 90 menit setelah 17:00 WIB / 10:00 UTC)
        $this->travelTo('2026-10-26 11:30:00');
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_OUT',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-26T18:30:00+07:00',
        ])->assertCreated()->assertJsonPath('data.status', 'ACCEPTED');

        // 5. Verifikasi bahwa 90 menit lembur tercatat
        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'actual_minutes' => 90,
            'verified_minutes' => 90,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'shift_session_id' => $session->id,
            'overtime_order_id' => $orderId,
            'verified_overtime_minutes' => 90,
        ]);
    }

    public function test_day_off_overtime_order_allows_checkin_and_checkout(): void
    {
        $this->actingAs($this->admin);

        // Karyawan tidak punya jadwal di 2026-10-27 (Day Off)
        $orderRes = $this->postJson('/admin/api/overtimes/order', [
            'employee_ids' => [$this->employee->id],
            'work_date' => '2026-10-27',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'title' => 'Lembur Darurat Maintenance',
        ])->assertCreated();
        $orderId = $orderRes->json('data.0.id');

        OvertimeOrder::query()->where('id', $orderId)->update(['consent_status' => 'ACCEPTED', 'consent_at' => now()]);

        // Pastikan shift hari libur otomatis terbuat
        $this->assertDatabaseHas('shift_assignments', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-27',
            'template_name' => 'Lembur Hari Libur',
            'status' => 'PUBLISHED',
        ]);

        $session = ShiftSession::query()->whereHas('assignment', fn ($q) => $q->where('employee_id', $this->employee->id)->where('work_date', '2026-10-27'))->firstOrFail();

        auth()->forgetGuards();
        $this->flushSession();

        // Check-in & Check-out oleh karyawan
        $this->travelTo('2026-10-27 02:00:00'); // 09:00 WIB
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_IN',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-27T09:00:00+07:00',
        ])->assertCreated();

        $this->travelTo('2026-10-27 06:00:00'); // 13:00 WIB (4 jam = 240 menit)
        $this->withToken($this->token)->postJson('/api/v1/attendance/events', [
            'shift_session_id' => $session->id,
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'CHECK_OUT',
            'device_id' => $this->device->device_uuid,
            'client_recorded_at' => '2026-10-27T13:00:00+07:00',
        ])->assertCreated();

        $this->assertDatabaseHas('overtime_orders', [
            'id' => $orderId,
            'actual_minutes' => 240,
            'verified_minutes' => 240,
        ]);
    }
}
