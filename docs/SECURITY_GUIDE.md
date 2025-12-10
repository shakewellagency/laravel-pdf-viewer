# Security Guide

This document describes the security features and best practices for the Laravel PDF Viewer package.

## Table of Contents

1. [Authentication](#authentication)
2. [Authorization](#authorization)
3. [Rate Limiting](#rate-limiting)
4. [Input Validation](#input-validation)
5. [Secure File Access](#secure-file-access)
6. [Configuration](#configuration)
7. [Security Best Practices](#security-best-practices)

---

## Authentication

### Laravel Sanctum

All API endpoints require authentication via Laravel Sanctum by default:

```php
// config/pdf-viewer.php
'middleware' => ['api', 'auth:sanctum'],
```

### Custom Middleware

You can customize the middleware stack:

```php
// config/pdf-viewer.php
'middleware' => ['api', 'auth:sanctum', 'verified', 'your-custom-middleware'],
```

### Public Access (Not Recommended)

To disable authentication (not recommended for production):

```php
// config/pdf-viewer.php
'middleware' => ['api'],
```

---

## Authorization

### Document Policy

The package includes a `DocumentPolicy` for fine-grained access control:

```php
// Available policy methods:
- viewAny($user)           // Can list documents
- view($user, $document)   // Can view a specific document
- create($user)            // Can upload documents
- update($user, $document) // Can update document metadata
- delete($user, $document) // Can delete documents
- process($user, $document)    // Can trigger processing
- viewOutline($user, $document)  // Can view TOC
- viewLinks($user, $document)    // Can view links
- search($user, $document)       // Can search within document
- download($user, $document)     // Can download pages
- viewCompliance($user, $document) // Can view compliance reports
```

### Extending the Policy

Create your own policy that extends the base:

```php
<?php

namespace App\Policies;

use App\Models\User;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Policies\DocumentPolicy as BasePolicy;

class DocumentPolicy extends BasePolicy
{
    public function view($user, PdfDocument $document): bool
    {
        // Custom logic: Check if user belongs to same organization
        return $user->organization_id === $document->organization_id;
    }

    public function delete($user, PdfDocument $document): bool
    {
        // Only admins can delete
        return $user->hasRole('admin');
    }
}
```

Register your custom policy:

```php
// AuthServiceProvider.php
protected $policies = [
    PdfDocument::class => \App\Policies\DocumentPolicy::class,
];
```

### Disabling the Policy

If you want to implement authorization differently:

```bash
# .env
PDF_VIEWER_ENABLE_POLICY=false
```

### Using Authorization in Controllers

The policy can be used in your application:

```php
// Check authorization
$this->authorize('view', $document);
$this->authorize('viewOutline', $document);
$this->authorize('download', $document);
```

### Document-Level Access Control

Documents can have metadata-based access restrictions:

```php
// When creating/updating a document
$document->metadata = [
    'restricted' => true,
    'allowed_users' => [1, 2, 3],
    'allowed_roles' => ['admin', 'editor'],
    'allow_downloads' => false,
];
```

---

## Rate Limiting

### Configuration

Rate limiting protects against abuse:

```bash
# .env configuration
PDF_VIEWER_RATE_LIMIT_ENABLED=true

# Requests per minute by endpoint type
PDF_VIEWER_RATE_LIMIT=60           # General API requests
PDF_VIEWER_RATE_LIMIT_UPLOAD=10    # Document uploads
PDF_VIEWER_RATE_LIMIT_SEARCH=30    # Search requests
PDF_VIEWER_RATE_LIMIT_DOWNLOAD=100 # Downloads
```

### Using the Rate Limit Middleware

Apply rate limiting to specific routes:

```php
// In your routes file
Route::middleware(['pdf-viewer.rate-limit:pdf-viewer-upload'])->group(function () {
    Route::post('/documents', [DocumentController::class, 'store']);
});
```

### Available Rate Limiters

| Limiter Name | Default Limit | Use Case |
|--------------|---------------|----------|
| `pdf-viewer` | 60/minute | General API requests |
| `pdf-viewer-upload` | 10/minute | File uploads |
| `pdf-viewer-search` | 30/minute | Search operations |
| `pdf-viewer-download` | 100/minute | File downloads |

### Rate Limit Headers

The middleware adds standard rate limit headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
Retry-After: 60  # Only when limit exceeded
```

### Customizing Rate Limits

Create custom rate limiters:

```php
// In AppServiceProvider or a dedicated provider
RateLimiter::for('pdf-viewer-premium', function (Request $request) {
    return $request->user()->isPremium()
        ? Limit::perMinute(500)
        : Limit::perMinute(60);
});
```

---

## Input Validation

### Request Validation Classes

All inputs are validated through dedicated Form Request classes:

| Request Class | Validates |
|---------------|-----------|
| `DocumentStoreRequest` | File uploads, metadata |
| `DocumentUpdateRequest` | Metadata updates |
| `DocumentIndexRequest` | List filters |
| `PageIndexRequest` | Page list filters |
| `SearchDocumentsRequest` | Search queries |
| `SearchPagesRequest` | Page search queries |
| `SearchSuggestionsRequest` | Autocomplete queries |

### File Upload Validation

```php
// DocumentStoreRequest validation
'file' => [
    'required',
    'file',
    'mimes:pdf',
    'max:102400', // 100MB max (configurable)
],
```

### Search Query Validation

```php
// Search validation
'q' => 'required|string|min:3|max:255',
'per_page' => 'sometimes|integer|min:1|max:50',
```

### Customizing Validation

Override the request classes in your application:

```php
// In your service provider
$this->app->bind(
    \Shakewellagency\LaravelPdfViewer\Requests\DocumentStoreRequest::class,
    \App\Http\Requests\CustomDocumentStoreRequest::class
);
```

---

## Secure File Access

### Hash-Based Identification

Documents are identified by SHA-256 hashes, not sequential IDs:

```
GET /api/pdf-viewer/documents/a1b2c3d4e5f6...
```

This prevents:
- Enumeration attacks
- Guessing document URLs
- Sequential ID disclosure

### Signed URLs for Storage

When using cloud storage (S3), files are accessed via signed URLs:

```php
// Configuration
'storage' => [
    'signed_url_expires' => 3600, // 1 hour
],
```

### Secure Local File Access

For local storage, the package provides encrypted token-based access:

```
GET /api/pdf-viewer/secure-file/{encrypted-token}
```

The token includes:
- File path
- Expiration timestamp
- Access type validation

---

## Configuration

### Security-Related Settings

```php
// config/pdf-viewer.php
'security' => [
    // Hash algorithm for document identification
    'hash_algorithm' => 'sha256',

    // Salt for hash generation (uses APP_KEY if not set)
    'salt' => env('PDF_VIEWER_SALT'),

    // Maximum upload attempts before throttling
    'max_upload_attempts' => 5,

    // Rate limiting
    'rate_limit_enabled' => true,
    'rate_limit' => 60,
    'rate_limit_upload' => 10,
    'rate_limit_search' => 30,
    'rate_limit_download' => 100,

    // Authorization policy toggle
    'enable_policy' => true,

    // Input sanitization
    'sanitize_filenames' => true,
    'max_filename_length' => 200,
],
```

### Environment Variables

```bash
# Security configuration
PDF_VIEWER_HASH_ALGORITHM=sha256
PDF_VIEWER_SALT=your-secure-salt
PDF_VIEWER_MAX_UPLOAD_ATTEMPTS=5
PDF_VIEWER_RATE_LIMIT_ENABLED=true
PDF_VIEWER_RATE_LIMIT=60
PDF_VIEWER_RATE_LIMIT_UPLOAD=10
PDF_VIEWER_RATE_LIMIT_SEARCH=30
PDF_VIEWER_RATE_LIMIT_DOWNLOAD=100
PDF_VIEWER_ENABLE_POLICY=true
PDF_VIEWER_SANITIZE_FILENAMES=true
PDF_VIEWER_MAX_FILENAME_LENGTH=200
```

---

## Security Best Practices

### 1. Always Use HTTPS

Ensure all API communication uses HTTPS:

```php
// In your TrustProxies middleware or .env
FORCE_HTTPS=true
```

### 2. Keep Dependencies Updated

Regularly update the package and its dependencies:

```bash
composer update shakewellagency/laravel-pdf-viewer
```

### 3. Implement Proper CORS

Configure CORS to restrict origins:

```php
// config/cors.php
'allowed_origins' => ['https://your-app.com'],
```

### 4. Use Strong Authentication

Enable multi-factor authentication for sensitive document access:

```php
'middleware' => ['api', 'auth:sanctum', 'verified', '2fa'],
```

### 5. Monitor Access Patterns

Enable audit logging:

```bash
PDF_VIEWER_AUDIT_TRAIL=true
PDF_VIEWER_LOG_USER_ACTIONS=true
```

### 6. Set Appropriate File Size Limits

Limit upload sizes to prevent resource exhaustion:

```bash
PDF_VIEWER_MAX_FILE_SIZE=104857600  # 100MB
```

### 7. Validate PDF Content

The package validates:
- File extension (.pdf)
- MIME type (application/pdf)
- File size limits

### 8. Secure Error Messages

Production should not expose detailed errors:

```php
// .env
APP_DEBUG=false
```

### 9. Regular Security Audits

- Review access logs regularly
- Check for unusual patterns
- Monitor rate limit triggers

### 10. Backup and Recovery

Enable compliance features:

```bash
PDF_VIEWER_PRESERVE_ORIGINAL=true
PDF_VIEWER_RETENTION_DAYS=2555  # 7 years
```

---

## Reporting Security Issues

If you discover a security vulnerability, please report it responsibly:

1. Do NOT open a public issue
2. Contact the maintainers directly
3. Provide detailed information about the vulnerability
4. Allow reasonable time for a fix before disclosure
