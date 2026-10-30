<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Path;

use RuntimeException;

use function explode;
use function implode;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Validates package-relative autoload paths and keeps resolved paths inside the package root.
 *
 * @internal
 */
final class PackagePath
{
    /**
     * Returns the path without empty or `.` segments, or `.` when it denotes the package root.
     *
     * @param bool $allowGlob Whether the classmap `*` wildcard is permitted
     *
     * @throws RuntimeException When the path contains a null byte or a `..` segment, is absolute, or uses a wildcard
     *                          form that the input kind does not support
     */
    public static function normalizeRelativePath(string $path, bool $allowGlob = false): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('Autoload paths must not contain null bytes.');
        }

        $path = PathSyntax::normalizeSeparators($path);

        if (PathSyntax::isAbsolutePath($path)) {
            throw new RuntimeException(sprintf('Autoload path "%s" must be package-relative.', $path));
        }

        if ($allowGlob) {
            if (PathSyntax::hasUnsupportedWildcard($path)) {
                throw new RuntimeException('Classmap paths support only the "*" wildcard.');
            }
        } elseif (str_contains($path, '*')) {
            throw new RuntimeException('Only classmap paths may contain the "*" wildcard.');
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                throw new RuntimeException('Autoload paths must not contain ".." segments.');
            }

            $segments[] = $segment;
        }

        return [] === $segments ? '.' : implode('/', $segments);
    }

    /**
     * @throws RuntimeException When the canonical path is neither the package root nor below it
     */
    public static function assertContained(string $path, string $packageRoot): void
    {
        if ($path !== $packageRoot && !str_starts_with($path, $packageRoot . '/')) {
            throw new RuntimeException(sprintf(
                'Autoload path "%s" resolves outside package root "%s".',
                $path,
                $packageRoot,
            ));
        }
    }
}
