<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SuperKernel\ComposerSourceReferencePlugin\SourceReferenceGenerator;
use Throwable;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

final class SourceReferenceGeneratorTest extends TestCase
{
    private string $workspace;

    /** @throws Throwable When fixture setup or a supplied test callback fails */
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/super-kernel-source-reference-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->workspace, 0o777, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->workspace)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->workspace);
    }

    public function testReferenceIsStableAcrossPackageLocations(): void
    {
        $this->write('first/src/Example.php', '<?php final class Example {}');
        $this->write('second/src/Example.php', '<?php final class Example {}');
        $generator = new SourceReferenceGenerator($this->workspace);
        $autoload = [
            'psr-4' => [
                'Example\\' => 'src/',
            ],
        ];

        $first = $generator->generate('super-kernel/example', 'first', 'config-reference', $autoload);
        $second = $generator->generate('super-kernel/example', 'second', 'config-reference', $autoload);

        $this->assertMatchesRegularExpression('/\Ask-source-v1-[a-f0-9]{64}\z/', $first);
        $this->assertSame($first, $second);
    }

    public function testReferenceDetectsAddedChangedAndDeletedSourceFiles(): void
    {
        $this->write('package/src/First.php', '<?php final class First {}');
        $generator = new SourceReferenceGenerator($this->workspace);
        $autoload = [
            'psr-4' => [
                'Example\\' => 'src/',
            ],
        ];
        $original = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);

        $this->write('package/src/Second.php', '<?php final class Second {}');
        $withAddition = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);
        $this->assertNotSame($original, $withAddition);

        unlink($this->workspace . '/package/src/Second.php');
        $afterDeletion = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);
        $this->assertSame($original, $afterDeletion);

        $this->write('package/src/First.php', '<?php final class First { public const int VALUE = 1; }');
        $afterChange = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);
        $this->assertNotSame($original, $afterChange);
    }

    public function testReferencePreservesThePathRepositoryConfigReference(): void
    {
        $this->write('package/src/Example.php', '<?php final class Example {}');
        $generator = new SourceReferenceGenerator($this->workspace);
        $autoload = [
            'psr-4' => [
                'Example\\' => 'src/',
            ],
        ];

        $first = $generator->generate('super-kernel/example', 'package', 'first-config', $autoload);
        $second = $generator->generate('super-kernel/example', 'package', 'second-config', $autoload);

        $this->assertNotSame($first, $second);
    }

    public function testReferenceIgnoresDevelopmentAndExcludedFiles(): void
    {
        $this->write('package/src/Included.php', '<?php final class Included {}');
        $this->write('package/src/Excluded/Ignored.php', '<?php final class Ignored {}');
        $this->write('package/tests/Development.php', '<?php final class Development {}');
        $generator = new SourceReferenceGenerator($this->workspace);
        $autoload = [
            'psr-4' => [
                'Example\\' => 'src/',
            ],
            'exclude-from-classmap' => [
                '/src/Excluded/',
            ],
        ];
        $original = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);

        $this->write('package/src/Excluded/Ignored.php', '<?php final class Ignored { public const int VALUE = 1; }');
        $this->write('package/tests/Development.php', '<?php final class Development { public const int VALUE = 1; }');
        $ignoredChanges = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);
        $this->assertSame($original, $ignoredChanges);

        $this->write('package/src/Included.php', '<?php final class Included { public const int VALUE = 1; }');
        $includedChange = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);
        $this->assertNotSame($original, $includedChange);
    }

    public function testReferenceIncludesExplicitAutoloadFiles(): void
    {
        $this->write('package/src/functions.php', '<?php function example(): void {}');
        $generator = new SourceReferenceGenerator($this->workspace);
        $autoload = [
            'files' => [
                'src/functions.php',
            ],
        ];
        $original = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);

        $this->write('package/src/functions.php', '<?php function example(): int { return 1; }');
        $changed = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);

        $this->assertNotSame($original, $changed);
    }

    public function testAlreadyGeneratedReferenceIsIdempotent(): void
    {
        $this->write('package/src/Example.php', '<?php final class Example {}');
        $generator = new SourceReferenceGenerator($this->workspace);
        $autoload = [
            'classmap' => [
                'src/',
            ],
        ];
        $reference = $generator->generate('super-kernel/example', 'package', 'config-reference', $autoload);

        $this->assertSame(
            $reference,
            $generator->generate('super-kernel/example', 'package', $reference, $autoload),
        );
    }

    public function testReferenceRejectsAFileSymlinkOutsideThePackage(): void
    {
        $this->write('outside.php', '<?php final class Outside {}');
        $this->write('package/src/Inside.php', '<?php final class Inside {}');

        if (!@symlink($this->workspace . '/outside.php', $this->workspace . '/package/src/Escape.php')) {
            $this->markTestSkipped('The test filesystem does not permit symbolic links.');
        }

        $generator = new SourceReferenceGenerator($this->workspace);

        try {
            $generator->generate('super-kernel/example', 'package', 'config-reference', [
                'psr-4' => [
                    'Example\\' => 'src/',
                ],
            ]);
            self::fail('A source symlink outside the package must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unable to scan source directory', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertStringContainsString(
                'resolves outside package root',
                $exception->getPrevious()->getMessage(),
            );
        }
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->workspace . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0o777, true));
        }

        $this->assertNotFalse(file_put_contents($path, $contents));
    }
}
