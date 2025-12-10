# Performance Optimization Guide

This document describes the performance optimizations implemented in the Laravel PDF Viewer package for the TOC (Table of Contents) and Links endpoints.

## Table of Contents

1. [Caching Strategy](#caching-strategy)
2. [Query Optimizations](#query-optimizations)
3. [Configuration Options](#configuration-options)
4. [Cache Invalidation](#cache-invalidation)
5. [Monitoring & Metrics](#monitoring--metrics)
6. [Best Practices](#best-practices)

---

## Caching Strategy

### Overview

The package implements a comprehensive caching strategy for static data that doesn't change after document processing:

- **Outline/TOC**: Cached for 24 hours (configurable)
- **Document Links**: Cached for 24 hours (configurable)
- **Page Links**: Cached for 24 hours (configurable)

### Why Cache These Endpoints?

TOC and link data are **static after extraction** - they never change unless the document is reprocessed. This makes them ideal candidates for aggressive caching:

| Endpoint | Cache TTL | Rationale |
|----------|-----------|-----------|
| `/documents/{hash}/outline` | 24 hours | TOC structure is immutable |
| `/documents/{hash}/links` | 24 hours | Link data is immutable |
| `/documents/{hash}/pages/{page}/links` | 24 hours | Per-page links are immutable |

### Cache Storage Requirements

The caching system supports:

- **Redis** (recommended) - Full tag support for efficient invalidation
- **Memcached** - Full tag support
- **File/Database** - Works but without tag support

```php
// .env configuration
PDF_VIEWER_CACHE_ENABLED=true
PDF_VIEWER_CACHE_STORE=redis
```

---

## Query Optimizations

### Before Optimization

The original implementation had several inefficiencies:

```php
// BEFORE: Multiple queries for link statistics
$allLinks = PdfDocumentLink::where('pdf_document_id', $document->id)->get();
$totalLinks = $allLinks->count();
$internalLinks = $allLinks->where('type', 'internal')->count();
$externalLinks = $allLinks->where('type', 'external')->count();
```

### After Optimization

```php
// AFTER: Single aggregate query for statistics
$stats = PdfDocumentLink::where('pdf_document_id', $document->id)
    ->selectRaw('COUNT(*) as total')
    ->selectRaw("SUM(CASE WHEN type = 'internal' THEN 1 ELSE 0 END) as internal")
    ->selectRaw("SUM(CASE WHEN type = 'external' THEN 1 ELSE 0 END) as external")
    ->first();
```

### Eager Loading for Outlines

The outline endpoint uses eager loading to prevent N+1 queries:

```php
// Loads entire tree in efficient queries
$outlineEntries = PdfDocumentOutline::where('pdf_document_id', $document->id)
    ->whereNull('parent_id')
    ->with('descendants')  // Recursive eager loading
    ->orderBy('order_index')
    ->get();
```

---

## Configuration Options

### Cache TTL Settings

Add these to your `.env` file to customize cache durations:

```bash
# Outline/TOC cache duration (default: 86400 seconds = 24 hours)
PDF_VIEWER_CACHE_OUTLINE_TTL=86400

# Links cache duration (default: 86400 seconds = 24 hours)
PDF_VIEWER_CACHE_LINKS_TTL=86400

# Document metadata cache (default: 3600 seconds = 1 hour)
PDF_VIEWER_CACHE_DOCUMENT_TTL=3600

# Page content cache (default: 7200 seconds = 2 hours)
PDF_VIEWER_CACHE_PAGE_TTL=7200

# Search results cache (default: 1800 seconds = 30 minutes)
PDF_VIEWER_CACHE_SEARCH_TTL=1800
```

### Cache Tags (Redis/Memcached)

The package uses cache tags for efficient group invalidation:

```php
// config/pdf-viewer.php
'cache' => [
    'tags' => [
        'documents' => 'pdf_viewer_documents',
        'pages' => 'pdf_viewer_pages',
        'search' => 'pdf_viewer_search',
        'outline' => 'pdf_viewer_outline',
        'links' => 'pdf_viewer_links',
    ],
],
```

---

## Cache Invalidation

### Automatic Invalidation

Cache is automatically invalidated when:

1. **Document is deleted** - All related caches are cleared
2. **Document is reprocessed** - Previous cache is invalidated
3. **Metadata is updated** - Document metadata cache is refreshed

### Manual Invalidation

```php
// Invalidate all cache for a specific document
$cacheService->invalidateDocumentCache($documentHash);

// Clear all PDF viewer cache
$cacheService->clearAllCache();
```

### API Endpoints for Cache Management

```bash
# Clear cache for a specific document
POST /api/pdf-viewer/documents/{hash}/cache/clear

# Warm cache for a document (pre-populate after processing)
POST /api/pdf-viewer/documents/{hash}/cache/warm

# Clear all PDF viewer cache (admin only)
DELETE /api/pdf-viewer/cache
```

---

## Monitoring & Metrics

### Cache Hit/Miss Logging

Enable cache logging to monitor performance:

```bash
# .env
PDF_VIEWER_LOG_CACHE=true
```

This logs cache operations to your Laravel log:

```
[2024-12-10 12:00:00] local.DEBUG: Document outline cached {"document_hash":"abc123...","cache_key":"pdf_viewer:outline:...","entries_count":45}
```

### Cache Statistics

```php
// Get cache statistics
$stats = $cacheService->getCacheStats();

// Returns:
[
    'cache_enabled' => true,
    'cache_store' => 'redis',
    'tags_supported' => true,
    'prefix' => 'pdf_viewer',
]
```

---

## Best Practices

### 1. Use Redis for Production

Redis provides the best performance for caching with:
- Low latency reads
- Full tag support for efficient invalidation
- Atomic operations
- Persistence options

### 2. Warm Cache After Processing

After a document is processed, warm the cache to ensure fast first access:

```php
// In your document processing completion handler
$cacheService->warmDocumentCache($documentHash);
```

### 3. Monitor Cache Hit Rates

In production, monitor your cache hit rates:

```php
// Example monitoring in a middleware or observer
if ($cachedData !== null) {
    // Cache hit - log or increment metric
    Metrics::increment('pdf_viewer.cache.hit');
} else {
    // Cache miss
    Metrics::increment('pdf_viewer.cache.miss');
}
```

### 4. Adjust TTL Based on Usage Patterns

- **High-traffic documents**: Consider longer TTLs
- **Frequently updated documents**: Consider shorter TTLs
- **Static archives**: Consider very long TTLs (7+ days)

### 5. Use Database Indexes

Ensure proper indexes exist on frequently queried columns:

```sql
-- Already created by package migrations
CREATE INDEX idx_pdf_document_outlines_document_id ON pdf_document_outlines(pdf_document_id);
CREATE INDEX idx_pdf_document_links_document_page ON pdf_document_links(pdf_document_id, source_page);
```

---

## Performance Benchmarks

### Expected Response Times

| Endpoint | Without Cache | With Cache |
|----------|---------------|------------|
| Outline (50 entries) | ~50-100ms | ~5-15ms |
| Outline (500 entries) | ~200-400ms | ~10-20ms |
| Document Links (100 links) | ~30-80ms | ~5-15ms |
| Document Links (1000 links) | ~150-300ms | ~15-30ms |
| Page Links (20 links) | ~15-40ms | ~3-10ms |

### Scaling Considerations

For documents with 5000+ pages:

1. **Outline caching** significantly reduces database load
2. **Page link caching** allows efficient per-page loading
3. **Aggregate queries** reduce memory usage for statistics

---

## Troubleshooting

### Cache Not Working

1. Check cache is enabled: `PDF_VIEWER_CACHE_ENABLED=true`
2. Verify cache store is accessible: `php artisan tinker` then `Cache::store('redis')->get('test')`
3. Check tag support if using file/database cache

### High Memory Usage

If experiencing high memory with large documents:

1. Reduce `PDF_VIEWER_LINK_BATCH_SIZE` for link extraction
2. Use per-page link endpoints instead of document-level
3. Consider implementing result pagination for links

### Slow First Requests

First request after cache expiry will be slower:

1. Use cache warming after processing
2. Consider background job for cache refresh
3. Implement cache preheating for frequently accessed documents
