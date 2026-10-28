<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * What went wrong between the forum and the gateway, for the administrator.
 *
 * An entry holds the moment, the HTTP status, the gateway's error code (or the plugin's
 * own: a masked text it could not store, a step that failed with an exception) and the
 * comment's id — never the comment's content, the key, the secret or an exception's
 * message. The log keeps the last
 * {@see MAX_ENTRIES}. A configuration error (a refusal that retrying will not fix) is also
 * kept on its own, for the admin notice, until the gateway accepts a comment again; so is
 * the moment until which the gateway asked to wait (`429`, `408`, `5xx`, a `2xx` other
 * than `202`, no answer), at
 * most a day ahead, which every submission keeps ({@see sendingPause}) and the
 * administrator is shown ({@see pausedUntil}), until that moment passes, a comment is
 * accepted, or the administrator changes the key, the gateway's address or the switch.
 */
final class Log
{
    /** The option with the entries (not autoloaded). */
    public const OPTION = 'minos_moderation_log';

    /** The option with the last configuration error (not autoloaded). */
    public const CONFIG_ERROR_OPTION = 'minos_moderation_config_error';

    /**
     * The option with the moment until which the gateway asked to wait, its kind and code
     * (not autoloaded).
     */
    public const PAUSE_OPTION = 'minos_moderation_paused';

    /**
     * {@see pause} kinds: the key's daily limit, the gateway's overload, and any other
     * answer that asks to wait; each has its own message for the administrator.
     */
    public const PAUSE_DAILY_LIMIT = 'limit_dobowy';
    public const PAUSE_OVERLOAD = 'przeciazenie';
    public const PAUSE_WAIT = 'ponowienie';

    /**
     * The longest pause kept, in seconds: a day, the longest receive timeout. The gateway's
     * daily limit renews within it.
     */
    public const MAX_PAUSE_S = 86400;

    /** The refusal codes behind each kind (the contract's 429 codes). */
    private const PAUSE_CODES = [
        'limit_dobowy_klucza' => self::PAUSE_DAILY_LIMIT,
        'limit_globalny_b2b'  => self::PAUSE_OVERLOAD,
        'kolejka_pelna'       => self::PAUSE_OVERLOAD,
    ];

    /** How many entries the log keeps. */
    public const MAX_ENTRIES = 50;

    /**
     * The steps {@see failure} names: the receive timeout's failure mode, a submission (in
     * the author's request, a retry or the sweep), the moderator's e-mail from WP-Cron, a
     * verdict the webhook applies, and the removal of a settled comment's data after 30 days.
     */
    public const STEP_TIMEOUT = 'czas_oczekiwania';
    public const STEP_SUBMIT = 'wysylka';
    public const STEP_NOTIFY = 'powiadomienie';
    public const STEP_VERDICT = 'werdykt';
    public const STEP_RETENTION = 'usuwanie_danych';

    /** The prefix of {@see failureCode}. */
    private const FAILURE_PREFIX = 'wyjatek_';

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
     * Records a failed submission.
     *
     * @param int         $commentId The comment.
     * @param int|null    $http      The HTTP status, or null when no answer came.
     * @param string|null $code      The gateway's error code, or null.
     * @return void
     */
    public function record(int $commentId, ?int $http, ?string $code): void
    {
        $entries = $this->entries();
        $entries[] = ['time' => $this->wp->now(), 'http' => $http, 'code' => self::safeCode($code),
            'comment' => $commentId];
        $this->wp->saveOption(self::OPTION, array_slice($entries, -self::MAX_ENTRIES), false);
    }

    /**
     * Records that a step for one comment failed with an exception: the step's name and the
     * comment's id only. Never the exception's message or trace: they can carry the
     * comment's text or the key.
     *
     * @param string $step      One of the `STEP_*` names.
     * @param int    $commentId The comment.
     * @return void
     */
    public function failure(string $step, int $commentId): void
    {
        $this->record($commentId, null, self::failureCode($step));
    }

    /**
     * The code a failed step is recorded under, in the log and in `_minos_error`.
     *
     * @param string $step One of the `STEP_*` names.
     * @return string `wyjatek_<step>`.
     */
    public static function failureCode(string $step): string
    {
        return self::FAILURE_PREFIX . $step;
    }

    /**
     * The step of a code {@see failureCode} made.
     *
     * @param string|null $code A logged code.
     * @return string|null The step, or null for any other code.
     */
    public static function failedStep(?string $code): ?string
    {
        return $code !== null && strpos($code, self::FAILURE_PREFIX) === 0
            ? substr($code, strlen(self::FAILURE_PREFIX)) : null;
    }

    /**
     * Records a configuration error for the admin notice.
     *
     * @param int|null    $http The HTTP status.
     * @param string|null $code The gateway's error code, or null.
     * @return void
     */
    public function configError(?int $http, ?string $code): void
    {
        $this->wp->saveOption(self::CONFIG_ERROR_OPTION,
            ['time' => $this->wp->now(), 'http' => $http, 'code' => self::safeCode($code)], false);
    }

