<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Masuk ke admin');
    }

    public function test_super_admin_can_login_without_a_second_factor(): void
    {
        $user = User::factory()->role('super_admin')->create(['email' => 'boss@deresort.test']);

        $this->post('/login', ['email' => 'BOSS@deresort.test', 'password' => 'password', 'remember' => true])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Deresort Attendance');
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_supervisor_can_login_and_employee_cannot_use_web(): void
    {
        $supervisor = User::factory()->role('supervisor')->create();
        $this->post('/login', ['email' => $supervisor->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->post('/logout');

        $employee = User::factory()->create();
        $this->post('/login', ['email' => $employee->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_mobile_login_me_and_logout(): void
    {
        $user = $this->employeeUser();
        $login = ['email' => $user->email, 'password' => 'password', 'device_uuid' => 'phone-1', 'device_name' => 'Android'];

        $token = $this->postJson('/api/v1/auth/login', $login)
            ->assertOk()->assertJsonPath('user.employee.code', 'EMP-001')->json('token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.email', $user->email);
        $this->assertDatabaseHas('device_registrations', ['user_id' => $user->id, 'device_uuid' => 'phone-1']);

        $newToken = $this->postJson('/api/v1/auth/login', $login)->assertOk()->json('token');
        $this->assertSame(1, $user->tokens()->count(), 'login ulang di perangkat sama mengganti token lama');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_mobile_errors_use_one_json_shape(): void
    {
        $user = $this->employeeUser();

        $this->postJson('/api/v1/auth/login', ['email' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['message', 'details', 'request_id']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'salah', 'device_uuid' => 'p'])
            ->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');

        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_uuid' => 'p'])
            ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_deactivated_user_token_is_rejected(): void
    {
        $user = $this->employeeUser();
        $token = $user->createToken('device:1')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x', 'device_uuid' => 'p'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x', 'device_uuid' => 'p'])
            ->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    private function employeeUser(): User
    {
        $this->seed(MasterDataSeeder::class);
        $user = User::factory()->create();
        Employee::query()->create([
            'code' => 'EMP-001', 'name' => $user->name, 'user_id' => $user->id, 'status' => 'active', 'started_on' => '2026-01-01',
            'department_id' => Department::query()->value('id'), 'position_id' => Position::query()->value('id'),
        ]);

        return $user;
    }
}
