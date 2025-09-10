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
        // First, migrate existing content to the new table
        $this->migrateExistingContent();
        
        // Then remove the content column and fulltext index from pdf_document_pages
        Schema::table('pdf_document_pages', function (Blueprint $table) {
            // Drop the existing fulltext index first (MySQL only)
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->dropFullText('pdf_pages_content_fulltext');
            }
            // Remove the content column
            $table->dropColumn('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add the content column to pdf_document_pages
        Schema::table('pdf_document_pages', function (Blueprint $table) {
            $table->longText('content')->nullable();
            // Add FULLTEXT index (MySQL only)
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['content'], 'pdf_pages_content_fulltext');
            }
        });

        // Migrate content back from pdf_page_content table
        $this->migrateContentBack();
    }

    /**
     * Migrate existing content from pdf_document_pages to pdf_page_content
     */
    protected function migrateExistingContent(): void
    {
        $pages = DB::table('pdf_document_pages')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->select('id', 'content')
            ->get();

        foreach ($pages as $page) {
            $content = $page->content;
            $contentLength = mb_strlen($content);
            $wordCount = str_word_count(strip_tags($content));
            $contentHash = hash('sha256', $content);

            DB::table('pdf_page_content')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'page_id' => $page->id,
                'content' => $content,
                'content_hash' => $contentHash,
                'content_length' => $contentLength,
                'word_count' => $wordCount,
                'extracted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Log::info('Migrated content for ' . $pages->count() . ' pages to pdf_page_content table');
    }

    /**
     * Migrate content back from pdf_page_content to pdf_document_pages (for rollback)
     */
    protected function migrateContentBack(): void
    {
        $contents = DB::table('pdf_page_content')
            ->select('page_id', 'content')
            ->get();

        foreach ($contents as $contentRecord) {
            DB::table('pdf_document_pages')
                ->where('id', $contentRecord->page_id)
                ->update(['content' => $contentRecord->content]);
        }

        \Illuminate\Support\Facades\Log::info('Migrated content back for ' . $contents->count() . ' pages to pdf_document_pages table');
    }
};