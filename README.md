# Laravel PDF Viewer Package

A comprehensive Laravel package for handling massive PDF documents with page-by-page processing, full-text search, and parallel job processing capabilities.

> **Latest**: Full Laravel Vapor support with S3 storage and multipart uploads added in v1.1.0!

## Features

- 📄 **Page-by-page PDF processing** for documents with 9000+ pages
- 🔍 **Full-text search** with MySQL FULLTEXT indexes and relevance scoring
- ⚡ **Parallel processing** with individual page jobs and specialized sub-jobs
- 🔒 **Hash-based security** to prevent document ID enumeration
- 🚀 **Comprehensive caching** using Laravel's default cache methods
- 🎯 **SOLID design principles** compliance with interface segregation
- 🧪 **Complete test coverage** with unit and feature tests
- 🔗 **Mozilla PDF.js integration** ready
- ☁️ **Laravel Vapor compatible** with S3 storage and serverless deployment
- 🏗️ **Flexible storage** supporting local, S3, and other Laravel filesystem drivers

## Requirements

- PHP 8.1+
- Laravel 11.9+
- MySQL 5.7+ (with FULLTEXT search support)
- Redis (recommended for caching)
- Queue driver (Redis/Database recommended)

### For S3/Laravel Vapor Support

- AWS SDK for PHP 3.0+ (automatically installed)
- League Flysystem S3 adapter 3.0+ (automatically installed)
- Valid AWS S3 bucket and credentials

## Installation

1. **Install via Composer:**
```bash
composer require shakewellagency/laravel-pdf-viewer
```

2. **Publish and run migrations:**
```bash
php artisan vendor:publish --provider="Shakewellagency\\LaravelPdfViewer\\Providers\\PdfViewerServiceProvider" --tag="migrations"
php artisan migrate
```

3. **Publish configuration file:**
```bash
php artisan vendor:publish --provider="Shakewellagency\\LaravelPdfViewer\\Providers\\PdfViewerServiceProvider" --tag="config"
```

4. **Configure environment variables:**

**For Local Storage:**
```env
# Storage Configuration
PDF_VIEWER_STORAGE_DISK=local
PDF_VIEWER_STORAGE_PATH=pdf-documents
```

**For AWS S3/Laravel Vapor:**
```env
# Storage Configuration
PDF_VIEWER_STORAGE_DISK=s3

# AWS S3 Configuration (Package-specific to avoid conflicts)
PDF_VIEWER_AWS_ACCESS_KEY_ID=your_access_key
PDF_VIEWER_AWS_SECRET_ACCESS_KEY=your_secret_key
PDF_VIEWER_AWS_REGION=ap-southeast-2
PDF_VIEWER_AWS_BUCKET=your-bucket-name
PDF_VIEWER_AWS_USE_PATH_STYLE_ENDPOINT=false
```

**Common Processing Configuration:**
```env
# Processing Configuration
PDF_VIEWER_MAX_FILE_SIZE=104857600
PDF_VIEWER_PROCESSING_TIMEOUT=1800
PDF_VIEWER_PARALLEL_JOBS=10

# Job Configuration
PDF_VIEWER_JOB_QUEUE=default
PDF_VIEWER_JOB_CONNECTION=redis
PDF_VIEWER_JOB_RETRY_ATTEMPTS=3

# Cache Configuration
PDF_VIEWER_CACHE_ENABLED=true
PDF_VIEWER_CACHE_TTL=3600
PDF_VIEWER_CACHE_PREFIX=pdf_viewer

# Search Configuration
PDF_VIEWER_SEARCH_MIN_SCORE=0.1
PDF_VIEWER_SEARCH_SNIPPET_LENGTH=200

# Thumbnail Configuration
PDF_VIEWER_THUMBNAILS_ENABLED=true
PDF_VIEWER_THUMBNAILS_WIDTH=300
PDF_VIEWER_THUMBNAILS_HEIGHT=400
PDF_VIEWER_THUMBNAILS_QUALITY=80
```

## Usage

### Document Upload

