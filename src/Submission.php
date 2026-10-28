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
 *
 * The gateway's limits count requests, not items, and a refusal the key caused spends its
 * allowance. So an answer that asks to wait (`429`, `408`, `5xx`, a `2xx` other than `202`,
 * no answer) pauses every
 * submission until its `ponow_za_s`, or the backoff, ends ({@see Log::sendingPause}): a
 * comment written meanwhile is held for WP-Cron instead of being sent in its author's
 * request. The retries then go out together, up to {@see MAX_ITEMS} comments a request,
 * and only the comments the `202` names in `przyjete` count as accepted.
 */
final class Submission
{
    /**
     * The WP-Cron action that retries a comment after a `429`, a `408`, a `5xx`, a `2xx`
     * other than `202` or no answer, or after the pause the gateway asked for; the request carries the other due
     * comments too.
     */
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
     * The longest `ponow_za_s` taken as given: a year, longer than any receive timeout and
     * any pause the plugin keeps, short enough that adding it to the clock stays an integer.
     */
    private const MAX_ASKED_S = 365 * 86400;

    /**
     * The most waiting comments switching the plugin on again sends again (a day of the
     * paid plan's limit); any beyond keep their old timeout.
     */
    public const RESUME_BATCH = 1000;

    /** The most items one request carries (the contract's batch). */
    public const MAX_ITEMS = 20;

