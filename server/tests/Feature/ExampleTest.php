<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_frontend_is_available_after_login(): void
    {
        $this->actingAs(User::factory()->role('supervisor')->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Deresort Attendance')
            ->assertSee('Buat jadwal');
    }
}
