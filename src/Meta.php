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

    /** When the plugin held the comment for assessment (unix seconds). */
    public const HELD_AT = '_minos_held_at';

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

    /** `1` when the gateway flagged the comment for support (`wsparcie`). */
    public const SUPPORT = '_minos_wsparcie';

    /** The gateway's category labels, comma separated. */
    public const CATEGORIES = '_minos_categories';

    /** The gateway's error code (or `http_<status>`) that refused the comment. */
    public const ERROR = '_minos_error';

    /** How many characters of the plain text were sent for assessment. */
    public const SENT_CHARS = '_minos_sent_chars';

    /** SHA-256 of the text that was sent, to notice an edit before a masked text lands. */
    public const SENT_HASH = '_minos_sent_hash';

    /** Every key above: what `uninstall.php` deletes. */
    public const ALL = [
        self::STATUS, self::HELD_AT, self::SUBMITTED_AT, self::WP_APPROVED, self::RETRY_AT,
        self::ATTEMPTS, self::ORIGINAL, self::SUPPORT, self::CATEGORIES, self::ERROR,
        self::SENT_CHARS, self::SENT_HASH,
    ];

    /** {@see STATUS} of a comment waiting for its verdict (a wire-style value, Polish). */
    public const PENDING = 'oczekuje';

    /** {@see STATUS} of a comment that got no verdict (the contract's `nieocenione`). */
    public const UNASSESSED = 'nieocenione';
}
