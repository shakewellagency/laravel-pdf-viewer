<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Note: SQLite doesn't enforce ENUM constraints - it stores them as TEXT.
     * Since we're just adding a new valid value, we don't need to modify
     * the column for SQLite; the new value will work automatically.
     */
    public function up(): void
    {
        // Handle different database drivers
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL supports direct ENUM modification
            DB::statement("ALTER TABLE pdf_documents MODIFY COLUMN status ENUM('uploaded', 'processing', 'completed', 'failed', 'cancelled', 'pending_upload') NOT NULL DEFAULT 'uploaded'");
        }
        // For SQLite and PostgreSQL, no modification needed - the column is TEXT-based
        // and will accept the new 'pending_upload' value automatically.
        // Laravel's ENUM is stored as varchar/text in SQLite.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Handle different database drivers
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL supports direct ENUM modification
            // First update any pending_upload status to uploaded
            DB::statement("UPDATE pdf_documents SET status = 'uploaded' WHERE status = 'pending_upload'");
            DB::statement("ALTER TABLE pdf_documents MODIFY COLUMN status ENUM('uploaded', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'uploaded'");
        } else {
            // For SQLite, just update any pending_upload values to uploaded
            DB::statement("UPDATE pdf_documents SET status = 'uploaded' WHERE status = 'pending_upload'");
        }
    }
};