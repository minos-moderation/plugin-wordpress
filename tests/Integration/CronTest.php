<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\WordPress\Meta;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;

/**
 * The retries on real WP-Cron. `wp_schedule_single_event` refuses an event when one with the
 * same action and arguments is due within ten minutes of it, so a comment has at most one
 * retry event in that window, and it may be earlier than the comment's retry: the sweep,
 * which finds due retries by `_minos_retry_at`, sends those.
 */
final class CronTest extends IntegrationTestCase
{
    /** Nothing listens there: the request fails at once, as a gateway that does not answer. */
    private const DOWN = 'http://127.0.0.1:9';

    public function testOneRetryEventACommentAndTheSweepSendsARetryWordPressDidNotSchedule(): void
    {
        $this->configure(['gateway_url' => self::DOWN]);
        $id = $this->post('Komentarz, gdy brama nie odpowiada.');
        $first = (int)self::stored($id, Meta::RETRY_AT);
        self::assertGreaterThan(time(), $first, 'the plugin waits');
        self::assertSame([['at' => $first, 'args' => [$id]]], self::events(Submission::HOOK_RETRY));

        // The retry falls due before its event runs (WP-Cron runs on visits); the address is
        // corrected to another one that does not answer either, which ends the pause.
        self::age($id, $first - time());
        $this->configure(['gateway_url' => 'http://localhost:9']);
        do_action(Sweeper::HOOK);

        $second = (int)self::stored($id, Meta::RETRY_AT);
        self::assertSame(['2', 2], [self::stored($id, Meta::ATTEMPTS), count($this->sent)], 'sent again, and failed');
        self::assertGreaterThan($first, $second);
        self::assertSame([['at' => $first, 'args' => [$id]]], self::events(Submission::HOOK_RETRY),
            'WordPress kept the earlier event and refused the one at the new retry');

        // The earlier event runs: the retry is not due yet, so nothing is sent.
        do_action(Submission::HOOK_RETRY, $id);
        self::assertCount(2, $this->sent);

        // The retry falls due and the gateway answers: the sweep sends it.
        $this->startGateway();
        self::age($id, $second - time());
        do_action(Sweeper::HOOK);

        self::assertCount(3, $this->sent);
        self::assertSame(['wp:' . $id], array_column($this->sent[2]['elementy'], 'id'));
        self::assertNotNull(self::stored($id, Meta::SUBMITTED_AT), 'the gateway took it');
        self::assertNull(self::stored($id, Meta::RETRY_AT));
    }

    public function testAWaitAskedForAgainDuringThePauseLeavesOneEventAComment(): void
    {
        $this->configure(['gateway_url' => self::DOWN]);
        $ids = [];
        foreach (['Pierwszy', 'Drugi', 'Trzeci'] as $word) {
            $ids[] = $this->post($word . ' komentarz w czasie przerwy.');
        }
        self::assertCount(1, $this->sent, 'only the first went out; the others wait for the pause');

        // Their retries fall due before the pause ends: the sweep holds them again until its
        // end, asking WordPress for the same event once more.
        self::age($ids[1], 60);
        self::age($ids[2], 60);
        do_action(Sweeper::HOOK);

        self::assertCount(1, $this->sent, 'nothing is sent while the pause runs');
        $byComment = [];
        foreach (self::events(Submission::HOOK_RETRY) as $event) {
            $byComment[$event['args'][0]][] = $event['at'];
        }
        ksort($byComment);
        $expected = [];
        foreach ($ids as $id) {
            $expected[$id] = [(int)self::stored($id, Meta::RETRY_AT)];
        }
        self::assertSame($expected, $byComment, 'one event a comment, at its retry');
    }
}
