<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation\Support;

/**
 * Query string redaction shared by the incoming (`url.query`) and outgoing
 * (`url.full`) HTTP instrumentations.
 *
 * @internal
 */
final class UrlRedaction
{
    private const string SENSITIVE_QUERY_PARAM = '/token|key|secret|password|passwd|pwd|auth|signature|credential/i';

    /**
     * Names too short or too common to match as a substring: `session` and
     * `nonce` anywhere, and `code`, `otp`, `state`, `access`, `refresh`, `sig`
     * as the whole name or its last segment (`oauth_code`, `auth[state]`), so
     * `postcode`, `barcode`, `statement` and `page` stay readable.
     */
    private const string SENSITIVE_NAME_EXTRA = '/session|nonce|(?:^|[_\-.\[])(?:code|otp|state|access|refresh|sig)\]?$/i';

    /**
     * Route parameters that are secrets on top of the query-parameter names:
     * the `{hash}` of `email/verify/{id}/{hash}`.
     */
    private const string SENSITIVE_PATH_PARAM = '/hash/i';

    /**
     * The path with the values of credential-looking route parameters
     * (`reset-password/{token}`, `email/verify/{id}/{hash}`, `invite/{token}`)
     * replaced by REDACTED. Only the matched route's own parameters are touched.
     *
     * @param  string  $path  the request path, e.g. `/reset-password/abc123`
     * @param  string  $routeUri  the matched route's URI template, e.g. `reset-password/{token}`
     */
    public static function path(string $path, string $routeUri): string
    {
        if (! str_contains($routeUri, '{')) {
            return $path;
        }

        $templateSegments = explode('/', trim($routeUri, '/'));
        $pathSegments = explode('/', ltrim($path, '/'));

        // A parameter that swallows slashes ({path}) shifts the alignment of the
        // segments after it; those are left as they are.
        foreach ($templateSegments as $index => $template) {
            if (! str_contains($template, '{') || ! isset($pathSegments[$index])) {
                continue;
            }

            $pathSegments[$index] = self::redactSegment($template, $pathSegments[$index]);
        }

        return '/'.implode('/', $pathSegments);
    }

    private static function redactSegment(string $template, string $segment): string
    {
        $names = [];
        $regex = '';
        $offset = 0;

        preg_match_all('/\{(\w+)(?::[^}?]+)?\??\}/', $template, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$whole, $at] = $match[0];

            $regex .= preg_quote(substr($template, $offset, $at - $offset), '#').'(.*?)';
            $names[] = $match[1][0];
            $offset = $at + strlen($whole);
        }

        $regex .= preg_quote(substr($template, $offset), '#');

        if ($names === [] || preg_match('#^'.$regex.'$#', $segment, $captured, PREG_OFFSET_CAPTURE) !== 1) {
            return $segment;
        }

        $result = $segment;

        // Replace from the last capture backwards so earlier offsets stay valid.
        for ($i = count($names); $i >= 1; $i--) {
            if (! self::sensitiveRouteParam($names[$i - 1])) {
                continue;
            }

            [$value, $start] = $captured[$i];

            $result = substr_replace($result, 'REDACTED', $start, strlen($value));
        }

        return $result;
    }

    private static function sensitiveName(string $name): bool
    {
        return preg_match(self::SENSITIVE_QUERY_PARAM, $name) === 1
            || preg_match(self::SENSITIVE_NAME_EXTRA, $name) === 1;
    }

    private static function sensitiveRouteParam(string $name): bool
    {
        return self::sensitiveName($name)
            || preg_match(self::SENSITIVE_PATH_PARAM, $name) === 1;
    }

    /**
     * The query string with the value of credential-looking parameters
     * (token, key, secret, signature, ...) replaced by REDACTED.
     */
    public static function query(string $query): string
    {
        if ($query === '') {
            return '';
        }

        return implode('&', array_map(
            static function (string $pair): string {
                if (! str_contains($pair, '=')) {
                    return $pair;
                }

                [$name] = explode('=', $pair, 2);

                return self::sensitiveName(rawurldecode($name)) ? "{$name}=REDACTED" : $pair;
            },
            explode('&', $query),
        ));
    }
}
