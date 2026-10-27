<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('email');
        });

        // Backfill username for existing users
        $employees = DB::table('employees')->whereNotNull('user_id')->get(['code', 'user_id']);
        foreach ($employees as $employee) {
            if ($employee->user_id && ! empty($employee->code)) {
                DB::table('users')->where('id', $employee->user_id)->update([
                    'username' => Str::lower($employee->code),
                ]);
            }
        }

        // For users without employee (e.g. web admin / super admin), fallback to email
        $remainingUsers = DB::table('users')->whereNull('username')->get(['id', 'email']);
        foreach ($remainingUsers as $user) {
            if (! empty($user->email)) {
                DB::table('users')->where('id', $user->id)->update([
                    'username' => Str::lower($user->email),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
