<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * Holds a new comment and sends it to the gateway for assessment.
 *
 * `pre_comment_approved` (late, after the site's own rules and other plugins) holds every
 * new comment the plugin moderates and remembers what WordPress decided; `comment_post`
 * (or `rest_insert_comment` for a comment posted over the REST API) then sends it. Not
 * moderated, and left exactly as WordPress decided: comments by users who may moderate
 * comments (they can publish them anyway), comments WordPress already marked as spam or
 * trash or refused, pingbacks, trackbacks and other comment types, and everything while
 * the plugin is switched off. Switched on without a usable key or secret, it sends
 * nothing and gives every new comment the failure mode at once.
 *
 * What travels is the comment's id, its plain text (the first 3000 characters; a longer
 * comment is marked {@see Meta::CUT} and no verdict publishes it), the profile, and spam
 * signals: the number of links, their registrable domains, and whether this is the
 * author's first approved comment. Never the author's name, e-mail, IP address, website
 * or user id.
 *
 * While moderation is switched off nothing is sent, not even a retry: comments already
 * waiting stay in WordPress's moderation queue for a person. Switching it on again sends
 * them again, each as its next revision with a fresh receive timeout ({@see onSettingsSaved}).
 *
 * An edit that changes the text of a comment still waiting for its verdict, or of one the
 * receive timeout left without an assessment (held, or published under fail-open), sends
 * it again as its next revision, `wp:<id>.<n>`; a published one goes back to hold while
 * the new text is assessed. An edit of a comment the gateway assessed is a person's
 * decision and is not sent.
 */
final class Submission
{
    /** The WP-Cron action that retries one comment after a `429`, a `503` or no answer. */
    public const HOOK_RETRY = 'minos_moderation_retry';

    /** The gateway's B2B route. */
    public const PATH = '/api/v1/b2b/oceny';

    /** Seconds one request may take, connection included. */
    public const TIMEOUT_S = 10;

    /** The first retry pause without the gateway's `ponow_za_s`; it doubles. */
    public const FIRST_BACKOFF_S = 60;

    /** The longest retry pause. */
    public const MAX_BACKOFF_S = 3600;

    /**
     * The most waiting comments switching the plugin on again sends again (a day of the
     * paid plan's limit); any beyond keep their old timeout.
     */
    public const RESUME_BATCH = 1000;

    /** The comment types the plugin moderates ('' is the legacy spelling of a comment). */
    private const TYPES = ['comment', ''];

    /** What each `submit()` answered. */
    public const ACCEPTED = 'accepted';
    public const RETRY = 'retry';
    public const REFUSED = 'refused';
    public const SKIPPED = 'skipped';

    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /** @var Outcome */
    private $outcome;

    /** @var Log */
    private $log;

    /**
     * What WordPress decided for the comment being posted in this request, between
     * `pre_comment_approved` and the insert; null when the plugin is not holding one.
     *
     * @var int|null
     */
    private $held;

    /**
     * @param Platform $wp       The WordPress adapter.
     * @param Settings $settings The settings.
     * @param Outcome  $outcome  What applies a verdict or the failure mode.
     * @param Log      $log      The administrator's log.
     */
    public function __construct(Platform $wp, Settings $settings, Outcome $outcome, Log $log)
    {
        $this->wp = $wp;
        $this->settings = $settings;
        $this->outcome = $outcome;
        $this->log = $log;
    }

    /**
     * `pre_comment_approved` filter: holds a comment the plugin moderates.
     *
     * WordPress may run the filter twice for one comment (6.7+ re-checks after filtering the
     * data); the last run wins, and both see WordPress's own decision.
     *
     * @param mixed               $approved    1, 0, 'spam', 'trash' or a `WP_Error`.
     * @param array<string,mixed> $commentdata The comment being posted.
     * @return mixed 0 for a comment the plugin holds; `$approved` unchanged otherwise.
     */
    public function holdForAssessment($approved, $commentdata)
    {
        $this->held = null;
        if (!in_array($approved, [0, 1, '0', '1'], true) || !is_array($commentdata)
            || !$this->settings->all()['enabled']) {
            return $approved;
        }
        if (!in_array((string)($commentdata['comment_type'] ?? ''), self::TYPES, true)) {
            return $approved;
        }
        $userId = (int)($commentdata['user_id'] ?? ($commentdata['user_ID'] ?? 0));
        if ($userId > 0 && $this->wp->userCan($userId, 'moderate_comments')) {
            return $approved;
        }
        $this->held = (int)$approved;
        return 0;
    }

