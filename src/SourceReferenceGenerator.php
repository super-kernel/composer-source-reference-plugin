<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin;

use FilesystemIterator;
use HashContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

use function array_is_list;
use function array_keys;
use function count;
use function explode;
use function glob;
use function hash_file;
use function hash_final;
use function hash_init;
use function hash_update;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_quote;
use function realpath;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function substr;
use function trim;

use const GLOB_NOSORT;
use const SORT_STRING;

/**
 * Builds stable path-package references from Composer production-autoload inputs.
 */
final readonly class SourceReferenceGenerator
{
    private const string REFERENCE_PREFIX = 'sk-source-v1-';

    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $canonicalRoot = realpath($projectRoot);

        if (!is_string($canonicalRoot) || !is_dir($canonicalRoot)) {
            throw new RuntimeException(sprintf('Composer project root "%s" is not a directory.', $projectRoot));
        }

        $this->projectRoot = self::normalizeSeparators($canonicalRoot);
    }

    /**
     * @param array<string, mixed> $autoload
     */
    public function generate(
        string $packageName,
        string $distUrl,
        string $configReference,
        array  $autoload,
    ): string
    {
        if (1 === preg_match('/\Ask-source-v1-[a-f0-9]{64}\z/', $configReference)) {
            return $configReference;
        }

        $packageRoot = $this->packageRoot($distUrl);
        $files = $this->autoloadFiles($packageRoot, $autoload);
        $context = hash_init('sha256');

        self::updateField($context, 'super-kernel-source-reference-v1');
        self::updateField($context, $packageName);
        self::updateField($context, $configReference);
        self::updateField($context, (string) count($files));

        foreach ($files as $relativePath => $file) {
            $fileHash = hash_file('sha256', $file, true);

            if (!is_string($fileHash)) {
                throw new RuntimeException(sprintf('Unable to hash source file "%s".', $file));
            }

            self::updateField($context, $relativePath);
            self::updateField($context, $fileHash);
        }

        return self::REFERENCE_PREFIX . hash_final($context);
    }

    private function packageRoot(string $distUrl): string
    {
        if (str_contains($distUrl, "\0")) {
            throw new RuntimeException('Path package URL must not contain a null byte.');
        }

        $candidate = self::isAbsolutePath($distUrl)
            ? $distUrl
            : $this->projectRoot . '/' . $distUrl;
        $canonical = realpath($candidate);

        if (!is_string($canonical) || !is_dir($canonical)) {
            throw new RuntimeException(sprintf('Path package directory "%s" is unavailable.', $distUrl));
        }

        return self::normalizeSeparators($canonical);
    }

    /**
     * @param array<string, mixed> $autoload
     *
     * @return array<string, non-empty-string> Package-relative path to canonical file
     */
    private function autoloadFiles(string $packageRoot, array $autoload): array
    {
        $files = [];
        $exclusions = $this->exclusions($autoload['exclude-from-classmap'] ?? []);

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
                foreach ($this->stringList($paths, sprintf('autoload.%s mapping', $field), true) as $path) {
                    $this->collectInclusion($packageRoot, $path, false, $exclusions, $files);
                }
            }
        }

        foreach ($this->stringList($autoload['classmap'] ?? [], 'autoload.classmap') as $path) {
            $this->collectInclusion($packageRoot, $path, true, $exclusions, $files);
        }

        foreach ($this->stringList($autoload['files'] ?? [], 'autoload.files') as $path) {
            $this->collectExplicitFile($packageRoot, $path, $files);
        }

        $relativePaths = array_keys($files);
        sort($relativePaths, SORT_STRING);
        $sorted = [];

        foreach ($relativePaths as $relativePath) {
            $sorted[$relativePath] = $files[$relativePath];
        }

        return $sorted;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $context, bool $allowString = false): array
    {
        if ($allowString && is_string($value)) {
            return [
                $value,
            ];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('%s must be a string list.', $context));
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new RuntimeException(sprintf('%s must contain only strings.', $context));
            }
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function exclusions(mixed $value): array
    {
        $patterns = [];

        foreach ($this->stringList($value, 'autoload.exclude-from-classmap') as $pattern) {
            if (str_contains($pattern, "\0")) {
                throw new RuntimeException('Classmap exclusion patterns must not contain null bytes.');
            }

            $pattern = self::normalizeSeparators($pattern);
            $relativePattern = ltrim($pattern, '/');

            if (
                str_starts_with($pattern, '//')
                || self::isAbsolutePath($relativePattern)
                || false !== strpbrk($pattern, '?[]{}')
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
     * @param list<string>                    $exclusions
     * @param array<string, non-empty-string> $files
     */
    private function collectInclusion(
        string $packageRoot,
        string $path,
        bool   $allowGlob,
        array  $exclusions,
        array &$files,
    ): void
    {
        $relativePath = $this->normalizeRelativePath($path, $allowGlob);

        if ($allowGlob && str_contains($relativePath, '*')) {
            $matches = glob($packageRoot . '/' . $relativePath, GLOB_NOSORT);

            if (false === $matches) {
                throw new RuntimeException(sprintf('Unable to expand classmap pattern "%s".', $relativePath));
            }

            sort($matches, SORT_STRING);

            foreach ($matches as $match) {
                $this->collectResolved($packageRoot, $match, $exclusions, $files);
            }

            return;
        }

        $candidate = '.' === $relativePath
            ? $packageRoot
            : $packageRoot . '/' . $relativePath;

        $this->collectResolved($packageRoot, $candidate, $exclusions, $files);
    }

    /** @param array<string, non-empty-string> $files */
    private function collectExplicitFile(string $packageRoot, string $path, array &$files): void
    {
        $relativePath = $this->normalizeRelativePath($path);
        $candidate = '.' === $relativePath
            ? $packageRoot
            : $packageRoot . '/' . $relativePath;
        $canonical = realpath($candidate);

        if (false === $canonical) {
            return;
        }

        if (!is_file($canonical)) {
            throw new RuntimeException(sprintf('Autoload file "%s" is not a regular file.', $relativePath));
        }

        $this->collectFile($packageRoot, $canonical, [], $files);
    }

    /**
     * @param list<string>                    $exclusions
     * @param array<string, non-empty-string> $files
     */
    private function collectResolved(
        string $packageRoot,
        string $candidate,
        array  $exclusions,
        array &$files,
    ): void
    {
        $canonical = realpath($candidate);

        if (false === $canonical) {
            return;
        }

        $canonical = self::normalizeSeparators($canonical);
        $this->assertContained($canonical, $packageRoot);

        if (is_dir($canonical)) {
            $this->collectDirectory($packageRoot, $canonical, $exclusions, $files);

            return;
        }

        if (is_file($canonical)) {
            $this->collectFile($packageRoot, $canonical, $exclusions, $files);
        }
    }

    /**
     * @param list<string>                    $exclusions
     * @param array<string, non-empty-string> $files
     */
    private function collectDirectory(
        string $packageRoot,
        string $directory,
        array  $exclusions,
        array &$files,
    ): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    $this->collectFile($packageRoot, $entry->getPathname(), $exclusions, $files);
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Unable to scan source directory "%s".', $directory),
                previous: $exception,
            );
        }
    }

    /**
     * @param list<string>                    $exclusions
     * @param array<string, non-empty-string> $files
     */
    private function collectFile(
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

        $canonical = self::normalizeSeparators($canonical);
        $this->assertContained($canonical, $packageRoot);
        $relativePath = ltrim(substr($canonical, strlen($packageRoot)), '/');

        if ($this->isExcluded($relativePath, $exclusions)) {
            return;
        }

        $files[$relativePath] = $canonical;
    }

    /** @param list<string> $patterns */
    private function isExcluded(string $relativePath, array $patterns): bool
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

    private function normalizeRelativePath(string $path, bool $allowGlob = false): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('Autoload paths must not contain null bytes.');
        }

        $path = self::normalizeSeparators($path);

        if (self::isAbsolutePath($path)) {
            throw new RuntimeException(sprintf('Autoload path "%s" must be package-relative.', $path));
        }

        if ($allowGlob) {
            if (false !== strpbrk($path, '?[]{}')) {
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

    private function assertContained(string $path, string $packageRoot): void
    {
        if ($path !== $packageRoot && !str_starts_with($path, $packageRoot . '/')) {
            throw new RuntimeException(sprintf(
                'Autoload path "%s" resolves outside package root "%s".',
                $path,
                $packageRoot,
            ));
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
    }

    private static function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function updateField(HashContext $context, string $value): void
    {
        hash_update($context, strlen($value) . ':');
        hash_update($context, $value);
    }
}