    /**
     * Forgets the configuration error and the refusal pause: the gateway accepted a comment.
     *
     * @return void
     */
    public function clearConfigError(): void
    {
        foreach ([self::CONFIG_ERROR_OPTION, self::PAUSE_OPTION] as $option) {
            if ($this->wp->option($option, null) !== null) {
                $this->wp->deleteOption($option);
            }
        }
    }

    /**
     * Records that the gateway asked to wait until a moment (a `429`, a `408`, a `5xx`, a
     * `2xx` other than `202`, no answer): nothing is sent before it ({@see sendingPause}), and the administrator is
     * shown until when and why ({@see pausedUntil}). The moment is kept at most
     * {@see MAX_PAUSE_S} ahead: while nothing is sent, no `202` can end the pause, so a
     * longer one would stop the plugin for good; when it ends, the next answer says whether
     * the gateway still refuses. A pause already running that ends later stays: the answer
     * to a request sent before it never shortens it.
     *
     * @param string|null $code  The refusal's code, or null.
     * @param int         $until Unix seconds: now plus the gateway's `ponow_za_s`, or plus
     *     the plugin's backoff.
     * @param bool        $given Whether the moment is the gateway's own (`ponow_za_s`).
     * @return void
     */
    public function pause(?string $code, int $until, bool $given): void
    {
        $until = min($until, $this->wp->now() + self::MAX_PAUSE_S);
        $running = $this->sendingPause();
        if ($running !== null && $running['until'] >= $until) {
            return;
        }
        $this->wp->saveOption(self::PAUSE_OPTION, ['kind' => self::PAUSE_CODES[(string)$code] ?? self::PAUSE_WAIT,
            'until' => $until, 'code' => self::safeCode($code), 'given' => $given], false);
    }

    /**
     * Ends the pause at once: the administrator changed what the requests go out with (the
     * key, the gateway's address) or switched moderation off or on, so the gateway's last
     * answer no longer says anything about the next one.
     *
     * @return void
     */
    public function endPause(): void
    {
        if ($this->wp->option(self::PAUSE_OPTION, null) !== null) {
            $this->wp->deleteOption(self::PAUSE_OPTION);
        }
    }

    /**
     * The pause every submission keeps while it runs: until when, why, and whether the
     * gateway itself gave the moment.
     *
     * @return array{until:int,code:string|null,given:bool}|null The pause, or null when
     *     there is none now.
     */
    public function sendingPause(): ?array
    {
        $pause = $this->runningPause();
        if ($pause === null) {
            return null;
        }
        return [
            'until' => (int)$pause['until'],
            'code'  => is_string($pause['code'] ?? null) ? self::safeCode($pause['code']) : null,
            // A pause stored before this field was the gateway's (a daily limit, an overload).
            'given' => ($pause['given'] ?? true) !== false,
        ];
    }

    /**
     * The pause still running, for the administrator: its kind (a daily limit, an
     * overload, or any other answer that asks to wait), its code, the moment it ends and
     * how far that is.
     *
     * @return array{kind:string,code:string|null,until:int,left_s:int}|null The pause, or
     *     null when there is none now.
     */
    public function pausedUntil(): ?array
    {
        $pause = $this->runningPause();
        if ($pause === null) {
            return null;
        }
        $kind = in_array($pause['kind'] ?? null, self::PAUSE_CODES, true) ? (string)$pause['kind'] : self::PAUSE_WAIT;
        return [
            'kind'   => $kind,
            'code'   => is_string($pause['code'] ?? null) ? self::safeCode($pause['code']) : null,
            'until'  => (int)$pause['until'],
            'left_s' => (int)$pause['until'] - $this->wp->now(),
        ];
    }

    /**
     * The stored pause while it runs. One that ends more than {@see MAX_PAUSE_S} ahead was
     * not written by {@see pause} (a damaged option) and counts as none.
     *
     * @return array<string,mixed>|null The stored pause, or null.
     */
    private function runningPause(): ?array
    {
        $pause = $this->wp->option(self::PAUSE_OPTION, null);
        $left = is_array($pause) ? (int)($pause['until'] ?? 0) - $this->wp->now() : 0;
        return is_array($pause) && $left > 0 && $left <= self::MAX_PAUSE_S ? $pause : null;
    }

    /**
     * The entries, oldest first.
     *
     * @return array<int,array{time:int,http:int|null,code:string|null,comment:int}>
     */
    public function entries(): array
    {
        $entries = $this->wp->option(self::OPTION, []);
        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * The last configuration error.
     *
     * @return array{time:int,http:int|null,code:string|null}|null The error, or null.
     */
    public function lastConfigError(): ?array
    {
        $error = $this->wp->option(self::CONFIG_ERROR_OPTION, null);
        return is_array($error) ? $error : null;
    }

    /**
     * A gateway error code, or null for anything that does not look like one: nothing
     * else from an answer is ever stored.
     *
     * @param string|null $code The code.
     * @return string|null The code, or null.
     */
    public static function safeCode(?string $code): ?string
    {
        return $code !== null && preg_match('/^[a-z0-9_]{1,64}$/', $code) ? $code : null;
    }
}
