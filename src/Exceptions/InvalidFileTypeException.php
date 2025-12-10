<?php

namespace Shakewellagency\LaravelPdfViewer\Exceptions;

use Exception;

class InvalidFileTypeException extends Exception
{
    public function __construct(string $message = "Invalid file type", int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}