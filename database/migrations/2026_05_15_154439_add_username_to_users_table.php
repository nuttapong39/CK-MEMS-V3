<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Add column as nullable first — populate before adding unique constraint
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->after('name')
                  ->comment('ชื่อผู้ใช้สำหรับเข้าสู่ระบบ (unique ทั่วทั้งระบบ)');
        });

        // 2) Populate from existing `name` field.
        //    If two users share the same name, append _<id> to keep uniqueness.
        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $user) {
            $base     = $user->name;
            $username = $base;
            if (isset($taken[$username])) {
                $username = $base . '_' . $user->id;
            }
            $taken[$username] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        // 3) Now safe to add unique index
        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
