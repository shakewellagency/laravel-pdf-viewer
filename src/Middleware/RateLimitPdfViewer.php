<?php

namespace Shakewellagency\LaravelPdfViewer\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting middleware for PDF Viewer endpoints.
 *
 * Protects against abuse and ensures fair usage of resources.
 * Rate limits are configurable via pdf-viewer.security.rate_limit
 */
class RateLimitPdfViewer
{
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $limiterName = 'pdf-viewer'): Response
    {
        // Skip rate limiting if disabled
        if (!config('pdf-viewer.security.rate_limit_enabled', true)) {
            return $next($request);
        }

        $key = $this->resolveRequestSignature($request);
        $maxAttempts = $this->getMaxAttempts($limiterName);
        $decayMinutes = $this->getDecayMinutes($limiterName);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->buildRateLimitResponse($key, $maxAttempts);
        }

        $this->limiter->hit($key, $decayMinutes * 60);

        $response = $next($request);

        return $this->addRateLimitHeaders(
            $response,
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts)
        );
    }

    /**
     * Resolve the request signature for rate limiting.
     */
    protected function resolveRequestSignature(Request $request): string
    {
        // Use authenticated user ID if available, otherwise use IP
        if ($user = $request->user()) {
            return 'pdf_viewer|user|' . $user->id;
        }

        return 'pdf_viewer|ip|' . $request->ip();
    }

    /**
     * Get the maximum number of attempts for the given limiter.
     */
    protected function getMaxAttempts(string $limiterName): int
    {
        $limits = [
            'pdf-viewer' => config('pdf-viewer.security.rate_limit', 60),
            'pdf-viewer-upload' => config('pdf-viewer.security.rate_limit_upload', 10),
            'pdf-viewer-search' => config('pdf-viewer.security.rate_limit_search', 30),
            'pdf-viewer-download' => config('pdf-viewer.security.rate_limit_download', 100),
        ];

        return $limits[$limiterName] ?? 60;
    }

    /**
     * Get the decay time in minutes.
     */
    protected function getDecayMinutes(string $limiterName): int
    {
        $decayTimes = [
            'pdf-viewer' => 1,
            'pdf-viewer-upload' => 1,
            'pdf-viewer-search' => 1,
            'pdf-viewer-download' => 1,
        ];

        return $decayTimes[$limiterName] ?? 1;
    }

    /**
     * Build the response when rate limit is exceeded.
     */
    protected function buildRateLimitResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return response()->json([
            'message' => 'Too many requests. Please try again later.',
            'error' => 'rate_limit_exceeded',
            'retry_after' => $retryAfter,
        ], 429, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + $retryAfter,
        ]);
    }

    /**
     * Calculate the number of remaining attempts.
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts): int
    {
        return $maxAttempts - $this->limiter->attempts($key);
    }

    /**
     * Add rate limit headers to the response.
     */
    protected function addRateLimitHeaders(Response $response, int $maxAttempts, int $remainingAttempts): Response
    {
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $remainingAttempts));

        return $response;
    }
}
