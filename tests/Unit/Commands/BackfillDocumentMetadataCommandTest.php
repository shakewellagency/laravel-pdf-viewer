<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Shakewellagency\LaravelPdfViewer\Jobs\ProcessDocumentJob;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentOutline;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocumentLink;

beforeEach(function () {
    // Create test documents
    $this->documentWithoutMetadata = PdfDocument::create([
        'hash' => 'doc-without-metadata-' . uniqid(),
        'title' => 'Document Without Metadata',
        'filename' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'file_path' => 'pdf-documents/test.pdf',
        'file_size' => 1024000,
        'page_count' => 10,
        'status' => 'completed',
    ]);

    $this->documentWithOutline = PdfDocument::create([
        'hash' => 'doc-with-outline-' . uniqid(),
        'title' => 'Document With Outline',
        'filename' => 'test2.pdf',
        'original_filename' => 'test2.pdf',
        'mime_type' => 'application/pdf',
        'file_path' => 'pdf-documents/test2.pdf',
        'file_size' => 1024000,
        'page_count' => 10,
        'status' => 'completed',
    ]);

    // Add outline to second document
    PdfDocumentOutline::create([
        'pdf_document_id' => $this->documentWithOutline->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    $this->documentWithLinks = PdfDocument::create([
        'hash' => 'doc-with-links-' . uniqid(),
        'title' => 'Document With Links',
        'filename' => 'test3.pdf',
        'original_filename' => 'test3.pdf',
        'mime_type' => 'application/pdf',
        'file_path' => 'pdf-documents/test3.pdf',
        'file_size' => 1024000,
        'page_count' => 10,
        'status' => 'completed',
    ]);

    // Add links to third document
    PdfDocumentLink::create([
        'pdf_document_id' => $this->documentWithLinks->id,
        'source_page' => 1,
        'type' => 'internal',
        'destination_page' => 5,
    ]);
});

it('requires either --document-hash or --all option', function () {
    $this->artisan('pdf-viewer:backfill-metadata')
        ->assertExitCode(1)
        ->expectsOutput('You must specify either --document-hash or --all');
});

it('shows help examples when no options provided', function () {
    $this->artisan('pdf-viewer:backfill-metadata')
        ->expectsOutputToContain('Examples:')
        ->expectsOutputToContain('--all --dry-run')
        ->assertExitCode(1);
});

it('dry run shows documents that would be processed', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
    ])
        ->expectsOutput('DRY RUN - No changes will be made')
        ->assertExitCode(0);
});

it('finds documents missing metadata', function () {
    // Document without metadata should be found
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('document(s) to process.')
        ->assertExitCode(0);
});

it('can filter by document hash', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--document-hash' => $this->documentWithoutMetadata->hash,
        '--dry-run' => true,
    ])
        ->expectsOutput('Found 1 document(s) to process.')
        ->assertExitCode(0);
});

it('shows configuration in output', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
        '--batch-size' => 25,
    ])
        ->expectsOutputToContain('Configuration:')
        ->expectsOutputToContain('Batch Size: 25')
        ->assertExitCode(0);
});

it('outline only mode only processes documents missing outlines', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
        '--outline-only' => true,
    ])
        ->expectsOutputToContain('Outlines only')
        ->assertExitCode(0);
});

it('links only mode only processes documents missing links', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
        '--links-only' => true,
    ])
        ->expectsOutputToContain('Links only')
        ->assertExitCode(0);
});

it('queue mode dispatches jobs', function () {
    Bus::fake();

    $this->artisan('pdf-viewer:backfill-metadata', [
        '--document-hash' => $this->documentWithoutMetadata->hash,
        '--queue' => true,
        '--force' => true,
    ])
        ->expectsOutputToContain('Dispatching documents to queue')
        ->assertExitCode(0);

    Bus::assertDispatched(ProcessDocumentJob::class, function ($job) {
        return $job->document->id === $this->documentWithoutMetadata->id;
    });
});

it('handles empty database gracefully', function () {
    // Delete all documents
    PdfDocument::query()->delete();

    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
    ])
        ->expectsOutput('No documents found that need metadata backfill.')
        ->assertExitCode(0);
});

it('respects status filter', function () {
    // Create a processing document
    $processingDoc = PdfDocument::create([
        'hash' => 'processing-doc-' . uniqid(),
        'title' => 'Processing Document',
        'filename' => 'processing.pdf',
        'original_filename' => 'processing.pdf',
        'mime_type' => 'application/pdf',
        'file_path' => 'pdf-documents/processing.pdf',
        'file_size' => 1024000,
        'page_count' => 10,
        'status' => 'processing', // Not completed
    ]);

    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
        '--status' => 'processing',
    ])
        ->expectsOutputToContain('Status Filter: processing')
        ->assertExitCode(0);
});

it('document with both outline and links not included in all query', function () {
    // Delete all test documents first
    PdfDocument::query()->delete();

    // Create a document with both outline and links
    $fullDoc = PdfDocument::create([
        'hash' => 'full-doc-' . uniqid(),
        'title' => 'Full Document',
        'filename' => 'full.pdf',
        'original_filename' => 'full.pdf',
        'mime_type' => 'application/pdf',
        'file_path' => 'pdf-documents/full.pdf',
        'file_size' => 1024000,
        'page_count' => 10,
        'status' => 'completed',
    ]);

    PdfDocumentOutline::create([
        'pdf_document_id' => $fullDoc->id,
        'title' => 'Chapter 1',
        'level' => 0,
        'destination_page' => 1,
        'order_index' => 0,
    ]);

    PdfDocumentLink::create([
        'pdf_document_id' => $fullDoc->id,
        'source_page' => 1,
        'type' => 'internal',
        'destination_page' => 5,
    ]);

    // When using --all, this complete document should not be found
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
    ])
        ->expectsOutput('No documents found that need metadata backfill.')
        ->assertExitCode(0);
});

it('can be cancelled with no when prompted for confirmation', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
    ])
        ->expectsQuestion('Proceed with backfilling metadata for 3 document(s)?', false)
        ->expectsOutput('Operation cancelled.')
        ->assertExitCode(0);
});

it('force option skips confirmation', function () {
    Bus::fake();

    $this->artisan('pdf-viewer:backfill-metadata', [
        '--document-hash' => $this->documentWithoutMetadata->hash,
        '--queue' => true,
        '--force' => true,
    ])
        ->doesntExpectOutput('Proceed with backfilling metadata')
        ->assertExitCode(0);
});

it('logs when dispatching to queue', function () {
    Bus::fake();
    Log::spy();

    $this->artisan('pdf-viewer:backfill-metadata', [
        '--document-hash' => $this->documentWithoutMetadata->hash,
        '--queue' => true,
        '--force' => true,
    ])->assertExitCode(0);

    Log::shouldHaveReceived('info')
        ->with('Dispatched document for metadata backfill', \Mockery::type('array'))
        ->once();
});

it('shows dry run preview with document info', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('document(s) to process')
        ->assertExitCode(0);
});

it('displays mode as live when not dry run', function () {
    Bus::fake();

    $this->artisan('pdf-viewer:backfill-metadata', [
        '--document-hash' => $this->documentWithoutMetadata->hash,
        '--queue' => true,
        '--force' => true,
    ])
        ->expectsOutputToContain('LIVE')
        ->assertExitCode(0);
});

it('displays mode as dry run when enabled', function () {
    $this->artisan('pdf-viewer:backfill-metadata', [
        '--all' => true,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);
});
