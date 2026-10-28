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
 * kept on its own, for the admin notice, until the gateway accepts a comment again.
 */
final class Log
{
    /** The option with the entries (not autoloaded). */
    public const OPTION = 'minos_moderation_log';

    /** The option with the last configuration error (not autoloaded). */
    public const CONFIG_ERROR_OPTION = 'minos_moderation_config_error';

    /** How many entries the log keeps. */
    public const MAX_ENTRIES = 50;

    /**
     * The steps {@see failure} names: the receive timeout's failure mode, a submission (in
     * the author's request, a retry or the sweep), the moderator's e-mail from WP-Cron, and
     * a verdict the webhook applies.
     */
    public const STEP_TIMEOUT = 'czas_oczekiwania';
    public const STEP_SUBMIT = 'wysylka';
    public const STEP_NOTIFY = 'powiadomienie';
    public const STEP_VERDICT = 'werdykt';

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
     * Forgets the configuration error: the gateway accepted a comment.
     *
     * @return void
     */
    public function clearConfigError(): void
    {
        if ($this->wp->option(self::CONFIG_ERROR_OPTION, null) !== null) {
            $this->wp->deleteOption(self::CONFIG_ERROR_OPTION);
        }
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
