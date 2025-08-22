<?php

namespace Shakewellagency\LaravelPdfViewer\Exceptions;

use Exception;

class InvalidFileTypeException extends Exception
{
    public function __construct($message = "Invalid file type", $code = 422, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}