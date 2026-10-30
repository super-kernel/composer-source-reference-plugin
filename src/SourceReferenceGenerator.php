<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin;

use RuntimeException;
use SuperKernel\ComposerSourceReferencePlugin\Hash\HashField;
use SuperKernel\ComposerSourceReferencePlugin\Path\PathSyntax;
use SuperKernel\ComposerSourceReferencePlugin\Scan\ProductionSourceCollector;

use function count;
use function hash_file;
use function hash_final;
use function hash_init;
use function is_dir;
use function is_string;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;

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

        $this->projectRoot = PathSyntax::normalizeSeparators($canonicalRoot);
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

        if (str_contains($distUrl, "\0")) {
            throw new RuntimeException('Path package URL must not contain a null byte.');
        }

        $candidate = PathSyntax::isAbsolutePath($distUrl)
            ? $distUrl
            : $this->projectRoot . '/' . $distUrl;
        $canonical = realpath($candidate);

        if (!is_string($canonical) || !is_dir($canonical)) {
            throw new RuntimeException(sprintf('Path package directory "%s" is unavailable.', $distUrl));
        }

        $packageRoot = PathSyntax::normalizeSeparators($canonical);
        $files = ProductionSourceCollector::collect($packageRoot, $autoload);
        $context = hash_init('sha256');

        HashField::update($context, 'super-kernel-source-reference-v1');
        HashField::update($context, $packageName);
        HashField::update($context, $configReference);
        HashField::update($context, (string) count($files));

        foreach ($files as $relativePath => $file) {
            $fileHash = hash_file('sha256', $file, true);

            if (!is_string($fileHash)) {
                throw new RuntimeException(sprintf('Unable to hash source file "%s".', $file));
            }

            HashField::update($context, $relativePath);
            HashField::update($context, $fileHash);
        }

        return self::REFERENCE_PREFIX . hash_final($context);
    }
}
