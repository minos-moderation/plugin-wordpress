<?php

declare(strict_types=1);

namespace Minos\WordPress;

use Minos\Client\WebhookPayload;

defined('ABSPATH') || exit;

/**
 * Applies what the gateway decided, or the failure mode when it decided nothing.
 *
 * The plugin never guesses a verdict. Six rules sit on top of the administrator's
 * settings:
 * - it publishes only a comment WordPress itself would have published: a comment that the
 *   site's own rules held (manual approval, moderation keys, too many links) stays held
 *   whatever the verdict, so Minos adds a check and never removes one;
 * - it changes only a comment that is still held, or one it published itself under
 *   fail-open and nobody touched since: when a person approved, spammed or trashed it, the
 *   verdict is recorded and the person's decision stays;
 * - a verdict applies only to the text that was sent: the receiver sends a comment that no
 *   longer reads as that text again, as its next revision;
 * - a comment longer than the gateway assesses (3000 characters) was assessed on its
 *   beginning only, so no verdict publishes it: `bezpieczne` gets the failure mode and
 *   `ocenzurowane` is held;
 * - the masked text it publishes is escaped as text and replaces the original only when
 *   it fits what was sent ({@see Text::maskFits}: the same length, only characters turned
 *   into `█`), the comment is plain text and still reads exactly as what was sent, and
 *   WordPress itself would publish the comment; it counts only once WordPress has stored
 *   exactly it. Otherwise the comment is held, never published with its original, and the
 *   masked text waits for the moderator in {@see Meta::MASKED};
 * - the moderator's "awaiting moderation" e-mail, held back while a comment waited, goes
 *   out from WP-Cron when the outcome holds it.
 *
 * The final status is written after the action, never before: when another plugin's hook
 * throws in the middle of one, the comment still waits, so the sweep, the retry or the
 * gateway's next delivery of the verdict does the step again.
 */
final class Outcome
{
    /** The WP-Cron action that sends the moderator's e-mail WordPress held back. */
    public const HOOK_NOTIFY = 'minos_moderation_notify';

    /** What a verdict asks of the comment ({@see action}). */
    private const PUBLISH = 'publish';
    private const PUBLISH_MASKED = 'publish_masked';
    private const HOLD = 'hold';
    private const BLOCK = 'block';
    private const FAILURE_MODE = 'failure_mode';

    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /** @var Log */
    private $log;

    /**
     * True while the plugin itself changes a comment (its content or its status), so the
     * `edit_comment` or `transition_comment_status` it fires is not taken for a person's.
     *
     * @var bool
     */
    private $writing = false;

    /**
     * @param Platform $wp       The WordPress adapter.
     * @param Settings $settings The settings.
     * @param Log      $log      The administrator's log.
     */
    public function __construct(Platform $wp, Settings $settings, Log $log)
    {
        $this->wp = $wp;
        $this->settings = $settings;
        $this->log = $log;
    }

    /**
     * Whether a delivered verdict may still change a comment: it is for the comment's
     * current revision, and the comment waits for its verdict or stands published by the
     * plugin's own fail-open decision with nobody touching it since. Anything else is a
     * repeated delivery, a late one with nothing left to do, or a verdict for a text the
     * comment no longer has.
     *
     * @param int $id       The comment.
     * @param int $revision The revision the verdict is for (0 for `wp:<id>`).
     * @return bool The answer.
     */
    public function awaitsVerdict(int $id, int $revision): bool
    {
        return $revision === (int)$this->wp->meta($id, Meta::REVISION)
            && ($this->wp->meta($id, Meta::STATUS) === Meta::PENDING
                || $this->wp->meta($id, Meta::AUTO_PUBLISHED) === '1');
    }

    /**
     * Whether the comment still reads as the text that was sent: its plain text, cut as it
     * was for sending, has the hash recorded when it was sent. A verdict is about that text
     * only; for an edited comment it says nothing.
     *
     * @param int $id The comment.
     * @return bool The answer; false for a comment that is gone or was never sent.
     */
    public function readsAsSent(int $id): bool
    {
        $comment = $this->wp->comment($id);
        $sent = $this->wp->meta($id, Meta::SENT_HASH);
        if ($comment === null || $sent === '') {
            return false;
        }
        // An earlier delivery of this verdict may have written the masked text before
        // another plugin's hook failed: the text that was sent is then the kept original.
        $original = $this->wp->meta($id, Meta::ORIGINAL);
        $text = $original !== '' ? $original : (string)$comment->comment_content;
        return hash_equals($sent, self::sentHash($text));
    }

