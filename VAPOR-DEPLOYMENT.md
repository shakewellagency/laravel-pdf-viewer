# Laravel PDF Viewer - Vapor Deployment Guide

This guide covers deploying the Laravel PDF Viewer package on Laravel Vapor with S3 storage for 100% serverless compatibility.

## Prerequisites

1. **Laravel Vapor Account** - Active subscription required
2. **AWS Account** - For S3, SQS, DynamoDB, and Lambda resources
3. **S3 Bucket** - Configured and accessible
4. **Package Installation** - Laravel PDF Viewer package installed via Composer

## Quick Setup

### 1. Install Required Dependencies

Ensure your `composer.json` includes:

```json
{
  "require": {
    "laravel/vapor-cli": "^1.0",
    "laravel/vapor-core": "^2.0",
    "aws/aws-sdk-php": "^3.0",
    "league/flysystem-aws-s3-v3": "^3.0"
  }
}
```

### 2. Environment Configuration

Copy `.env.vapor.example` to your project root and update:

```bash
cp vendor/shakewellagency/laravel-pdf-viewer/.env.vapor.example .env.vapor
```

**Key Environment Variables:**
```env
# S3 Storage (REQUIRED)
AWS_ACCESS_KEY_ID=your_access_key_here
AWS_SECRET_ACCESS_KEY=your_secret_access_key_here
AWS_DEFAULT_REGION=ap-southeast-2
AWS_BUCKET=your-bucket-name

# PDF Viewer S3 Configuration
PDF_VIEWER_DISK=s3
PDF_VIEWER_VAPOR_ENABLED=true
```

### 3. Vapor Configuration

Copy the example `vapor.yml`:

```bash
cp vendor/shakewellagency/laravel-pdf-viewer/vapor.yml.example vapor.yml
```

Update your `vapor.yml` with your project details:

```yaml
id: your-project-id
name: your-project-name
environments:
  production:
    memory: 3008  # Maximum for PDF processing
    timeout: 900  # 15 minutes for large PDFs
    cli-memory: 3008
    cli-timeout: 900
```

### 4. Configure Laravel Filesystems

Update `config/filesystems.php`:

```php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'throw' => false,
        'visibility' => 'public',
    ],
],
```

## Vapor-Specific Features

### 1. Lambda Timeout Management

The package automatically adjusts job timeouts for Lambda limits:
- Maximum Lambda timeout: 15 minutes
- Package jobs respect this limit with 30-second buffer
- Large PDFs are processed in smaller chunks

### 2. Temporary File Handling

```php
// Automatic temp file management
PDF_VIEWER_TEMP_DIR=/tmp
PDF_VIEWER_CHUNK_SIZE=1048576
```

### 3. S3 Signed URLs

API endpoints return signed URLs instead of streaming content:

```json
GET /api/pdf-viewer/documents/{hash}/pages/{page}/thumbnail
{
  "url": "https://s3.amazonaws.com/...",
  "expires_in": 3600,
  "content_type": "image/jpeg"
}
```

## Deployment Steps

### 1. Initial Deployment

```bash
# Deploy to production
vapor deploy production

# Deploy to staging  
vapor deploy staging
```

### 2. Configure AWS Resources

Vapor automatically creates:
- **Lambda Functions** - For web and queue processing
- **SQS Queues** - For job processing
- **DynamoDB Tables** - For cache and sessions
- **CloudFront Distribution** - For asset delivery

### 3. Set Environment Variables

Via Vapor dashboard or CLI:

```bash
vapor env:push production
vapor env:push staging
```

### 4. Run Database Migrations

```bash
vapor command production "migrate --force"
```

## Performance Optimizations

### Memory Configuration

For large PDFs (100MB+):
```yaml
environments:
  production:
    memory: 3008      # Maximum available
    cli-memory: 3008  # For queue workers
```

### Timeout Settings

Balanced for large document processing:
```env
PDF_VIEWER_LAMBDA_TIMEOUT=900        # 15 minutes max
PDF_VIEWER_DOCUMENT_TIMEOUT=270      # 4.5 minutes
PDF_VIEWER_PAGE_TIMEOUT=50           # 50 seconds
PDF_VIEWER_TEXT_TIMEOUT=25           # 25 seconds
```

### Warming Configuration

