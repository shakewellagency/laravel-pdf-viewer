# Laravel PDF Viewer Package - Deployment Guide

This guide covers deployment considerations and best practices for the Laravel PDF Viewer Package in production environments.

## Pre-Deployment Checklist

### System Requirements Verification

- [ ] **PHP 8.1+** with required extensions (fileinfo, zip, gd)
- [ ] **Laravel 11.9+** installed and configured
- [ ] **MySQL 5.7+** with FULLTEXT search support enabled
- [ ] **Redis** server for caching (recommended)
- [ ] **Queue worker** capability (Redis/Database)
- [ ] **Sufficient disk space** for PDF storage and thumbnails
- [ ] **Memory limits** appropriate for PDF processing (512MB+ recommended)

### Dependencies Installation

```bash
# Install the package
composer require shakewellagency/laravel-pdf-viewer

# Verify dependencies
composer show shakewellagency/laravel-pdf-viewer
```

## Production Configuration

### Environment Variables

Add these variables to your production `.env` file:

```env
# Storage Configuration
PDF_VIEWER_STORAGE_DISK=local
PDF_VIEWER_STORAGE_PATH=pdf-documents

# Processing Configuration  
PDF_VIEWER_MAX_FILE_SIZE=104857600
PDF_VIEWER_PROCESSING_TIMEOUT=1800
PDF_VIEWER_PARALLEL_JOBS=10

# Job Configuration
PDF_VIEWER_JOB_QUEUE=pdf-processing
PDF_VIEWER_JOB_CONNECTION=redis
PDF_VIEWER_JOB_RETRY_ATTEMPTS=3
PDF_VIEWER_JOB_RETRY_DELAY=30

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

# Security Configuration
PDF_VIEWER_HASH_ALGORITHM=sha256
PDF_VIEWER_UPLOAD_MAX_SIZE=104857600
PDF_VIEWER_REQUIRE_AUTH=true

# Monitoring Configuration
PDF_VIEWER_MONITORING_ENABLED=true
PDF_VIEWER_METRICS_RETENTION_DAYS=30
```

### Database Setup

1. **Run migrations in production:**
```bash
php artisan migrate --force
```

2. **Verify FULLTEXT indexes:**
```sql
-- Check indexes exist
SHOW INDEX FROM pdf_documents WHERE Key_name LIKE 'FULLTEXT%';
SHOW INDEX FROM pdf_document_pages WHERE Key_name LIKE 'FULLTEXT%';

-- Verify ft_min_word_len setting
SHOW VARIABLES LIKE 'ft_min_word_len';
```

3. **Optimize database configuration:**
```sql
-- MySQL optimization for FULLTEXT search
SET innodb_ft_min_token_size = 2;
SET innodb_ft_cache_size = 32000000;
SET innodb_ft_total_cache_size = 640000000;
```

### Queue Configuration

1. **Configure Redis for queues:**
```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('PDF_VIEWER_JOB_QUEUE', 'pdf-processing'),
        'retry_after' => 1800,
        'block_for' => null,
    ],
],
```

2. **Start queue workers:**
```bash
# Start dedicated PDF processing workers
php artisan queue:work redis --queue=pdf-processing --timeout=1800 --memory=512 --tries=3

# Or use Supervisor for process management (recommended)
```

3. **Supervisor configuration example:**
```ini
[program:pdf-processing-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work redis --queue=pdf-processing --sleep=3 --tries=3 --max-time=3600 --memory=512
directory=/var/www
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/queue-worker.log
stopwaitsecs=3600
```

## Server Configuration

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/public;

    index index.php;

    # Increase upload limits for PDFs
    client_max_body_size 100M;
    client_body_timeout 300s;

    # Optimize for static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|pdf)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Increase timeouts for PDF processing
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    # API rate limiting
    location /api/pdf-viewer/ {
        limit_req zone=api burst=20 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Secure direct access to storage
    location /storage/pdf-documents {
        deny all;
    }
}

# Rate limiting configuration
http {
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/m;
}
```

### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/public
    
    # Increase upload limits
    LimitRequestBody 104857600  # 100MB
    
    # PHP settings
    <Directory /var/www/public>
        AllowOverride All
        Require all granted
        
        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
    </Directory>
    
    # Secure storage directory
    <Directory /var/www/storage/app/pdf-documents>
        Order deny,allow
        Deny from all
    </Directory>
    
    # Cache static assets
    <LocationMatch "\.(css|js|png|jpg|jpeg|gif|ico)$">
        ExpiresActive On
        ExpiresDefault "access plus 1 year"
    </LocationMatch>
</VirtualHost>
```

### PHP Configuration

Update `php.ini` for production:

```ini
# Memory and execution limits
memory_limit = 512M
max_execution_time = 1800
max_input_time = 300

# File upload limits
upload_max_filesize = 100M
post_max_size = 100M
max_file_uploads = 10

# Required extensions
extension=fileinfo
extension=zip
extension=gd
extension=imagick

# OpCache optimization
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.save_comments=1
```

## Storage Configuration

### Local Storage Setup

```bash
# Create storage directories
mkdir -p storage/app/pdf-documents
mkdir -p storage/app/thumbnails

# Set proper permissions
chown -R www-data:www-data storage/
chmod -R 755 storage/
chmod -R 644 storage/app/pdf-documents/
```

### S3/Cloud Storage (Optional)

