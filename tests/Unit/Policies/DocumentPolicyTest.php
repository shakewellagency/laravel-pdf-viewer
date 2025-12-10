<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;
use Shakewellagency\LaravelPdfViewer\Policies\DocumentPolicy;

beforeEach(function () {
    $this->policy = new DocumentPolicy;
    $this->user = new class extends Authenticatable
    {
        public $id = 1;
    };
});

it('allows any authenticated user to view any documents', function () {
    $result = $this->policy->viewAny($this->user);

    expect($result)->toBeTrue();
});

it('allows viewing document without restrictions', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->policy->view($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows creating documents', function () {
    $result = $this->policy->create($this->user);

    expect($result)->toBeTrue();
});

it('allows updating document without restrictions', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->policy->update($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows deleting document when no ownership defined', function () {
    // When no user_id or created_by column exists, policy allows for backward compatibility
    $document = PdfDocument::factory()->create();

    $result = $this->policy->delete($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows processing document when can view', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->policy->process($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows viewing outline when can view document', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->policy->viewOutline($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows viewing links when can view document', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->policy->viewLinks($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows searching when can view document', function () {
    $document = PdfDocument::factory()->create();

    $result = $this->policy->search($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows downloading when downloads are enabled', function () {
    $document = PdfDocument::factory()->create([
        'metadata' => ['allow_downloads' => true],
    ]);

    $result = $this->policy->download($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows downloading when no download restriction is set', function () {
    $document = PdfDocument::factory()->create([
        'metadata' => [],
    ]);

    $result = $this->policy->download($this->user, $document);

    expect($result)->toBeTrue();
});

it('restricts viewing document with access restrictions', function () {
    $document = PdfDocument::factory()->create([
        'metadata' => [
            'restricted' => true,
            'allowed_users' => [2, 3], // User 1 not in list
        ],
    ]);

    $result = $this->policy->view($this->user, $document);

    expect($result)->toBeFalse();
});

it('allows viewing restricted document when user is in allowed list', function () {
    $document = PdfDocument::factory()->create([
        'metadata' => [
            'restricted' => true,
            'allowed_users' => [1, 2, 3], // User 1 is in list
        ],
    ]);

    $result = $this->policy->view($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows compliance report when no ownership defined', function () {
    // When no user_id or created_by column exists, policy allows for backward compatibility
    $document = PdfDocument::factory()->create();

    $result = $this->policy->viewCompliance($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows download when no ownership defined even with downloads disabled', function () {
    // When no user_id/created_by, isDocumentOwner returns true for backward compatibility
    $document = PdfDocument::factory()->create([
        'metadata' => ['allow_downloads' => false],
    ]);

    $result = $this->policy->download($this->user, $document);

    expect($result)->toBeTrue();
});

it('allows updating when ownership not defined even with restrictions', function () {
    // When no user_id/created_by column, isDocumentOwner returns true for backward compatibility
    // This means owners can always update regardless of restriction metadata
    $document = PdfDocument::factory()->create([
        'metadata' => [
            'restricted' => true,
            'allowed_users' => [2, 3], // User 1 not in list but is "owner" by default
        ],
    ]);

    $result = $this->policy->update($this->user, $document);

    // Owner can always update
    expect($result)->toBeTrue();
});

it('restricts processing when document has view restrictions', function () {
    // Processing depends on view permission
    $document = PdfDocument::factory()->create([
        'metadata' => [
            'restricted' => true,
            'allowed_users' => [2, 3], // User 1 not in list
        ],
    ]);

    $result = $this->policy->process($this->user, $document);

    // Process uses view() which respects restrictions
    expect($result)->toBeFalse();
});
