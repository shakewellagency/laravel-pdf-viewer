<?php

namespace Shakewellagency\LaravelPdfViewer\Exceptions;

use Exception;

class DocumentNotFoundException extends Exception
{
    public function __construct($message = "Document not found", $code = 404, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}