    /**
     * Refusals of one item of a request (`blad.element`), for a fault of that item alone:
     * that comment gets the failure mode, and the others are sent again. Any other refusal
     * names the key's setup (a profile, the webhook), so it is every item's.
     */
    private const ITEM_CODES = ['brak_tekstu', 'limit_dlugosci', 'bledny_identyfikator', 'powtorzony_identyfikator'];

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
     * A comment a person decided on meanwhile is left to that decision. Switching it off or
     * on, or another gateway address, also ends the gateway's pause ({@see Log::endPause}).
     *
     * @param mixed $old The settings before.
     * @param mixed $new The settings now.
     * @return void
     */
    public function onSettingsSaved($old, $new): void
    {
        $before = Settings::normalise(is_array($old) ? $old : []);
        $after = Settings::normalise(is_array($new) ? $new : []);
        if ($before['enabled'] !== $after['enabled'] || $before['gateway_url'] !== $after['gateway_url']) {
            $this->log->endPause();
        }
        if ($before['enabled'] || !$after['enabled']) {
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
     * `update_option_{key}` and `update_option_{secret}` actions (WordPress fires them only
     * on a change): another key may have other limits, so the gateway's pause, which
     * answered the old one, ends.
     *
     * @param mixed $old The value before.
     * @param mixed $new The value now.
     * @return void
     */
    public function onCredentialsSaved($old, $new): void
    {
        if ($old !== $new) {
            $this->log->endPause();
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
     * {@see HOOK_RETRY} action: a retry that fell due. The comment goes first in a request
     * that also carries the other waiting comments whose retry is due, up to
     * {@see MAX_ITEMS}: the gateway's limits count requests, not items, so the comments
     * held during one pause go out together. Their own events then find nothing to send.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function retry($commentId): void
    {
        $id = (int)$commentId;
        try {
            if ($this->due($id)) {
                $this->sendDue($this->dueWith($id), 1);
            }
        } catch (\Throwable $e) {
            // The retry stays due, so the sweep sends it; WP-Cron's other events still run.
            $this->outcome->failed(Log::STEP_SUBMIT, $id);
        }
    }

    /**
     * Whether a waiting comment's retry fell due, before its receive timeout.
     *
     * @param int $id The comment.
     * @return bool The answer.
     */
    public function due(int $id): bool
    {
        $retryAt = $this->wp->meta($id, Meta::RETRY_AT);
        return $this->wp->meta($id, Meta::STATUS) === Meta::PENDING && $retryAt !== ''
            && (int)$retryAt <= $this->wp->now() && !$this->pastDeadline($id);
    }

    /**
     * A comment whose retry fell due, then the other waiting comments whose retry is due,
     * oldest first, up to {@see MAX_ITEMS}.
     *
     * @param int $id The comment.
     * @return array<int,int> Comment ids.
     */
    private function dueWith(int $id): array
    {
        $ids = [$id];
        foreach ($this->wp->retryCommentIds(Sweeper::BATCH) as $other) {
            if (count($ids) >= self::MAX_ITEMS) {
                break;
            }
            try {
                if ($other !== $id && $this->due($other)) {
                    $ids[] = $other;
                }
            } catch (\Throwable $e) {
                // Left to its own event or to the sweep.
            }
        }
        return $ids;
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
     * Sends one pending comment at once, in the request of the person who wrote or edited
     * it, and records what the gateway answered. While the gateway asked to wait
     * ({@see Log::sendingPause}), nothing is sent: the comment stays held for WP-Cron, which
     * sends it once the pause ends.
     *
     * @param int $id The comment.
     * @return string {@see ACCEPTED}, {@see RETRY} (it waits, or its wait could not end in
     *     time), {@see REFUSED} (the failure mode applied) or {@see SKIPPED} (not pending,
     *     or the plugin is off).
     */
    public function submit(int $id): string
    {
        if (!$this->settings->all()['enabled']) {
            return self::SKIPPED;
        }
        $comment = $this->assessable($id);
        if ($comment === null) {
            return $this->wp->meta($id, Meta::STATUS) === Meta::PENDING ? self::SKIPPED : self::REFUSED;
        }
        $pause = $this->log->sendingPause();
        if ($pause !== null) {
            $this->wait($id, $pause);
            return self::RETRY;
        }
        $budget = 1;
        return $this->sendBatch([$this->entry($comment)], $budget);
    }

    /**
     * Sends comments whose retry fell due (the sweep, a retry event), in requests of at most
     * {@see MAX_ITEMS}, and stops at the first answer that asks to wait. While a pause runs,
     * nothing is sent: each comment is held until it ends, or gets the failure mode at once
     * when the gateway's pause ends after its receive timeout (shared rule D9.3(a)).
     *
     * One comment that fails while its item is built (another plugin's hook throwing) is left
     * out of the request and stays due; one request that fails while it is sent leaves its
     * comments due, and the next request goes on. The failure is recorded by step and
     * comment id ({@see Outcome::failed}), never by its message.
     *
     * @param array<int,int> $ids         Comments whose retry is due, in the order to send.
     * @param int            $maxRequests The most HTTP requests to send, a request sent again
     *     after one refused item included.
     * @return void
     */
    public function sendDue(array $ids, int $maxRequests): void
    {
        if ($ids === [] || !$this->settings->all()['enabled']) {
            return;
        }
        $pause = $this->log->sendingPause();
        if ($pause !== null) {
            foreach ($ids as $id) {
                try {
                    if ($this->wp->meta($id, Meta::STATUS) === Meta::PENDING) {
                        $this->wait($id, $pause);
                    }
                } catch (\Throwable $e) {
                    $this->outcome->failed(Log::STEP_SUBMIT, $id);
                }
            }
            return;
        }
        $ids = array_values($ids);
        $last = count($ids) - 1;
        $batch = [];
        $budget = $maxRequests;
        foreach ($ids as $index => $id) {
            if ($budget <= 0) {
                return;
            }
            try {
                $comment = $this->assessable($id);
                if ($comment !== null) {
                    $batch[] = $this->entry($comment);
                }
            } catch (\Throwable $e) {
                // Left out of the request; its retry stays due for the next pass.
                $this->outcome->failed(Log::STEP_SUBMIT, $id);
            }
            if ($batch === [] || (count($batch) < self::MAX_ITEMS && $index < $last)) {
                continue;
            }
            $answer = $this->sendBatchIsolated($batch, $budget);
            $batch = [];
            if ($answer === self::RETRY) {
                return;
            }
        }
    }

    /**
     * A pending comment that can be sent, or null when nothing is to be sent: it is gone or
     * not pending (left as it is), or there is nothing to assess it with or nothing to
     * assess (the failure mode at once; no verdict, no guess).
     *
     * @param int $id The comment.
     * @return object|null The `WP_Comment`.
     */
    private function assessable(int $id): ?object
    {
        $comment = $this->wp->comment($id);
        if ($comment === null || $this->wp->meta($id, Meta::STATUS) !== Meta::PENDING) {
            return null;
        }
        if (!$this->settings->usable()) {
            // Nothing to assess it with, nor to check its verdict: no verdict, no guess.
            $this->outcome->withoutVerdict($id, Meta::ERROR_NOT_CONFIGURED);
            return null;
        }
        $plain = Text::plain((string)$comment->comment_content);
        if (Text::cut($plain) === '') {
            // Nothing to assess (a link with a blank text, an image without alternative
            // text): no request, no verdict, no guess - the failure mode at once (shared
            // rule D9.5), with the reason for the moderator.
            $this->outcome->withoutVerdict($id, Meta::ERROR_NO_TEXT);
            return null;
        }
        return $comment;
    }

    /**
     * Records what is about to be sent for a comment (the cut, the length and the hash of
     * the text) and builds its item.
     *
     * @param object $comment A comment {@see assessable} returned.
     * @return array{id:int,item:array<string,mixed>} The comment's id and its item.
     */
    private function entry(object $comment): array
    {
        $id = (int)$comment->comment_ID;
        $plain = Text::plain((string)$comment->comment_content);
        $text = Text::cut($plain);
        if (Text::isCut($plain)) {
            $this->wp->setMeta($id, Meta::CUT, '1');
        } else {
            $this->wp->deleteMeta($id, Meta::CUT);
        }
        $this->wp->setMeta($id, Meta::SENT_CHARS, mb_strlen($text, 'UTF-8'));
        $this->wp->setMeta($id, Meta::SENT_HASH, hash('sha256', $text));
        return ['id' => $id, 'item' => $this->item($comment, $text)];
    }

    /**
     * Holds a comment while the gateway's pause runs: its retry falls due when the pause
     * ends. When the gateway itself said until when it refuses and that is at or after the
     * comment's receive timeout, no verdict can come in time: the failure mode applies at
     * once (shared rule D9.3(a)), with the refusal that made the wait hopeless.
     *
     * @param int                                           $id    The comment.
     * @param array{until:int,code:string|null,given:bool} $pause The pause.
     * @return void
     */
    private function wait(int $id, array $pause): void
    {
        if ($pause['given'] && $pause['until'] >= $this->deadline($id)) {
            $this->outcome->timedOut($id, $pause['code']);
            return;
        }
        $this->wp->setMeta($id, Meta::RETRY_AT, $pause['until']);
        $this->wp->scheduleOnce($pause['until'], self::HOOK_RETRY, [$id]);
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
     * {@see sendBatch} where an exception must not escape (WP-Cron): a request that fails
     * on the way (another plugin's HTTP hook throwing) leaves every comment it carried due,
     * recorded once under the first comment's id and on each comment.
     *
     * @param array<int,array{id:int,item:array<string,mixed>}> $entries The comments and their items.
     * @param int                                               $budget  HTTP requests left; each one sent counts.
     * @return string What {@see sendBatch} answered, or {@see REFUSED} when it failed.
     */
    private function sendBatchIsolated(array $entries, int &$budget): string
    {
        try {
            return $this->sendBatch($entries, $budget);
        } catch (\Throwable $e) {
            $this->outcome->failedTogether(Log::STEP_SUBMIT, array_column($entries, 'id'));
            return self::REFUSED;
        }
    }

    /**
     * One request with the given items, and what its answer means for each comment. A
     * refusal of one item alone (`blad.element`, for a fault of that item) gives that
     * comment the failure mode and sends the others again at once: the gateway took none
     * of them, and such a refusal costs the key nothing.
     *
     * @param array<int,array{id:int,item:array<string,mixed>}> $entries 1-{@see MAX_ITEMS} comments and their items.
     * @param int                                               $budget  HTTP requests left; each one sent counts,
     *     and the comments not sent for want of it stay due.
     * @return string {@see ACCEPTED} (the gateway took at least one), {@see RETRY} (it asked
     *     to wait, or took none) or {@see REFUSED}.
     */
    private function sendBatch(array $entries, int &$budget): string
    {
        while ($budget > 0) {
            $budget--;
            $answer = $this->wp->post(
                $this->settings->all()['gateway_url'] . self::PATH,
                ['Content-Type' => 'application/json', 'X-Gateway-Key' => $this->settings->apiKey()],
                (string)json_encode(['elementy' => array_column($entries, 'item')],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::TIMEOUT_S
            );
            $rest = $this->handleAnswer($entries, $answer['status'], $answer['body']);
            if (!is_array($rest)) {
                return $rest;
            }
            $entries = $rest;
        }
        // Out of requests after a refused item: the others stay due for the next pass.
        return self::REFUSED;
    }

    /**
     * Records the gateway's answer for every comment of a request.
     *
     * - `202`: each comment whose item id is in `przyjete` is accepted (its verdict will
     *   come to the webhook); any other is retried after its own backoff (shared rule D9.6:
     *   only `202` with the item in it accepts). Any acceptance ends the refusal pause.
     * - `429`, `5xx`, `408` (a timeout on the way, as no answer), a `2xx` other than `202`
     *   (something on the way answered for the gateway, which took nothing: shared rule
     *   F5a) or no answer: every submission waits until the gateway's `ponow_za_s`
     *   or, without it, a doubling backoff ends ({@see Log::pause}); each comment of the
     *   request is retried then, but when the gateway's `ponow_za_s`, as given, ends at or
     *   after the comment's receive timeout, no verdict can come in time and the failure
     *   mode applies at once (shared rule D9.3(a)).
     * - a refusal of one item (`blad.element`, for an id, a text or a length): that comment
     *   gets the failure mode; the others are returned, to be sent again.
     * - anything else: a configuration error (the key, the webhook, the URL, the profile);
     *   retrying will not help, so the failure mode applies and the administrator is told.
     *
     * @param array<int,array{id:int,item:array<string,mixed>}> $entries The comments and their items.
     * @param int|null                                          $status  The HTTP status, or null when no answer came.
     * @param string                                            $body    The answer's body.
     * @return string|array<int,array{id:int,item:array<string,mixed>}> {@see ACCEPTED},
     *     {@see RETRY} or {@see REFUSED}; or the comments to send again.
     */
    private function handleAnswer(array $entries, ?int $status, string $body)
    {
        if ($status === 202) {
            return $this->accept($entries, $body);
        }
        $error = self::error($body);
        $code = $error['kod'] ?? 'http_' . (int)$status;
        $element = $error['element'];
        // Only `202` accepts (shared rule F5a): any other `2xx` took nothing, and is retried.
        $wait = $status === null || $status === 408 || $status === 429 || $status >= 500
            || ($status >= 200 && $status < 300);
        if (!$wait && $element !== null && isset($entries[$element]) && in_array($code, self::ITEM_CODES, true)) {
            // One request, one log entry: under the comment it refused.
            $this->log->record($entries[$element]['id'], $status, $error['kod']);
            $this->outcome->withoutVerdict($entries[$element]['id'], $code);
            unset($entries[$element]);
            return $entries === [] ? self::REFUSED : array_values($entries);
        }
        $this->log->record($entries[0]['id'], $status, $error['kod']);
        if ($wait) {
            $this->waitForGateway($entries, $status, $error);
            return self::RETRY;
        }
        $this->log->configError($status, $error['kod']);
        foreach ($entries as $entry) {
            $this->outcome->withoutVerdict($entry['id'], $code);
        }
        return self::REFUSED;
    }

    /**
     * A `202`: the comments in `przyjete` wait for their verdicts; the others are retried.
     *
     * @param array<int,array{id:int,item:array<string,mixed>}> $entries The comments and their items.
     * @param string                                            $body    The answer's body.
     * @return string {@see ACCEPTED} when the gateway took any, {@see RETRY} when none.
     */
    private function accept(array $entries, string $body): string
    {
        $decoded = json_decode($body, true);
        $taken = is_array($decoded) && is_array($decoded['przyjete'] ?? null) ? $decoded['przyjete'] : [];
        $left = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if (!in_array($entry['item']['id'], $taken, true)) {
                $left[] = $id;
                continue;
            }
            $this->wp->setMeta($id, Meta::SUBMITTED_AT, $this->wp->now());
            // Removed only now: a request that fails on the way leaves the retry due.
            $this->wp->deleteMeta($id, Meta::RETRY_AT);
            $this->wp->deleteMeta($id, Meta::ATTEMPTS);
        }
        if (count($left) < count($entries)) {
            $this->log->clearConfigError();
        }
        if ($left !== []) {
            // Not taken: each is retried after its own backoff; one log entry a request.
            $this->log->record($left[0], 202, null);
            foreach ($left as $id) {
                $this->retryAfter($id, self::backoff((int)$this->wp->meta($id, Meta::ATTEMPTS) + 1));
            }
        }
        return count($left) < count($entries) ? self::ACCEPTED : self::RETRY;
    }

    /**
     * An answer that asks to wait: the pause every submission keeps, and each comment's
     * retry, or its failure mode at once when the gateway refuses until after its timeout.
     *
     * @param array<int,array{id:int,item:array<string,mixed>}>             $entries The comments and their items.
     * @param int|null                                                       $status  The HTTP status, or null.
     * @param array{kod:string|null,ponow_za_s:int|null,asked_s:int|null,element:int|null} $error What the refusal said.
     * @return void
     */
    private function waitForGateway(array $entries, ?int $status, array $error): void
    {
        $attempts = 0;
        foreach ($entries as $entry) {
            $attempts = max($attempts, (int)$this->wp->meta($entry['id'], Meta::ATTEMPTS) + 1);
        }
        $pause = $error['ponow_za_s'] ?? self::backoff($attempts);
        $asked = $error['asked_s'];
        $this->log->pause($error['kod'], $this->wp->now() + ($asked ?? $pause), $asked !== null);
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if ($asked !== null && $this->wp->now() + $asked >= $this->deadline($id)) {
                // The gateway refuses until after this comment's time-out: waiting for it
                // changes nothing but the 20 minutes.
                $this->outcome->timedOut($id, $error['kod'] ?? 'http_' . (int)$status);
                continue;
            }
            $this->retryAfter($id, $pause);
        }
    }

    /**
     * Counts a failed attempt and schedules the comment's next one.
     *
     * @param int $id      The comment.
     * @param int $pauseS  Seconds until the next attempt.
     * @return void
     */
    private function retryAfter(int $id, int $pauseS): void
    {
        $at = $this->wp->now() + $pauseS;
        $this->wp->setMeta($id, Meta::ATTEMPTS, (int)$this->wp->meta($id, Meta::ATTEMPTS) + 1);
        $this->wp->setMeta($id, Meta::RETRY_AT, $at);
        $this->wp->scheduleOnce($at, self::HOOK_RETRY, [$id]);
    }

    /**
     * The retry pause without the gateway's `ponow_za_s`: a minute, doubling, at most an hour.
     *
     * @param int $attempts The failed attempts so far, this one included.
     * @return int Seconds.
     */
    private static function backoff(int $attempts): int
    {
        return min(self::MAX_BACKOFF_S, self::FIRST_BACKOFF_S * 2 ** min(20, max(0, $attempts - 1)));
    }

    /**
     * The code, the retry pause and the item of a refusal,
     * `{"blad": {"kod", "ponow_za_s"?, "element"?}}`.
     *
     * @param string $body The answer's body.
     * @return array{kod:string|null,ponow_za_s:int|null,asked_s:int|null,element:int|null} What
     *     could be read: the pause the plugin waits (capped at a day), the one the gateway
     *     gave, and the position of the item the refusal is about.
     */
    private static function error(string $body): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['blad'] ?? null) ? $decoded['blad'] : [];
        $code = is_string($error['kod'] ?? null) ? Log::safeCode($error['kod']) : null;
        $retry = $error['ponow_za_s'] ?? null;
        // Clamped far beyond any timeout and any pause, so the arithmetic stays in integers.
        $asked = is_int($retry) && $retry > 0 ? min($retry, self::MAX_ASKED_S) : null;
        $element = $error['element'] ?? null;
        return [
            'kod'        => $code,
            'ponow_za_s' => $asked === null ? null : min($asked, 86400),
            'asked_s'    => $asked,
            'element'    => is_int($element) && $element >= 0 ? $element : null,
        ];
    }
}
