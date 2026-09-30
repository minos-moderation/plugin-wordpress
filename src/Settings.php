<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * The administrator's settings, read into known values.
 *
 * Everything but the two secrets lives in one option ({@see OPTION}). The API key and the
 * webhook secret live in options of their own, created non-autoloaded, and are shown back
 * only as a prefix ({@see prefix}). A stored value that is not one of the known choices
 * reads as the default, so a damaged option never produces a mode nobody chose.
 */
final class Settings
{
    /** The option with every setting but the secrets. */
    public const OPTION = 'minos_moderation_settings';

    /** The option with the gateway key (`wgb2b_…`). */
    public const KEY_OPTION = 'minos_moderation_api_key';

    /** The option with the webhook secret. */
    public const SECRET_OPTION = 'minos_moderation_webhook_secret';

    /** The production gateway. */
    public const DEFAULT_GATEWAY = 'https://gateway.wergiliusz.app';

    /** The profiles a forum key may ask for; the first is the default. */
    public const PROFILES = ['forum_adult', 'forum_teen'];

    /** A comment without a verdict is published (when WordPress itself would publish it). */
    public const FAIL_OPEN = 'fail-open';

    /** A comment without a verdict stays held for manual moderation. */
    public const FAIL_CLOSED = 'fail-closed';

    /** `ocenzurowane`: publish the masked text. */
    public const CENSORED_PUBLISH = 'publish';

    /** `ocenzurowane` or `zablokowane`: hold for manual moderation. */
    public const HOLD = 'hold';

    /** `zablokowane`: mark as spam. */
    public const BLOCKED_SPAM = 'spam';

    /** The receive timeout: the gateway's 15-minute TTL plus a grace period. */
    public const DEFAULT_TIMEOUT_MIN = 20;

    /** The shortest and longest receive timeout the settings accept, in minutes. */
    public const MIN_TIMEOUT_MIN = 5;
    public const MAX_TIMEOUT_MIN = 1440;

    /** @var Platform */
    private $wp;

    /**
     * @param Platform $wp The WordPress adapter.
     */
    public function __construct(Platform $wp)
    {
        $this->wp = $wp;
    }

    /**
     * The defaults of a fresh installation. The plugin starts switched off: it holds no
     * comment until the administrator has entered a key and a secret and switched it on.
     *
     * @return array<string,mixed> Setting name → value.
     */
    public static function defaults(): array
    {
        return [
            'enabled'        => false,
            'gateway_url'    => self::DEFAULT_GATEWAY,
            'profile'        => self::PROFILES[0],
            'failure_mode'   => self::FAIL_CLOSED,
            'timeout_min'    => self::DEFAULT_TIMEOUT_MIN,
            'censored_mode'  => self::CENSORED_PUBLISH,
            'blocked_mode'   => self::HOLD,
            'notice_enabled' => true,
            'notice_text'    => '',
        ];
    }

    /**
     * The default privacy notice shown under the comment form.
     *
     * @return string Polish plain text.
     */
    public static function defaultNotice(): string
    {
        return __('Komentarze są automatycznie sprawdzane przez usługę Minos. Aby ocenić komentarz, przesyłamy do niej wyłącznie jego treść — bez imienia, adresu e-mail i adresu IP. Treść nie jest tam przechowywana po zakończeniu oceny.', 'minos-moderation');
    }

    /**
     * Every setting, normalised.
     *
     * @return array<string,mixed> Setting name → a known value.
     */
    public function all(): array
    {
        $stored = $this->wp->option(self::OPTION, []);
        return self::normalise(is_array($stored) ? $stored : []);
    }

