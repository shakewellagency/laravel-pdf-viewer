<?php

namespace Shakewellagency\LaravelPdfViewer\Tests\Unit\Exceptions;

use Shakewellagency\LaravelPdfViewer\Exceptions\InvalidFileTypeException;
use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

class InvalidFileTypeExceptionTest extends TestCase
{
    public function test_exception_has_default_values(): void
    {
        $exception = new InvalidFileTypeException();

        $this->assertEquals("Invalid file type", $exception->getMessage());
        $this->assertEquals(422, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function test_exception_with_custom_message(): void
    {
        $message = "Custom invalid file type message";
        $exception = new InvalidFileTypeException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(422, $exception->getCode());
    }

    public function test_exception_with_custom_code(): void
    {
        $code = 400;
        $exception = new InvalidFileTypeException("Message", $code);

        $this->assertEquals("Message", $exception->getMessage());
        $this->assertEquals($code, $exception->getCode());
    }

    public function test_exception_with_previous(): void
    {
        $previous = new \Exception("Previous exception");
        $exception = new InvalidFileTypeException("Message", 422, $previous);

        $this->assertEquals("Message", $exception->getMessage());
        $this->assertEquals(422, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_exception_is_throwable(): void
    {
        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage("Test exception");
        $this->expectExceptionCode(422);

        throw new InvalidFileTypeException("Test exception");
    }

    public function test_exception_extends_base_exception(): void
    {
        $exception = new InvalidFileTypeException();

        $this->assertInstanceOf(\Exception::class, $exception);
    }
}