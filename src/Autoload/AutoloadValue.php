<?php

declare(strict_types=1);

namespace SuperKernel\ComposerSourceReferencePlugin\Autoload;

use RuntimeException;

use function array_is_list;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Validates Composer autoload values that must be lists of strings.
 *
 * @internal
 */
final class AutoloadValue
{
    /**
     * @param string $context     Configuration label used in failure messages
     * @param bool   $allowString Whether a single string is accepted as a one-entry list
     *
     * @return list<string>
     *
     * @throws RuntimeException When the value is not a list of strings and not an accepted single string
     */
    public static function stringList(mixed $value, string $context, bool $allowString = false): array
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
}
