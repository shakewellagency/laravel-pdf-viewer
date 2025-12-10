<?php

namespace Shakewellagency\LaravelPdfViewer\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Shakewellagency\LaravelPdfViewer\Models\PdfDocument;

/**
 * Authorization policy for PDF documents.
 *
 * This policy can be extended by the consuming application to implement
 * custom authorization logic based on their user/permission system.
 *
 * By default, all actions are allowed for authenticated users.
 * To enable document-specific authorization, extend this class in your application.
 */
class DocumentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any documents.
     */
    public function viewAny($user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view($user, PdfDocument $document): bool
    {
        // Check if document has access restrictions
        if ($this->hasAccessRestriction($document)) {
            return $this->checkDocumentAccess($user, $document);
        }

        return true;
    }

    /**
     * Determine whether the user can create documents.
     */
    public function create($user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the document.
     */
    public function update($user, PdfDocument $document): bool
    {
        // Check if user is the document owner
        if ($this->isDocumentOwner($user, $document)) {
            return true;
        }

        // Check if user has explicit access
        if ($this->hasAccessRestriction($document)) {
            return $this->checkDocumentAccess($user, $document, 'write');
        }

        return true;
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete($user, PdfDocument $document): bool
    {
        // Only document owner or admin can delete
        return $this->isDocumentOwner($user, $document);
    }

    /**
     * Determine whether the user can process the document.
     */
    public function process($user, PdfDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Determine whether the user can view document outline/TOC.
     */
    public function viewOutline($user, PdfDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Determine whether the user can view document links.
     */
    public function viewLinks($user, PdfDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Determine whether the user can search within the document.
     */
    public function search($user, PdfDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Determine whether the user can download the document.
     */
    public function download($user, PdfDocument $document): bool
    {
        // Check if downloads are allowed for this document
        $allowDownloads = $document->metadata['allow_downloads'] ?? true;

        if (!$allowDownloads) {
            return $this->isDocumentOwner($user, $document);
        }

        return $this->view($user, $document);
    }

    /**
     * Determine whether the user can access compliance reports.
     */
    public function viewCompliance($user, PdfDocument $document): bool
    {
        // Compliance reports may require elevated access
        return $this->isDocumentOwner($user, $document);
    }

    /**
     * Check if user is the document owner.
     */
    protected function isDocumentOwner($user, PdfDocument $document): bool
    {
        // Check common patterns for document ownership
        if (method_exists($document, 'user') && $document->user_id) {
            return $document->user_id === $user->id;
        }

        if (method_exists($document, 'created_by') && $document->created_by) {
            return $document->created_by === $user->id;
        }

        // If no ownership is defined, allow for backward compatibility
        return true;
    }

    /**
     * Check if document has access restrictions.
     */
    protected function hasAccessRestriction(PdfDocument $document): bool
    {
        // Check if document has explicit access control
        $metadata = $document->metadata ?? [];

        return isset($metadata['restricted']) && $metadata['restricted'] === true;
    }

    /**
     * Check document-level access.
     */
    protected function checkDocumentAccess($user, PdfDocument $document, string $level = 'read'): bool
    {
        $metadata = $document->metadata ?? [];
        $allowedUsers = $metadata['allowed_users'] ?? [];

        if (in_array($user->id, $allowedUsers)) {
            return true;
        }

        // Check for role-based access if user has roles
        if (method_exists($user, 'hasRole')) {
            $allowedRoles = $metadata['allowed_roles'] ?? [];
            foreach ($allowedRoles as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
        }

        return false;
    }
}
