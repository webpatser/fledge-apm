<?php

declare(strict_types=1);

use Fledge\Apm\Instrumentation\Support\UrlRedaction;

it('redacts sensitive route parameters from a path', function (string $template, string $path, string $expected) {
    expect(UrlRedaction::path($path, $template))->toBe($expected);
})->with([
    'token' => ['reset-password/{token}', '/reset-password/abc', '/reset-password/REDACTED'],
    'hash' => ['email/verify/{id}/{hash}', '/email/verify/9/deadbeef', '/email/verify/9/REDACTED'],
    'binding field' => ['invite/{token:uuid}', '/invite/1234', '/invite/REDACTED'],
    'two sensitive' => ['x/{key}/{secret}', '/x/a/b', '/x/REDACTED/REDACTED'],
    'no params' => ['health', '/health', '/health'],
    'harmless' => ['users/{id}', '/users/42', '/users/42'],
]);
