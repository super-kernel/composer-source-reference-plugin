<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Scan;

use RuntimeException;
use SuperKernel\ComposerSourceReferencePlugin\Autoload\AutoloadValue;
use SuperKernel\ComposerSourceReferencePlugin\Autoload\ClassmapExclusion;
use SuperKernel\ComposerSourceReferencePlugin\Path\PackagePath;

use function array_keys;
use function is_array;
use function is_file;
use function realpath;
use function sort;
use function sprintf;

use const SORT_STRING;

/**
 * Collects the files reached by a package's production `psr-0`, `psr-4`, `classmap`, and `files` autoload rules.
 *
 * @internal
 */
final class ProductionSourceCollector
{
    /**
     * Applies `exclude-from-classmap` to PSR and classmap inputs but not to explicit `files` entries.
     *
     * @param array<string, mixed> $autoload Production autoload configuration of the package
     *
     * @return array<string, non-empty-string> Package-relative path to canonical file, sorted by path
     *
     * @throws RuntimeException When the configuration is malformed, a path is unsafe or resolves outside the package
     *                          root, an explicit file is not a regular file, or a source directory cannot be scanned
     */
    public static function collect(string $packageRoot, array $autoload): array
    {
        $files = [];
        $exclusions = ClassmapExclusion::patterns($autoload['exclude-from-classmap'] ?? []);

        foreach ([
            'psr-0',
            'psr-4',
        ] as $field) {
            if (!isset($autoload[$field])) {
                continue;
            }

            if (!is_array($autoload[$field])) {
                throw new RuntimeException(sprintf('autoload.%s must be an array.', $field));
            }

            foreach ($autoload[$field] as $paths) {
                foreach (AutoloadValue::stringList($paths, sprintf('autoload.%s mapping', $field), true) as $path) {
                    InclusionCollector::collect($packageRoot, $path, false, $exclusions, $files);
                }
            }
        }

        foreach (AutoloadValue::stringList($autoload['classmap'] ?? [], 'autoload.classmap') as $path) {
            InclusionCollector::collect($packageRoot, $path, true, $exclusions, $files);
        }

        foreach (AutoloadValue::stringList($autoload['files'] ?? [], 'autoload.files') as $path) {
            $relativePath = PackagePath::normalizeRelativePath($path);
            $candidate = '.' === $relativePath
                ? $packageRoot
                : $packageRoot . '/' . $relativePath;
            $canonical = realpath($candidate);

            if (false === $canonical) {
                continue;
            }

            if (!is_file($canonical)) {
                throw new RuntimeException(sprintf('Autoload file "%s" is not a regular file.', $relativePath));
            }

            SourceFileCollector::collect($packageRoot, $canonical, [], $files);
        }

        $relativePaths = array_keys($files);
        sort($relativePaths, SORT_STRING);
        $sorted = [];

        foreach ($relativePaths as $relativePath) {
            $sorted[$relativePath] = $files[$relativePath];
        }

        return $sorted;
    }
}
