<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Scan;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SuperKernel\ComposerSourceReferencePlugin\Path\PackagePath;
use SuperKernel\ComposerSourceReferencePlugin\Path\PathSyntax;
use Throwable;

use function glob;
use function is_dir;
use function is_file;
use function realpath;
use function sort;
use function sprintf;
use function str_contains;

use const GLOB_NOSORT;
use const SORT_STRING;

/**
 * Collects the source files reached by one PSR or classmap autoload path, including sorted classmap wildcard matches.
 *
 * @internal
 */
final class InclusionCollector
{
    /**
     * Skips paths that do not exist.
     *
     * @param bool                            $allowGlob  Whether the path is a classmap entry that may use `*`
     * @param list<string>                    $exclusions Normalized classmap exclusion patterns
     * @param array<string, non-empty-string> $files      Package-relative path to canonical file, updated in place
     *
     * @throws RuntimeException When the path is unsafe, a classmap wildcard cannot be expanded, a match resolves
     *                          outside the package root, or a directory scan fails; a scan failure keeps its cause as
     *                          the previous exception
     */
    public static function collect(
        string $packageRoot,
        string $path,
        bool   $allowGlob,
        array  $exclusions,
        array &$files,
    ): void
    {
        $relativePath = PackagePath::normalizeRelativePath($path, $allowGlob);

        if ($allowGlob && str_contains($relativePath, '*')) {
            $candidates = glob($packageRoot . '/' . $relativePath, GLOB_NOSORT);

            if (false === $candidates) {
                throw new RuntimeException(sprintf('Unable to expand classmap pattern "%s".', $relativePath));
            }

            sort($candidates, SORT_STRING);
        } else {
            $candidates = [
                '.' === $relativePath ? $packageRoot : $packageRoot . '/' . $relativePath,
            ];
        }

        foreach ($candidates as $candidate) {
            $canonical = realpath($candidate);

            if (false === $canonical) {
                continue;
            }

            $canonical = PathSyntax::normalizeSeparators($canonical);
            PackagePath::assertContained($canonical, $packageRoot);

            if (is_dir($canonical)) {
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($canonical, FilesystemIterator::SKIP_DOTS),
                    );

                    foreach ($iterator as $entry) {
                        if ($entry->isFile()) {
                            SourceFileCollector::collect($packageRoot, $entry->getPathname(), $exclusions, $files);
                        }
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        sprintf('Unable to scan source directory "%s".', $canonical),
                        previous: $exception,
                    );
                }

                continue;
            }

            if (is_file($canonical)) {
                SourceFileCollector::collect($packageRoot, $canonical, $exclusions, $files);
            }
        }
    }
}
