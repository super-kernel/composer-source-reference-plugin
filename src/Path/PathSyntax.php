<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Path;

use function preg_match;
use function str_replace;
use function str_starts_with;
use function strpbrk;

/**
 * Normalizes path separators and recognizes Unix and Windows drive-absolute forms and unsupported wildcards.
 *
 * @internal
 */
final class PathSyntax
{
    private const string UNSUPPORTED_WILDCARDS = '?[]{}';

    public static function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * Reports whether the path contains a glob character other than the supported `*` wildcard.
     */
    public static function hasUnsupportedWildcard(string $path): bool
    {
        return false !== strpbrk($path, self::UNSUPPORTED_WILDCARDS);
    }
}