    /**
     * `comment_post` action: sends the comment the filter held.
     *
     * @param int|string $commentId The new comment.
     * @param mixed      $approved  Its status as inserted.
     * @return void
     */
    public function onCommentPost($commentId, $approved): void
    {
        $this->start((int)$commentId, $approved);
    }

    /**
     * `rest_insert_comment` action: the REST API inserts without `comment_post`.
     *
     * @param object $comment  The `WP_Comment`.
     * @param mixed  $request  The `WP_REST_Request`.
     * @param bool   $creating True for a new comment.
     * @return void
     */
    public function onRestInsertComment($comment, $request, $creating): void
    {
        if ($creating === true && is_object($comment) && isset($comment->comment_ID)) {
            $this->start((int)$comment->comment_ID, $comment->comment_approved ?? null);
        }
    }

    /**
     * `edit_comment` action: an edit that changed the text of a comment that was never
     * assessed sends it again ({@see reassess}). The plugin's own write of a masked text is
     * not an edit; an edit of an assessed comment is the editor's decision; while the plugin
     * is switched off an edit only ends a fail-open publication's wait for a late verdict.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function onEdit($commentId): void
    {
        $id = (int)$commentId;
        if ($id <= 0 || $this->outcome->writing()) {
            return;
        }
        $status = $this->wp->meta($id, Meta::STATUS);
        $unassessed = $status === Meta::PENDING
            || ($status === Meta::UNASSESSED && $this->wp->meta($id, Meta::TIMED_OUT) === '1');
        if (!$this->settings->all()['enabled'] || !$unassessed) {
            $this->outcome->forgetAutoPublication($id);
            return;
        }
        // Not sent yet, or the text is unchanged (an edit of the author's name, say): the
        // revision on its way is still the right one.
        if ($this->wp->meta($id, Meta::SENT_HASH) === '' || $this->outcome->readsAsSent($id)) {
            return;
        }
        $this->reassess($id);
    }

    /**
     * `update_option_{settings}` action: switching moderation on again sends what was
     * waiting when it was switched off, each as its next revision with a fresh receive
     * timeout (shared rule D9.13), from WP-Cron rather than in the administrator's request.
     * A comment a person decided on meanwhile is left to that decision.
     *
     * @param mixed $old The settings before.
     * @param mixed $new The settings now.
     * @return void
     */
    public function onSettingsSaved($old, $new): void
    {
        $was = Settings::normalise(is_array($old) ? $old : [])['enabled'];
        $is = Settings::normalise(is_array($new) ? $new : [])['enabled'];
        if ($was || !$is) {
            return;
        }
        foreach ($this->wp->pendingCommentIds(self::RESUME_BATCH) as $id) {
            try {
                if (!$this->reassessLater($id)) {
                    $this->outcome->abandon($id);
                }
            } catch (\Throwable $e) {
                // Left waiting: the sweep gives it the failure mode once its old timeout is past.
                $this->outcome->failed(Log::STEP_SUBMIT, $id);
            }
        }
    }

    /**
     * Sends a comment again as its next revision, because its text is no longer the one
     * that was sent. A comment the plugin published under fail-open goes back to hold while
     * the new text is assessed.
     *
     * @param int $id The comment.
     * @return bool False when the comment is gone or a person decided on it meanwhile
     *     (approved, spammed or trashed it): then nothing is sent.
     */
    public function reassess(int $id): bool
    {
        if (!$this->reopen($id)) {
            return false;
        }
        $this->submitIsolated($id);
        return true;
    }

