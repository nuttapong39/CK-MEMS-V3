<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_tickets', function (Blueprint $table) {
            // Free-text reporter name for anonymous (public QR) repair requests
            $table->string('reporter_name', 191)->nullable()->after('reported_by');
        });

        // Allow anonymous repair tickets (reported via public QR scan, no login)
        DB::statement('ALTER TABLE repair_tickets MODIFY reported_by BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Revert to NOT NULL (will fail if anonymous rows exist — clean them first if needed)
        DB::statement('ALTER TABLE repair_tickets MODIFY reported_by BIGINT UNSIGNED NOT NULL');

        Schema::table('repair_tickets', function (Blueprint $table) {
            $table->dropColumn('reporter_name');
        });
    }
};
