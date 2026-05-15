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
        Schema::table('users', function (Blueprint $table) {
            $table->string('moph_client_key', 128)->nullable()->after('is_active')
                  ->comment('MOPH Alert client-key สำหรับแจ้งเตือนส่วนตัวของผู้ใช้');
            $table->string('moph_secret_key', 128)->nullable()->after('moph_client_key')
                  ->comment('MOPH Alert secret-key สำหรับแจ้งเตือนส่วนตัวของผู้ใช้');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['moph_client_key', 'moph_secret_key']);
        });
    }
};
