# Laravel PDF Viewer Package - API Documentation

## Overview

The Laravel PDF Viewer Package provides a comprehensive RESTful API for handling massive PDF documents with page-by-page processing, full-text search capabilities, and parallel job processing. All API endpoints require authentication and return JSON responses.

## Base URL

```
{base_url}/api/pdf-viewer
```

## Authentication

All API endpoints require authentication using Bearer tokens:

```http
Authorization: Bearer {your_auth_token}
```

## Response Format

All API responses follow a consistent JSON structure:

### Success Response
```json
{
  "message": "Success message",
  "data": {
    // Response data
  },
  "meta": {
    // Pagination or additional metadata (when applicable)
  }
}
```

### Error Response
```json
{
  "message": "Error message",
  "errors": {
    "field_name": ["Validation error messages"]
  }
}
```

## HTTP Status Codes

- `200` - OK: Request successful
- `201` - Created: Resource created successfully
- `400` - Bad Request: Invalid request data
- `401` - Unauthorized: Authentication required
- `404` - Not Found: Resource not found
- `422` - Unprocessable Entity: Validation errors
- `500` - Internal Server Error: Server error

---

## Document Management

### Upload PDF Document

Upload a PDF document for processing with parallel page extraction and text analysis.

**Endpoint:** `POST /documents`

**Content-Type:** `multipart/form-data`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | File | Yes | PDF file to upload (max size configurable) |
| `title` | String | No | Document title (derived from filename if not provided) |
| `description` | String | No | Document description |
| `metadata[author]` | String | No | Document author (max 255 chars) |
| `metadata[subject]` | String | No | Document subject (max 255 chars) |
| `metadata[keywords]` | String | No | Document keywords (max 1000 chars) |
| `metadata[*]` | String | No | Additional metadata fields |

**Example Request:**
```bash
curl -X POST \
  {base_url}/api/pdf-viewer/documents \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json' \
  -F 'file=@aviation-manual.pdf' \
  -F 'title=Aviation Safety Manual' \
  -F 'description=Comprehensive aviation safety procedures' \
  -F 'metadata[author]=Aviation Authority' \
  -F 'metadata[subject]=Safety Procedures'
```

**Response (201):**
```json
{
  "message": "Document uploaded successfully and queued for processing",
  "data": {
    "id": "123e4567-e89b-12d3-a456-426614174000",
    "hash": "abc123def456789...",
    "title": "Aviation Safety Manual",
    "filename": "aviation-manual.pdf",
    "file_size": 15728640,
    "status": "uploaded",
    "created_at": "2024-08-22T10:30:00.000000Z"
  }
}
```

### List Documents

Retrieve a paginated list of documents with optional filtering.

**Endpoint:** `GET /documents`

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `page` | Integer | No | 1 | Page number for pagination |
| `per_page` | Integer | No | 15 | Items per page (max 100) |
| `status` | String | No | - | Filter by status: `uploaded`, `processing`, `completed`, `failed` |
| `search` | String | No | - | Search in title and description |
| `date_from` | Date | No | - | Filter documents created from date (Y-m-d) |
| `date_to` | Date | No | - | Filter documents created until date (Y-m-d) |

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/documents?page=1&per_page=10&status=completed&search=aviation' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": [
    {
      "id": "123e4567-e89b-12d3-a456-426614174000",
      "hash": "abc123def456789...",
      "title": "Aviation Safety Manual",
      "filename": "aviation-manual.pdf",
      "file_size": 15728640,
      "formatted_file_size": "15.0 MB",
      "page_count": 150,
      "status": "completed",
      "is_searchable": true,
      "metadata": {
        "author": "Aviation Authority",
        "subject": "Safety Procedures"
      },
      "created_at": "2024-08-22T10:30:00.000000Z",
      "updated_at": "2024-08-22T11:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1
  }
}
```

### Get Document Metadata

Retrieve detailed metadata for a specific document.

**Endpoint:** `GET /documents/{document_hash}`

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/documents/abc123def456789' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": {
    "id": "123e4567-e89b-12d3-a456-426614174000",
    "hash": "abc123def456789...",
    "title": "Aviation Safety Manual",
    "filename": "aviation-manual.pdf",
    "file_size": 15728640,
    "formatted_file_size": "15.0 MB",
    "page_count": 150,
    "status": "completed",
    "is_searchable": true,
    "metadata": {
      "author": "Aviation Authority",
      "subject": "Safety Procedures",
      "keywords": "aviation, safety, procedures"
    },
    "processing_started_at": "2024-08-22T10:31:00.000000Z",
    "processing_completed_at": "2024-08-22T10:45:00.000000Z",
    "created_at": "2024-08-22T10:30:00.000000Z",
    "updated_at": "2024-08-22T10:45:00.000000Z"
  }
}
```