```php
// config/filesystems.php
'disks' => [
    'pdf-storage' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],
],
```

## Monitoring and Logging

### Application Logging

```php
// config/logging.php
'channels' => [
    'pdf-viewer' => [
        'driver' => 'daily',
        'path' => storage_path('logs/pdf-viewer.log'),
        'level' => env('LOG_LEVEL', 'info'),
        'days' => 14,
    ],
],
```

### Queue Monitoring

```bash
# Monitor queue status
php artisan queue:monitor redis:pdf-processing

# Check failed jobs
php artisan queue:failed

# Queue statistics
php artisan queue:work --once --verbose
```

### Health Check Endpoint

Create a custom health check for the PDF processing system:

```php
// routes/web.php or api.php
Route::get('/health/pdf-viewer', function () {
    $checks = [
        'database' => DB::connection()->getPdo() !== null,
        'redis' => Cache::store('redis')->get('health-check') !== false,
        'storage' => Storage::disk(config('pdf-viewer.storage.disk'))->exists(''),
        'queue' => Queue::size('pdf-processing') !== null,
    ];
    
    $healthy = !in_array(false, $checks, true);
    
    return response()->json([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $healthy ? 200 : 503);
});
```

## Performance Optimization

### Database Optimization

```sql
-- Optimize tables regularly
OPTIMIZE TABLE pdf_documents;
OPTIMIZE TABLE pdf_document_pages;

-- Monitor query performance
EXPLAIN SELECT * FROM pdf_document_pages WHERE MATCH(content) AGAINST('search term');

-- Index optimization
ANALYZE TABLE pdf_documents;
ANALYZE TABLE pdf_document_pages;
```

### Cache Optimization

```php
// Warm caches after deployment
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

// PDF Viewer specific cache warming
php artisan pdf-viewer:cache:warm --popular
```

### Memory Management

```bash
# Monitor memory usage
free -h
htop

# Monitor PHP memory usage
php -i | grep memory_limit

# Monitor queue worker memory
ps aux | grep "queue:work"
```

## Security Configuration

### File Security

```php
// config/pdf-viewer.php
'security' => [
    'allowed_mime_types' => ['application/pdf'],
    'max_file_size' => 104857600, // 100MB
    'hash_algorithm' => 'sha256',
    'scan_uploads' => env('PDF_VIEWER_SCAN_UPLOADS', true),
],
```

### Access Control

```php
// Middleware for API authentication
'api' => [
    'throttle:60,1',
    'auth:api',
    'pdf-viewer.auth',
],
```

### Rate Limiting

```php
// config/pdf-viewer.php
'rate_limiting' => [
    'upload' => '10,1', // 10 uploads per minute
    'search' => '60,1', // 60 searches per minute
    'api' => '100,1',   // 100 API calls per minute
],
```

## Backup Strategy

### Database Backup

```bash
# Daily backup script
#!/bin/bash
BACKUP_DIR="/backups/database"
DB_NAME="your_database"
DATE=$(date +%Y%m%d_%H%M%S)

mysqldump --single-transaction --routines --triggers ${DB_NAME} > ${BACKUP_DIR}/pdf_viewer_${DATE}.sql
gzip ${BACKUP_DIR}/pdf_viewer_${DATE}.sql

# Keep only last 7 days
find ${BACKUP_DIR} -name "pdf_viewer_*.sql.gz" -mtime +7 -delete
```

### File Storage Backup

```bash
# Backup PDF files and thumbnails
rsync -av --progress storage/app/pdf-documents/ /backups/pdf-documents/
rsync -av --progress storage/app/thumbnails/ /backups/thumbnails/
```

## Troubleshooting

### Common Issues

1. **Queue workers not processing:**
```bash
# Check queue status
php artisan queue:work --once --verbose

# Restart workers
sudo supervisorctl restart pdf-processing-worker:*
```

2. **Out of memory errors:**
```bash
# Check memory usage
php -r "echo ini_get('memory_limit').PHP_EOL;"

# Increase memory limit temporarily
php -d memory_limit=1G artisan queue:work
```

3. **FULLTEXT search not working:**
```sql
-- Check minimum word length
SHOW VARIABLES LIKE 'ft_min_word_len';

-- Rebuild indexes
ALTER TABLE pdf_document_pages DROP INDEX content_fulltext;
ALTER TABLE pdf_document_pages ADD FULLTEXT(content);
```

### Log Analysis

```bash
# Monitor application logs
tail -f storage/logs/laravel.log | grep "pdf-viewer"

# Monitor queue logs
tail -f storage/logs/queue-worker.log

# System resource monitoring
top -p $(pgrep -f "queue:work")
```

## Scaling Considerations

### Horizontal Scaling

1. **Separate queue workers:**
   - Dedicated servers for PDF processing
   - Load balancing for API endpoints
   - Shared storage for PDF files

2. **Database scaling:**
   - Read replicas for search queries
   - Partitioning large tables by date
   - Connection pooling

3. **Cache scaling:**
   - Redis cluster for high availability
   - Distributed caching strategies
   - CDN for thumbnail delivery

### Performance Monitoring

```bash
# Monitor key metrics
watch -n 1 'php artisan queue:work --once --verbose'
watch -n 5 'redis-cli info memory'
watch -n 10 'df -h'
```

This deployment guide provides a comprehensive foundation for running the Laravel PDF Viewer Package in production. Adjust configurations based on your specific requirements and infrastructure.