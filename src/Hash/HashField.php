<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Hash;

use HashContext;

use function hash_update;
use function strlen;

/**
 * Appends one length-prefixed field to the source-reference hash input.
 *
 * @internal
 */
final class HashField
{
    public static function update(HashContext $context, string $value): void
    {
        hash_update($context, strlen($value) . ':');
        hash_update($context, $value);
    }
}
