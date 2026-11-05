<?php

namespace Tests\Feature;

use App\Actions\PublishSchedule;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ShiftCategory;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\WorkTime;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->travelTo('2026-10-24 03:00:00');
    }

    public function test_admin_can_create_and_deactivate_an_unused_category(): void
    {
        $admin = User::factory()->role('admin')->create();

        $this->actingAs($admin)->getJson('/admin/api/shift-categories')->assertOk()->assertJsonCount(0, 'data');

        $created = $this->actingAs($admin)
            ->postJson('/admin/api/shift-categories', ['name' => 'Shift Middle'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Shift Middle')
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        $this->putJson("/admin/api/shift-categories/{$id}", [
            'name' => 'Shift Middle',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_first_template_category_backfills_legacy_assignments_and_later_changes_do_not_relabel_them(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($department, 'ENG-CAT-001', 'Karyawan Kategori');
        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-25', $template, $admin);

        $firstCategory = ShiftCategory::query()->create(['name' => 'Shift 1', 'is_active' => true]);
        $secondCategory = ShiftCategory::query()->create(['name' => 'Middle', 'is_active' => true]);
        $sessions = [['local_start' => '08:00', 'local_end' => '16:00']];

        $this->actingAs($admin)->putJson("/admin/api/templates/{$template->id}", [
            'name' => $template->name,
            'type' => $template->type,
            'shift_category_id' => $firstCategory->id,
            'sessions' => $sessions,
        ])->assertOk();

        $this->assertSame($firstCategory->id, $assignment->fresh()->shift_category_id);

        $this->putJson("/admin/api/templates/{$template->id}", [
            'name' => $template->name,
            'type' => $template->type,
            'shift_category_id' => $secondCategory->id,
            'sessions' => $sessions,
        ])->assertOk();

        $this->assertSame($firstCategory->id, $assignment->fresh()->shift_category_id);

        $futureAssignment = app(PublishSchedule::class)->upsert($employee, '2026-10-26', $template->fresh('sessions'), $admin);
        $this->assertSame($secondCategory->id, $futureAssignment->shift_category_id);

        $this->putJson("/admin/api/templates/{$template->id}", [
            'name' => $template->name,
            'type' => $template->type,
            'shift_category_id' => null,
            'sessions' => $sessions,
        ])->assertOk();
        $uncategorizedAssignment = app(PublishSchedule::class)->upsert($employee, '2026-10-27', $template->fresh('sessions'), $admin);

        $this->putJson("/admin/api/templates/{$template->id}", [
            'name' => $template->name,
            'type' => $template->type,
            'shift_category_id' => $secondCategory->id,
            'sessions' => $sessions,
        ])->assertOk();
        $this->assertNull($uncategorizedAssignment->fresh()->shift_category_id);

        $this->putJson("/admin/api/shift-categories/{$secondCategory->id}", [
            'name' => 'Middle',
            'is_active' => false,
        ])->assertUnprocessable();
    }

    public function test_manual_custom_schedule_requires_and_snapshots_an_active_category(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($department, 'ENG-CAT-002', 'Karyawan Shift Khusus');
        $category = ShiftCategory::query()->create(['name' => 'Shift 2', 'is_active' => true]);
        $admin = User::factory()->role('admin')->create();
        $body = [
            'employee_id' => $employee->id,
            'work_date' => '2026-10-26',
            'name' => 'Jadwal khusus',
            'sessions' => [
                ['seq' => 1, 'start_date' => '2026-10-26', 'start_time' => '08:00', 'end_date' => '2026-10-26', 'end_time' => '12:00'],
                ['seq' => 2, 'start_date' => '2026-10-26', 'start_time' => '13:00', 'end_date' => '2026-10-26', 'end_time' => '17:00'],
            ],
        ];

        $this->actingAs($admin)->postJson('/admin/api/schedule', $body)->assertUnprocessable();

        $this->postJson('/admin/api/schedule', [...$body, 'shift_category_id' => $category->id])
            ->assertCreated()
            ->assertJsonPath('data.shift_category_id', $category->id);

        $this->assertDatabaseHas('shift_assignments', [
            'employee_id' => $employee->id,
            'work_date' => '2026-10-26',
            'shift_category_id' => $category->id,
        ]);

        $this->postJson('/admin/api/schedule/copy-month', [
            'from' => '2026-10-01',
            'to' => '2026-11-01',
        ])->assertOk()->assertJsonPath('created', 1);

        $this->assertDatabaseHas('shift_assignments', [
            'employee_id' => $employee->id,
            'work_date' => '2026-11-26',
            'shift_category_id' => $category->id,
        ]);
    }

    public function test_converting_an_uncategorized_template_schedule_to_custom_requires_a_category(): void
    {
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();
        $employee = $this->createEmployee($department, 'ENG-CAT-003', 'Karyawan Template Lama');
        $template = ShiftTemplate::query()->with('sessions')->where('name', 'Reguler')->firstOrFail();
        $admin = User::factory()->role('admin')->create();
        $assignment = app(PublishSchedule::class)->upsert($employee, '2026-10-28', $template, $admin);
        $session = $assignment->sessions->firstOrFail();
        $payload = [
            'expected_revision' => $assignment->revision,
            'sessions' => [[
                'id' => $session->id,
                'seq' => 1,
                'start_date' => '2026-10-28',
                'start_time' => WorkTime::local($session->start_at)->format('H:i'),
                'end_date' => '2026-10-28',
                'end_time' => WorkTime::local($session->end_at)->format('H:i'),
            ]],
        ];

        $this->actingAs($admin)
            ->patchJson("/admin/api/schedule/{$assignment->id}/sessions", $payload)
            ->assertUnprocessable();

        $category = ShiftCategory::query()->create(['name' => 'Shift 3', 'is_active' => true]);
        $this->patchJson("/admin/api/schedule/{$assignment->id}/sessions", [...$payload, 'shift_category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.shift_category_id', $category->id);
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
}
