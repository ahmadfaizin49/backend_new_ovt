<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Bersihkan semua nilai yang tidak valid, set ke 'hari_biasa'
        DB::statement("UPDATE overtimes SET keterangan = 'hari_biasa' WHERE keterangan NOT IN ('hari_biasa', 'libur') OR keterangan IS NULL");
        // Nonaktifkan strict mode sementara agar MySQL tidak treat warning sebagai error
        DB::statement("SET SESSION sql_mode = ''");
        DB::statement("ALTER TABLE overtimes MODIFY COLUMN keterangan ENUM('hari_biasa','libur') NOT NULL DEFAULT 'hari_biasa'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE overtimes MODIFY COLUMN keterangan VARCHAR(500) NULL");
    }
};