    /**
     * The hash the submission records for a content: of its plain text, cut to what the
     * gateway assesses.
     *
     * @param string $content The stored comment content.
     * @return string SHA-256, hex.
     */
    public static function sentHash(string $content): string
    {
        return hash('sha256', Text::cut(Text::plain($content)));
    }

    /**
     * Whether the plugin is changing a comment right now ({@see writing}).
     *
     * @return bool The answer.
     */
    public function writing(): bool
    {
        return $this->writing;
    }

    /**
     * Applies a verdict the gateway delivered.
     *
     * @param int                 $id      A comment {@see awaitsVerdict} accepts.
     * @param array<string,mixed> $payload A payload read by `WebhookPayload::parse`.
     * @return void
     */
    public function verdict(int $id, array $payload): void
    {
        $late = $this->wp->meta($id, Meta::AUTO_PUBLISHED) === '1';
        if ($payload['wsparcie'] === true) {
            $this->wp->setMeta($id, Meta::SUPPORT, '1');
        }
        $categories = array_values(array_filter($payload['kategorie'], static function ($label): bool {
            return is_string($label) && preg_match('/^[a-z0-9_]{1,64}$/', $label) === 1;
        }));
        if ($categories !== []) {
            $this->wp->setMeta($id, Meta::CATEGORIES, implode(',', $categories));
        }

        if ($payload['status'] !== WebhookPayload::ASSESSED) {
            // A late `nieocenione` changes nothing: the failure mode was applied already.
            if (!$late) {
                $this->withoutVerdict($id, null);
            }
            return;
        }
        $qualification = (string)$payload['kwalifikacja'];
        $action = $this->action($id, $qualification);
        $masked = is_string($payload['ocenzurowany']) ? $payload['ocenzurowany'] : null;
        if ($late) {
            $this->applyLate($id, $qualification, $action, $masked);
            return;
        }
        // A comment a person decided on keeps that decision: only the verdict is recorded.
        if ($this->isHeld($id)) {
            $this->act($id, $action, $masked);
        }
        $this->settle($id, $qualification);
    }

    /**
     * Does what a verdict asks of a held comment.
     *
     * @param int         $id     The comment.
     * @param string      $action What {@see action} asked.
     * @param string|null $masked The gateway's `ocenzurowany`.
     * @return void
     */
    private function act(int $id, string $action, ?string $masked): void
    {
        switch ($action) {
            case self::PUBLISH:
                $this->publish($id);
                break;
            case self::PUBLISH_MASKED:
                if (!$this->publishMasked($id, $masked)) {
                    $this->keepMask($id, $masked);
                    $this->hold($id);
                }
                break;
            case self::BLOCK:
                if ($this->settings->all()['blocked_mode'] === Settings::BLOCKED_SPAM
                    && $this->setStatus($id, 'spam')) {
                    break;
                }
                $this->hold($id);
                break;
            case self::FAILURE_MODE:
                $this->failureMode($id);
                break;
            default:
                $this->keepMask($id, $masked);
                $this->hold($id);
        }
    }

    /**
     * Applies the failure mode to a comment without a verdict: `nieocenione`, no answer in
     * time, or a refusal that retrying will not fix.
     *
     * @param int         $id    The comment.
     * @param string|null $error The error that refused it (a gateway code or `http_<status>`).
     * @return void
     */
    public function withoutVerdict(int $id, ?string $error): void
    {
        if ($error !== null) {
            $this->wp->setMeta($id, Meta::ERROR, $error);
        }
        if ($this->isHeld($id)) {
            $this->failureMode($id);
        }
        $this->settle($id, Meta::UNASSESSED);
    }

    /**
     * Records that a step for one comment failed with an exception (another plugin's hook,
     * say): the step and the comment id in the log and in `_minos_error`, never the
     * exception's message or trace, which may carry the comment's text or the key. The
     * comment keeps waiting, so the step runs again. It never throws itself.
     *
     * @param string $step One of the `Log::STEP_*` names.
     * @param int    $id   The comment.
     * @return void
     */
    public function failed(string $step, int $id): void
    {
        try {
            $this->log->failure($step, $id);
            $this->wp->setMeta($id, Meta::ERROR, Log::failureCode($step));
        } catch (\Throwable $e) {
            // Nothing more can be recorded; the step stays undone and runs again.
        }
    }

