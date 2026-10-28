<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
use Minos\WordPress\Upgrade;
use WpStub;

/**
 * The five-minute sweep through its WP-Cron action: the receive timeout and the retries.
 */
final class SweeperTest extends PluginTestCase
{
    public function testTheScheduleIsFiveMinutesAndStartsWithWordPress(): void
    {
        $schedules = apply_filters('cron_schedules', []);
        self::assertSame(300, $schedules[Sweeper::SCHEDULE]['interval']);

        do_action('init');
        do_action('init');
        $sweeps = self::events(Sweeper::HOOK);
        self::assertCount(1, $sweeps, 'scheduled once, however often WordPress starts');
        self::assertSame(Sweeper::SCHEDULE, $sweeps[0]['recurrence']);
    }

    public function testACommentPastTheTimeoutGetsTheFailureMode(): void
    {
        $this->configure(['timeout_min' => 20, 'failure_mode' => Settings::FAIL_OPEN]);
        $late = $this->post('Werdykt nie nadszedł.');
        $this->now += 19 * 60;
        $recent = $this->post('Werdykt jeszcze może nadejść.');

        $this->now += 60;
        do_action(Sweeper::HOOK);

        self::assertSame(Meta::UNASSESSED, $this->field($late, Meta::STATUS));
        self::assertSame('1', $this->field($late, 'comment_approved'));
        self::assertSame('1', $this->field($late, Meta::AUTO_PUBLISHED), 'a later verdict may still apply');
        self::assertTrue($this->pending($recent));
        self::assertSame('0', $this->field($recent, 'comment_approved'));
    }

    public function testFailClosedLeavesATimedOutCommentHeld(): void
    {
        $this->configure(['timeout_min' => 20, 'failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post('Werdykt nie nadszedł przy fail-closed.');

        $this->now += 20 * 60;
        do_action(Sweeper::HOOK);

        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
        self::assertSame('0', $this->field($id, 'comment_approved'));
        // A verdict that comes after the timeout finds nothing waiting.
        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne')));
        self::assertSame('0', $this->field($id, 'comment_approved'));
    }

    public function testTheTimeoutCountsFromTheGatewaysAcceptance(): void
    {
        $this->configure(['timeout_min' => 20]);
        WpStub::answer(503);
        $id = $this->post('Przyjęty dopiero za drugim razem.');

        $this->now += 10 * 60;
        do_action(Sweeper::HOOK);
        self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT), 'the due retry was sent');

        $this->now += 15 * 60;
        do_action(Sweeper::HOOK);
        self::assertTrue($this->pending($id), '25 minutes after it was held, 15 after it was accepted');
    }

