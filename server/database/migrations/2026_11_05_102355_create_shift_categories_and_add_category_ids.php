<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('shift_templates', function (Blueprint $table) {
            $table->foreignId('shift_category_id')->nullable()->after('name')->constrained('shift_categories')->nullOnDelete();
            $table->timestamp('category_first_assigned_at')->nullable()->after('shift_category_id');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->foreignId('shift_category_id')->nullable()->after('shift_template_id')->constrained('shift_categories')->nullOnDelete();
            $table->index(['shift_category_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex(['shift_category_id', 'work_date']);
            $table->dropConstrainedForeignId('shift_category_id');
        });

        Schema::table('shift_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_category_id');
            $table->dropColumn('category_first_assigned_at');
        });

        Schema::dropIfExists('shift_categories');
    }
};