    /**
     * Reads raw values into known ones; anything unknown becomes the default.
     *
     * @param array<string,mixed> $raw The stored or submitted values.
     * @return array<string,mixed> Setting name → a known value.
     */
    public static function normalise(array $raw): array
    {
        $defaults = self::defaults();
        $pick = static function (string $name, array $allowed) use ($raw, $defaults) {
            $value = $raw[$name] ?? null;
            return in_array($value, $allowed, true) ? $value : $defaults[$name];
        };
        $url = is_string($raw['gateway_url'] ?? null) ? trim($raw['gateway_url']) : '';
        $timeout = $raw['timeout_min'] ?? null;
        $timeout = is_numeric($timeout) ? (int)$timeout : self::DEFAULT_TIMEOUT_MIN;

        return [
            'enabled'        => ($raw['enabled'] ?? false) === true,
            'gateway_url'    => self::validGatewayUrl($url) ? rtrim($url, '/') : self::DEFAULT_GATEWAY,
            'profile'        => $pick('profile', self::PROFILES),
            'failure_mode'   => $pick('failure_mode', [self::FAIL_OPEN, self::FAIL_CLOSED]),
            'timeout_min'    => max(self::MIN_TIMEOUT_MIN, min(self::MAX_TIMEOUT_MIN, $timeout)),
            'censored_mode'  => $pick('censored_mode', [self::CENSORED_PUBLISH, self::HOLD]),
            'blocked_mode'   => $pick('blocked_mode', [self::HOLD, self::BLOCKED_SPAM]),
            'notice_enabled' => ($raw['notice_enabled'] ?? true) === true,
            'notice_text'    => is_string($raw['notice_text'] ?? null) ? $raw['notice_text'] : '',
        ];
    }

    /**
     * Whether the plugin holds and submits comments: switched on, with a key and a secret.
     *
     * @return bool The answer.
     */
    public function isActive(): bool
    {
        return $this->all()['enabled'] && $this->apiKey() !== '' && $this->webhookSecret() !== '';
    }

    /**
     * The gateway key.
     *
     * @return string The key, or '' when none is set.
     */
    public function apiKey(): string
    {
        $key = $this->wp->option(self::KEY_OPTION, '');
        return is_string($key) ? $key : '';
    }

    /**
     * The webhook secret.
     *
     * @return string The secret, or '' when none is set.
     */
    public function webhookSecret(): string
    {
        $secret = $this->wp->option(self::SECRET_OPTION, '');
        return is_string($secret) ? $secret : '';
    }

    /**
     * The receive timeout.
     *
     * @return int Seconds.
     */
    public function timeoutS(): int
    {
        return (int)$this->all()['timeout_min'] * 60;
    }

    /**
     * The notice under the comment form.
     *
     * @return string Plain text; the default when the administrator left it empty.
     */
    public function noticeText(): string
    {
        $text = trim((string)$this->all()['notice_text']);
        return $text !== '' ? $text : self::defaultNotice();
    }

    /**
     * Whether a URL may be the gateway: `https`, or plain `http` to this machine only (the
     * mock gateway in development), with a host and no credentials.
     *
     * @param string $url The URL.
     * @return bool The answer.
     */
    public static function validGatewayUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        return $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /**
     * Whether a value looks like a gateway key.
     *
     * @param string $key The value.
     * @return bool The answer.
     */
    public static function validKey(string $key): bool
    {
        return (bool)preg_match('/^wgb2b_[A-Za-z0-9_-]{8,200}$/', $key);
    }

    /**
     * Whether a value may be a webhook secret: 16–256 visible ASCII characters.
     *
     * @param string $secret The value.
     * @return bool The answer.
     */
    public static function validSecret(string $secret): bool
    {
        return (bool)preg_match('/^[\x21-\x7e]{16,256}$/', $secret);
    }

    /**
     * What a page may show of a secret: its first characters.
     *
     * @param string $secret The key or the secret.
     * @param int    $length How many characters to show.
     * @return string The prefix and an ellipsis, or '' for an empty value.
     */
    public static function prefix(string $secret, int $length): string
    {
        if ($secret === '') {
            return '';
        }
        return substr($secret, 0, min($length, intdiv(strlen($secret), 2))) . '…';
    }
}