    /**
     * The failure mode for a comment whose verdict can no longer arrive in time. Such a
     * comment was never assessed, so an edit of it is sent again ({@see Meta::TIMED_OUT}).
     *
     * @param int         $id    The comment.
     * @param string|null $error The refusal that made the wait hopeless, or null.
     * @return void
     */
    public function timedOut(int $id, ?string $error): void
    {
        $this->wp->setMeta($id, Meta::TIMED_OUT, '1');
        $this->withoutVerdict($id, $error);
    }

    /**
     * Ends the wait of a comment whose text changed since it was sent and which a person
     * decided on meanwhile: the verdict is for another text, and the person's decision
     * stands. Nothing but the status changes.
     *
     * @param int $id The comment.
     * @return void
     */
    public function abandon(int $id): void
    {
        $this->settle($id, Meta::UNASSESSED);
    }

    /**
     * `notify_moderator` filter: no "awaiting moderation" e-mail for a comment that is held
     * only until its verdict arrives. The e-mail follows if the outcome holds it.
     *
     * @param mixed      $notify    WordPress's answer so far.
     * @param int|string $commentId The comment.
     * @return mixed False for a comment waiting for its verdict; `$notify` otherwise.
     */
    public function filterNotifyModerator($notify, $commentId)
    {
        if (!$this->settings->all()['enabled']) {
            return $notify;
        }
        return $this->wp->meta((int)$commentId, Meta::STATUS) === Meta::PENDING ? false : $notify;
    }

    /**
     * The {@see HOOK_NOTIFY} action: the moderator's e-mail for a comment the outcome held.
     *
     * It runs even while moderation is switched off: it only completes an outcome applied
     * before, with the e-mail WordPress itself would have sent.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function notify($commentId): void
    {
        $id = (int)$commentId;
        try {
            if ($id > 0 && $this->wp->comment($id) !== null) {
                $this->wp->notifyModerator($id);
            }
        } catch (\Throwable $e) {
            // Another plugin's mail hook: the rest of WP-Cron's run must not stop here.
            $this->failed(Log::STEP_NOTIFY, $id);
        }
    }

    /**
     * `transition_comment_status` action: a status change after the plugin's fail-open
     * publication (a person unapproved, spammed or trashed the comment) is a decision a
     * later verdict does not overrule.
     *
     * @param mixed $newStatus The new status.
     * @param mixed $oldStatus The old status.
     * @param mixed $comment   The `WP_Comment`.
     * @return void
     */
    public function onStatusChange($newStatus, $oldStatus, $comment): void
    {
        if (!$this->writing && is_object($comment) && isset($comment->comment_ID)) {
            $this->forgetAutoPublication((int)$comment->comment_ID);
        }
    }

    /**
     * What a verdict asks of the comment, before the comment's current state is known.
     *
     * @param int    $id            The comment.
     * @param string $qualification The gateway's `kwalifikacja`.
     * @return string One of the action constants.
     */
    private function action(int $id, string $qualification): string
    {
        // Only the first 3000 characters were assessed: no verdict covers the rest.
        $cut = $this->wp->meta($id, Meta::CUT) === '1';
        switch ($qualification) {
            case 'bezpieczne':
                return $cut ? self::FAILURE_MODE : self::PUBLISH;
            case 'ocenzurowane':
                return !$cut && $this->settings->all()['censored_mode'] === Settings::CENSORED_PUBLISH
                    ? self::PUBLISH_MASKED : self::HOLD;
            case 'zablokowane':
                return self::BLOCK;
            default:
                return self::FAILURE_MODE;
        }
    }

    /**
     * A verdict for a comment the plugin published under fail-open before the verdict came
     * (the receive timeout passed). It is applied: `ocenzurowane` as the setting says,
     * `zablokowane` by holding the comment (not by marking it as spam). A verdict that
     * publishes, or would only apply the failure mode again, leaves the comment published.
     *
     * @param int         $id            The comment.
     * @param string      $qualification The gateway's `kwalifikacja`.
     * @param string      $action        What {@see action} asked.
     * @param string|null $masked        The gateway's `ocenzurowany`.
     * @return void
     */
    private function applyLate(int $id, string $qualification, string $action, ?string $masked): void
    {
        $comment = $this->wp->comment($id);
        if ($comment !== null && (string)$comment->comment_approved === '1'
            && $action !== self::PUBLISH && $action !== self::FAILURE_MODE
            && !($action === self::PUBLISH_MASKED && $this->replacesWithMask($id, $masked))) {
            $this->keepMask($id, $masked);
            if ($this->setStatus($id, 'hold')) {
                $this->scheduleNotification($id);
            }
        }
        // After the action: when it fails, the next delivery of the verdict still finds the
        // comment waiting for it.
        $this->wp->deleteMeta($id, Meta::AUTO_PUBLISHED);
        $this->settle($id, $qualification);
    }

