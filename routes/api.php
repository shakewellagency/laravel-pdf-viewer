<?php

use Illuminate\Support\Facades\Route;
use Shakewellagency\LaravelPdfViewer\Controllers\DocumentController;
use Shakewellagency\LaravelPdfViewer\Controllers\PageController;
use Shakewellagency\LaravelPdfViewer\Controllers\SearchController;

/*
|--------------------------------------------------------------------------
| PDF Viewer API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for the PDF Viewer package.
| These routes use hash-based document identification for security.
|
*/

// Document management routes
Route::prefix('documents')->group(function () {
    // Document CRUD operations
    Route::get('/', [DocumentController::class, 'index'])->name('pdf-viewer.documents.index');
    Route::post('/', [DocumentController::class, 'store'])->name('pdf-viewer.documents.store');
    Route::get('/{document_hash}', [DocumentController::class, 'show'])->name('pdf-viewer.documents.show');
    Route::patch('/{document_hash}', [DocumentController::class, 'update'])->name('pdf-viewer.documents.update');
    Route::delete('/{document_hash}', [DocumentController::class, 'destroy'])->name('pdf-viewer.documents.destroy');

    // Multipart upload routes
    Route::post('/multipart/initiate', [DocumentController::class, 'initiateMultipartUpload'])->name('pdf-viewer.documents.multipart.initiate');
    Route::post('/{document_hash}/multipart/complete', [DocumentController::class, 'completeMultipartUpload'])->name('pdf-viewer.documents.multipart.complete');
    Route::delete('/{document_hash}/multipart/abort', [DocumentController::class, 'abortMultipartUpload'])->name('pdf-viewer.documents.multipart.abort');
    Route::post('/{document_hash}/multipart/urls', [DocumentController::class, 'getMultipartUrls'])->name('pdf-viewer.documents.multipart.urls');

    // Document processing routes
    Route::post('/{document_hash}/process', [DocumentController::class, 'process'])->name('pdf-viewer.documents.process');
    Route::get('/{document_hash}/progress', [DocumentController::class, 'progress'])->name('pdf-viewer.documents.progress');
    Route::post('/{document_hash}/retry', [DocumentController::class, 'retry'])->name('pdf-viewer.documents.retry');
    Route::post('/{document_hash}/cancel', [DocumentController::class, 'cancel'])->name('pdf-viewer.documents.cancel');

    // Document pages routes
    Route::get('/{document_hash}/pages', [PageController::class, 'index'])->name('pdf-viewer.documents.pages.index');
    Route::get('/{document_hash}/pages/{page_number}', [PageController::class, 'show'])->name('pdf-viewer.documents.pages.show');
    Route::get('/{document_hash}/pages/{page_number}/thumbnail', [PageController::class, 'thumbnail'])->name('pdf-viewer.documents.pages.thumbnail');
    Route::get('/{document_hash}/pages/{page_number}/download', [PageController::class, 'download'])->name('pdf-viewer.documents.pages.download');

    // Compliance and audit routes
    Route::get('/{document_hash}/download-original', [DocumentController::class, 'downloadOriginal'])->name('pdf-viewer.documents.download-original');
    Route::get('/{document_hash}/compliance-report', [DocumentController::class, 'complianceReport'])->name('pdf-viewer.documents.compliance-report');
    Route::get('/{document_hash}/detailed-progress', [DocumentController::class, 'detailedProgress'])->name('pdf-viewer.documents.detailed-progress');
    Route::get('/{document_hash}/cross-ref-script.js', [DocumentController::class, 'crossReferenceScript'])->name('pdf-viewer.documents.cross-ref-script');
});

// Search routes
Route::prefix('search')->group(function () {
    // Global search across all documents
    Route::get('/', [SearchController::class, 'searchDocuments'])->name('pdf-viewer.search.documents');
    
    // Search within specific document
    Route::get('/documents/{document_hash}', [SearchController::class, 'searchPages'])->name('pdf-viewer.search.pages');
    
    // Search suggestions/autocomplete
    Route::get('/suggestions', [SearchController::class, 'suggestions'])->name('pdf-viewer.search.suggestions');
});

// Secure file access route (for signed URLs with local storage)
Route::get('/secure-file/{token}', [DocumentController::class, 'secureFileAccess'])->name('pdf-viewer.secure-file');

// Utility routes
Route::prefix('utils')->group(function () {
    // Cache management
    Route::post('/cache/clear', [DocumentController::class, 'clearCache'])->name('pdf-viewer.utils.cache.clear');
    Route::post('/cache/warm/{document_hash}', [DocumentController::class, 'warmCache'])->name('pdf-viewer.utils.cache.warm');
    
    // Statistics and monitoring
    Route::get('/stats', [DocumentController::class, 'stats'])->name('pdf-viewer.utils.stats');
    Route::get('/health', [DocumentController::class, 'health'])->name('pdf-viewer.utils.health');
    Route::get('/monitoring', [DocumentController::class, 'monitoringDashboard'])->name('pdf-viewer.utils.monitoring');
});