<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Autoload;

use RuntimeException;
use SuperKernel\ComposerSourceReferencePlugin\Path\PathSyntax;

use function explode;
use function implode;
use function ltrim;
use function preg_match;
use function preg_quote;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trim;

use const SORT_STRING;

/**
 * Normalizes `exclude-from-classmap` patterns and matches package-relative paths against them.
 *
 * @internal
 */
final class ClassmapExclusion
{
    /**
     * @return list<string> Sorted package-relative patterns
     *
     * @throws RuntimeException When the value is not a string list or a pattern contains a null byte or a `..` segment,
     *                          is absolute, or uses a wildcard other than `*` and `**`
     */
    public static function patterns(mixed $value): array
    {
        $patterns = [];

        foreach (AutoloadValue::stringList($value, 'autoload.exclude-from-classmap') as $pattern) {
            if (str_contains($pattern, "\0")) {
                throw new RuntimeException('Classmap exclusion patterns must not contain null bytes.');
            }

            $pattern = PathSyntax::normalizeSeparators($pattern);
            $relativePattern = ltrim($pattern, '/');

            if (
                str_starts_with($pattern, '//')
                || PathSyntax::isAbsolutePath($relativePattern)
                || PathSyntax::hasUnsupportedWildcard($pattern)
            ) {
                throw new RuntimeException(sprintf('Invalid classmap exclusion pattern "%s".', $pattern));
            }

            $segments = [];

            foreach (explode('/', $relativePattern) as $segment) {
                if ('' === $segment || '.' === $segment) {
                    continue;
                }

                if ('..' === $segment) {
                    throw new RuntimeException('Classmap exclusion patterns must not contain ".." segments.');
                }

                $segments[] = $segment;
            }

            $normalized = implode('/', $segments);

            if (str_ends_with($pattern, '/') && '' !== $normalized) {
                $normalized .= '/';
            }

            $patterns[] = $normalized;
        }

        sort($patterns, SORT_STRING);

        return $patterns;
    }

    /**
     * @param list<string> $patterns Normalized exclusion patterns; `**` may cross directories and `*` may not
     */
    public static function isExcluded(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');
            $regex = preg_quote($pattern, '~');
            $regex = str_replace([
                '\\*\\*',
                '\\*',
            ], [
                '.+?',
                '[^/]+?',
            ], $regex);

            if (1 === preg_match('~\A' . $regex . '(?:\z|/)~', $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