### Update Document Metadata

Update metadata for an existing document.

**Endpoint:** `PUT /documents/{document_hash}`

**Content-Type:** `application/json`

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title` | String | No | Updated document title |
| `description` | String | No | Updated document description |
| `metadata` | Object | No | Updated metadata object |

**Example Request:**
```bash
curl -X PUT \
  '{base_url}/api/pdf-viewer/documents/abc123def456789' \
  -H 'Authorization: Bearer {token}' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "title": "Updated Aviation Safety Manual",
    "description": "Updated comprehensive aviation safety procedures",
    "metadata": {
      "author": "Updated Aviation Authority",
      "version": "2.0"
    }
  }'
```

**Response (200):**
```json
{
  "message": "Document metadata updated successfully",
  "data": {
    "id": "123e4567-e89b-12d3-a456-426614174000",
    "hash": "abc123def456789...",
    "title": "Updated Aviation Safety Manual",
    "description": "Updated comprehensive aviation safety procedures",
    "metadata": {
      "author": "Updated Aviation Authority",
      "subject": "Safety Procedures",
      "version": "2.0"
    },
    "updated_at": "2024-08-22T12:00:00.000000Z"
  }
}
```

### Get Processing Progress

Get detailed processing progress for a document.

**Endpoint:** `GET /documents/{document_hash}/progress`

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/documents/abc123def456789/progress' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": {
    "status": "processing",
    "progress_percentage": 75.5,
    "total_pages": 150,
    "completed_pages": 113,
    "processing_pages": 12,
    "failed_pages": 1,
    "pending_pages": 24,
    "processing_started_at": "2024-08-22T10:31:00.000000Z",
    "estimated_completion": "2024-08-22T10:50:00.000000Z"
  }
}
```

### Delete Document

Soft delete a document and clean up associated files and caches.

**Endpoint:** `DELETE /documents/{document_hash}`

**Example Request:**
```bash
curl -X DELETE \
  '{base_url}/api/pdf-viewer/documents/abc123def456789' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "message": "Document deleted successfully"
}
```

---

## Page Management

### List Document Pages

Get a paginated list of pages for a specific document.

**Endpoint:** `GET /documents/{document_hash}/pages`

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `page` | Integer | No | 1 | Page number for pagination |
| `per_page` | Integer | No | 20 | Items per page (max 100) |
| `status` | String | No | - | Filter by page status: `pending`, `processing`, `completed`, `failed` |

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/documents/abc123def456789/pages?page=1&per_page=20' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": [
    {
      "id": "page-uuid-1",
      "page_number": 1,
      "content_length": 1250,
      "word_count": 180,
      "status": "completed",
      "has_thumbnail": true,
      "thumbnail_url": "{base_url}/api/pdf-viewer/documents/abc123def456789/pages/1/thumbnail",
      "processing_completed_at": "2024-08-22T10:32:00.000000Z",
      "created_at": "2024-08-22T10:31:00.000000Z",
      "updated_at": "2024-08-22T10:32:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8
  }
}
```

### Get Specific Page

Retrieve content and metadata for a specific page.

**Endpoint:** `GET /documents/{document_hash}/pages/{page_number}`

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/documents/abc123def456789/pages/1' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": {
    "id": "page-uuid-1",
    "page_number": 1,
    "content": "This page contains aviation safety procedures and emergency protocols...",
    "content_length": 1250,
    "word_count": 180,
    "status": "completed",
    "has_thumbnail": true,
    "thumbnail_url": "{base_url}/api/pdf-viewer/documents/abc123def456789/pages/1/thumbnail",
    "processing_started_at": "2024-08-22T10:31:30.000000Z",
    "processing_completed_at": "2024-08-22T10:32:00.000000Z",
    "created_at": "2024-08-22T10:31:00.000000Z",
    "updated_at": "2024-08-22T10:32:00.000000Z"
  }
}
```

