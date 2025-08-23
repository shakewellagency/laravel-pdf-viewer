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
        // Handle different database drivers
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'mysql') {
            // MySQL supports direct ENUM modification
            DB::statement("ALTER TABLE pdf_documents MODIFY COLUMN status ENUM('uploaded', 'processing', 'completed', 'failed', 'cancelled', 'pending_upload') NOT NULL DEFAULT 'uploaded'");
        } else {
            // For SQLite, use Laravel's schema builder to recreate the table
            Schema::table('pdf_documents', function (Blueprint $table) {
                // Drop the index first to avoid conflicts
                $table->dropIndex('pdf_documents_status_index');
            });
            
            Schema::table('pdf_documents', function (Blueprint $table) {
                $table->enum('status_temp', [
                    'uploaded',
                    'processing',
                    'completed',
                    'failed',
                    'cancelled',
                    'pending_upload'
                ])->default('uploaded');
            });
            
            // Copy data from old column to new column
            DB::statement('UPDATE pdf_documents SET status_temp = status');
            
            // Drop the old column and rename
            Schema::table('pdf_documents', function (Blueprint $table) {
                $table->dropColumn('status');
            });
            
            Schema::table('pdf_documents', function (Blueprint $table) {
                $table->renameColumn('status_temp', 'status');
                // Recreate the index
                $table->index('status');
            });
        }
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
            DB::statement("ALTER TABLE pdf_documents MODIFY COLUMN status ENUM('uploaded', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'uploaded'");
        } else {
            // For SQLite, recreate with original enum values
            Schema::table('pdf_documents', function (Blueprint $table) {
                // Drop the index first to avoid conflicts
                $table->dropIndex('pdf_documents_status_index');
            });
            
            Schema::table('pdf_documents', function (Blueprint $table) {
                $table->enum('status_temp', [
                    'uploaded',
                    'processing',
                    'completed',
                    'failed',
                    'cancelled'
                ])->default('uploaded');
            });
            
            // Copy data from old column to new column, filtering out pending_upload values
            DB::statement("UPDATE pdf_documents SET status_temp = CASE WHEN status = 'pending_upload' THEN 'uploaded' ELSE status END");
            
            // Drop the old column and rename
            Schema::table('pdf_documents', function (Blueprint $table) {
                $table->dropColumn('status');
            });
            
            Schema::table('pdf_documents', function (Blueprint $table) {
                $table->renameColumn('status_temp', 'status');
                // Recreate the index
                $table->index('status');
            });
        }
    }
};