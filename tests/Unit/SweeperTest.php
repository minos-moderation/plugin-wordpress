<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
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
        WpStub::answer(429, ['blad' => ['kod' => 'limit_dobowy_klucza', 'ponow_za_s' => 3600]]);
        $id = $this->post('Dzienny limit wyczerpany.');

        $this->now += 20 * 60;
        do_action(Sweeper::HOOK);
        do_action(Submission::HOOK_RETRY, $id);

        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
        self::assertCount(1, WpStub::$requests, 'no retry after the timeout');
    }

    public function testADueRetryIsSentAndOneNotDueIsNot(): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 30]]);
        $due = $this->post('Ponowienie za 30 s.');
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 600]]);
        $later = $this->post('Ponowienie za 10 min.');

        $this->now += 30;
        do_action(Sweeper::HOOK);

        self::assertCount(3, WpStub::$requests);
        self::assertSame('wp:' . $due, $this->sentBody()['elementy'][0]['id']);
        self::assertSame((string)$this->now, $this->field($due, Meta::SUBMITTED_AT));
        self::assertNull($this->field($later, Meta::SUBMITTED_AT));
    }

    public function testASweepStopsSendingAtTheFirstAnswerThatAsksToWait(): void
    {
        $this->configure();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            WpStub::networkFailure();
            $ids[] = $this->post('Komentarz w czasie awarii numer ' . $i . '.');
        }
        $this->now += 60;
        WpStub::answer(503);
        do_action(Sweeper::HOOK);

        self::assertCount(4, WpStub::$requests, 'one attempt, then the sweep waits');
    }

    public function testASweepSendsAtMostTenComments(): void
    {
        $this->configure();
        for ($i = 0; $i < Sweeper::MAX_SUBMISSIONS + 3; $i++) {
            WpStub::networkFailure();
            $this->post('Komentarz w kolejce ponowień numer ' . $i . '.');
        }
        $before = count(WpStub::$requests);
        $this->now += 60;
        do_action(Sweeper::HOOK);

        self::assertSame(Sweeper::MAX_SUBMISSIONS, count(WpStub::$requests) - $before);
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
}
