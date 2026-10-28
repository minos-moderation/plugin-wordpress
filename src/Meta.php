<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * The comment meta the plugin keeps, and the values of its status.
 *
 * Every key is listed in {@see ALL}, which `uninstall.php` removes; `MetaKeysTest` proves
 * that no `_minos_*` key used in `src/` is missing from it. None of them holds anything
 * about the commenter: only what the plugin did with the comment and why.
 */
final class Meta
{
    /** Where the comment stands: {@see PENDING} or the verdict (`bezpieczne`…`nieocenione`). */
    public const STATUS = '_minos_status';

    /**
     * `1` while the comment waits for its verdict ({@see STATUS} is {@see PENDING}), and gone
     * once it is settled: the sweep finds waiting comments by this key alone, never by a
     * meta value, so its query does not grow with every comment ever assessed.
     */
    public const WAITING = '_minos_waiting';

    /** When the plugin held the comment for assessment, or for its current revision (unix seconds). */
    public const HELD_AT = '_minos_held_at';

    /**
     * Which text of the comment the gateway assesses: absent or 0 for the first, `n` for the
     * text after the n-th edit that needed a new assessment. The item id is `wp:<id>` for
     * revision 0 and `wp:<id>.<n>` after it; a verdict for any other revision changes nothing.
     */
    public const REVISION = '_minos_revision';

    /** When the gateway accepted it with `202` (unix seconds). */
    public const SUBMITTED_AT = '_minos_submitted_at';

    /** What WordPress itself decided before the plugin held it: `1` publish, `0` hold. */
    public const WP_APPROVED = '_minos_wp_approved';

    /** When the next submission attempt is due (unix seconds), after a `429`/`503`. */
    public const RETRY_AT = '_minos_retry_at';

    /** How many submission attempts failed in a way worth retrying. */
    public const ATTEMPTS = '_minos_attempts';

    /** The original content of a comment the plugin published in its masked form. */
    public const ORIGINAL = '_minos_original';

    /**
     * The gateway's masked text (`ocenzurowany`) of a comment the plugin held instead of
     * publishing it masked, shown to the moderator in the "Minos" column.
     */
    public const MASKED = '_minos_masked';

    /** `1` when the gateway flagged the comment for support (`wsparcie`). */
    public const SUPPORT = '_minos_wsparcie';

    /** The gateway's category labels, comma separated. */
    public const CATEGORIES = '_minos_categories';

    /** The gateway's error code (or `http_<status>`) that refused the comment. */
    public const ERROR = '_minos_error';

    /** How many characters of the plain text were sent for assessment. */
    public const SENT_CHARS = '_minos_sent_chars';

    /**
     * SHA-256 of the text that was sent: a verdict applies only while the comment still
     * reads as that text. Absent while the current revision has not been sent yet.
     */
    public const SENT_HASH = '_minos_sent_hash';

    /**
     * `1` when the plain text was longer than the gateway assesses: only its first 3000
     * characters were sent, so no verdict covers the whole comment.
     */
    public const CUT = '_minos_cut';

    /**
     * `1` while a comment stands published by the plugin's own fail-open decision, so a
     * verdict that arrives later may still be applied to it. Any status change or edit
     * after that publication removes it: a person's decision stands.
     */
    public const AUTO_PUBLISHED = '_minos_auto_published';

    /**
     * `1` when the failure mode applied because no verdict could arrive in time (the receive
     * timeout passed): such a comment was never assessed, so an edit sends it again.
     */
    public const TIMED_OUT = '_minos_timed_out';

    /** Every key above: what `uninstall.php` deletes. */
    public const ALL = [
        self::STATUS, self::WAITING, self::HELD_AT, self::REVISION, self::SUBMITTED_AT, self::WP_APPROVED, self::RETRY_AT,
        self::ATTEMPTS, self::ORIGINAL, self::MASKED, self::SUPPORT, self::CATEGORIES, self::ERROR,
        self::SENT_CHARS, self::SENT_HASH, self::CUT, self::AUTO_PUBLISHED, self::TIMED_OUT,
    ];

    /** {@see STATUS} of a comment waiting for its verdict (a wire-style value, Polish). */
    public const PENDING = 'oczekuje';

    /** {@see STATUS} of a comment that got no verdict (the contract's `nieocenione`). */
    public const UNASSESSED = 'nieocenione';

    /**
     * {@see ERROR} when the masked text could not be written in place of the content
     * (WordPress refused the update, or stored something else): the comment stays held.
     */
    public const ERROR_MASKED_WRITE = 'zapis_zamaskowanej_nieudany';
}
