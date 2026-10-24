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

it('redacts extra credential-looking query parameter names', function (string $query, string $expected) {
    expect(UrlRedaction::query($query))->toBe($expected);
})->with([
    'code' => ['code=abc', 'code=REDACTED'],
    'otp' => ['otp=123456', 'otp=REDACTED'],
    'state' => ['state=xyz', 'state=REDACTED'],
    'nonce' => ['nonce=n1', 'nonce=REDACTED'],
    'session id' => ['PHPSESSION=1&session_id=2', 'PHPSESSION=REDACTED&session_id=REDACTED'],
    'access' => ['access=a&refresh=r&sig=s', 'access=REDACTED&refresh=REDACTED&sig=REDACTED'],
    'last segment' => ['oauth_code=a&auth-state=b&filter[state]=c', 'oauth_code=REDACTED&auth-state=REDACTED&filter[state]=REDACTED'],
    'unrelated names stay' => ['postcode=3011&barcode=8712&statement=x&page=2&q=hi', 'postcode=3011&barcode=8712&statement=x&page=2&q=hi'],
]);

it('redacts code and state route parameters in a path', function () {
    expect(UrlRedaction::path('/oauth/callback/abc', 'oauth/callback/{code}'))->toBe('/oauth/callback/REDACTED')
        ->and(UrlRedaction::path('/area/3011', 'area/{postcode}'))->toBe('/area/3011');
});
