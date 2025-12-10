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
     * Note: The content column is kept in pdf_document_pages for backwards compatibility.
     * The new pdf_page_content table provides a normalized alternative.
     */
    public function up(): void
    {
        // Migrate existing content to the new table for normalization
        // But keep the original column for backwards compatibility
        $this->migrateExistingContent();

        // Note: NOT removing the content column to maintain backwards compatibility
        // with existing code and tests that directly access content
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The content column was not dropped, so no need to re-add it.
        // Just clear the migrated content from pdf_page_content table.
        DB::table('pdf_page_content')->truncate();
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