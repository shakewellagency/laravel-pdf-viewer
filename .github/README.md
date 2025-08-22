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
PDF_VIEWER_AWS_ACCESS_KEY_ID=your_access_key_here
PDF_VIEWER_AWS_SECRET_ACCESS_KEY=your_secret_access_key_here
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
- Orchestrates the entire processing pipeline
- Splits PDF into individual pages
- Dispatches ProcessPageTextJob for each page
- Handles processing completion and error states

### 2. ProcessPageTextJob
- Extracts text content from individual pages using Poppler/pdftotext
- Processes and cleans extracted text
- Dispatches ExtractPageJob for thumbnail generation
- Updates page processing status

### 3. ExtractPageJob
- Generates high-quality thumbnails using ImageMagick
- Creates multiple thumbnail sizes if configured
- Handles image optimization and compression
- Updates final page completion status

### Queue Configuration

Jobs can be distributed across different queues for optimal performance:

```env
# Primary document processing queue
PDF_VIEWER_JOB_QUEUE=pdf-processing

# Page-specific processing queues
PDF_VIEWER_PAGE_QUEUE=pdf-pages
PDF_VIEWER_TEXT_QUEUE=pdf-text

# Queue connections
PDF_VIEWER_JOB_CONNECTION=redis
```

### Error Handling & Retries

- **Automatic Retries**: Jobs automatically retry up to 3 times with exponential backoff
- **Dead Letter Queue**: Failed jobs are logged for manual inspection
- **Progressive Fallbacks**: Graceful degradation when external tools fail
- **Status Tracking**: Real-time processing status updates

## Testing

The package includes a comprehensive test suite covering:

- **Unit Tests**: Core service functionality and edge cases
- **Feature Tests**: Complete API endpoint testing
- **Integration Tests**: Database interactions and job processing
- **Performance Tests**: Large document handling capabilities

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run specific test suites
composer test:unit
composer test:feature
```

## Performance Considerations

### Large Document Handling

The package is specifically designed for massive PDF documents:

- **Memory Efficient**: Pages processed individually, never loading entire document
- **Parallel Processing**: Multiple pages processed simultaneously
- **Progress Tracking**: Real-time progress updates for long-running operations
- **Fault Tolerance**: Individual page failures don't affect entire document

### Optimization Strategies

- **Database Indexing**: Optimized indexes for hash lookups and search queries
- **Caching Strategy**: Multi-layer caching for thumbnails and processed content
- **Queue Management**: Separate queues for different processing stages
- **Resource Limiting**: Configurable parallel job limits to prevent resource exhaustion

## Security Features

### Hash-Based Document Access
- Documents identified by cryptographic hashes instead of sequential IDs
- Prevents document enumeration attacks
- Secure URL generation for thumbnails and downloads

### Input Validation
- File type validation (PDF only)
- File size limits with configurable maximums
- Malicious content scanning capabilities
- Sanitized text extraction

### Access Control
- Laravel authentication integration ready
- Configurable access policies per document
- Secure file serving through Laravel routes
- Optional signed URL generation for enhanced security

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

### Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Copy and configure: `cp .env.example .env`
4. Run migrations: `php artisan migrate`
5. Run tests: `composer test`

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for version history and notable changes.

## License

This package is licensed under the MIT License. See [LICENSE](LICENSE) file for details.

## Support

- **Documentation**: Complete API documentation available in `/docs`
- **Examples**: Sample implementations in `/examples`
- **Issues**: Report bugs via GitHub Issues
- **Discussions**: Community support via GitHub Discussions

---

Built with ❤️ for handling massive PDF documents efficiently.