Prevent cold starts:
```yaml
environments:
  production:
    warm: 25  # Keep 25 instances warm
  staging:
    warm: 5   # Keep 5 instances warm
```

## Queue Processing

### SQS Configuration

```env
QUEUE_CONNECTION=sqs
SQS_PREFIX=https://sqs.us-east-1.amazonaws.com/your-account-id
```

### Queue Workers

Vapor automatically manages queue workers. Monitor processing:

```bash
vapor metrics production
vapor logs production --filter=queue
```

## Storage Structure

S3 bucket organization:
```
www.shakewell.agency/
├── pdf-documents/           # Original PDFs
│   ├── {hash1}.pdf
│   └── {hash2}.pdf
├── pdf-pages/              # Extracted pages
│   ├── {hash1}/
│   │   ├── page_1.pdf
│   │   └── page_2.pdf
│   └── {hash2}/
└── pdf-thumbnails/         # Generated thumbnails
    ├── {hash1}/
    │   ├── page_1_thumb.jpg
    │   └── page_2_thumb.jpg
    └── {hash2}/
```

## Monitoring & Debugging

### CloudWatch Logs

```bash
# View application logs
vapor logs production

# View queue processing logs
vapor logs production --filter=queue

# View specific timeframe
vapor logs production --since="1 hour ago"
```

### Performance Metrics

```bash
# View performance metrics
vapor metrics production

# Monitor memory usage
vapor metrics production --metric=memory

# Monitor invocation errors
vapor metrics production --metric=errors
```

### Debug Mode

Never enable debug mode in production:
```env
APP_DEBUG=false
LOG_LEVEL=error
```

## Troubleshooting

### Common Issues

1. **Lambda Timeout Errors**
   - Reduce `PDF_VIEWER_TIMEOUT` values
   - Increase Lambda memory allocation
   - Process large PDFs in smaller chunks

2. **S3 Permission Errors**
   - Verify AWS credentials
   - Check bucket permissions
   - Ensure CORS configuration

3. **Memory Errors**
   - Increase `memory` in vapor.yml
   - Set `PDF_VIEWER_MEMORY_LIMIT=1024M`
   - Enable memory monitoring

4. **Queue Processing Issues**
   - Check SQS queue configuration
   - Verify queue driver settings
   - Monitor failed jobs table

### Health Checks

Built-in health endpoint:
```bash
curl https://your-domain.com/api/pdf-viewer/health
```

Expected response:
```json
{
  "status": "healthy",
  "checks": {
    "database": {"status": "healthy"},
    "cache": {"status": "healthy"},
    "storage": {"status": "healthy"}
  }
}
```

## Security Considerations

### S3 Security

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {"AWS": "arn:aws:iam::ACCOUNT:role/vapor-role"},
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::www.shakewell.agency/pdf-*"
    }
  ]
}
```

### Signed URLs

- Default expiry: 1 hour for thumbnails, 30 minutes for downloads
- Automatic rotation for security
- No public access to raw S3 objects

### Environment Security

- Store AWS credentials in Vapor dashboard only
- Use IAM roles with minimal permissions
- Enable CloudTrail for audit logging

## Cost Optimization

### Lambda Costs
- Right-size memory allocation (balance speed vs cost)
- Use provisioned concurrency only if needed
- Monitor invocation duration

### S3 Costs
- Use S3 Intelligent Tiering for older documents
- Implement lifecycle policies for cleanup
- Monitor data transfer costs

### Monitoring
```bash
# Check cost allocation
vapor metrics production --metric=cost

# Monitor S3 usage
aws s3api get-bucket-metrics-configuration
```

## Support

For issues specific to Vapor deployment:
1. Check [Laravel Vapor Documentation](https://vapor.laravel.com)
2. Review CloudWatch logs for errors
3. Use Vapor's support channels for infrastructure issues
4. Package-specific issues: GitHub Issues

## Recommended Resources

- **Production**: 3008MB memory, 900s timeout, 25 warm instances
- **Staging**: 1024MB memory, 300s timeout, 5 warm instances  
- **Database**: RDS MySQL (Aurora Serverless for cost optimization)
- **Cache**: DynamoDB (included with Vapor)
- **Queue**: SQS (included with Vapor)
- **Storage**: S3 with CloudFront CDN