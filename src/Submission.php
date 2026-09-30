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
 * the plugin is switched off or has no key or secret.
 *
 * What travels is the comment's id, its plain text (the first 3000 characters; a longer
 * comment is marked {@see Meta::CUT} and no verdict publishes it), the profile, and spam
 * signals: the number of links, their registrable domains, and whether this is the
 * author's first approved comment. Never the author's name, e-mail, IP address, website
 * or user id.
 *
 * While moderation is switched off nothing is sent, not even a retry: comments already
 * waiting stay in WordPress's moderation queue for a person.
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
            || !$this->settings->isActive()) {
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
     * {@see HOOK_RETRY} action: a retry that fell due.
     *
     * @param int|string $commentId The comment.
     * @return void
     */
    public function retry($commentId): void
    {
        $id = (int)$commentId;
        $retryAt = $this->wp->meta($id, Meta::RETRY_AT);
        if ($this->wp->meta($id, Meta::STATUS) === Meta::PENDING && $retryAt !== ''
            && (int)$retryAt <= $this->wp->now() && !$this->pastDeadline($id)) {
            $this->submit($id);
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
        $since = $this->wp->meta($id, Meta::SUBMITTED_AT);
        if ($since === '') {
            $since = $this->wp->meta($id, Meta::HELD_AT);
        }
        return $this->wp->now() >= (int)$since + $this->settings->timeoutS();
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
        if ($text === '' || $key === '') {
            // Nothing to assess, or nothing to assess it with: no verdict, no guess.
            $this->outcome->withoutVerdict($id, null);
            return self::REFUSED;
        }
        if (Text::isCut($plain)) {
            $this->wp->setMeta($id, Meta::CUT, '1');
        } else {
            $this->wp->deleteMeta($id, Meta::CUT);
        }
        $this->wp->setMeta($id, Meta::SENT_CHARS, mb_strlen($text, 'UTF-8'));
        $this->wp->setMeta($id, Meta::SENT_HASH, hash('sha256', $text));
        $this->wp->deleteMeta($id, Meta::RETRY_AT);

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
            'id'     => 'wp:' . (int)$comment->comment_ID,
            'tekst'  => $text,
            'profil' => $this->settings->all()['profile'],
            'meta'   => $meta,
        ];
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
        $this->wp->setMeta($id, Meta::HELD_AT, $this->wp->now());
        $this->wp->setMeta($id, Meta::WP_APPROVED, $wordpress);
        $this->submit($id);
    }

    /**
     * Records the gateway's answer.
     *
     * - `202`: accepted; the verdict will come to the webhook.
     * - `429`, `5xx` or no answer: kept pending, retried after `ponow_za_s` or a doubling
     *   backoff.
     * - anything else: a configuration error (the key, the webhook, the URL); retrying will
     *   not help, so the failure mode applies and the administrator is told.
     *
     * @param int         $id     The comment.
     * @param int|null    $status The HTTP status, or null when no answer came.
     * @param string      $body   The answer's body.
     * @return string {@see ACCEPTED}, {@see RETRY} or {@see REFUSED}.
     */
    private function handleAnswer(int $id, ?int $status, string $body): string
    {
        if ($status === 202) {
            $this->wp->setMeta($id, Meta::SUBMITTED_AT, $this->wp->now());
            $this->wp->deleteMeta($id, Meta::ATTEMPTS);
            $this->log->clearConfigError();
            return self::ACCEPTED;
        }
        $error = self::error($body);
        $this->log->record($id, $status, $error['kod']);
        if ($status === null || $status === 429 || $status >= 500) {
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
     * @return array{kod:string|null,ponow_za_s:int|null} What could be read.
     */
    private static function error(string $body): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['blad'] ?? null) ? $decoded['blad'] : [];
        $code = is_string($error['kod'] ?? null) ? Log::safeCode($error['kod']) : null;
        $retry = $error['ponow_za_s'] ?? null;
        return [
            'kod'        => $code,
            'ponow_za_s' => is_int($retry) && $retry > 0 ? min($retry, 86400) : null,
        ];
    }
}
