<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('allowance_results', function (Blueprint $table) {
            $table->index('shift_assignment_id');
            $table->dropUnique('allowance_results_shift_assignment_id_unique');
            $table->unique(['employee_id', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('allowance_results', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'work_date']);
            $table->unique('shift_assignment_id');
            $table->dropIndex(['shift_assignment_id']);
        });
    }
};
