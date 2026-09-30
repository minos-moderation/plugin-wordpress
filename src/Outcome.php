<?php

declare(strict_types=1);

namespace Minos\WordPress;

use Minos\Client\WebhookPayload;

defined('ABSPATH') || exit;

/**
 * Applies what the gateway decided, or the failure mode when it decided nothing.
 *
 * The plugin never guesses a verdict. Three rules sit on top of the administrator's
 * settings:
 * - it publishes only a comment WordPress itself would have published: a comment that the
 *   site's own rules held (manual approval, moderation keys, too many links) stays held
 *   whatever the verdict, so Minos adds a check and never removes one;
 * - it changes only a comment that is still held: when a person approved, spammed or
 *   trashed it in the meantime, the verdict is recorded and the person's decision stays;
 * - the masked text it publishes is escaped as text, and replaces the original only when
 *   the comment still reads as what was sent.
 */
final class Outcome
{
    /** The WP-Cron action that sends the e-mails WordPress held back while Minos decided. */
    public const HOOK_NOTIFY = 'minos_moderation_notify';

    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /**
     * @param Platform $wp       The WordPress adapter.
     * @param Settings $settings The settings.
     */
    public function __construct(Platform $wp, Settings $settings)
    {
        $this->wp = $wp;
        $this->settings = $settings;
    }

    /**
     * Applies a verdict the gateway delivered.
     *
     * @param int                 $id      A comment waiting for its verdict.
     * @param array<string,mixed> $payload A payload read by `WebhookPayload::parse`.
     * @return void
     */
    public function verdict(int $id, array $payload): void
    {
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
            $this->withoutVerdict($id, null);
            return;
        }
        $qualification = (string)$payload['kwalifikacja'];
        if (!$this->settle($id, $qualification)) {
            return;
        }

        $settings = $this->settings->all();
        if ($qualification === 'bezpieczne') {
            $this->publish($id);
        } elseif ($qualification === 'ocenzurowane') {
            $masked = $payload['ocenzurowany'];
            if ($settings['censored_mode'] === Settings::CENSORED_PUBLISH && is_string($masked)
                && $this->replaceWithMasked($id, $masked)) {
                $this->publish($id);
            } else {
                $this->hold($id);
            }
        } elseif ($settings['blocked_mode'] === Settings::BLOCKED_SPAM) {
            $this->wp->setStatus($id, 'spam');
        } else {
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
        if (!$this->settle($id, Meta::UNASSESSED)) {
            return;
        }
        if ($this->settings->all()['failure_mode'] === Settings::FAIL_OPEN) {
            $this->publish($id);
        } else {
            $this->hold($id);
        }
    }

    /**
     * `notify_moderator` filter: no "awaiting moderation" e-mail for a comment that is held
     * only until its verdict arrives. The e-mail follows if the verdict holds it.
     *
     * @param mixed      $notify    WordPress's answer so far.
     * @param int|string $commentId The comment.
     * @return mixed False for a comment waiting for its verdict; `$notify` otherwise.
     */
    public function filterNotifyModerator($notify, $commentId)
    {
        return $this->wp->meta((int)$commentId, Meta::STATUS) === Meta::PENDING ? false : $notify;
    }

    /**
     * The {@see HOOK_NOTIFY} action: the e-mail WordPress would have sent for the comment's
     * final status.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function notify($commentId): void
    {
        $id = (int)$commentId;
        if ($id > 0 && $this->wp->comment($id) !== null) {
            $this->wp->notifyAsWordPressWould($id);
        }
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
     * Publishes a held comment, unless WordPress itself would have held it.
     *
     * @param int $id The comment.
     * @return void
     */
    private function publish(int $id): void
    {
        if ($this->wp->meta($id, Meta::WP_APPROVED) === '1') {
            $this->wp->setStatus($id, 'approve');
        }
        $this->scheduleNotification($id);
    }

    /**
     * Leaves a comment held for manual moderation.
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
     * Puts the masked text in place of the content, keeping the original in comment meta.
     *
     * The gateway masked the plain text that was sent (the first 3000 characters); what
     * followed it was never assessed and is kept as it was, as plain text. When the comment
     * no longer starts with the text that was sent (a moderator edited it), nothing is
     * replaced and the caller holds it.
     *
     * @param int    $id     The comment.
     * @param string $masked The gateway's `ocenzurowany`.
     * @return bool Whether the content was replaced.
     */
    private function replaceWithMasked(int $id, string $masked): bool
    {
        $comment = $this->wp->comment($id);
        if ($comment === null) {
            return false;
        }
        $original = (string)$comment->comment_content;
        $plain = Text::plain($original);
        $sentChars = (int)$this->wp->meta($id, Meta::SENT_CHARS);
        $sent = Text::cut($plain);
        if ($sentChars !== mb_strlen($sent, 'UTF-8')
            || !hash_equals($this->wp->meta($id, Meta::SENT_HASH), hash('sha256', $sent))) {
            return false;
        }
        $rest = mb_substr($plain, $sentChars, null, 'UTF-8');
        $this->wp->setMeta($id, Meta::ORIGINAL, $original);
        $this->wp->replaceContent($id, Text::asHtml($masked . $rest));
        return true;
    }
}
