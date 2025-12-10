<?php

use Shakewellagency\LaravelPdfViewer\Exceptions\InvalidFileTypeException;

it('has default values', function () {
    $exception = new InvalidFileTypeException();

    expect($exception->getMessage())->toBe('Invalid file type');
    expect($exception->getCode())->toBe(422);
    expect($exception->getPrevious())->toBeNull();
});

it('accepts custom message', function () {
    $message = 'Custom invalid file type message';
    $exception = new InvalidFileTypeException($message);

    expect($exception->getMessage())->toBe($message);
    expect($exception->getCode())->toBe(422);
});

it('accepts custom code', function () {
    $code = 400;
    $exception = new InvalidFileTypeException('Message', $code);

    expect($exception->getMessage())->toBe('Message');
    expect($exception->getCode())->toBe($code);
});

it('accepts previous exception', function () {
    $previous = new \Exception('Previous exception');
    $exception = new InvalidFileTypeException('Message', 422, $previous);

    expect($exception->getMessage())->toBe('Message');
    expect($exception->getCode())->toBe(422);
    expect($exception->getPrevious())->toBe($previous);
});

it('is throwable', function () {
    throw new InvalidFileTypeException('Test exception');
})->throws(InvalidFileTypeException::class, 'Test exception');

it('extends base exception', function () {
    $exception = new InvalidFileTypeException();

    expect($exception)->toBeInstanceOf(\Exception::class);
});
