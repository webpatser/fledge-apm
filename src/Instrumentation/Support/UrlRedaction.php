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
    private const string SENSITIVE_QUERY_PARAM = '/token|key|secret|password|passwd|pwd|auth|signature|credential|^sig$/i';

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

                return preg_match(self::SENSITIVE_QUERY_PARAM, rawurldecode($name)) === 1 ? "{$name}=REDACTED" : $pair;
            },
            explode('&', $query),
        ));
    }
}
