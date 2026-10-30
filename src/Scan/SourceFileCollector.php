<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Scan;

use RuntimeException;
use SuperKernel\ComposerSourceReferencePlugin\Autoload\ClassmapExclusion;
use SuperKernel\ComposerSourceReferencePlugin\Path\PackagePath;
use SuperKernel\ComposerSourceReferencePlugin\Path\PathSyntax;

use function is_file;
use function is_string;
use function ltrim;
use function realpath;
use function strlen;
use function substr;

/**
 * Records one canonical source file under its package-relative path unless an exclusion pattern matches it.
 *
 * @internal
 */
final class SourceFileCollector
{
    /**
     * Ignores a path that does not resolve to a regular file.
     *
     * @param list<string>                    $exclusions Normalized classmap exclusion patterns
     * @param array<string, non-empty-string> $files      Package-relative path to canonical file, updated in place
     *
     * @throws RuntimeException When the file resolves outside the package root
     */
    public static function collect(
        string $packageRoot,
        string $file,
        array  $exclusions,
        array &$files,
    ): void
    {
        $canonical = realpath($file);

        if (!is_string($canonical) || !is_file($canonical)) {
            return;
        }

        $canonical = PathSyntax::normalizeSeparators($canonical);
        PackagePath::assertContained($canonical, $packageRoot);
        $relativePath = ltrim(substr($canonical, strlen($packageRoot)), '/');

        if (ClassmapExclusion::isExcluded($relativePath, $exclusions)) {
            return;
        }

        $files[$relativePath] = $canonical;
    }
}