    /**
     * The failure mode: fail-open publishes (when WordPress itself would), fail-closed holds.
     *
     * @param int $id A held comment.
     * @return void
     */
    private function failureMode(int $id): void
    {
        if ($this->settings->all()['failure_mode'] !== Settings::FAIL_OPEN) {
            $this->hold($id);
            return;
        }
        if ($this->wp->meta($id, Meta::WP_APPROVED) === '1') {
            // Before the approval, so a hook that fails after it leaves the mark in place;
            // the approval itself is the plugin's own change and does not clear it.
            $this->wp->setMeta($id, Meta::AUTO_PUBLISHED, '1');
            if ($this->setStatus($id, 'approve')) {
                return;
            }
            $this->wp->deleteMeta($id, Meta::AUTO_PUBLISHED);
        }
        $this->hold($id);
    }

    /**
     * Records the final status and ends the waiting: the last step, after the action.
     *
     * @param int    $id     The comment.
     * @param string $status The verdict or {@see Meta::UNASSESSED}.
     * @return void
     */
    private function settle(int $id, string $status): void
    {
        $this->wp->setMeta($id, Meta::STATUS, $status);
        $this->wp->deleteMeta($id, Meta::RETRY_AT);
    }

    /**
     * Whether a comment is still held, so the plugin may change it.
     *
     * @param int $id The comment.
     * @return bool The answer.
     */
    private function isHeld(int $id): bool
    {
        $comment = $this->wp->comment($id);
        return $comment !== null && (string)$comment->comment_approved === '0';
    }

    /**
     * Publishes a held comment, unless WordPress itself would have held it. Core mails the
     * post's author on the approval.
     *
     * @param int $id The comment.
     * @return void
     */
    private function publish(int $id): void
    {
        if ($this->wp->meta($id, Meta::WP_APPROVED) === '1' && $this->setStatus($id, 'approve')) {
            return;
        }
        $this->hold($id);
    }

    /**
     * Publishes a held comment in its masked form ({@see replacesWithMask}).
     *
     * @param int         $id     The comment.
     * @param string|null $masked The gateway's `ocenzurowany`.
     * @return bool Whether the masked text replaced the content; when not, the caller holds
     *     the comment.
     */
    private function publishMasked(int $id, ?string $masked): bool
    {
        if (!$this->replacesWithMask($id, $masked)) {
            return false;
        }
        $this->publish($id);
        return true;
    }

    /**
     * Puts the masked text in place of the content, when WordPress itself would publish the
     * comment: a comment the site's own rules hold keeps its content, and the masked text
     * waits in the meta for the moderator.
     *
     * @param int         $id     The comment.
     * @param string|null $masked The gateway's `ocenzurowany`.
     * @return bool Whether the masked text is now the comment's content.
     */
    private function replacesWithMask(int $id, ?string $masked): bool
    {
        return $masked !== null && $this->wp->meta($id, Meta::WP_APPROVED) === '1'
            && $this->replaceWithMasked($id, $masked);
    }

    /**
     * Keeps the gateway's masked text of a comment the plugin holds, for the moderator.
     *
     * @param int         $id     The comment.
     * @param string|null $masked The gateway's `ocenzurowany`, or null when there is none.
     * @return void
     */
    private function keepMask(int $id, ?string $masked): void
    {
        if ($masked !== null && $masked !== '') {
            $this->wp->setMeta($id, Meta::MASKED, $masked);
        }
    }

    /**
     * Leaves a comment held for manual moderation, and tells the moderator.
     *
     * @param int $id The comment.
     * @return void
     */
    private function hold(int $id): void
    {
        $this->scheduleNotification($id);
    }

