<?php

namespace Shakewellagency\LaravelPdfViewer\Exceptions;

use Exception;

class DocumentNotFoundException extends Exception
{
    public function __construct(string $message = "Document not found", int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}