# Infrastructure Configuration Guide

This document outlines the infrastructure requirements and configuration for the Laravel PDF Viewer package, including the TOC/Outline and Link extraction features.

## Table of Contents

1. [Environment Variables](#environment-variables)
2. [AWS S3 Configuration](#aws-s3-configuration)
3. [Queue Configuration](#queue-configuration)
4. [Database Configuration](#database-configuration)
5. [Cache Configuration](#cache-configuration)
6. [Laravel Vapor Configuration](#laravel-vapor-configuration)
7. [Performance Tuning](#performance-tuning)

---

## Environment Variables

### Core Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PDF_VIEWER_ROUTE_PREFIX` | `api/pdf-viewer` | API route prefix for all endpoints |
| `PDF_VIEWER_DISK` | `s3` | Storage disk for PDF files |
| `PDF_VIEWER_PATH` | `pdf-documents` | Base path for document storage |
| `PDF_VIEWER_PAGES_PATH` | `pdf-pages` | Path for extracted page PDFs |
| `PDF_VIEWER_THUMBNAILS_PATH` | `pdf-thumbnails` | Path for page thumbnails |

### TOC & Link Extraction Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PDF_VIEWER_OUTLINE_ENABLED` | `true` | Enable automatic TOC/outline extraction |
| `PDF_VIEWER_LINKS_ENABLED` | `true` | Enable automatic link extraction |
| `PDF_VIEWER_MAX_PAGES_FOR_LINKS` | `0` | Max pages for link extraction (0 = unlimited) |
| `PDF_VIEWER_LINK_BATCH_SIZE` | `100` | Batch size for link database inserts |

### AWS/S3 Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PDF_VIEWER_AWS_ACCESS_KEY_ID` | - | AWS access key (optional, uses Laravel's default if not set) |
| `PDF_VIEWER_AWS_SECRET_ACCESS_KEY` | - | AWS secret key (optional) |
| `PDF_VIEWER_AWS_REGION` | `ap-southeast-2` | AWS region |
| `PDF_VIEWER_AWS_BUCKET` | - | S3 bucket name |
| `PDF_VIEWER_SIGNED_URL_EXPIRES` | `3600` | Signed URL expiration (seconds) |
| `PDF_VIEWER_MULTIPART_THRESHOLD` | `52428800` | Multipart upload threshold (50MB) |

### Queue Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PDF_VIEWER_QUEUE` | `default` | Default queue name |
| `PDF_VIEWER_DOCUMENT_QUEUE` | `pdf-processing` | Queue for document processing jobs |
| `PDF_VIEWER_PAGE_QUEUE` | `pdf-pages` | Queue for page extraction jobs |
| `PDF_VIEWER_TEXT_QUEUE` | `pdf-text` | Queue for text processing jobs |
| `PDF_VIEWER_DOCUMENT_TRIES` | `3` | Max retries for document jobs |
| `PDF_VIEWER_DOCUMENT_TIMEOUT` | `300` | Document job timeout (seconds) |
| `PDF_VIEWER_PAGE_TRIES` | `2` | Max retries for page jobs |
| `PDF_VIEWER_PAGE_TIMEOUT` | `60` | Page job timeout (seconds) |

### Cache Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PDF_VIEWER_CACHE_ENABLED` | `true` | Enable caching |
| `PDF_VIEWER_CACHE_STORE` | `redis` | Cache store driver |
| `PDF_VIEWER_CACHE_PREFIX` | `pdf_viewer` | Cache key prefix |
| `PDF_VIEWER_CACHE_DOCUMENT_TTL` | `3600` | Document metadata cache TTL |
| `PDF_VIEWER_CACHE_PAGE_TTL` | `7200` | Page content cache TTL |
| `PDF_VIEWER_CACHE_SEARCH_TTL` | `1800` | Search results cache TTL |

### Vapor/Lambda Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `PDF_VIEWER_VAPOR_ENABLED` | `false` | Enable Vapor-specific optimizations |
| `PDF_VIEWER_LAMBDA_TIMEOUT` | `900` | Lambda function timeout (max 15 min) |
| `PDF_VIEWER_LAMBDA_MEMORY` | `3008` | Lambda memory allocation (MB) |
| `PDF_VIEWER_TEMP_DIR` | `/tmp` | Temporary directory for processing |
| `PDF_VIEWER_CHUNK_SIZE` | `1048576` | S3 streaming chunk size (1MB) |

---

## AWS S3 Configuration

### Required S3 Bucket Policy

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "PDFViewerAccess",
            "Effect": "Allow",
            "Principal": {
                "AWS": "arn:aws:iam::YOUR_ACCOUNT_ID:role/YOUR_ROLE"
            },
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::YOUR_BUCKET_NAME",
                "arn:aws:s3:::YOUR_BUCKET_NAME/*"
            ]
        }
    ]
}
```

### CORS Configuration (for presigned URLs)

```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "PUT", "POST", "HEAD"],
        "AllowedOrigins": ["https://your-domain.com"],
        "ExposeHeaders": ["ETag", "Content-Length"],
        "MaxAgeSeconds": 3600
    }
]
```

### Recommended S3 Structure

```
your-bucket/
├── pdf-documents/           # Original PDF files
│   └── {document-hash}.pdf
├── pdf-pages/               # Extracted single-page PDFs
│   └── {document-hash}/
│       └── page-{number}.pdf
└── pdf-thumbnails/          # Page thumbnails
    └── {document-hash}/
        └── thumb-{number}.jpg