    /**
     * {@see reassess}, with the request left to WP-Cron: for the webhook, which answers the
     * gateway at once, and for switching the plugin on again. The retry is due now; the
     * sweep sends it too if its event is lost.
     *
     * @param int $id The comment.
     * @return bool False when the comment is gone or a person decided on it meanwhile.
     */
    public function reassessLater(int $id): bool
    {
        if (!$this->reopen($id)) {
            return false;
        }
        $this->wp->setMeta($id, Meta::RETRY_AT, $this->wp->now());
        $this->wp->scheduleOnce($this->wp->now(), self::HOOK_RETRY, [$id]);
        return true;
    }

    /**
     * Starts the next revision of a held comment, or of one the plugin published under
     * fail-open (held again), with a fresh receive timeout.
     *
     * @param int $id The comment.
     * @return bool Whether the comment now waits for the new revision's verdict.
     */
    private function reopen(int $id): bool
    {
        $comment = $this->wp->comment($id);
        if ($comment === null) {
            return false;
        }
        $approved = (string)$comment->comment_approved;
        if ($approved === '1' && $this->wp->meta($id, Meta::AUTO_PUBLISHED) === '1') {
            $this->wp->deleteMeta($id, Meta::AUTO_PUBLISHED);
            if (!$this->wp->setStatus($id, 'hold')) {
                $this->wp->setMeta($id, Meta::AUTO_PUBLISHED, '1');
                return false;
            }
        } elseif ($approved !== '0') {
            return false;
        }
        $this->wp->setMeta($id, Meta::REVISION, (int)$this->wp->meta($id, Meta::REVISION) + 1);
        $this->wp->setMeta($id, Meta::STATUS, Meta::PENDING);
        $this->wp->setMeta($id, Meta::WAITING, '1');
        $this->wp->setMeta($id, Meta::HELD_AT, $this->wp->now());
        foreach ([Meta::SUBMITTED_AT, Meta::RETRY_AT, Meta::ATTEMPTS, Meta::SENT_HASH, Meta::SENT_CHARS,
            Meta::TIMED_OUT, Meta::ERROR, Meta::DECIDED_AT] as $key) {
            $this->wp->deleteMeta($id, $key);
        }
        return true;
    }

    /**
     * {@see HOOK_RETRY} action: a retry that fell due.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function retry($commentId): void
    {
        $id = (int)$commentId;
        try {
            $retryAt = $this->wp->meta($id, Meta::RETRY_AT);
            if ($this->wp->meta($id, Meta::STATUS) === Meta::PENDING && $retryAt !== ''
                && (int)$retryAt <= $this->wp->now() && !$this->pastDeadline($id)) {
                $this->submit($id);
            }
        } catch (\Throwable $e) {
            // The retry stays due, so the sweep sends it; WP-Cron's other events still run.
            $this->outcome->failed(Log::STEP_SUBMIT, $id);
        }
    }

    /**
     * Whether a pending comment waited past the receive timeout: from its acceptance by the
     * gateway, or, while it was never accepted, from the moment it was held.
     *
     * @param int $id The comment.
     * @return bool The answer.
     */
    public function pastDeadline(int $id): bool
    {
        return $this->wp->now() >= $this->deadline($id);
    }

    /**
     * When a pending comment's receive timeout ends ({@see pastDeadline}).
     *
     * @param int $id The comment.
     * @return int Unix seconds.
     */
    private function deadline(int $id): int
    {
        $since = $this->wp->meta($id, Meta::SUBMITTED_AT);
        if ($since === '') {
            $since = $this->wp->meta($id, Meta::HELD_AT);
        }
        return (int)$since + $this->settings->timeoutS();
    }

