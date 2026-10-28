<?php

declare(strict_types=1);

namespace Minos\WordPress;

defined('ABSPATH') || exit;

/**
 * Every five minutes (WP-Cron): what the webhook alone cannot finish.
 *
 * - A comment that waited past the receive timeout gets the failure mode: the gateway
 *   gives a delivery up after its TTL, and its worker may have been down for all of it.
 * - The retries that fell due are sent again, in requests of up to
 *   {@see Submission::MAX_ITEMS} comments ({@see Submission::sendDue}): the gateway's limits
 *   count requests, not items. The sweep sends at most {@see MAX_REQUESTS} and stops at the
 *   first answer that asks to wait, so one run stays short while the gateway is
 *   unavailable; while the gateway's pause runs it sends nothing.
 *
 * Each comment is handled on its own: an exception from another plugin's hook is recorded
 * by step and comment id ({@see Outcome::failed}) and the run goes on.
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

    /**
     * The most requests one run sends, each up to {@see Submission::MAX_ITEMS} comments.
     * Each may take {@see Submission::TIMEOUT_S}: five keep the worst case of a run at
     * half of the ten one-comment requests it was, and carry a hundred comments, ten times
     * as many. What is left stays due for the next run or its own retry event.
     */
    public const MAX_REQUESTS = 5;

    /** @var Platform */
    private $wp;

    /** @var Submission */
    private $submission;

    /** @var Outcome */
    private $outcome;

    /** @var Settings */
    private $settings;

    /**
     * @param Platform   $wp         The WordPress adapter.
     * @param Submission $submission What sends a comment.
     * @param Outcome    $outcome    What applies the failure mode.
     * @param Settings   $settings   The settings.
     */
    public function __construct(Platform $wp, Submission $submission, Outcome $outcome, Settings $settings)
    {
        $this->wp = $wp;
        $this->submission = $submission;
        $this->outcome = $outcome;
        $this->settings = $settings;
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
     * One sweep; none while moderation is switched off, so waiting comments stay in
     * WordPress's moderation queue for a person.
     *
     * @return void
     */
    public function run(): void
    {
        if (!$this->settings->all()['enabled']) {
            return;
        }
        foreach ($this->wp->pendingCommentIds(self::BATCH) as $id) {
            // One comment that fails (another plugin's hook throwing) stops nothing else: it
            // keeps waiting, and the next sweep does its step again.
            try {
                if ($this->submission->pastDeadline($id)) {
                    $this->outcome->timedOut($id, null);
                }
            } catch (\Throwable $e) {
                $this->outcome->failed(Log::STEP_TIMEOUT, $id);
            }
        }
        // The retries by their own key: comments waiting for a verdict never fill this window.
        $due = [];
        foreach ($this->wp->retryCommentIds(self::BATCH) as $id) {
            try {
                if ($this->submission->due($id)) {
                    $due[] = $id;
                }
            } catch (\Throwable $e) {
                // Left to its own retry event or the next run.
            }
        }
        // Each comment and each request is isolated inside; anything else waits for the next run.
        try {
            $this->submission->sendDue($due, self::MAX_REQUESTS);
        } catch (\Throwable $e) {
            if ($due !== []) {
                $this->outcome->failed(Log::STEP_SUBMIT, $due[0]);
            }
        }
    }
}
