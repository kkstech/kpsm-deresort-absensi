<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DeviceRegistration;
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
        $this->assertNotNull($user->devices()->where('device_uuid', 'phone-1')->firstOrFail()->revoked_at);
    }

    public function test_a_device_can_switch_accounts_only_after_logout(): void
    {
        $firstUser = $this->employeeUser();
        $secondUser = User::factory()->create();
        Employee::query()->create([
            'code' => 'EMP-002',
            'name' => $secondUser->name,
            'user_id' => $secondUser->id,
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
        ]);

        $firstToken = $this->postJson('/api/v1/auth/login', [
            'email' => $firstUser->email,
            'password' => 'password',
            'device_uuid' => 'shared-phone',
        ])->assertOk()->json('token');

        $this->postJson('/api/v1/auth/login', [
            'email' => $secondUser->email,
            'password' => 'password',
            'device_uuid' => 'shared-phone',
        ])->assertStatus(409)->assertJsonPath('code', 'DEVICE_IN_USE');

        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $firstUser->id);
        $this->app['auth']->forgetGuards();

        $legacyRegistration = DeviceRegistration::query()->create([
            'user_id' => $secondUser->id,
            'device_uuid' => 'shared-phone',
            'name' => 'Android',
            'last_seen_at' => now(),
        ]);
        $legacyToken = $secondUser->createToken("device:{$legacyRegistration->id}")->plainTextToken;

        $this->withToken($firstToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNotNull($legacyRegistration->fresh()->revoked_at);

        $this->app['auth']->forgetGuards();
        $this->withToken($legacyToken)->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $secondUser->email,
            'password' => 'password',
            'device_uuid' => 'shared-phone',
        ])->assertOk()->assertJsonPath('user.id', $secondUser->id);
    }

    public function test_employee_can_use_mobile_api_on_multiple_devices_at_once(): void
    {
        $user = $this->employeeUser();
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $firstToken = $this->postJson('/api/v1/auth/login', [...$credentials, 'device_uuid' => 'phone-1'])
            ->assertOk()->json('token');
        $secondToken = $this->postJson('/api/v1/auth/login', [...$credentials, 'device_uuid' => 'tablet-1'])
            ->assertOk()->json('token');

        $this->assertSame(2, $user->tokens()->count());
        $this->withToken($firstToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_different_employees_can_use_mobile_api_at_the_same_time(): void
    {
        $firstUser = $this->employeeUser();
        $secondUser = User::factory()->create();
        Employee::query()->create([
            'code' => 'EMP-002',
            'name' => $secondUser->name,
            'user_id' => $secondUser->id,
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
        ]);

        $firstToken = $this->postJson('/api/v1/auth/login', [
            'email' => $firstUser->email, 'password' => 'password', 'device_uuid' => 'employee-phone-1',
        ])->assertOk()->json('token');
        $secondToken = $this->postJson('/api/v1/auth/login', [
            'email' => $secondUser->email, 'password' => 'password', 'device_uuid' => 'employee-phone-2',
        ])->assertOk()->json('token');

        $this->withToken($firstToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $firstUser->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $secondUser->id);
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

    public function test_mobile_login_with_personal_email_and_optional_phone(): void
    {
        $this->seed(MasterDataSeeder::class);
        $user = User::factory()->create([
            'email' => 'nadia.official@deresort.test',
            'phone' => null,
        ]);
        $employee = Employee::query()->create([
            'code' => 'FO-001',
            'name' => $user->name,
            'user_id' => $user->id,
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'email' => 'nadia.personal@gmail.com',
            'phone' => '081234567801',
        ]);

        // Login using personal email (from employee model) + optional phone
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nadia.personal@gmail.com',
            'phone' => '081234567801',
            'password' => 'password',
            'device_uuid' => 'mobile-device-1',
        ])->assertOk();

        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->assertSame('nadia.official@deresort.test', $response->json('user.email'));
        $this->assertSame('081234567801', $response->json('user.phone'));
        $this->assertSame('nadia.personal@gmail.com', $response->json('user.employee.email'));
        $this->assertSame('081234567801', $response->json('user.employee.phone'));

        $this->assertSame('081234567801', $user->fresh()->phone);
    }

    public function test_mobile_login_with_phone_number_only(): void
    {
        $this->seed(MasterDataSeeder::class);
        $user = User::factory()->create([
            'email' => 'dimas.official@deresort.test',
            'phone' => '081299998888',
        ]);
        Employee::query()->create([
            'code' => 'EN-001',
            'name' => $user->name,
            'user_id' => $user->id,
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'email' => 'dimas.personal@gmail.com',
            'phone' => '081299998888',
        ]);

        // Login using phone only in +62 format
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+62 812-9999-8888',
            'password' => 'password',
            'device_uuid' => 'mobile-device-2',
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('081299998888', $response->json('user.phone'));
    }

    public function test_mobile_login_with_phone_in_email_field(): void
    {
        $this->seed(MasterDataSeeder::class);
        $user = User::factory()->create([
            'email' => 'lina@deresort.test',
            'phone' => '081255554444',
        ]);
        Employee::query()->create([
            'code' => 'FI-001',
            'name' => $user->name,
            'user_id' => $user->id,
            'status' => 'active',
            'started_on' => '2026-01-01',
            'department_id' => Department::query()->value('id'),
            'position_id' => Position::query()->value('id'),
            'email' => 'lina.pribadi@yahoo.com',
            'phone' => '081255554444',
        ]);

        // User typed phone into email field
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '081255554444',
            'password' => 'password',
            'device_uuid' => 'mobile-device-3',
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_update_profile_and_change_password(): void
    {
        $user = $this->employeeUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => 'device-profile-1',
        ])->assertOk()->json('token');

        // Update name, email, and phone
        $response = $this->withToken($token)->patchJson('/api/v1/me', [
            'name' => 'Nama Baru Mandiri',
            'phone' => '081234567899',
            'email' => 'emailbaru@example.com',
        ])->assertOk();

        $response->assertJsonPath('user.name', 'Nama Baru Mandiri')
            ->assertJsonPath('user.phone', '081234567899')
            ->assertJsonPath('user.email', 'emailbaru@example.com')
            ->assertJsonPath('user.employee.name', 'Nama Baru Mandiri')
            ->assertJsonPath('user.employee.phone', '081234567899')
            ->assertJsonPath('user.employee.email', 'emailbaru@example.com');

        // Change password
        $this->withToken($token)->patchJson('/api/v1/me', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        // Login with new password
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'emailbaru@example.com',
            'password' => 'newpassword123',
            'device_uuid' => 'device-profile-2',
        ])->assertOk();
    }

    public function test_upload_and_delete_avatar(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $user = $this->employeeUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_uuid' => 'device-avatar-1',
        ])->assertOk()->json('token');

        $file = \Illuminate\Http\UploadedFile::fake()->image('profile.jpg', 200, 200);

        $upload = $this->withToken($token)->post('/api/v1/me/avatar', [
            'avatar' => $file,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull($upload->json('user.avatar_path'));
        $this->assertNotNull($upload->json('user.avatar_url'));
        $this->assertNotNull($user->fresh()->avatar_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($user->fresh()->avatar_path);

        // Delete avatar
        $delete = $this->withToken($token)->deleteJson('/api/v1/me/avatar')->assertOk();
        $this->assertNull($delete->json('user.avatar_path'));
        $this->assertNull($delete->json('user.avatar_url'));
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_employee_can_login_using_username_employee_code_case_insensitive(): void
    {
        $user = $this->employeeUser();
        $user->update(['username' => 'emp-001']);

        // Test with uppercase code
        $respUpper = $this->postJson('/api/v1/auth/login', [
            'username' => 'EMP-001',
            'password' => 'password',
            'device_uuid' => 'phone-username-1',
            'device_name' => 'Android Device',
        ])->assertOk();

        $this->assertSame('EMP-001', $respUpper->json('user.employee.code'));

        // Test with lowercase code
        $respLower = $this->postJson('/api/v1/auth/login', [
            'username' => 'emp-001',
            'password' => 'password',
            'device_uuid' => 'phone-username-2',
            'device_name' => 'Android Device',
        ])->assertOk();

        $this->assertSame('EMP-001', $respLower->json('user.employee.code'));
    }

    public function test_admin_can_login_to_web_using_username(): void
    {
        $admin = User::factory()->role('admin')->create([
            'email' => 'admin@deresort.test',
            'username' => 'admin@deresort.test',
            'password' => 'password123',
        ]);

        $this->post('/login', [
            'username' => 'admin@deresort.test',
            'password' => 'password123',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_fails_with_invalid_username(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'non-existent-user',
            'password' => 'password',
            'device_uuid' => 'phone-x',
        ])->assertStatus(401)
          ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->post('/login', [
            'username' => 'non-existent-user',
            'password' => 'password',
        ])->assertSessionHasErrors('username');
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