```php
use Shakewellagency\LaravelPdfViewer\Services\DocumentService;

$documentService = app(DocumentService::class);

$uploadedFile = request()->file('pdf');
$metadata = [
    'title' => 'Aviation Safety Manual',
    'description' => 'Comprehensive safety procedures',
    'metadata' => [
        'author' => 'Aviation Authority',
        'subject' => 'Safety Procedures',
    ],
];

$document = $documentService->upload($uploadedFile, $metadata);
```

### Multipart Upload (S3/Large Files)

For large PDF files (especially when using S3), you can use multipart uploads with signed URLs for better performance and reliability:

```php
use Shakewellagency\LaravelPdfViewer\Services\DocumentService;

$documentService = app(DocumentService::class);

// 1. Initiate multipart upload
$metadata = [
    'title' => 'Large Aviation Manual',
    'original_filename' => 'large-aviation-manual.pdf',
    'file_size' => 52428800, // 50MB
    'total_parts' => 10
];

$uploadData = $documentService->initiateMultipartUpload($metadata);
$documentHash = $uploadData['document_hash'];
$uploadId = $uploadData['upload_id'];

// 2. Get signed URLs for each part (client-side upload)
$urlsData = $documentService->getMultipartUploadUrls($documentHash, 10);
$signedUrls = $urlsData['urls'];

// Client uploads parts directly to S3 using the signed URLs
// Each part returns an ETag that needs to be collected

// 3. Complete the multipart upload
$parts = [
    ['PartNumber' => 1, 'ETag' => '"d41d8cd98f00b204e9800998ecf8427e"'],
    ['PartNumber' => 2, 'ETag' => '"098f6bcd4621d373cade4e832627b4f6"'],
    // ... more parts
];

$result = $documentService->completeMultipartUpload($documentHash, $parts);

// 4. Or abort the upload if needed
// $documentService->abortMultipartUpload($documentHash);
```

### Laravel Vapor Deployment

The package is fully compatible with Laravel Vapor serverless deployment:

1. **Automatic S3 Integration**: When `PDF_VIEWER_STORAGE_DISK=s3` is configured, the package automatically uses S3 for file storage.

2. **Lambda Timeout Handling**: Processing is designed to work within Lambda's 15-minute timeout limit by using efficient page-by-page processing.

3. **Temporary File Management**: All processing uses `/tmp` directory which is available in Lambda environments.

4. **Package-Specific AWS Credentials**: Uses `PDF_VIEWER_AWS_*` environment variables to avoid conflicts with other packages or Vapor's own AWS configuration.

### Document Search

```php
use Shakewellagency\LaravelPdfViewer\Services\SearchService;

$searchService = app(SearchService::class);

// Search documents
$documents = $searchService->searchDocuments('aviation safety', [
    'status' => 'completed',
    'date_from' => now()->subDays(30),
]);

// Search pages
$pages = $searchService->searchPages('emergency procedures');

// Get search suggestions
$suggestions = $searchService->getSearchSuggestions('aviat');
```

### Caching

```php
use Shakewellagency\LaravelPdfViewer\Services\CacheService;

$cacheService = app(CacheService::class);

// Warm cache for a document
$cacheService->warmDocumentCache($documentHash);

// Get cached page content
$pageContent = $cacheService->getCachedPageContent($documentHash, $pageNumber);

// Invalidate document cache
$cacheService->invalidateDocumentCache($documentHash);
```

## API Endpoints

### Document Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/pdf-viewer/documents` | Upload PDF document |
| `GET` | `/api/pdf-viewer/documents` | List documents |
| `GET` | `/api/pdf-viewer/documents/{hash}` | Get document metadata |
| `PUT` | `/api/pdf-viewer/documents/{hash}` | Update document metadata |
| `DELETE` | `/api/pdf-viewer/documents/{hash}` | Delete document |
| `GET` | `/api/pdf-viewer/documents/{hash}/progress` | Get processing progress |
| `POST` | `/api/pdf-viewer/documents/{hash}/process` | Manually trigger processing |
| `POST` | `/api/pdf-viewer/documents/{hash}/retry` | Retry failed processing |
| `POST` | `/api/pdf-viewer/documents/{hash}/cancel` | Cancel ongoing processing |