### Get Page Thumbnail

Retrieve the thumbnail image for a specific page.

**Endpoint:** `GET /documents/{document_hash}/pages/{page_number}/thumbnail`

**Response:** Binary image data (JPEG format)

**Headers:**
- `Content-Type: image/jpeg`
- `Content-Length: {size_in_bytes}`

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/documents/abc123def456789/pages/1/thumbnail' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: image/jpeg' \
  --output page-1-thumbnail.jpg
```

---

## Search Operations

### Search Documents

Perform full-text search across document titles, descriptions, and metadata.

**Endpoint:** `GET /search/documents`

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | String | Yes | - | Search query (minimum 2 characters) |
| `page` | Integer | No | 1 | Page number for pagination |
| `per_page` | Integer | No | 10 | Items per page (max 50) |
| `status` | String | No | - | Filter by document status |
| `date_from` | Date | No | - | Filter documents created from date (Y-m-d) |
| `date_to` | Date | No | - | Filter documents created until date (Y-m-d) |

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/search/documents?q=aviation%20safety&status=completed&per_page=10' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": [
    {
      "id": "123e4567-e89b-12d3-a456-426614174000",
      "hash": "abc123def456789...",
      "title": "Aviation Safety Manual",
      "filename": "aviation-manual.pdf",
      "file_size": 15728640,
      "formatted_file_size": "15.0 MB",
      "page_count": 150,
      "status": "completed",
      "is_searchable": true,
      "relevance_score": 0.8542,
      "search_snippets": [
        "...comprehensive <mark>aviation safety</mark> procedures for emergency situations...",
        "...updated <mark>safety</mark> protocols for <mark>aviation</mark> personnel..."
      ],
      "matching_pages": 23,
      "metadata": {
        "author": "Aviation Authority",
        "subject": "Safety Procedures"
      },
      "created_at": "2024-08-22T10:30:00.000000Z",
      "updated_at": "2024-08-22T11:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 1,
    "last_page": 1,
    "search_time_ms": 45
  }
}
```

### Search Pages

Perform full-text search within page content with highlighted snippets.

**Endpoint:** `GET /search/pages`

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | String | Yes | - | Search query (minimum 2 characters) |
| `page` | Integer | No | 1 | Page number for pagination |
| `per_page` | Integer | No | 10 | Items per page (max 50) |
| `highlight` | Boolean | No | true | Include highlighted content |
| `include_full_content` | Boolean | No | false | Include full page content (use sparingly) |

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/search/pages?q=emergency%20procedures&highlight=true&per_page=10' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "data": [
    {
      "id": "page-uuid-1",
      "page_number": 15,
      "content_length": 1850,
      "word_count": 275,
      "relevance_score": 0.9231,
      "search_snippet": "...In case of <mark>emergency procedures</mark>, pilots must follow the established safety protocols...",
      "highlighted_content": "...comprehensive guide for <mark>emergency procedures</mark> including evacuation protocols and communication <mark>procedures</mark> during critical situations...",
      "has_thumbnail": true,
      "document": {
        "hash": "abc123def456789...",
        "title": "Aviation Safety Manual",
        "filename": "aviation-manual.pdf"
      },
      "thumbnail_url": "{base_url}/api/pdf-viewer/documents/abc123def456789/pages/15/thumbnail",
      "page_url": "{base_url}/api/pdf-viewer/documents/abc123def456789/pages/15",
      "created_at": "2024-08-22T10:31:00.000000Z",
      "updated_at": "2024-08-22T10:32:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 5,
    "last_page": 1,
    "search_time_ms": 12
  }
}
```

### Get Search Suggestions

Get autocomplete suggestions for search queries based on indexed content.

**Endpoint:** `GET /search/suggestions`

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | String | Yes | - | Partial search term (minimum 2 characters) |
| `limit` | Integer | No | 10 | Maximum number of suggestions (max 20) |

**Example Request:**
```bash
curl -X GET \
  '{base_url}/api/pdf-viewer/search/suggestions?q=aviat&limit=10' \
  -H 'Authorization: Bearer {token}' \
  -H 'Accept: application/json'
