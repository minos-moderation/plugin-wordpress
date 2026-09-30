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

    /** The most `meta.link_domains` the contract takes. */
    public const MAX_LINK_DOMAINS = 10;

    /** Unicode whitespace, for trimming. */
    private const SPACE = '[\s\p{Z}]';

    /**
     * An opening tag whose quoted values hold no `>` (kses, which filters every comment the
     * plugin moderates, ends a tag at its first `>`). Possessive, and stopping at the next
     * `<` outside quotes, so a comment full of unclosed tags costs linear time. A tag this
     * does not match is left to `strip_tags`.
     */
    private const TAG = '/<[a-z][^<>"\']*+(?:(?:"[^">]*+"|\'[^\'>]*+\')[^<>"\']*+)*+>/i';

    /** A `title` or `alt` attribute inside a tag, quoted or not. */
    private const TEXT_ATTRIBUTE = '/[\s"\'](?:title|alt)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i';

    /** A link, in the markup or in the text. */
    private const LINK = '#https?://[^\s"\'<>]+#i';

    /**
     * Second-level labels that are public suffixes under a country code (`co.uk`,
     * `com.pl`…): a short list in place of the Public Suffix List, which is too large to
     * ship. A miss merges two sites into one domain or keeps a subdomain apart; either way
     * only a domain from a link travels.
     */
    private const SECOND_LEVEL = ['ac', 'co', 'com', 'edu', 'gov', 'mil', 'net', 'nom', 'org', 'sch',
        'info', 'biz', 'ltd', 'plc', 'gob', 'or', 'ne', 'go', 'gv', 'waw', 'priv', 'gmina', 'powiat',
        'sklep', 'shop', 'media', 'agro', 'auto'];

    /** A bare domain, as the gateway accepts it in `meta.link_domains`. */
    private const DOMAIN = '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * The comment's plain text: tags removed (with the content of `script` and `style`),
     * the text of `title` and `alt` attributes kept in their place, entities decoded,
     * surrounding whitespace trimmed.
     *
     * Attribute text is assessed like any other text: a reader sees it (a tooltip, an
     * image's alternative), so dropping it would let words past the assessment.
     *
     * @param string $html The stored comment content.
     * @return string The text, or '' when the content is not valid UTF-8.
     */
    public static function plain(string $html): string
    {
        if (preg_match('//u', $html) !== 1) {
            return '';
        }
        // A regex that gives up (a PCRE limit) returns null: then the step is skipped and
        // more text is assessed, never less — an empty text would get the failure mode.
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html) ?? $html;
        $text = preg_replace_callback(self::TAG, [self::class, 'attributeText'], $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::trim($text);
    }

    /**
     * Whether a plain text is longer than the gateway assesses, so what is sent is only its
     * beginning.
     *
     * @param string $plain The plain text.
     * @return bool The answer.
     */
    public static function isCut(string $plain): bool
    {
        return mb_strlen($plain, 'UTF-8') > self::MAX_CHARS;
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
        return min(100000, count(self::links($html)));
    }

    /**
     * The registrable domains of a comment's `http(s)` links, for `meta.link_domains`: in
     * order of appearance, without repeats, at most {@see MAX_LINK_DOMAINS}. IP addresses,
     * single-label hosts and anything the gateway would not take as a bare domain are left
     * out. Only the content's links count, never the author's website.
     *
     * @param string $html The stored comment content.
     * @return array<int,string> The domains, lower case.
     */
    public static function linkDomains(string $html): array
    {
        $domains = [];
        foreach (self::links($html) as $url) {
            $domain = self::registrableDomain((string)parse_url($url, PHP_URL_HOST));
            if ($domain !== null) {
                $domains[$domain] = true;
                if (count($domains) === self::MAX_LINK_DOMAINS) {
                    break;
                }
            }
        }
        return array_keys($domains);
    }

    /**
     * The distinct links of a comment, trailing punctuation removed.
     *
     * @param string $html The stored comment content.
     * @return array<int,string> The links, lower case, in order of appearance.
     */
    private static function links(string $html): array
    {
        preg_match_all(self::LINK, $html, $matches);
        $links = [];
        foreach ($matches[0] as $url) {
            $links[strtolower(rtrim($url, '.,;:!?)'))] = true;
        }
        return array_map('strval', array_keys($links));
    }

    /**
     * A host's registrable domain: its last two labels, or three under a country code
     * whose second level is a public suffix ({@see SECOND_LEVEL}).
     *
     * @param string $host A URL's host.
     * @return string|null The domain, or null for an IP address or anything not a domain.
     */
    private static function registrableDomain(string $host): ?string
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                return null;
            }
            $host = strtolower($ascii);
        }
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count < 2) {
            return null;
        }
        $take = $count >= 3 && strlen($labels[$count - 1]) === 2
            && in_array($labels[$count - 2], self::SECOND_LEVEL, true) ? 3 : 2;
        $domain = implode('.', array_slice($labels, -$take));
        return preg_match(self::DOMAIN, $domain) === 1 ? $domain : null;
    }

    /**
     * {@see plain}'s callback: a tag with `title` or `alt` text becomes that text between
     * spaces; any other tag is left for `strip_tags`.
     *
     * @param array<int,string> $match The tag.
     * @return string The replacement.
     */
    private static function attributeText(array $match): string
    {
        preg_match_all(self::TEXT_ATTRIBUTE, $match[0], $attributes, PREG_SET_ORDER);
        $texts = [];
        foreach ($attributes as $attribute) {
            $value = trim(($attribute[1] ?? '') . ($attribute[2] ?? '') . ($attribute[3] ?? ''));
            if ($value !== '') {
                // Still HTML until the entities are decoded: a `<` in it must not open a tag.
                $texts[] = str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
            }
        }
        return $texts === [] ? $match[0] : ' ' . implode(' ', $texts) . ' ';
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
