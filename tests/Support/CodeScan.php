<?php

declare(strict_types=1);

namespace BitFlag\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the plain function calls and superglobals a source tree uses, by tokens rather than by text.
 */
final class CodeScan
{
    /**
     * @param  list<string>  $directories
     * @return list<string> every PHP file in the directories
     */
    public static function files(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (str_ends_with($file->getPathname(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> names of functions called without an object or class, in lower case, plus variables
     */
    public static function calls(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $calls = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_VARIABLE) {
                $calls[] = $token[1];

                continue;
            }
            if (! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $next = self::next($tokens, $i);
            $previous = self::previous($tokens, $i);
            if ($next === '(' && ! in_array($previous, ['->', '?->', '::', 'function', 'new'], true)) {
                $calls[] = strtolower(ltrim($token[1], '\\'));
            }
        }

        return $calls;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function next(array $tokens, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return '';
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function previous(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? strtolower($token[1]) : $token;
        }

        return '';
    }
}
