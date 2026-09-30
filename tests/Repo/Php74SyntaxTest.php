<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The plugin promises PHP 7.4. CI runs the suite on 7.4, which has the final word; this
 * test catches the obvious PHP 8 syntax on any PHP, from the tokens of every plugin and test
 * file: `match`, `?->`, attributes, enums, `readonly`, non-capturing `catch`, and calls to
 * the string and array functions PHP 8 added.
 */
final class Php74SyntaxTest extends TestCase
{
    /** Functions PHP 8 added that a 7.4 host does not have. */
    private const PHP8_FUNCTIONS = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list',
        'get_debug_type', 'fdiv', 'get_resource_id'];

    /** Token names that exist only for PHP 8 syntax. */
    private const PHP8_TOKENS = ['T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ATTRIBUTE', 'T_ENUM', 'T_READONLY'];

    public function testNoFileUsesPhp8Syntax(): void
    {
        $files = self::files();
        self::assertGreaterThanOrEqual(15, count($files), 'the scan must find the plugin\'s files: it is broken');
        $forbidden = [];
        foreach (self::PHP8_TOKENS as $name) {
            if (defined($name)) {
                $forbidden[constant($name)] = $name;
            }
        }
        foreach ($files as $file) {
            $tokens = array_values(array_filter(token_get_all((string)file_get_contents($file)), static function ($token): bool {
                return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            }));
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                self::assertArrayNotHasKey($token[0], $forbidden, "{$file}:{$token[2]}: PHP 8 syntax");
                if ($token[0] === T_STRING && in_array(strtolower($token[1]), self::PHP8_FUNCTIONS, true)
                    && ($tokens[$i + 1] ?? null) === '(') {
                    self::fail("{$file}:{$token[2]}: {$token[1]}() needs PHP 8");
                }
                if ($token[0] === T_CATCH) {
                    $closing = array_search(')', array_slice($tokens, $i, 8, true), true);
                    $variables = array_filter(array_slice($tokens, $i, (int)$closing - $i + 1), static function ($t): bool {
                        return is_array($t) && $t[0] === T_VARIABLE;
                    });
                    self::assertNotEmpty($variables, "{$file}:{$token[2]}: a catch without a variable needs PHP 8");
                }
            }
        }
    }

    /**
     * The plugin's PHP files: the entry points, `src/`, `tests/` and `bin/`.
     *
     * @return array<int,string>
     */
    private static function files(): array
    {
        $root = __DIR__ . '/../..';
        $files = [$root . '/minos-moderation.php', $root . '/uninstall.php'];
        foreach (['src', 'tests', 'bin'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }
}
