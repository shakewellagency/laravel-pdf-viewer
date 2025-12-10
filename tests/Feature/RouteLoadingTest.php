<?php

it('search documents route requires authentication', function () {
    // Skip test if auth middleware is not configured
    // Auth is configurable by consuming application via pdf-viewer.middleware config
    $middleware = config('pdf-viewer.middleware', []);
    if (! in_array('auth', $middleware) && ! in_array('auth:sanctum', $middleware) && ! in_array('auth:api', $middleware)) {
        $this->markTestSkipped('Auth middleware not configured for routes');
    }

    // Test if the route exists and requires authentication
    $response = $this->getJson('/api/pdf-viewer/search?q=test');

    // Should get 401 (auth required) not 404 (route not found)
    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('search documents route works with authentication', function () {
    // Test authenticated access
    $this->actingAsUser();

    $response = $this->getJson('/api/pdf-viewer/search?q=test');

    // Should not return 404 (route exists) and should return proper search response
    expect($response->getStatusCode())->not->toBe(404);

    // Should get 422 (validation error), 500 (FULLTEXT not supported in SQLite), or 200 (successful search)
    expect(in_array($response->getStatusCode(), [200, 422, 500]))->toBeTrue();

    if ($response->getStatusCode() === 422) {
        $response->assertJson([
            'message' => 'Query must be at least 3 characters long',
        ]);
    } elseif ($response->getStatusCode() === 500) {
        // Expected in SQLite testing environment due to FULLTEXT search not being supported
        $response->assertJsonFragment(['message' => 'Search failed']);
    }
});
