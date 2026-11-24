<?php

declare(strict_types=1);

namespace Fledge\Apm\Instrumentation\Support;

use Throwable;

/**
 * The `error.type` metric attribute of a failure: the exception class short
 * name, never the message. The set of classes an app throws is small and
 * bounded, unlike messages or fully qualified names of generated classes.
 */
final class ErrorType
{
    public const string OTHER = '_OTHER';

    public static function shortName(Throwable $exception): string
    {
        $class = $exception::class;

        // Anonymous classes carry a NUL byte and a file path in their name.
        if (($anonymous = strpos($class, '@anonymous')) !== false) {
            return substr($class, 0, $anonymous + strlen('@anonymous'));
        }

        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
