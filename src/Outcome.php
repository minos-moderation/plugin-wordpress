<?php

declare(strict_types=1);

namespace Minos\WordPress;

use Minos\Client\WebhookPayload;

defined('ABSPATH') || exit;

/**
 * Applies what the gateway decided, or the failure mode when it decided nothing.
 *
 * The plugin never guesses a verdict. Five rules sit on top of the administrator's
 * settings:
 * - it publishes only a comment WordPress itself would have published: a comment that the
 *   site's own rules held (manual approval, moderation keys, too many links) stays held
 *   whatever the verdict, so Minos adds a check and never removes one;
 * - it changes only a comment that is still held, or one it published itself under
 *   fail-open and nobody touched since: when a person approved, spammed, trashed or edited
 *   it, the verdict is recorded and the person's decision stays;
 * - a comment longer than the gateway assesses (3000 characters) was assessed on its
 *   beginning only, so no verdict publishes it: `bezpieczne` gets the failure mode and
 *   `ocenzurowane` is held;
 * - the masked text it publishes is escaped as text, replaces the original only when the
 *   comment still reads exactly as what was sent, and counts only once WordPress has
 *   stored exactly it — otherwise the comment is held, never published with its original;
 * - the moderator's "awaiting moderation" e-mail, held back while a comment waited, goes
 *   out from WP-Cron when the outcome holds it.
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
     * Whether a delivered verdict may still change a comment: it waits for its verdict, or
     * it stands published by the plugin's own fail-open decision and nobody touched it
     * since. Anything else is a repeated or a late delivery with nothing left to do.
     *
     * @param int $id The comment.
     * @return bool The answer.
     */
    public function awaitsVerdict(int $id): bool
    {
        return $this->wp->meta($id, Meta::STATUS) === Meta::PENDING
            || $this->wp->meta($id, Meta::AUTO_PUBLISHED) === '1';
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
        if (!$this->settle($id, $qualification)) {
            return;
        }
        switch ($action) {
            case self::PUBLISH:
                $this->publish($id);
                break;
            case self::PUBLISH_MASKED:
                if ($masked !== null && $this->replaceWithMasked($id, $masked)) {
                    $this->publish($id);
                } else {
                    $this->hold($id);
                }
                break;
            case self::BLOCK:
                if ($this->settings->all()['blocked_mode'] === Settings::BLOCKED_SPAM
                    && $this->wp->setStatus($id, 'spam')) {
                    break;
                }
                $this->hold($id);
                break;
            case self::FAILURE_MODE:
                $this->failureMode($id);
                break;
            default:
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
        if ($this->settle($id, Meta::UNASSESSED)) {
            $this->failureMode($id);
        }
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
        if ($id > 0 && $this->wp->comment($id) !== null) {
            $this->wp->notifyModerator($id);
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
        if (is_object($comment) && isset($comment->comment_ID)) {
            $this->forgetAutoPublication((int)$comment->comment_ID);
        }
    }

    /**
     * `edit_comment` action: so is an edit.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function onEdit($commentId): void
    {
        $this->forgetAutoPublication((int)$commentId);
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
        // First, so the plugin's own changes below do not count as a person's.
        $this->wp->deleteMeta($id, Meta::AUTO_PUBLISHED);
        $this->wp->setMeta($id, Meta::STATUS, $qualification);
        $comment = $this->wp->comment($id);
        if ($comment === null || (string)$comment->comment_approved !== '1'
            || $action === self::PUBLISH || $action === self::FAILURE_MODE) {
            return;
        }
        if ($action === self::PUBLISH_MASKED && $masked !== null && $this->replaceWithMasked($id, $masked)) {
            return;
        }
        if ($this->wp->setStatus($id, 'hold')) {
            $this->scheduleNotification($id);
        }
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
        if ($this->wp->meta($id, Meta::WP_APPROVED) === '1' && $this->wp->setStatus($id, 'approve')) {
            // Set after the approval, so its own status change does not clear it.
            $this->wp->setMeta($id, Meta::AUTO_PUBLISHED, '1');
            return;
        }
        $this->hold($id);
    }

    /**
     * Records the final status and ends the waiting.
     *
     * @param int    $id     The comment.
     * @param string $status The verdict or {@see Meta::UNASSESSED}.
     * @return bool Whether the comment is still held, so the plugin may change it.
     */
    private function settle(int $id, string $status): bool
    {
        $this->wp->setMeta($id, Meta::STATUS, $status);
        $this->wp->deleteMeta($id, Meta::RETRY_AT);
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
        if ($this->wp->meta($id, Meta::WP_APPROVED) === '1' && $this->wp->setStatus($id, 'approve')) {
            return;
        }
        $this->hold($id);
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
     * Forgets that the plugin published a comment under fail-open.
     *
     * @param int $id The comment.
     * @return void
     */
    private function forgetAutoPublication(int $id): void
    {
        if ($id > 0 && $this->wp->meta($id, Meta::AUTO_PUBLISHED) === '1') {
            $this->wp->deleteMeta($id, Meta::AUTO_PUBLISHED);
        }
    }

    /**
     * Puts the masked text in place of the content, keeping the original in comment meta.
     *
     * Only when the comment still reads exactly as the text that was sent: a moderator's
     * edit, or a text longer than what was assessed, is never replaced. And only when
     * WordPress then stores exactly the masked text: it may refuse the update (wpdb refuses
     * a value longer than the column, and escaping can make a text six times longer) or a
     * filter may change it on the way in. On a failure the original is put back when it
     * was changed, `_minos_original` is removed once the content is the original again,
     * and the failure is recorded (its code only); the caller holds the comment.
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
        $original = (string)$comment->comment_content;
        $plain = Text::plain($original);
        if (Text::isCut($plain) || (int)$this->wp->meta($id, Meta::SENT_CHARS) !== mb_strlen($plain, 'UTF-8')
            || !hash_equals($this->wp->meta($id, Meta::SENT_HASH), hash('sha256', $plain))) {
            return false;
        }
        $html = Text::asHtml($masked);
        $this->wp->setMeta($id, Meta::ORIGINAL, $original);
        if ($this->wp->replaceContent($id, $html) && $this->content($id) === $html) {
            return true;
        }

        if ($this->content($id) !== $original) {
            $this->wp->replaceContent($id, $original);
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
