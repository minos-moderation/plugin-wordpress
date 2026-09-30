<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * Every five minutes (WP-Cron): what the webhook alone cannot finish.
 *
 * - A comment that waited past the receive timeout gets the failure mode: the gateway
 *   gives a delivery up after its TTL, and its worker may have been down for all of it.
 * - A retry that fell due is sent again, in case its own one-off event was lost. The
 *   sweep sends at most {@see MAX_SUBMISSIONS} and stops at the first answer that asks to
 *   wait, so one run stays short while the gateway is unavailable.
 */
final class Sweeper
{
    /** The WP-Cron action. */
    public const HOOK = 'minos_moderation_sweep';

    /** The schedule's name in `cron_schedules`. */
    public const SCHEDULE = 'minos_moderation_5min';

    /** The schedule's interval. */
    public const INTERVAL_S = 300;

    /** The most pending comments one run looks at. */
    public const BATCH = 200;

    /** The most comments one run sends. */
    public const MAX_SUBMISSIONS = 10;

    /** @var Platform */
    private $wp;

    /** @var Submission */
    private $submission;

    /** @var Outcome */
    private $outcome;

    /**
     * @param Platform   $wp         The WordPress adapter.
     * @param Submission $submission What sends a comment.
     * @param Outcome    $outcome    What applies the failure mode.
     */
    public function __construct(Platform $wp, Submission $submission, Outcome $outcome)
    {
        $this->wp = $wp;
        $this->submission = $submission;
        $this->outcome = $outcome;
    }

    /**
     * `cron_schedules` filter: adds the five-minute schedule.
     *
     * @param mixed $schedules The schedules so far.
     * @return mixed The schedules with this one.
     */
    public static function addSchedule($schedules)
    {
        if (!is_array($schedules)) {
            return $schedules;
        }
        $schedules[self::SCHEDULE] = [
            'interval' => self::INTERVAL_S,
            'display'  => __('Co 5 minut (Minos)', 'minos-moderation'),
        ];
        return $schedules;
    }

    /**
     * Makes sure the sweep is scheduled (activation does not run on every update).
     *
     * @return void
     */
    public function ensureScheduled(): void
    {
        $this->wp->ensureRecurring(self::HOOK, self::SCHEDULE);
    }

    /**
     * One sweep.
     *
     * @return void
     */
    public function run(): void
    {
        $sent = 0;
        $sending = true;
        foreach ($this->wp->pendingCommentIds(self::BATCH) as $id) {
            if ($this->submission->pastDeadline($id)) {
                $this->outcome->withoutVerdict($id, null);
                continue;
            }
            $retryAt = $this->wp->meta($id, Meta::RETRY_AT);
            if (!$sending || $retryAt === '' || (int)$retryAt > $this->wp->now()) {
                continue;
            }
            $answer = $this->submission->submit($id);
            $sent++;
            $sending = $answer !== Submission::RETRY && $sent < self::MAX_SUBMISSIONS;
        }
    }
}