```

---

## Queue Configuration

### Recommended Queue Architecture

For optimal performance, use separate queues for different job types:

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

### Queue Worker Configuration

```bash
# Document processing (long-running)
php artisan queue:work redis --queue=pdf-processing --timeout=600 --tries=3

# Page extraction (medium duration)
php artisan queue:work redis --queue=pdf-pages --timeout=120 --tries=2

# Text processing (fast)
php artisan queue:work redis --queue=pdf-text --timeout=60 --tries=2
```

### Supervisor Configuration

```ini
[program:pdf-document-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --queue=pdf-processing --sleep=3 --tries=3 --timeout=600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/pdf-document-worker.log

[program:pdf-page-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --queue=pdf-pages --sleep=3 --tries=2 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/pdf-page-worker.log
```

---

## Database Configuration

### Required Tables

The package requires the following database tables:

| Table | Purpose |
|-------|---------|
| `pdf_documents` | Main document metadata |
| `pdf_document_pages` | Individual page data |
| `pdf_document_outlines` | TOC/outline entries (hierarchical) |
| `pdf_document_links` | Link annotations with coordinates |

### Migration Order

Run migrations in this order:

```bash
php artisan migrate --path=database/migrations/2024_08_22_000001_create_pdf_documents_table.php
php artisan migrate --path=database/migrations/2024_08_22_000002_create_pdf_document_pages_table.php
php artisan migrate --path=database/migrations/2025_12_09_000001_create_pdf_document_outlines_table.php
php artisan migrate --path=database/migrations/2025_12_09_000002_create_pdf_document_links_table.php
```

Or publish and run all package migrations:

```bash
php artisan vendor:publish --tag=pdf-viewer-migrations
php artisan migrate
```

### Database Indexes

The following indexes are automatically created for performance:

**pdf_document_outlines:**
- `idx_document_level` - Composite index on (pdf_document_id, level)
- `idx_parent` - Index on parent_id for tree traversal
- Index on `destination_page`

**pdf_document_links:**
- `idx_document` - Index on pdf_document_id
- `idx_source_page` - Index on source_page_id
- Composite index on (pdf_document_id, source_page)
- Index on `destination_type`
- Index on `destination_page`

### MySQL Performance Recommendations

```sql
-- Ensure InnoDB buffer pool is appropriately sized
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB

-- For large documents with many links, increase sort buffer
SET GLOBAL sort_buffer_size = 4194304; -- 4MB

-- Enable query cache for read-heavy workloads
SET GLOBAL query_cache_type = 1;
SET GLOBAL query_cache_size = 67108864; -- 64MB
```

---

## Cache Configuration

### Redis Configuration

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],
    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],
],
```

### Cache Tags

The package uses the following cache tags for invalidation:

- `pdf_viewer_documents` - Document metadata
- `pdf_viewer_pages` - Page content
- `pdf_viewer_search` - Search results

Clear specific cache tags:

```php
Cache::tags('pdf_viewer_documents')->flush();
Cache::tags('pdf_viewer_pages')->flush();
```

---

## Laravel Vapor Configuration

### vapor.yml Configuration

```yaml
id: your-project-id
name: your-project-name

environments:
    production:
        memory: 3008
        cli-memory: 3008
        timeout: 900
        runtime: php-8.2:al2
        queues:
            - pdf-processing
            - pdf-pages
            - pdf-text
        storage: your-bucket-name

        environment:
            PDF_VIEWER_VAPOR_ENABLED: "true"
            PDF_VIEWER_DISK: "s3"
            PDF_VIEWER_TEMP_DIR: "/tmp"
            PDF_VIEWER_LAMBDA_TIMEOUT: "870"
            PDF_VIEWER_STREAM_READS: "true"
```

### Vapor Queue Workers

Configure queue workers in `vapor.yml`:

```yaml
environments:
    production:
        queues:
            - name: pdf-processing
              timeout: 900
              tries: 3
              memory: 3008
            - name: pdf-pages
              timeout: 120
              tries: 2
              memory: 1536
            - name: pdf-text
              timeout: 60
              tries: 2
              memory: 1024
```

### Lambda Timeout Considerations

Lambda has a maximum timeout of 15 minutes (900 seconds). The package automatically adjusts job timeouts:

```php
// Effective timeout = min(job_timeout, lambda_timeout - 30 buffer)
// Example: 300 second job timeout with 900 lambda timeout = 300 seconds
// Example: 1200 second job timeout with 900 lambda timeout = 870 seconds
```

---

## Performance Tuning

### Large Document Handling

For documents with 1000+ pages:

```env
# Increase batch sizes
PDF_VIEWER_LINK_BATCH_SIZE=200

# Enable chunked processing
PDF_VIEWER_CHUNK_PROCESSING=true
PDF_VIEWER_CHUNK_SIZE=100

# Increase memory limits
PDF_VIEWER_MEMORY_LIMIT=1024M
```

### Backfill Command for Existing Documents

After enabling TOC/Link extraction, backfill existing documents:

```bash
# Dry run to preview affected documents
php artisan pdf-viewer:backfill-metadata --all --dry-run

# Process all documents using queue
php artisan pdf-viewer:backfill-metadata --all --queue --batch-size=50

# Process specific document
php artisan pdf-viewer:backfill-metadata --document-hash=abc123 --force

# Process only TOC or links
php artisan pdf-viewer:backfill-metadata --all --outline-only --queue
php artisan pdf-viewer:backfill-metadata --all --links-only --queue
```

### Monitoring & Health Checks

```bash
# Check system health
php artisan pdf-viewer:monitor-health

# Cleanup old audit records
php artisan pdf-viewer:cleanup-audits --days=90
```

### Recommended Resource Allocation

| Document Size | Memory | Timeout | Queue Workers |
|--------------|--------|---------|---------------|
| < 100 pages | 512MB | 60s | 2 |
| 100-500 pages | 1GB | 180s | 4 |
| 500-1000 pages | 2GB | 300s | 4 |
| 1000+ pages | 3GB | 600s | 2 |

---

## Feature Flags (Kill Switches)

Disable features without code deployment:

```env
# Disable TOC extraction (kill switch)
PDF_VIEWER_OUTLINE_ENABLED=false

# Disable link extraction (kill switch)
PDF_VIEWER_LINKS_ENABLED=false

# Disable all processing
PDF_VIEWER_PROCESSING_ENABLED=false
```

These can be changed at runtime via environment variables without requiring code changes or redeployment.

---

## Troubleshooting

### Common Issues

1. **S3 Permission Denied**: Verify IAM role has required S3 permissions
2. **Queue Timeout**: Increase `PDF_VIEWER_DOCUMENT_TIMEOUT` for large files
3. **Memory Exhausted**: Increase `PDF_VIEWER_MEMORY_LIMIT` and PHP's `memory_limit`
4. **Database Deadlocks**: Reduce `PDF_VIEWER_LINK_BATCH_SIZE` for high concurrency

### Debug Mode

Enable detailed logging:

```env
PDF_VIEWER_LOG_PROCESSING=true
PDF_VIEWER_METRICS_ENABLED=true
LOG_LEVEL=debug
```

Check logs:

```bash
tail -f storage/logs/laravel.log | grep -E "(pdf|PDF)"
```
