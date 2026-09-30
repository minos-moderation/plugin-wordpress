<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * The one adapter between the moderation logic and WordPress.
 *
 * Every WordPress function the submission, the receiver, the verdicts and the sweeper call
 * goes through here, so the list of platform touch points is this file, and the tests run
 * the real logic against hand-written stubs of these functions (`tests/stubs/`). The hook
 * wiring (`Plugin`) and the admin screens (`Admin\`) are WordPress glue and call it
 * directly. Every function used here was checked against the WordPress 6.0 and 7.1 sources.
 */
class Platform
{
    /** @var callable():int */
    private $clock;

    /**
     * @param callable():int|null $clock The clock, in unix seconds; `time()` by default. Tests
     *     pass a fixed one.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * The current time.
     *
     * @return int Unix seconds.
     */
    public function now(): int
    {
        return (int)call_user_func($this->clock);
    }

    /**
     * Reads an option.
     *
     * @param string $name    The option.
     * @param mixed  $default What a missing option reads as.
     * @return mixed The value.
     */
    public function option(string $name, $default = false)
    {
        return get_option($name, $default);
    }

    /**
     * Writes an option.
     *
     * @param string $name     The option.
     * @param mixed  $value    The value.
     * @param bool   $autoload Whether WordPress loads it on every request (only when created).
     * @return void
     */
    public function saveOption(string $name, $value, bool $autoload = false): void
    {
        update_option($name, $value, $autoload);
    }

    /**
     * Creates an option unless it exists; used to create the secrets non-autoloaded.
     *
     * @param string $name  The option.
     * @param mixed  $value The value.
     * @return void
     */
    public function addOption(string $name, $value): void
    {
        add_option($name, $value, '', false);
    }

    /**
     * Deletes an option.
     *
     * @param string $name The option.
     * @return void
     */
    public function deleteOption(string $name): void
    {
        delete_option($name);
    }

    /**
     * A comment.
     *
     * @param int $id The comment id.
     * @return object|null The `WP_Comment`, or null when there is none.
     */
    public function comment(int $id): ?object
    {
        $comment = get_comment($id);
        return is_object($comment) ? $comment : null;
    }

    /**
     * One comment meta value.
     *
     * @param int    $id  The comment id.
     * @param string $key The meta key.
     * @return string The value, or '' when it is not set.
     */
    public function meta(int $id, string $key): string
    {
        $value = get_comment_meta($id, $key, true);
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Sets a comment meta value. WordPress unslashes what it is given, hence the slash:
     * without it a backslash in a stored original would be lost.
     *
     * @param int        $id    The comment id.
     * @param string     $key   The meta key.
     * @param string|int $value The value.
     * @return void
     */
    public function setMeta(int $id, string $key, $value): void
    {
        update_comment_meta($id, $key, wp_slash((string)$value));
    }

    /**
     * Removes a comment meta value.
     *
     * @param int    $id  The comment id.
     * @param string $key The meta key.
     * @return void
     */
    public function deleteMeta(int $id, string $key): void
    {
        delete_comment_meta($id, $key);
    }

    /**
     * Removes a meta key from every comment (uninstall).
     *
     * @param string $key The meta key.
     * @return void
     */
    public function deleteMetaEverywhere(string $key): void
    {
        delete_metadata('comment', 0, $key, '', true);
    }

    /**
     * Changes a comment's status.
     *
     * @param int    $id     The comment id.
     * @param string $status `approve`, `hold` or `spam`.
     * @return void
     */
    public function setStatus(int $id, string $status): void
    {
        wp_set_comment_status($id, $status);
    }

    /**
     * Replaces a comment's content. WordPress unslashes what it is given, hence the slash.
     *
     * @param int    $id   The comment id.
     * @param string $html The new content, already safe as HTML.
     * @return void
     */
    public function replaceContent(int $id, string $html): void
    {
        wp_update_comment(['comment_ID' => $id, 'comment_content' => wp_slash($html)]);
    }

    /**
     * Whether a user holds a capability.
     *
     * @param int    $userId     The user id.
     * @param string $capability The capability.
     * @return bool The answer.
     */
    public function userCan(int $userId, string $capability): bool
    {
        return (bool)user_can($userId, $capability);
    }

    /**
     * Whether the current user holds a capability.
     *
     * @param string $capability The capability.
     * @return bool The answer.
     */
    public function currentUserCan(string $capability): bool
    {
        return (bool)current_user_can($capability);
    }

    /**
     * How many approved comments a commenter has, the one being assessed excluded (it is held).
     *
     * The e-mail address is used only for this local query and never leaves the site.
     *
     * @param int    $userId The commenter's user id, or 0.
     * @param string $email  The commenter's e-mail address, for a guest.
     * @return int The count, at most 1 (it answers "any?").
     */
    public function approvedCommentsBy(int $userId, string $email): int
    {
        $args = ['status' => 'approve', 'count' => true];
        if ($userId > 0) {
            $args['user_id'] = $userId;
        } elseif ($email !== '') {
            $args['author_email'] = $email;
        } else {
            return 0;
        }
        return min(1, (int)get_comments($args));
    }

    /**
     * Comments waiting for their verdict, oldest first.
     *
     * @param int $limit The most to return.
     * @return array<int,int> Comment ids.
     */
    public function pendingCommentIds(int $limit): array
    {
        $ids = get_comments([
            'status'     => 'any',
            'meta_key'   => Meta::STATUS,
            'meta_value' => Meta::PENDING,
            'fields'     => 'ids',
            'number'     => $limit,
            'orderby'    => 'comment_ID',
            'order'      => 'ASC',
        ]);
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * How many comments flagged for support were written since a moment.
     *
     * @param int $since Unix seconds.
     * @return int The count.
     */
    public function supportFlagsSince(int $since): int
    {
        return (int)get_comments([
            'status'     => 'any',
            'meta_key'   => Meta::SUPPORT,
            'meta_value' => '1',
            'count'      => true,
            'date_query' => [['column' => 'comment_date_gmt', 'after' => gmdate('Y-m-d H:i:s', $since)]],
        ]);
    }

    /**
     * A `POST` to the gateway. Redirects are never followed: the key must not travel to
     * another host.
     *
     * @param string               $url      The URL.
     * @param array<string,string> $headers  The headers.
     * @param string               $body     The body.
     * @param int                  $timeoutS The timeout, connection included.
     * @return array{status:int|null,body:string} `status` is null when no answer came.
     */
    public function post(string $url, array $headers, string $body, int $timeoutS): array
    {
        $response = wp_remote_post($url, [
            'timeout'     => $timeoutS,
            'redirection' => 0,
            'headers'     => $headers,
            'body'        => $body,
        ]);
        if (is_wp_error($response)) {
            return ['status' => null, 'body' => ''];
        }
        $status = wp_remote_retrieve_response_code($response);
        return [
            'status' => is_numeric($status) && (int)$status > 0 ? (int)$status : null,
            'body'   => (string)wp_remote_retrieve_body($response),
        ];
    }

    /**
     * Schedules a one-off WP-Cron event.
     *
     * @param int               $at   Unix seconds.
     * @param string            $hook The action.
     * @param array<int,mixed>  $args Its arguments.
     * @return void
     */
    public function scheduleOnce(int $at, string $hook, array $args): void
    {
        wp_schedule_single_event($at, $hook, $args);
    }

    /**
     * Makes sure a recurring WP-Cron event is scheduled.
     *
     * @param string $hook     The action.
     * @param string $interval A schedule name from `cron_schedules`.
     * @return void
     */
    public function ensureRecurring(string $hook, string $interval): void
    {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event($this->now(), $interval, $hook);
        }
    }

    /**
     * Removes every scheduled event of an action, whatever its arguments.
     *
     * @param string $hook The action.
     * @return void
     */
    public function unscheduleAll(string $hook): void
    {
        wp_unschedule_hook($hook);
    }

    /**
     * Sends the e-mails WordPress would have sent when the comment was posted: to the
     * moderator for a held comment, to the post's author for a published one. Each
     * function checks the site's settings and the comment's status itself.
     *
     * @param int $id The comment id.
     * @return void
     */
    public function notifyAsWordPressWould(int $id): void
    {
        wp_new_comment_notify_moderator($id);
        wp_new_comment_notify_postauthor($id);
    }

    /**
     * The public URL of a REST route of this site.
     *
     * @param string $path The route, e.g. `minos/v1/webhook`.
     * @return string The URL.
     */
    public function restUrl(string $path): string
    {
        return (string)rest_url($path);
    }

    /**
     * A REST answer without a body worth reading (the gateway discards it).
     *
     * @param int $status The HTTP status.
     * @return \WP_REST_Response The response.
     */
    public function response(int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(null, $status);
    }
}
