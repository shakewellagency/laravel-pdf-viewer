<?php

use Shakewellagency\LaravelPdfViewer\Exceptions\DocumentNotFoundException;

it('has default values', function () {
    $exception = new DocumentNotFoundException();

    expect($exception->getMessage())->toBe('Document not found');
    expect($exception->getCode())->toBe(404);
    expect($exception->getPrevious())->toBeNull();
});

it('accepts custom message', function () {
    $message = 'Custom document not found message';
    $exception = new DocumentNotFoundException($message);

    expect($exception->getMessage())->toBe($message);
    expect($exception->getCode())->toBe(404);
});

it('accepts custom code', function () {
    $code = 500;
    $exception = new DocumentNotFoundException('Message', $code);

    expect($exception->getMessage())->toBe('Message');
    expect($exception->getCode())->toBe($code);
});

it('accepts previous exception', function () {
    $previous = new \Exception('Previous exception');
    $exception = new DocumentNotFoundException('Message', 404, $previous);

    expect($exception->getMessage())->toBe('Message');
    expect($exception->getCode())->toBe(404);
    expect($exception->getPrevious())->toBe($previous);
});

it('is throwable', function () {
    throw new DocumentNotFoundException('Test exception');
})->throws(DocumentNotFoundException::class, 'Test exception');

it('extends base exception', function () {
    $exception = new DocumentNotFoundException();

    expect($exception)->toBeInstanceOf(\Exception::class);
});
