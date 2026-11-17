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

it('redacts credentials carried in an outgoing request path', function (string $host, string $path, string $expected) {
    expect(UrlRedaction::clientPath($path, $host))->toBe($expected);
})->with([
    'telegram bot token' => ['api.telegram.org', '/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage', '/botREDACTED/sendMessage'],
    'telegram file download' => ['api.telegram.org', '/file/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/photos/a.jpg', '/file/botREDACTED/photos/a.jpg'],
    'telegram encoded colon' => ['api.telegram.org', '/bot123456789%3AAAHdqTcvCH1vGWJx/getMe', '/botREDACTED/getMe'],
    'slack webhook' => ['hooks.slack.com', '/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX', '/services/REDACTED/REDACTED/REDACTED'],
    'slack workflow trigger' => ['hooks.slack.com', '/triggers/T0/123/abc', '/triggers/REDACTED/REDACTED/REDACTED'],
    'discord webhook' => ['discord.com', '/api/webhooks/1234567890/short-token', '/api/webhooks/1234567890/REDACTED'],
    'discord versioned' => ['canary.discordapp.com', '/api/v10/webhooks/1234567890/tok/slack', '/api/v10/webhooks/1234567890/REDACTED/slack'],
    'hex digest' => ['api.example.test', '/download/9e107d9d372bb6826bd81d3542a419d6/file.zip', '/download/REDACTED/file.zip'],
    'base64url token' => ['api.example.test', '/invite/aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3z_-Q', '/invite/REDACTED'],
    'alphanumeric api key' => ['api.example.test', '/v1/sk4f8a9b2c7d1e6f3a0b5c8d2e7f1a4b9c/items', '/v1/REDACTED/items'],
    'jwt' => ['api.example.test', '/magic/eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpM', '/magic/REDACTED'],
    'percent-encoded base64' => ['api.example.test', '/t/AbC1dEf2GhI3jKl4MnO5pQr6StU7vWx8YZ%2B%3D%3D', '/t/REDACTED'],
    'matrix params' => ['api.example.test', '/k/9e107d9d372bb6826bd81d3542a419d6;v=1', '/k/REDACTED'],
]);

it('keeps ordinary outgoing request paths readable', function (string $host, string $path) {
    expect(UrlRedaction::clientPath($path, $host))->toBe($path);
})->with([
    'root' => ['api.example.test', '/'],
    'empty' => ['api.example.test', ''],
    'ids' => ['api.example.test', '/v1/users/42/orders/1337'],
    'uuid' => ['api.example.test', '/v1/orders/123e4567-e89b-12d3-a456-426614174000'],
    'long slug' => ['blog.example.test', '/posts/how-we-moved-the-whole-stack-to-fibers-in-2026'],
    'long word' => ['api.example.test', '/internationalizationconfigurations'],
    'short mixed' => ['api.example.test', '/v2/Items/A1b2C3'],
    'discord non webhook' => ['discord.com', '/api/v10/channels/1234567890/messages'],
    'webhooks elsewhere' => ['api.example.test', '/api/webhooks/1234567890/deliveries'],
    'bot word' => ['api.example.test', '/bots/robot/bot123'],
    'dotted file name' => ['files.example.test', '/reports/report-2026.final.pdf'],
    'version' => ['api.example.test', '/releases/v1.2.3/notes'],
    'uuid with extension' => ['api.example.test', '/exports/123e4567-e89b-12d3-a456-426614174000.csv'],
    'long dotted file name' => ['files.example.test', '/docs/annual-report-for-the-year-2026-version-2.final.pdf'],
    'title case file name' => ['files.example.test', '/docs/Annual-Report-2026-Final-Version-Q3.pdf'],
    'encoded spaces' => ['files.example.test', '/docs/Quarterly%20Report%202026%20Q3%20Final%20Version.pdf'],
]);

it('redacts code and state route parameters in a path', function () {
    expect(UrlRedaction::path('/oauth/callback/abc', 'oauth/callback/{code}'))->toBe('/oauth/callback/REDACTED')
        ->and(UrlRedaction::path('/area/3011', 'area/{postcode}'))->toBe('/area/3011');
});
