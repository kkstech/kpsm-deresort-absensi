<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'supervisor')->update(['role' => 'employee']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'employee')
            ->where(fn (Builder $query) => $query->where('can_view_all', true)->orWhereNotNull('department_id'))
            ->update(['role' => 'supervisor']);
    }
};