### Multipart Upload (S3)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/pdf-viewer/documents/multipart/initiate` | Initiate multipart upload |
| `POST` | `/api/pdf-viewer/documents/{hash}/multipart/urls` | Get signed upload URLs |
| `POST` | `/api/pdf-viewer/documents/{hash}/multipart/complete` | Complete multipart upload |
| `DELETE` | `/api/pdf-viewer/documents/{hash}/multipart/abort` | Abort multipart upload |

### Page Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/pdf-viewer/documents/{hash}/pages` | List document pages |
| `GET` | `/api/pdf-viewer/documents/{hash}/pages/{page}` | Get specific page |
| `GET` | `/api/pdf-viewer/documents/{hash}/pages/{page}/thumbnail` | Get page thumbnail |
| `GET` | `/api/pdf-viewer/documents/{hash}/pages/{page}/download` | Download specific page as PDF |

### Search

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/pdf-viewer/search/documents` | Search across all documents |
| `GET` | `/api/pdf-viewer/search/documents/{hash}` | Search within specific document |
| `GET` | `/api/pdf-viewer/search/pages` | Search pages with snippets |
| `GET` | `/api/pdf-viewer/search/suggestions` | Get search suggestions |

### Utilities

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/pdf-viewer/utils/cache/clear` | Clear all cached data |
| `POST` | `/api/pdf-viewer/utils/cache/warm/{hash}` | Pre-generate cache for document |
| `GET` | `/api/pdf-viewer/utils/stats` | Get system statistics |
| `GET` | `/api/pdf-viewer/utils/health` | System health check |

## Job Processing Architecture

The package uses a 3-stage job processing system for maximum parallelization:

### 1. ProcessDocumentJob
- Master coordinator job
- Determines page count and spawns ExtractPageJob for each page
- Updates document status and progress tracking

### 2. ExtractPageJob  
- Individual page extraction job (one per page)
- Extracts single page from PDF
- Creates thumbnail image
- Spawns ProcessPageTextJob for text processing

### 3. ProcessPageTextJob
- Text extraction and search indexing
- OCR processing if needed
- Updates page status and search indexes

### Queue Configuration

```php
// In your AppServiceProvider or dedicated service provider
Queue::after(function (JobProcessed $event) {
    if ($event->job->payload()['displayName'] === ProcessDocumentJob::class) {
        // Handle document processing completion
    }
});
```

## Configuration

The package provides extensive configuration options in `config/pdf-viewer.php`:

### Storage Configuration
- Disk selection for PDF storage
- Path configuration for documents and thumbnails
- File size limits and validation rules

### Processing Configuration
- Timeout settings for PDF processing
- Parallel job limits
- Memory limits and optimization

### Cache Configuration
- Cache TTL settings
- Cache key prefixes
- Cache warming strategies

### Search Configuration
- MySQL FULLTEXT search settings
- Relevance scoring thresholds
- Snippet generation options

### Security Configuration
- Document hash algorithms
- Access control settings
- Rate limiting options

## Testing

The package includes comprehensive test coverage:

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit tests/Unit
./vendor/bin/phpunit tests/Feature

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Sample PDF Integration

The package supports testing with real PDF files. Place sample PDFs in the `SamplePDF` folder for integration testing:

```php
// In tests
$samplePdf = $this->createRealSamplePdf();
if ($samplePdf) {
    $response = $this->postJson('/api/pdf-viewer/documents', [
        'file' => $samplePdf,
        'title' => 'Real Aviation Document',
    ]);
}
```

## Frontend Integration

The package is designed to work with Mozilla PDF.js on the frontend:

### JavaScript Integration Example

```javascript
// Initialize PDF.js viewer
const pdfjsLib = window['pdfjs-dist/build/pdf'];

// Load document from API
async function loadDocument(documentHash) {
    const response = await fetch(`/api/pdf-viewer/documents/${documentHash}`);
    const docData = await response.json();
    
    const pdf = await pdfjsLib.getDocument({
        url: `/api/pdf-viewer/documents/${documentHash}/pdf`,
        httpHeaders: {
            'Authorization': `Bearer ${authToken}`
        }
    }).promise;
    
    return pdf;
}

// Search integration
async function searchDocument(query) {
    const response = await fetch(`/api/pdf-viewer/search/pages?q=${encodeURIComponent(query)}`);
    const results = await response.json();
    return results.data;
}
```

