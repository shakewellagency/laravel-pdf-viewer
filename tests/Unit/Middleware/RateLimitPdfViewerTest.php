<?php

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Shakewellagency\LaravelPdfViewer\Middleware\RateLimitPdfViewer;

beforeEach(function () {
    $this->limiter = app(RateLimiter::class);
    $this->middleware = new RateLimitPdfViewer($this->limiter);

    // Clear any existing rate limit data
    $this->limiter->clear('pdf_viewer|ip|127.0.0.1');
    $this->limiter->clear('pdf_viewer|user|1');

    // Enable rate limiting for tests
    config(['pdf-viewer.security.rate_limit_enabled' => true]);
    config(['pdf-viewer.security.rate_limit' => 60]);
});

afterEach(function () {
    $this->limiter->clear('pdf_viewer|ip|127.0.0.1');
    $this->limiter->clear('pdf_viewer|user|1');
});

it('allows requests within rate limit', function () {
    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['message' => 'success']);
    });

    expect($response->getStatusCode())->toBe(200);
});

it('adds rate limit headers to response', function () {
    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['message' => 'success']);
    });

    expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
    expect($response->headers->has('X-RateLimit-Remaining'))->toBeTrue();
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('60');
});

it('decrements remaining count on each request', function () {
    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response1 = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $response2 = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));

    $remaining1 = (int) $response1->headers->get('X-RateLimit-Remaining');
    $remaining2 = (int) $response2->headers->get('X-RateLimit-Remaining');

    expect($remaining2)->toBeLessThan($remaining1);
});

it('returns 429 when rate limit exceeded', function () {
    config(['pdf-viewer.security.rate_limit' => 2]);

    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    // Make requests to exceed limit
    $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $response = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));

    expect($response->getStatusCode())->toBe(429);
});

it('includes retry-after header when rate limited', function () {
    config(['pdf-viewer.security.rate_limit' => 1]);

    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    // Exceed limit
    $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $response = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));

    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('uses user id when authenticated', function () {
    $user = new class extends Authenticatable
    {
        public $id = 1;
    };

    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');
    $request->setUserResolver(fn() => $user);

    $response = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));

    expect($response->getStatusCode())->toBe(200);
});

it('uses different limits for upload limiter', function () {
    config(['pdf-viewer.security.rate_limit_upload' => 5]);

    $request = Request::create('/api/pdf-viewer/documents', 'POST');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['message' => 'success']);
    }, 'pdf-viewer-upload');

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('5');
});

it('uses different limits for search limiter', function () {
    config(['pdf-viewer.security.rate_limit_search' => 30]);

    $request = Request::create('/api/pdf-viewer/search', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['message' => 'success']);
    }, 'pdf-viewer-search');

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('30');
});

it('uses different limits for download limiter', function () {
    config(['pdf-viewer.security.rate_limit_download' => 100]);

    $request = Request::create('/api/pdf-viewer/documents/hash/pages/1/download', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['message' => 'success']);
    }, 'pdf-viewer-download');

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('100');
});

it('skips rate limiting when disabled', function () {
    config(['pdf-viewer.security.rate_limit_enabled' => false]);
    config(['pdf-viewer.security.rate_limit' => 1]);

    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    // Make multiple requests - should all succeed
    $response1 = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $response2 = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $response3 = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));

    expect($response1->getStatusCode())->toBe(200);
    expect($response2->getStatusCode())->toBe(200);
    expect($response3->getStatusCode())->toBe(200);
});

it('returns proper error message when rate limited', function () {
    config(['pdf-viewer.security.rate_limit' => 1]);

    $request = Request::create('/api/pdf-viewer/documents', 'GET');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    // Exceed limit
    $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));
    $response = $this->middleware->handle($request, fn($req) => response()->json(['message' => 'success']));

    $content = json_decode($response->getContent(), true);

    expect($content)->toHaveKey('message');
    expect($content)->toHaveKey('error');
    expect($content['error'])->toBe('rate_limit_exceeded');
});