    /**
     * The held-back e-mail goes out from WP-Cron, so the webhook answers fast.
     *
     * @param int $id The comment.
     * @return void
     */
    private function scheduleNotification(int $id): void
    {
        $this->wp->scheduleOnce($this->wp->now(), self::HOOK_NOTIFY, [$id]);
    }

    /**
     * Forgets that the plugin published a comment under fail-open: a person's status change
     * or edit after that publication stands.
     *
     * @param int $id The comment.
     * @return void
     */
    public function forgetAutoPublication(int $id): void
    {
        if ($id > 0 && $this->wp->meta($id, Meta::AUTO_PUBLISHED) === '1') {
            $this->wp->deleteMeta($id, Meta::AUTO_PUBLISHED);
        }
    }

    /**
     * Puts the masked text in place of the content, keeping the original in comment meta.
     *
     * Only when the comment still reads exactly as the text that was sent: a moderator's
     * edit, or a text longer than what was assessed, is never replaced. Only when that text
     * is the whole content, as plain text ({@see Text::isPlain}): replacing markup with
     * escaped text would drop links and formatting and show attribute text, which is more
     * than the masked characters. Only when the masked text fits the sent one
     * ({@see Text::maskFits}). And only when WordPress then stores exactly the masked text:
     * it may refuse the update (wpdb refuses a value longer than the column) or a filter may
     * change it on the way in. On a failure to store, the original is put back when it was
     * changed, `_minos_original` is removed once the content is the original again, and the
     * failure is recorded (its code only); the caller holds the comment.
     *
     * @param int    $id     The comment.
     * @param string $masked The gateway's `ocenzurowany`.
     * @return bool Whether the masked text is now the comment's content.
     */
    private function replaceWithMasked(int $id, string $masked): bool
    {
        $comment = $this->wp->comment($id);
        if ($comment === null) {
            return false;
        }
        $html = Text::asHtml($masked);
        $content = (string)$comment->comment_content;
        $kept = $this->wp->meta($id, Meta::ORIGINAL);
        // An earlier delivery of this verdict wrote the masked text before another plugin's
        // hook failed: the text to check is then the kept original.
        $written = $kept !== '' && $content === $html;
        $original = $written ? $kept : $content;
        $plain = Text::plain($original);
        if (Text::isCut($plain) || (int)$this->wp->meta($id, Meta::SENT_CHARS) !== mb_strlen($plain, 'UTF-8')
            || !hash_equals($this->wp->meta($id, Meta::SENT_HASH), hash('sha256', $plain))
            || !Text::isPlain($original) || !Text::maskFits($plain, $masked)) {
            return false;
        }
        if ($written) {
            return true;
        }
        $this->wp->setMeta($id, Meta::ORIGINAL, $original);
        if ($this->replaceContent($id, $html) && $this->content($id) === $html) {
            return true;
        }

        if ($this->content($id) !== $original) {
            $this->replaceContent($id, $original);
        }
        // Kept when the original could not be put back: it is then the only copy.
        if ($this->content($id) === $original) {
            $this->wp->deleteMeta($id, Meta::ORIGINAL);
        }
        $this->wp->setMeta($id, Meta::ERROR, Meta::ERROR_MASKED_WRITE);
        $this->log->record($id, null, Meta::ERROR_MASKED_WRITE);
        return false;
    }

    /**
     * Changes a comment's status; the `transition_comment_status` it fires is the plugin's
     * own.
     *
     * @param int    $id     The comment.
     * @param string $status `approve`, `hold` or `spam`.
     * @return bool Whether the status was written.
     */
    private function setStatus(int $id, string $status): bool
    {
        $this->writing = true;
        try {
            return $this->wp->setStatus($id, $status);
        } finally {
            $this->writing = false;
        }
    }

    /**
     * Writes a comment's content; the `edit_comment` it fires is the plugin's own.
     *
     * @param int    $id   The comment.
     * @param string $html The new content, safe as HTML.
     * @return bool Whether WordPress reported the update as done.
     */
    private function replaceContent(int $id, string $html): bool
    {
        $this->writing = true;
        try {
            return $this->wp->replaceContent($id, $html);
        } finally {
            $this->writing = false;
        }
    }

    /**
     * A comment's stored content, read again.
     *
     * @param int $id The comment.
     * @return string|null The content, or null when the comment is gone.
     */
    private function content(int $id): ?string
    {
        $comment = $this->wp->comment($id);
        return $comment === null ? null : (string)$comment->comment_content;
    }
}
