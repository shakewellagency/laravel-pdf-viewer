# PDF Viewer API Documentation

This document provides usage examples for the Table of Contents (TOC) and Links endpoints.

## Table of Contents

1. [Authentication](#authentication)
2. [Outline/TOC Endpoints](#outlinetoc-endpoints)
3. [Links Endpoints](#links-endpoints)
4. [Code Examples](#code-examples)
5. [Error Handling](#error-handling)

---

## Authentication

All API endpoints require authentication via Laravel Sanctum Bearer tokens.

```bash
# Example request with authentication
curl -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Accept: application/json" \
     https://api.example.com/api/pdf-viewer/documents/{hash}/outline
```

---

## Outline/TOC Endpoints

### GET /documents/{document_hash}/outline

Retrieves the hierarchical Table of Contents for a PDF document.

**Response Structure:**
```json
{
  "data": [
    {
      "id": "uuid",
      "title": "Chapter 1: Introduction",
      "level": 0,
      "destination_page": 1,
      "destination_type": "page",
      "destination_name": null,
      "children": [
        {
          "id": "uuid",
          "title": "1.1 Overview",
          "level": 1,
          "destination_page": 3,
          "children": []
        }
      ]
    }
  ]
}
```

---

## Links Endpoints

### GET /documents/{document_hash}/links

Retrieves link statistics for the entire document.

**Response:**
```json
{
  "data": {
    "total_links": 45,
    "internal_links": 30,
    "external_links": 15,
    "pages_with_links": 12,
    "links_by_page": {
      "1": 5,
      "3": 8,
      "10": 12
    }
  }
}
```

### GET /documents/{document_hash}/pages/{page_number}/links

Retrieves all links for a specific page with coordinates.

**Response:**
```json
{
  "data": [
    {
      "id": "uuid",
      "type": "internal",
      "source_page": 1,
      "destination_page": 25,
      "destination_url": null,
      "link_text": "See Chapter 3",
      "absolute_coordinates": {
        "x": 72.0,
        "y": 150.5,
        "width": 120.0,
        "height": 14.0
      },
      "normalized_coordinates": {
        "x_percent": 10.0,
        "y_percent": 18.5,
        "width_percent": 16.67,
        "height_percent": 1.72
      }
    }
  ]
}
```

---

## Code Examples

### JavaScript/TypeScript (Fetch API)

```typescript
interface OutlineEntry {
  id: string;
  title: string;
  level: number;
  destination_page: number | null;
  destination_type: 'page' | 'named';
  destination_name: string | null;
  children: OutlineEntry[];
}

interface PageLink {
  id: string;
  type: 'internal' | 'external' | 'unknown';
  source_page: number;
  destination_page: number | null;
  destination_url: string | null;
  link_text: string | null;
  absolute_coordinates: {
    x: number;
    y: number;
    width: number;
    height: number;
  };
  normalized_coordinates: {
    x_percent: number;
    y_percent: number;
    width_percent: number;
    height_percent: number;
  };
}

// Fetch document outline
async function getDocumentOutline(documentHash: string): Promise<OutlineEntry[]> {
  const response = await fetch(
    `/api/pdf-viewer/documents/${documentHash}/outline`,
    {
      headers: {
        'Authorization': `Bearer ${getToken()}`,
        'Accept': 'application/json',
      },
    }
  );

  if (!response.ok) {
    throw new Error(`Failed to fetch outline: ${response.statusText}`);
  }

  const { data } = await response.json();
  return data;
}

// Fetch links for a specific page
async function getPageLinks(documentHash: string, pageNumber: number): Promise<PageLink[]> {
  const response = await fetch(
    `/api/pdf-viewer/documents/${documentHash}/pages/${pageNumber}/links`,
    {
      headers: {
        'Authorization': `Bearer ${getToken()}`,
        'Accept': 'application/json',
      },
    }
  );

  if (!response.ok) {
    throw new Error(`Failed to fetch links: ${response.statusText}`);
  }

  const { data } = await response.json();
  return data;
}

// Render clickable link overlays on a page
function renderLinkOverlays(
  container: HTMLElement,
  links: PageLink[],
  onNavigate: (page: number) => void
): void {
  links.forEach((link) => {
    const overlay = document.createElement('div');
    overlay.className = 'pdf-link-overlay';
    overlay.style.cssText = `
      position: absolute;
      left: ${link.normalized_coordinates.x_percent}%;
      top: ${link.normalized_coordinates.y_percent}%;
      width: ${link.normalized_coordinates.width_percent}%;
      height: ${link.normalized_coordinates.height_percent}%;
      cursor: pointer;
      background: rgba(0, 0, 255, 0.1);
    `;

    overlay.addEventListener('click', () => {
      if (link.type === 'internal' && link.destination_page) {
        onNavigate(link.destination_page);
      } else if (link.type === 'external' && link.destination_url) {
        window.open(link.destination_url, '_blank', 'noopener,noreferrer');
      }
    });

    container.appendChild(overlay);
  });
}
```

### PHP (Laravel HTTP Client)

```php
<?php

use Illuminate\Support\Facades\Http;

class PdfViewerClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function getOutline(string $documentHash): array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->get("{$this->baseUrl}/documents/{$documentHash}/outline");

        if ($response->failed()) {
            throw new \Exception("Failed to fetch outline: {$response->status()}");
        }

        return $response->json('data', []);
    }

    public function getPageLinks(string $documentHash, int $pageNumber): array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->get("{$this->baseUrl}/documents/{$documentHash}/pages/{$pageNumber}/links");

        if ($response->failed()) {
            throw new \Exception("Failed to fetch page links: {$response->status()}");
        }

        return $response->json('data', []);
    }
}
```

### Python (requests)

```python
import requests
from typing import List, Dict, Optional

class PdfViewerClient:
    def __init__(self, base_url: str, token: str):
        self.base_url = base_url.rstrip('/')
        self.session = requests.Session()
        self.session.headers.update({
            'Authorization': f'Bearer {token}',
            'Accept': 'application/json',
        })

    def get_outline(self, document_hash: str) -> List[Dict]:
        response = self.session.get(
            f'{self.base_url}/documents/{document_hash}/outline'
        )
        response.raise_for_status()
        return response.json().get('data', [])

    def get_page_links(self, document_hash: str, page_number: int) -> List[Dict]:
        response = self.session.get(
            f'{self.base_url}/documents/{document_hash}/pages/{page_number}/links'
        )
        response.raise_for_status()
        return response.json().get('data', [])
```

---

## Error Handling

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 401 | Unauthorized - Invalid or missing token |
| 404 | Document not found |
| 422 | Validation error |
| 500 | Server error |

### Handling Feature Toggles

When TOC or link extraction is disabled, endpoints return empty arrays:

```javascript
const doc = await fetch(`/api/pdf-viewer/documents/${hash}`);
const { data } = await doc.json();

if (data.has_outline) {
  // Show TOC panel
}

if (data.has_links) {
  // Enable link overlay rendering
}
```

---

## OpenAPI Specification

The complete OpenAPI 3.1 specification is available at `docs/openapi.yaml`.