## Monitoring and Metrics

The package provides built-in monitoring capabilities:

### Job Monitoring

```php
// Monitor job progress
$progress = $documentService->getProgress($documentHash);

// Get processing statistics
$stats = [
    'total_pages' => $progress['total_pages'],
    'completed_pages' => $progress['completed_pages'],
    'failed_pages' => $progress['failed_pages'],
    'progress_percentage' => $progress['progress_percentage'],
];
```

### Cache Monitoring

```php
// Get cache statistics
$cacheStats = $cacheService->getCacheStats();

// Monitor cache hit rates
$hitRate = $cacheStats['hit_rate'];
$memoryUsage = $cacheStats['memory_usage'];
```

## Performance Optimization

### Recommended Settings

For optimal performance with large PDFs:

1. **Queue Configuration:**
```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('PDF_VIEWER_JOB_QUEUE', 'pdf-processing'),
    'retry_after' => 90,
    'block_for' => null,
],
```

2. **Database Optimization:**
```sql
-- Optimize FULLTEXT search
ALTER TABLE pdf_document_pages ADD FULLTEXT(content);
ALTER TABLE pdf_documents ADD FULLTEXT(title, description);

-- Add indexes for common queries
CREATE INDEX idx_documents_status_searchable ON pdf_documents(status, is_searchable);
CREATE INDEX idx_pages_document_status ON pdf_document_pages(document_id, status);
```

3. **Cache Optimization:**
```php
'cache' => [
    'enabled' => true,
    'ttl' => 3600, // 1 hour
    'tags_enabled' => true,
    'warm_on_completion' => true,
],
```

## Troubleshooting

### Common Issues

1. **Memory Issues with Large PDFs:**
```php
// Increase memory limit in job
ini_set('memory_limit', '512M');
```

2. **Queue Worker Configuration:**
```bash
# Start queue workers for PDF processing
php artisan queue:work --queue=pdf-processing --timeout=1800 --memory=512
```

3. **FULLTEXT Search Not Working:**
```sql
-- Check FULLTEXT indexes
SHOW INDEX FROM pdf_document_pages WHERE Key_name LIKE '%content%';

-- Verify minimum word length
SHOW VARIABLES LIKE 'ft_min_word_len';
```

## Laravel Vapor Deployment

The package is fully compatible with Laravel Vapor for serverless deployment:

### Quick Setup for Vapor

1. **Configure for S3 Storage:**
```env
PDF_VIEWER_DISK=s3
PDF_VIEWER_VAPOR_ENABLED=true
AWS_BUCKET=www.shakewell.agency
```

2. **Copy Vapor configuration:**
```bash
cp vendor/shakewellagency/laravel-pdf-viewer/vapor.yml.example vapor.yml
cp vendor/shakewellagency/laravel-pdf-viewer/.env.vapor.example .env.vapor
```

3. **Deploy to Vapor:**
```bash
vapor deploy production
```

### Vapor-Specific Features

- **S3 Signed URLs** - API endpoints return signed URLs for secure file access
- **Lambda Timeout Management** - Jobs automatically respect Lambda timeout limits
- **Temporary File Handling** - Efficient temp file management in `/tmp`
- **Memory Optimization** - Configurable memory limits for Lambda functions

For detailed Vapor deployment instructions, see: [VAPOR-DEPLOYMENT.md](VAPOR-DEPLOYMENT.md)

## Security Considerations

- Documents are identified by cryptographically secure hashes
- File type validation prevents malicious uploads
- Rate limiting on search endpoints
- Authentication required for all API endpoints
- Secure file storage with proper permissions
- S3 signed URLs for secure file access in cloud deployments

## Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## License

MIT License - see LICENSE file for details.

## Support

For support and questions, please create an issue on the GitHub repository or contact the development team.

---

**Shakewell Agency** - Laravel PDF Viewer Package v1.0.0