```

**Response (200):**
```json
{
  "suggestions": [
    {
      "term": "aviation",
      "frequency": 156,
      "category": "keyword"
    },
    {
      "term": "aviation safety",
      "frequency": 89,
      "category": "phrase"
    },
    {
      "term": "aviation procedures",
      "frequency": 67,
      "category": "phrase"
    },
    {
      "term": "aviation manual",
      "frequency": 45,
      "category": "document"
    }
  ]
}
```

---

## Error Responses

### Validation Errors (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "file": [
      "The file field is required.",
      "The file must be a PDF document."
    ],
    "title": [
      "The title must not be greater than 255 characters."
    ],
    "metadata.author": [
      "The metadata.author must not be greater than 255 characters."
    ]
  }
}
```

### Not Found (404)

```json
{
  "message": "Document not found or access denied."
}
```

### Unauthorized (401)

```json
{
  "message": "Unauthenticated."
}
```

### Server Error (500)

```json
{
  "message": "An error occurred while processing your request.",
  "error_id": "error-uuid-for-tracking"
}
```

---

## Rate Limiting

The API implements rate limiting to ensure fair usage:

- **Document Upload**: 10 requests per minute per user
- **Search Operations**: 60 requests per minute per user
- **General API**: 100 requests per minute per user

Rate limit headers are included in all responses:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1692707400
```

---

## Pagination

All list endpoints support pagination using the following query parameters:

- `page`: Page number (default: 1)
- `per_page`: Items per page (default varies by endpoint, maximum limits apply)

Pagination metadata is included in the `meta` object:

```json
{
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 150,
    "last_page": 15,
    "from": 1,
    "to": 10
  }
}
```

---

## Webhooks (Optional)

The package supports optional webhook notifications for document processing events:

### Webhook Events

- `document.uploaded` - Document successfully uploaded
- `document.processing.started` - Processing started
- `document.processing.completed` - Processing completed successfully
- `document.processing.failed` - Processing failed
- `document.page.completed` - Individual page processing completed

### Webhook Payload Example

```json
{
  "event": "document.processing.completed",
  "timestamp": "2024-08-22T11:00:00.000000Z",
  "data": {
    "document_hash": "abc123def456789...",
    "status": "completed",
    "processing_time_seconds": 900,
    "total_pages": 150,
    "completed_pages": 150,
    "failed_pages": 0
  }
}
```

---

## SDKs and Integration Examples

### JavaScript/Node.js Example

```javascript
class PdfViewerClient {
  constructor(baseUrl, authToken) {
    this.baseUrl = baseUrl;
    this.authToken = authToken;
  }

  async uploadDocument(file, metadata = {}) {
    const formData = new FormData();
    formData.append('file', file);
    
    Object.entries(metadata).forEach(([key, value]) => {
      if (typeof value === 'object') {
        Object.entries(value).forEach(([subKey, subValue]) => {
          formData.append(`${key}[${subKey}]`, subValue);
        });
      } else {
        formData.append(key, value);
      }
    });

    const response = await fetch(`${this.baseUrl}/documents`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.authToken}`,
        'Accept': 'application/json'
      },
      body: formData
    });

    return await response.json();
  }

  async searchDocuments(query, filters = {}) {
    const params = new URLSearchParams({ q: query, ...filters });
    const response = await fetch(`${this.baseUrl}/search/documents?${params}`, {
      headers: {
        'Authorization': `Bearer ${this.authToken}`,
        'Accept': 'application/json'
      }
    });

    return await response.json();
  }
}
```

### PHP Example

```php
class PdfViewerClient
{
    private string $baseUrl;
    private string $authToken;

    public function __construct(string $baseUrl, string $authToken)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authToken = $authToken;
    }

    public function uploadDocument($filePath, array $metadata = []): array
    {
        $curl = curl_init();
        
        $postFields = [
            'file' => new CURLFile($filePath, 'application/pdf')
        ];
        
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $postFields["{$key}[{$subKey}]"] = $subValue;
                }
            } else {
                $postFields[$key] = $value;
            }
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => "{$this->baseUrl}/documents",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->authToken}",
                "Accept: application/json"
            ]
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }

    public function searchDocuments(string $query, array $filters = []): array
    {
        $params = http_build_query(array_merge(['q' => $query], $filters));
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "{$this->baseUrl}/search/documents?{$params}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->authToken}",
                "Accept: application/json"
            ]
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }
}
```

---

This documentation covers all available API endpoints and provides comprehensive examples for integration. For additional support or questions, please refer to the main package documentation or create an issue on the project repository.