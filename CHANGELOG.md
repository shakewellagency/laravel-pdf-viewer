# Changelog

All notable changes to the Laravel PDF Viewer Package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2024-08-22

### Added

#### Core Features
- **Page-by-page PDF processing** for massive documents (9000+ pages)
- **Full-text search** with MySQL FULLTEXT indexes and relevance scoring
- **Hash-based document security** to prevent ID enumeration attacks
- **Parallel processing** with individual page jobs and specialized sub-jobs
- **Comprehensive caching** using Laravel's default cache methods with tag support
- **SOLID design principles** compliance with complete interface segregation

#### Database Schema
- `pdf_documents` table with UUID primary keys and soft deletes
- `pdf_document_pages` table with FULLTEXT search indexes
- Optimized database indexes for performance
- Hash-based document identification system

#### Job Processing Architecture
- `ProcessDocumentJob` - Master document processing coordinator
- `ExtractPageJob` - Individual page extraction with parallelization  
- `ProcessPageTextJob` - Text extraction and search indexing
- Job dependency chains and failure handling
- Configurable queue settings and retry mechanisms

#### Services (SOLID-compliant)
- `DocumentService` - Document CRUD operations with caching
- `DocumentProcessingService` - PDF processing orchestration
- `PageProcessingService` - Individual page extraction and processing
- `SearchService` - MySQL FULLTEXT search with snippets and highlighting
- `CacheService` - Redis caching with tag support and cache warming

#### API Endpoints
- **Document Management**: Upload, list, retrieve, update, delete documents
- **Page Management**: List pages, retrieve specific pages, serve thumbnails
- **Search Operations**: Full-text search for documents and pages with suggestions
- All endpoints secured with authentication and validation

#### Testing Framework
- Comprehensive unit tests for all services and jobs
- Feature tests for API endpoints with real PDF integration
- Database factories for test data generation
- PHPUnit configuration optimized for the package
- Sample PDF integration from SamplePDF folder

#### Documentation
- Complete README with installation and usage instructions
- Comprehensive API documentation with examples
- Postman collection with all endpoints and test scenarios
- Configuration guide and performance optimization recommendations

### Technical Specifications

#### Requirements
- PHP 8.1+
- Laravel 11.9+
- MySQL 5.7+ (with FULLTEXT search support)
- Redis (recommended for caching)
- Queue driver (Redis/Database recommended)

#### Dependencies
- `spatie/pdf-to-text` - PDF text extraction
- `intervention/image` - Image processing for thumbnails
- `laravel/scout` (optional) - Enhanced search capabilities
- Standard Laravel packages for queues, cache, and storage

#### Configuration Options
- **Storage**: Configurable disk and path settings
- **Processing**: Timeout, parallel job limits, memory settings
- **Cache**: TTL, prefixes, warming strategies
- **Search**: Relevance scoring, snippet generation
- **Security**: Hash algorithms, access control
- **Thumbnails**: Size, quality, format settings

#### Performance Features
- Parallel page processing for maximum efficiency
- Redis caching with intelligent cache warming
- Database query optimization with proper indexing
- Configurable resource limits and timeouts
- Memory-efficient processing for large documents

#### Security Features
- Document hash-based identification (prevents enumeration)
- File type validation and size limits
- Authentication required for all API endpoints
- Secure file storage with proper permissions
- Rate limiting on search endpoints

### Integration
- Mozilla PDF.js frontend integration ready
- Laravel Scout integration for enhanced search
- Queue worker configuration examples
- Webhook support for processing events (optional)
- JavaScript and PHP SDK examples provided

### Monitoring & Observability
- Processing progress tracking with detailed metrics
- Cache statistics and hit rate monitoring
- Job failure handling and retry mechanisms
- Error tracking with unique error IDs
- Performance metrics and optimization recommendations

---

## Migration Guide

### From No Package (Fresh Installation)

1. **Install the package:**
```bash
composer require shakewellagency/laravel-pdf-viewer
```

2. **Publish and run migrations:**
```bash
php artisan vendor:publish --provider="Shakewellagency\\LaravelPdfViewer\\Providers\\PdfViewerServiceProvider" --tag="migrations"
php artisan migrate
```

3. **Publish configuration:**
```bash
php artisan vendor:publish --provider="Shakewellagency\\LaravelPdfViewer\\Providers\\PdfViewerServiceProvider" --tag="config"
```

4. **Configure environment variables** (see README for complete list)

5. **Set up queue workers:**
```bash
php artisan queue:work --queue=pdf-processing --timeout=1800 --memory=512
```

### Future Versions

Migration guides will be provided for each version update with breaking changes clearly documented.

---

## Development

### Contributing
1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

### Testing
```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit tests/Unit
./vendor/bin/phpunit tests/Feature

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Code Style
The package follows PSR-12 coding standards and Laravel conventions.

---

## Support

- **Documentation**: Complete README and API documentation
- **Examples**: Postman collection and SDK examples
- **Issue Tracking**: GitHub Issues for bug reports and feature requests
- **Testing**: Comprehensive test suite for quality assurance

---

**Laravel PDF Viewer Package** by Shakewell Agency  
Licensed under the MIT License