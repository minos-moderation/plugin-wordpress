<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * Keeps what the plugin stores about a comment for 30 days after it was settled (shared
 * rule D9.11): then every `_minos_*` key of the comment goes, the kept original of a
 * masked comment, the masked text a moderator was shown, the verdict and its categories
 * among them. The comment itself stays as it is.
 *
 * Hourly (WP-Cron), at most {@see BATCH} comments a run, also while moderation is switched
 * off: it removes the plugin's own data and changes no comment. A comment waiting for a
 * new revision's verdict has no {@see Meta::DECIDED_AT}, so it is never touched.
 */
final class Retention
{
    /** The WP-Cron action. */
    public const HOOK = 'minos_moderation_cleanup';

    /** How long the data of a settled comment is kept. */
    public const DAYS = 30;

    /** The most comments one run cleans. */
    public const BATCH = 200;

    /** @var Platform */
    private $wp;

    /** @var Log */
    private $log;

    /**
     * @param Platform $wp  The WordPress adapter.
     * @param Log      $log The administrator's log.
     */
    public function __construct(Platform $wp, Log $log)
    {
        $this->wp = $wp;
        $this->log = $log;
    }

    /**
     * Makes sure the hourly run is scheduled.
     *
     * @return void
     */
    public function ensureScheduled(): void
    {
        $this->wp->ensureRecurring(self::HOOK, 'hourly');
    }

    /**
     * One run: the data of comments settled more than {@see DAYS} days ago goes.
     *
     * @return void
     */
    public function run(): void
    {
        $cutoff = $this->wp->now() - self::DAYS * 86400;
        foreach ($this->wp->commentIdsDecidedBefore($cutoff, self::BATCH) as $id) {
            try {
                foreach (Meta::ALL as $key) {
                    $this->wp->deleteMeta($id, $key);
                }
            } catch (\Throwable $e) {
                // One comment's failure stops no other; the next run tries it again.
                $this->log->failure(Log::STEP_RETENTION, $id);
            }
        }
    }
}