    /**
     * Sends one pending comment and records what the gateway answered.
     *
     * @param int $id The comment.
     * @return string {@see ACCEPTED}, {@see RETRY}, {@see REFUSED} or {@see SKIPPED}.
     */
    public function submit(int $id): string
    {
        if (!$this->settings->all()['enabled']) {
            return self::SKIPPED;
        }
        $comment = $this->wp->comment($id);
        if ($comment === null || $this->wp->meta($id, Meta::STATUS) !== Meta::PENDING) {
            return self::SKIPPED;
        }
        $key = $this->settings->apiKey();
        $plain = Text::plain((string)$comment->comment_content);
        $text = Text::cut($plain);
        if (!$this->settings->usable()) {
            // Nothing to assess it with, nor to check its verdict: no verdict, no guess.
            $this->outcome->withoutVerdict($id, Meta::ERROR_NOT_CONFIGURED);
            return self::REFUSED;
        }
        if ($text === '') {
            // Nothing to assess (a link with a blank text, an image without alternative
            // text): no request, no verdict, no guess - the failure mode at once (shared
            // rule D9.5), with the reason for the moderator.
            $this->outcome->withoutVerdict($id, Meta::ERROR_NO_TEXT);
            return self::REFUSED;
        }
        if (Text::isCut($plain)) {
            $this->wp->setMeta($id, Meta::CUT, '1');
        } else {
            $this->wp->deleteMeta($id, Meta::CUT);
        }
        $this->wp->setMeta($id, Meta::SENT_CHARS, mb_strlen($text, 'UTF-8'));
        $this->wp->setMeta($id, Meta::SENT_HASH, hash('sha256', $text));

        $answer = $this->wp->post(
            $this->settings->all()['gateway_url'] . self::PATH,
            ['Content-Type' => 'application/json', 'X-Gateway-Key' => $key],
            (string)json_encode(['elementy' => [$this->item($comment, $text)]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::TIMEOUT_S
        );
        return $this->handleAnswer($id, $answer['status'], $answer['body']);
    }

    /**
     * The request's item: nothing about the author but spam signals derived from the
     * content and one yes/no about the author's history.
     *
     * @param object $comment The `WP_Comment`.
     * @param string $text    The text to assess.
     * @return array<string,mixed> The item.
     */
    private function item(object $comment, string $text): array
    {
        $content = (string)$comment->comment_content;
        $userId = (int)($comment->user_id ?? 0);
        $email = (string)($comment->comment_author_email ?? '');
        $meta = ['links' => Text::linkCount($content)];
        $domains = Text::linkDomains($content);
        if ($domains !== []) {
            $meta['link_domains'] = $domains;
        }
        $meta['author_first_post'] = $this->wp->approvedCommentsBy($userId, $email) === 0;
        return [
            'id'     => self::itemId((int)$comment->comment_ID, (int)$this->wp->meta((int)$comment->comment_ID, Meta::REVISION)),
            'tekst'  => $text,
            'profil' => $this->settings->all()['profile'],
            'meta'   => $meta,
        ];
    }

    /**
     * The item id of a comment's revision: `wp:<id>`, and `wp:<id>.<n>` after the first.
     *
     * @param int $id       The comment.
     * @param int $revision The revision.
     * @return string The id.
     */
    public static function itemId(int $id, int $revision): string
    {
        return 'wp:' . $id . ($revision > 0 ? '.' . $revision : '');
    }

    /**
     * Starts the moderation of a comment the filter held in this request.
     *
     * @param int   $id       The comment.
     * @param mixed $approved Its status as inserted.
     * @return void
     */
    private function start(int $id, $approved): void
    {
        $wordpress = $this->held;
        $this->held = null;
        // Another filter after this one may have changed the status: then it is not ours.
        if ($wordpress === null || $id <= 0 || (string)$approved !== '0') {
            return;
        }
        $this->wp->setMeta($id, Meta::STATUS, Meta::PENDING);
        $this->wp->setMeta($id, Meta::WAITING, '1');
        $this->wp->setMeta($id, Meta::HELD_AT, $this->wp->now());
        $this->wp->setMeta($id, Meta::WP_APPROVED, $wordpress);
        $this->submitIsolated($id);
    }

    /**
     * Sends a comment where an exception must not escape: in the author's or the editor's
     * request, or in the webhook's. A failure is recorded by step and comment id, and the
     * comment is left due for a retry, which the sweep sends.
     *
     * @param int $id The comment.
     * @return void
     */
    private function submitIsolated(int $id): void
    {
        try {
            $this->submit($id);
        } catch (\Throwable $e) {
            $this->outcome->failed(Log::STEP_SUBMIT, $id);
            try {
                if ($this->wp->meta($id, Meta::RETRY_AT) === '') {
                    $this->wp->setMeta($id, Meta::RETRY_AT, $this->wp->now() + self::FIRST_BACKOFF_S);
                }
            } catch (\Throwable $ignored) {
                // The receive timeout still gives the comment its failure mode.
            }
        }
    }

    /**
     * Records the gateway's answer.
     *
     * - `202`: accepted; the verdict will come to the webhook.
     * - `429`, `5xx` or no answer: kept pending, retried after `ponow_za_s` or a doubling
     *   backoff; but when the gateway's `ponow_za_s`, as given, ends at or after the
     *   comment's receive timeout, no verdict can come in time and the failure mode applies
     *   at once (shared rule D9.3(a)). A daily limit or an overload is also kept for the
     *   administrator's notice ({@see Log::pause}).
     * - anything else: a configuration error (the key, the webhook, the URL); retrying will
     *   not help, so the failure mode applies and the administrator is told.
     *
     * @param int         $id     The comment.
     * @param int|null    $status The HTTP status, or null when no answer came.
     * @param string      $body   The answer's body.
     * @return string {@see ACCEPTED}, {@see RETRY} (the gateway asked to wait, whether the
     *     comment waits or its wait could not end in time) or {@see REFUSED}.
     */
    private function handleAnswer(int $id, ?int $status, string $body): string
    {
        if ($status === 202) {
            $this->wp->setMeta($id, Meta::SUBMITTED_AT, $this->wp->now());
            // Removed only now: a request that fails on the way leaves the retry due.
            $this->wp->deleteMeta($id, Meta::RETRY_AT);
            $this->wp->deleteMeta($id, Meta::ATTEMPTS);
            $this->log->clearConfigError();
            return self::ACCEPTED;
        }
        $error = self::error($body);
        $this->log->record($id, $status, $error['kod']);
        if ($status === null || $status === 429 || $status >= 500) {
            $asked = $error['asked_s'];
            if ($asked !== null) {
                $this->log->pause($error['kod'], $this->wp->now() + $asked);
                if ($this->wp->now() + $asked >= $this->deadline($id)) {
                    // The gateway refuses until after this comment's time-out: waiting
                    // for it changes nothing but the 20 minutes.
                    $this->outcome->timedOut($id, $error['kod'] ?? 'http_' . (int)$status);
                    return self::RETRY;
                }
            }
            $attempts = (int)$this->wp->meta($id, Meta::ATTEMPTS) + 1;
            $pause = $error['ponow_za_s'] ?? min(self::MAX_BACKOFF_S, self::FIRST_BACKOFF_S * 2 ** min(20, $attempts - 1));
            $at = $this->wp->now() + $pause;
            $this->wp->setMeta($id, Meta::ATTEMPTS, $attempts);
            $this->wp->setMeta($id, Meta::RETRY_AT, $at);
            $this->wp->scheduleOnce($at, self::HOOK_RETRY, [$id]);
            return self::RETRY;
        }
        $this->log->configError($status, $error['kod']);
        $this->outcome->withoutVerdict($id, $error['kod'] ?? 'http_' . $status);
        return self::REFUSED;
    }

    /**
     * The code and the retry pause of a refusal, `{"blad": {"kod", "ponow_za_s"?}}`.
     *
     * @param string $body The answer's body.
     * @return array{kod:string|null,ponow_za_s:int|null,asked_s:int|null} What
     *     could be read: the pause the plugin waits (capped at a day) and the one the
     *     gateway gave.
     */
    private static function error(string $body): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['blad'] ?? null) ? $decoded['blad'] : [];
        $code = is_string($error['kod'] ?? null) ? Log::safeCode($error['kod']) : null;
        $retry = $error['ponow_za_s'] ?? null;
        $asked = is_int($retry) && $retry > 0 ? $retry : null;
        return [
            'kod'        => $code,
            'ponow_za_s' => $asked === null ? null : min($asked, 86400),
            'asked_s'    => $asked,
        ];
    }
}
