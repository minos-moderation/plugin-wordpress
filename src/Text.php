<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * The comment as the gateway sees it: plain text, and the way back to safe HTML.
 *
 * Pure functions; `mb_*` are available on every WordPress site (WordPress ships fallbacks
 * for `mb_substr` and `mb_strlen`).
 */
final class Text
{
    /**
     * The most the gateway assesses of one comment, in characters. A longer comment is
     * assessed on its first 3000 characters.
     */
    public const MAX_CHARS = 3000;

    /** Unicode whitespace, for trimming. */
    private const SPACE = '[\s\p{Z}]';

    /**
     * The comment's plain text: tags removed (with the content of `script` and `style`),
     * entities decoded, surrounding whitespace trimmed.
     *
     * @param string $html The stored comment content.
     * @return string The text, or '' when the content is not valid UTF-8.
     */
    public static function plain(string $html): string
    {
        if (preg_match('//u', $html) !== 1) {
            return '';
        }
        $text = (string)preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::trim($text);
    }

    /**
     * What is sent: the first {@see MAX_CHARS} characters of the plain text, trimmed.
     *
     * @param string $plain The plain text.
     * @return string The text to assess.
     */
    public static function cut(string $plain): string
    {
        return self::trim(mb_substr($plain, 0, self::MAX_CHARS, 'UTF-8'));
    }

    /**
     * Plain text as HTML that shows exactly that text.
     *
     * @param string $text The text.
     * @return string Escaped HTML.
     */
    public static function asHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * How many distinct `http(s)` links a comment carries, in its markup or its text.
     *
     * @param string $html The stored comment content.
     * @return int The count, at most 100000 (the contract's ceiling).
     */
    public static function linkCount(string $html): int
    {
        preg_match_all('#https?://[^\s"\'<>]+#i', $html, $matches);
        $links = [];
        foreach ($matches[0] as $url) {
            $links[strtolower(rtrim($url, '.,;:!?)'))] = true;
        }
        return min(100000, count($links));
    }

    /**
     * Removes Unicode whitespace from both ends.
     *
     * @param string $text Valid UTF-8.
     * @return string The trimmed text.
     */
    private static function trim(string $text): string
    {
        return (string)preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $text);
    }
}
