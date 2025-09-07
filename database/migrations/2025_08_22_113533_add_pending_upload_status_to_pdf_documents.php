<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add pending_upload to the status enum (MySQL only)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pdf_documents MODIFY COLUMN status ENUM('uploaded', 'processing', 'completed', 'failed', 'cancelled', 'pending_upload') NOT NULL DEFAULT 'uploaded'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove pending_upload from the status enum (MySQL only)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pdf_documents MODIFY COLUMN status ENUM('uploaded', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'uploaded'");
        }
    }
};