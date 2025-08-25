<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Exceptions;

use Shakewellagency\LaravelPdfViewer\Exceptions\DocumentNotFoundException;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class DocumentNotFoundExceptionTest extends TestCase
{
    public function test_exception_has_default_values(): void
    {
        $exception = new DocumentNotFoundException();

        $this->assertEquals("Document not found", $exception->getMessage());
        $this->assertEquals(404, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function test_exception_with_custom_message(): void
    {
        $message = "Custom document not found message";
        $exception = new DocumentNotFoundException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(404, $exception->getCode());
    }

    public function test_exception_with_custom_code(): void
    {
        $code = 500;
        $exception = new DocumentNotFoundException("Message", $code);

        $this->assertEquals("Message", $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
    }

    public function test_exception_with_previous(): void
    {
        $previous = new \Exception("Previous exception");
        $exception = new DocumentNotFoundException("Message", 404, $previous);

        $this->assertEquals("Message", $exception->getMessage());
        $this->assertEquals(404, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_exception_is_throwable(): void
    {
        $this->expectException(DocumentNotFoundException::class);
        $this->expectExceptionMessage("Test exception");
        $this->expectExceptionCode(404);

        throw new DocumentNotFoundException("Test exception");
    }

    public function test_exception_extends_base_exception(): void
    {
        $exception = new DocumentNotFoundException();

        $this->assertInstanceOf(\Exception::class, $exception);
    }
}