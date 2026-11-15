<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\AppBuild;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForceReloginOnUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_session_is_invalidated_after_build_changes(): void
    {
        config(['app.build_id' => 'build-a']);
        $admin = User::factory()->role('admin')->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $this->assertSame('build-a', $admin->fresh()->web_login_build_id);

        config(['app.build_id' => 'build-b']);
        $this->get('/admin')->assertRedirect('/login')->assertSessionHas('status', AppBuild::RELOGIN_MESSAGE);
        $this->assertGuest('web');
        $this->get('/login')->assertOk()->assertSee(AppBuild::RELOGIN_MESSAGE);
    }

    public function test_admin_ajax_request_gets_relogin_code_after_build_changes(): void
    {
        config(['app.build_id' => 'build-a']);
        $admin = User::factory()->role('admin')->create();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

        config(['app.build_id' => 'build-b']);
        $this->getJson('/admin/api/attendance/filters')
            ->assertStatus(401)
            ->assertJsonPath('code', AppBuild::RELOGIN_CODE)
            ->assertJsonPath('message', AppBuild::RELOGIN_MESSAGE);
        $this->assertGuest('web');
    }

    public function test_web_login_after_build_change_works(): void
    {
        config(['app.build_id' => 'build-a']);
        $admin = User::factory()->role('admin')->create();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

        config(['app.build_id' => 'build-b']);
        $this->get('/admin')->assertRedirect('/login');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $this->assertSame('build-b', $admin->fresh()->web_login_build_id);
    }

    public function test_remember_me_login_is_kept_only_within_the_same_build(): void
    {
        config(['app.build_id' => 'build-a']);
        $admin = User::factory()->role('admin')->create();
        $admin->forceFill(['web_login_build_id' => 'build-a', 'remember_token' => 'remember-token-value'])->save();
        $cookie = $admin->id.'|remember-token-value|'.$admin->getAuthPassword();
        $name = $this->app['auth']->guard('web')->getRecallerName();

        $this->withCookie($name, $cookie)->get('/admin')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        config(['app.build_id' => 'build-b']);
        $this->withCookie($name, $cookie)->get('/admin')->assertRedirect('/login');
    }

    public function test_api_token_is_rejected_after_build_changes_and_relogin_works(): void
    {
        config(['app.build_id' => 'build-a']);
        $user = $this->employeeUser();
        $login = ['email' => $user->email, 'password' => 'password', 'device_uuid' => 'phone-1', 'device_name' => 'Android'];

        $token = $this->postJson('/api/v1/auth/login', $login)->assertOk()->json('token');
        $this->assertSame('build-a', $user->tokens()->firstOrFail()->build_id);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        config(['app.build_id' => 'build-b']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', AppBuild::RELOGIN_CODE)
            ->assertJsonPath('message', AppBuild::RELOGIN_MESSAGE);
        $this->assertSame(0, $user->tokens()->count(), 'token lama dihapus');
        $this->assertNull($user->devices()->firstOrFail()->revoked_at, 'perangkat tetap terdaftar');

        $this->app['auth']->forgetGuards();
        $newToken = $this->postJson('/api/v1/auth/login', $login)->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_api_is_noop_when_build_id_is_unset(): void
    {
        config(['app.build_id' => null]);
        $user = $this->employeeUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_uuid' => 'phone-1',
        ])->assertOk()->json('token');
        $this->assertNull($user->tokens()->firstOrFail()->build_id);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_web_is_noop_when_build_id_is_unset(): void
    {
        config(['app.build_id' => null]);
        $admin = User::factory()->role('admin')->create();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $this->get('/admin')->assertOk();
        $this->assertNull($admin->fresh()->web_login_build_id);
    }

    public function test_switch_can_turn_the_feature_off(): void
    {
        config(['app.build_id' => 'build-a']);
        $user = $this->employeeUser();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_uuid' => 'phone-1',
        ])->assertOk()->json('token');

        config(['app.build_id' => 'build-b', 'app.force_relogin_on_update' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
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
