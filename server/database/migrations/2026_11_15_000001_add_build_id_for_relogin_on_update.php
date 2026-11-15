<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'build_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('build_id', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('users', 'web_login_build_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('web_login_build_id', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'build_id')) {
            Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('build_id'));
        }
        if (Schema::hasColumn('users', 'web_login_build_id')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('web_login_build_id'));
        }
    }
};
