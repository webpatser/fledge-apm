<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation\Support;

/**
 * Query string redaction shared by the incoming (`url.query`) and outgoing
 * (`url.full`) HTTP instrumentations, plus path redaction for both: by route
 * template for incoming requests, by known secret shapes for outgoing ones.
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

    /**
     * The path of an outgoing request with credentials that APIs carry in the
     * path replaced by REDACTED. There is no route template to go by, so this
     * matches known shapes plus a heuristic:
     *
     * - Telegram bot API: `/bot<id>:<token>/...` becomes `/botREDACTED/...`.
     * - Slack incoming webhooks (`hooks.slack.com`): every segment after the
     *   first (`/services/REDACTED/REDACTED/REDACTED`).
     * - Discord webhooks (`discord.com`, `discordapp.com`): the token after
     *   `webhooks/<id>`.
     * - Any segment holding a random-looking run of 32 or more token
     *   characters (hex digests, API keys, base64, JWTs), judged on the
     *   percent-decoded segment; see randomPart().
     *
     * @param  string  $path  the request path, e.g. `/bot123:AAE.../sendMessage`
     * @param  string  $host  the request host, e.g. `api.telegram.org`
     */
    public static function clientPath(string $path, string $host = ''): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }

        $host = strtolower($host);
        $segments = explode('/', $path);
        $slack = $host === 'hooks.slack.com';
        $discord = in_array(preg_replace('/^(?:ptb\.|canary\.)/', '', $host), ['discord.com', 'discordapp.com'], true);
        $first = true;

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            $decoded = rawurldecode($segment);

            if (preg_match('/^bot\d+:[\w\-]+$/', $decoded) === 1) {
                $segments[$index] = 'botREDACTED';
            } elseif ($slack && ! $first) {
                $segments[$index] = 'REDACTED';
            } elseif ($discord && $index >= 2 && strtolower($segments[$index - 2]) === 'webhooks' && ctype_digit($segments[$index - 1])) {
                $segments[$index] = 'REDACTED';
            } elseif (self::randomLooking($decoded)) {
                $segments[$index] = 'REDACTED';
            }

            $first = false;
        }

        return implode('/', $segments);
    }

    /**
     * Whether any part of the decoded segment looks like a secret. The
     * segment is split on characters outside the base64, base64url and JWT
     * alphabets (`;` matrix parameters, `,`, spaces, ...), so `<hex>;v=1`
     * is judged by its hex part.
     */
    private static function randomLooking(string $segment): bool
    {
        if (strlen($segment) < 32) {
            return false;
        }

        $parts = preg_split('#[^\w\-.+/=~]+#', $segment, -1, PREG_SPLIT_NO_EMPTY);

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (self::randomPart($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A run of 32 or more token characters that is either letters and digits
     * only with at least one of each (hex digests, API keys), or mixes upper
     * case, lower case and digits with an upper case letter inside a word
     * (base64, base64url, JWT). UUIDs, slugs, versions, dotted file names and
     * Title-Case-Words stay readable.
     */
    private static function randomPart(string $part): bool
    {
        if (strlen($part) < 32) {
            return false;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $part) === 1) {
            return false;
        }

        $digit = preg_match('/\d/', $part) === 1;
        $lower = preg_match('/[a-z]/', $part) === 1;
        $upper = preg_match('/[A-Z]/', $part) === 1;

        if (ctype_alnum($part)) {
            return $digit && ($lower || $upper);
        }

        return $digit && $lower && $upper && preg_match('/[a-z0-9][A-Z]/', $part) === 1;
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