    public function testACommentNeverAcceptedTimesOutFromWhenItWasHeld(): void
    {
        $this->configure(['timeout_min' => 20]);
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 19 * 60]]);
        $id = $this->post('Limit minutowy wyczerpany.');
        self::assertTrue($this->pending($id), 'the pause ends before the timeout: it waits');

        $this->now += 20 * 60;
        do_action(Sweeper::HOOK);
        do_action(Submission::HOOK_RETRY, $id);

        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
        self::assertSame('1', $this->field($id, Meta::TIMED_OUT));
        self::assertCount(1, WpStub::$requests, 'no retry after the timeout');
    }

    public function testADueRetryIsSentAndOneNotDueIsNot(): void
    {
        $this->configure();
        // A 202 that took neither: each is retried after its own backoff, with no pause.
        WpStub::answer(202, ['przyjete' => []]);
        $due = $this->post('Ponowienie za minutę.');
        $this->now += 30;
        WpStub::answer(202, ['przyjete' => []]);
        $later = $this->post('Ponowienie za półtorej minuty.');

        $this->now += 30;
        do_action(Sweeper::HOOK);

        self::assertCount(3, WpStub::$requests);
        self::assertSame(['wp:' . $due], array_column($this->sentBody()['elementy'], 'id'));
        self::assertSame((string)$this->now, $this->field($due, Meta::SUBMITTED_AT));
        self::assertNull($this->field($later, Meta::SUBMITTED_AT));
    }

    public function testASweepSendsAtMostItsRequestsAndLeavesTheRestDue(): void
    {
        $this->configure();
        WpStub::networkFailure();
        $ids = [];
        for ($i = 0; $i < Sweeper::MAX_REQUESTS * Submission::MAX_ITEMS + 5; $i++) {
            $ids[] = $this->post('Komentarz w kolejce ponowień numer ' . $i . '.');
        }
        $before = count(WpStub::$requests);
        $this->now += Submission::FIRST_BACKOFF_S;
        do_action(Sweeper::HOOK);

        self::assertSame(Sweeper::MAX_REQUESTS, count(WpStub::$requests) - $before);
        foreach (array_slice($ids, -5) as $id) {
            self::assertNull($this->field($id, Meta::SUBMITTED_AT));
            self::assertTrue($this->plugin->submission->due($id), 'left due');
        }
        do_action(Sweeper::HOOK);
        self::assertSame(Sweeper::MAX_REQUESTS + 1, count(WpStub::$requests) - $before, 'the next run sends them');
    }

    public function testTheSweepFindsWaitingCommentsByTheirKeyAloneAndNeverTheSettledOnes(): void
    {
        $this->configure();
        $settled = $this->post('Komentarz już oceniony.');
        $this->deliver(self::verdict($settled, 'bezpieczne'));
        $waiting = $this->post('Komentarz czekający na werdykt.');
        self::assertNull($this->field($settled, Meta::WAITING), 'settled: no longer marked');
        self::assertSame('1', $this->field($waiting, Meta::WAITING));
        WpStub::$queries = [];

        self::assertSame([$waiting], $this->plugin->wp->pendingCommentIds(Sweeper::BATCH));
        WpStub::$queries = [];
        do_action(Sweeper::HOOK);

        self::assertSame([Meta::WAITING, Meta::RETRY_AT], array_column(WpStub::$queries, 'meta_key'),
            'the timeouts by the waiting mark, the retries by their own key');
        foreach (WpStub::$queries as $query) {
            self::assertArrayNotHasKey('meta_value', $query, 'never a query by meta value');
            self::assertArrayNotHasKey('meta_query', $query);
        }
    }

    public function testAnUpdateMarksTheCommentsThatWereWaitingBeforeIt(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $waiting = $this->post('Komentarz czekający przed aktualizacją.');
        $settled = $this->post('Komentarz oceniony przed aktualizacją.');
        $this->deliver(self::verdict($settled, 'bezpieczne'));
        // What the previous version stored: no waiting mark, no data version.
        unset(WpStub::$meta[$waiting][Meta::WAITING], WpStub::$options[Upgrade::OPTION]);
        self::assertSame([], $this->plugin->wp->pendingCommentIds(Sweeper::BATCH), 'invisible to the sweep');

        do_action('init');

        self::assertSame('1', $this->field($waiting, Meta::WAITING));
        self::assertNull($this->field($settled, Meta::WAITING));
        self::assertSame(Upgrade::VERSION, WpStub::$options[Upgrade::OPTION]);
        self::assertTrue(WpStub::$autoload[Upgrade::OPTION], 'read on every request, so autoloaded');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        self::assertSame(Meta::UNASSESSED, $this->field($waiting, Meta::STATUS), 'the sweep finds it again');

        // Once done, a request reads the version and stops: no query, no write.
        WpStub::$queries = [];
        WpStub::$optionWrites = [];
        do_action('init');
        self::assertSame([], WpStub::$queries);
        self::assertNotContains(Upgrade::OPTION, WpStub::$optionWrites);
    }

    public function testACommentAPersonDecidedIsNotChangedByTheTimeout(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post('Usunięty ręcznie przed upływem czasu.');
        wp_set_comment_status($id, 'spam');

        $this->now += 3600;
        do_action(Sweeper::HOOK);

        self::assertSame('spam', $this->field($id, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
    }

    /**
     * The reviewer's LOAD probe turned around: a key of 10 requests a minute and a backlog
     * larger than the sweep's window. The oldest comments were accepted late and still wait
     * for their verdicts in time; the newer ones were never accepted and are past their
     * timeout. They get the failure mode in that very pass, not once the older ones settle.
     */
    public function testARetryPastItsTimeoutBeyondTheWaitingWindowGetsTheFailureModeInTheSamePass(): void
    {
        $this->configure(['timeout_min' => 20, 'failure_mode' => Settings::FAIL_CLOSED]);
        $down = true;
        $perMinute = [];
        add_filter('pre_http_request', function ($pre, $args) use (&$down, &$perMinute) {
            WpStub::$requests[] = ['url' => '', 'args' => $args];
            if ($down) {
                return new \WP_Error('http_request_failed', 'cURL error 7: connection refused');
            }
            $minute = intdiv($this->now, 60);
            $perMinute[$minute] = ($perMinute[$minute] ?? 0) + 1;
            if ($perMinute[$minute] > 10) {
                return ['response' => ['code' => 429], 'body' => (string)json_encode(['blad' => [
                    'kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 60 - $this->now % 60]])];
            }
            return WpStub::accepted($args);
        }, 10, 2);
        $start = $this->now;
        $ids = [];
        for ($i = 0; $i < Sweeper::BATCH + 10; $i++) {
            $ids[] = $this->post('Komentarz z zaległości numer ' . $i . '.');
        }
        $down = false;

        // Two sweeps take the oldest 200, five requests of 20 each time.
        foreach ([10, 15] as $minutes) {
            $this->now = $start + $minutes * 60;
            do_action(Sweeper::HOOK);
        }
        $old = array_slice($ids, 0, Sweeper::BATCH);
        $new = array_slice($ids, Sweeper::BATCH);
        foreach ($old as $id) {
            self::assertNotNull($this->field($id, Meta::SUBMITTED_AT), 'accepted late, in time');
        }
        foreach ($new as $id) {
            self::assertNotNull($this->field($id, Meta::RETRY_AT), 'never accepted');
        }
        self::assertSame($old, $this->plugin->wp->pendingCommentIds(Sweeper::BATCH), 'the waiting window holds only the old ones');
        $requests = count(WpStub::$requests);

        $this->now = $start + 20 * 60;
        do_action(Sweeper::HOOK);

        foreach ($new as $id) {
            self::assertSame([Meta::UNASSESSED, '1', '0'],
                [$this->field($id, Meta::STATUS), $this->field($id, Meta::TIMED_OUT), $this->field($id, 'comment_approved')]);
            self::assertNull($this->field($id, Meta::RETRY_AT));
        }
        foreach ($old as $id) {
            self::assertTrue($this->pending($id), 'their timeout counts from their acceptance');
        }
        self::assertCount($requests, WpStub::$requests, 'nothing sent for a comment past its timeout');
    }

    public function testAStaleRetryMarkOnAnAssessedCommentChangesNothing(): void
    {
        $this->configure(['timeout_min' => 20]);
        $id = $this->post('Komentarz oceniony, ze starym znacznikiem ponowienia.');
        $this->deliver(self::verdict($id, 'bezpieczne'));
        WpStub::$meta[$id][Meta::RETRY_AT] = (string)$this->now;

        $this->now += 3600;
        do_action(Sweeper::HOOK);

        self::assertSame(['bezpieczne', '1'], [$this->field($id, Meta::STATUS), $this->field($id, 'comment_approved')]);
        self::assertNull($this->field($id, Meta::TIMED_OUT));
    }
}
