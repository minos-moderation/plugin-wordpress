<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
use WpStub;

/**
 * The gateway's limits count requests, not items, and a refusal the key caused spends its
 * allowance: after an answer that asks to wait, nothing is sent until the pause ends, not
 * even from the author's own request, and the retries that fall due go out together, up to
 * twenty comments a request, each one checked against what the gateway took. Invented
 * comments only.
 */
final class PauseAndBatchTest extends PluginTestCase
{
    public function testThreeCommentsInAMinuteAfterAMinuteLimitCostOneRefusedRequest(): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 40]]);
        $end = $this->now + 40;
        $ids = [$this->post('Pierwszy komentarz w tej minucie.')];
        $this->now += 15;
        $ids[] = $this->post('Drugi komentarz w tej minucie.');
        $this->now += 15;
        $ids[] = $this->post('Trzeci komentarz w tej minucie.');

        self::assertCount(1, WpStub::$requests, 'one refused request, not three');
        foreach ($ids as $id) {
            self::assertTrue($this->pending($id));
            self::assertSame('0', $this->field($id, 'comment_approved'), 'held for WP-Cron');
            self::assertSame((string)$end, $this->field($id, Meta::RETRY_AT), 'due when the pause ends');
        }

        $this->now = $end;
        $this->runDueEvents();

        self::assertCount(2, WpStub::$requests, 'then one request for all three');
        self::assertSame(self::itemIds($ids), $this->sentIds());
        foreach ($ids as $id) {
            self::assertSame((string)$end, $this->field($id, Meta::SUBMITTED_AT));
        }
    }

    /**
     * @return array<string,array{0:callable,1:int|null}> The answer, and the pause it asks for (null: the backoff).
     */
    public static function answersThatAskToWait(): array
    {
        $refusal = static function (int $status, string $code, ?int $pause): callable {
            return static function () use ($status, $code, $pause): void {
                WpStub::answer($status, ['blad' => ['kod' => $code] + ($pause === null ? [] : ['ponow_za_s' => $pause])]);
            };
        };
        return [
            'the key\'s minute limit'    => [$refusal(429, 'limit_minutowy_klucza', 30), 30],
            'the key\'s requests in flight' => [$refusal(429, 'limit_w_locie_klucza', 5), 5],
            'the key\'s daily limit'     => [$refusal(429, 'limit_dobowy_klucza', 600), 600],
            'the global ceiling'         => [$refusal(429, 'limit_globalny_b2b', 300), 300],
            'a full queue'               => [$refusal(429, 'kolejka_pelna', 60), 60],
            'the queue unavailable'      => [$refusal(503, 'kolejka_niedostepna', null), null],
            'a proxy error'              => [static function (): void {
                WpStub::answer(502);
            }, null],
            'no answer'                  => [[WpStub::class, 'networkFailure'], null],
        ];
    }

    /**
     * @dataProvider answersThatAskToWait
     */
    public function testEveryAnswerThatAsksToWaitHoldsTheNextCommentsForWpCron(callable $answer, ?int $pause): void
    {
        $this->configure();
        $answer();
        $first = $this->post('Komentarz, któremu brama kazała czekać.');
        $end = $this->now + ($pause ?? Submission::FIRST_BACKOFF_S);
        $this->now += 1;
        $next = $this->post('Komentarz napisany w czasie pauzy.');

        self::assertCount(1, WpStub::$requests, 'nothing sent in the author\'s request during the pause');
        self::assertSame(['at' => $end, 'hook' => Submission::HOOK_RETRY, 'args' => [$next], 'recurrence' => null],
            self::events(Submission::HOOK_RETRY)[1] ?? null);
        self::assertSame($end, $this->plugin->log->sendingPause()['until'] ?? null);
        self::assertSame((string)$end, $this->field($first, Meta::RETRY_AT));

        $this->now = $end;
        $this->runDueEvents();

        self::assertSame(self::itemIds([$first, $next]), $this->sentIds(), 'both in one request once it ends');
        self::assertNull($this->plugin->log->sendingPause(), 'the gateway took them: no pause');
    }

    public function testThePluginsOwnBackoffNeverGivesTheFailureModeAtOnce(): void
    {
        // A 32-minute timeout: the first comment lives through six failed attempts.
        $this->configure(['timeout_min' => 32]);
        WpStub::networkFailure();
        $first = $this->post('Komentarz w czasie długiej awarii.');
        // Five more failed attempts: the backoff, and the pause, reach 32 minutes.
        for ($i = 0; $i < 5; $i++) {
            $this->now = (int)$this->field($first, Meta::RETRY_AT);
            WpStub::networkFailure();
            do_action(Submission::HOOK_RETRY, $first);
        }
        $pause = $this->plugin->log->sendingPause();
        self::assertSame([$this->now + 32 * 60, false], [$pause['until'] ?? null, $pause['given'] ?? null]);

        $id = $this->post('Komentarz napisany w czasie długiej awarii.');

        self::assertTrue($this->pending($id), 'not the gateway\'s word: it waits for its own timeout');
        $this->now += 32 * 60;
        do_action(Sweeper::HOOK);
        self::assertSame([Meta::UNASSESSED, '1'], [$this->field($id, Meta::STATUS), $this->field($id, Meta::TIMED_OUT)]);
    }

    public function testAnAnswerToAnEarlierRequestNeverShortensThePause(): void
    {
        $this->configure();
        // While this comment's request is on its way, another author's request gets the
        // daily limit; this one then gets a 503.
        add_filter('pre_http_request', function () {
            $this->plugin->log->pause('limit_dobowy_klucza', $this->now + 36000, true);
            return ['response' => ['code' => 503], 'body' => '{}'];
        });
        $this->post('Komentarz wysłany tuż przed wyczerpaniem limitu.');

        self::assertSame($this->now + 36000, $this->plugin->log->sendingPause()['until'] ?? null);
        self::assertSame(Log::PAUSE_DAILY_LIMIT, $this->plugin->log->pausedUntil()['kind'] ?? null);
    }

    public function testAnEditDuringThePauseWaitsForIt(): void
    {
        $this->configure();
        $id = $this->post('Komentarz przed pauzą.');
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 30]]);
        $this->post('Komentarz, po którym brama każe czekać.');

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Komentarz poprawiony w czasie pauzy.']);

        self::assertCount(2, WpStub::$requests, 'the edit is not sent in the editor\'s request');
        self::assertSame(['1', (string)($this->now + 30)], [$this->field($id, Meta::REVISION), $this->field($id, Meta::RETRY_AT)]);
    }

    public function testACommentWithNoTextGetsItsFailureModeDuringThePauseToo(): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => 30]]);
        $this->post('Komentarz, po którym brama każe czekać.');
        $id = $this->post('<a href="https://kasyno.example/bonus">&nbsp;</a>');

        self::assertSame([Meta::UNASSESSED, Meta::ERROR_NO_TEXT], [$this->field($id, Meta::STATUS), $this->field($id, Meta::ERROR)]);
        self::assertSame([], array_column(array_slice(self::events(Submission::HOOK_RETRY), 1), 'args'), 'nothing left to retry');
    }

    public function testTwentyFiveDueRetriesGoOutInTwoRequestsFromTheSweep(): void
    {
        $ids = $this->dueRetries(25);

        do_action(Sweeper::HOOK);

        self::assertSame(3, count(WpStub::$requests), 'the refused one, then 20 + 5');
        self::assertSame(self::itemIds(array_slice($ids, 0, Submission::MAX_ITEMS)), $this->sentIds(1));
        self::assertSame(self::itemIds(array_slice($ids, Submission::MAX_ITEMS)), $this->sentIds(2));
        foreach ($ids as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
        }
    }

    public function testTwentyFiveDueRetriesGoOutInTwoRequestsFromTheirOwnEvents(): void
    {
        $ids = $this->dueRetries(25);

        $this->runDueEvents();

        self::assertSame(3, count(WpStub::$requests), 'the refused one, then 20 + 5');
        self::assertCount(Submission::MAX_ITEMS, $this->sentIds(1));
        self::assertCount(5, $this->sentIds(2));
        self::assertSame(self::itemIds($ids), array_merge($this->sentIds(1), $this->sentIds(2)));
    }

    public function testACommentTheGatewayDidNotTakeIsRetriedAndTheOthersWait(): void
    {
        $ids = $this->dueRetries(Submission::MAX_ITEMS);
        $left = $ids[Submission::MAX_ITEMS - 1];
        WpStub::answer(202, ['przyjete' => self::itemIds(array_slice($ids, 0, Submission::MAX_ITEMS - 1))]);

        do_action(Sweeper::HOOK);

        self::assertCount(Submission::MAX_ITEMS, $this->sentIds(), 'the request carried all twenty');
        foreach (array_slice($ids, 0, Submission::MAX_ITEMS - 1) as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT), 'taken: waits for its verdict');
        }
        self::assertNull($this->field($left, Meta::SUBMITTED_AT), 'not in `przyjete`: not taken');
        self::assertTrue($this->pending($left));
        self::assertSame([(string)($this->now + Submission::FIRST_BACKOFF_S), '1'],
            [$this->field($left, Meta::RETRY_AT), $this->field($left, Meta::ATTEMPTS)], 'its own backoff, from its first failed attempt');
        self::assertNull($this->plugin->log->sendingPause(), 'the gateway took the others: no pause');
        self::assertSame([202, null, $left], array_values(array_slice(end(WpStub::$options[Log::OPTION]) ?: [], 1)),
            'one log entry: HTTP 202, no code, the comment not taken');

        $this->now += Submission::FIRST_BACKOFF_S;
        $this->runDueEvents();
        self::assertSame(self::itemIds([$left]), $this->sentIds(), 'then sent alone');
        self::assertSame((string)$this->now, $this->field($left, Meta::SUBMITTED_AT));
    }

    public function testAfterTheFirstAnswerThatAsksToWaitNoFurtherRequestGoes(): void
    {
        $ids = $this->dueRetries(25);
        WpStub::answer(503, ['blad' => ['kod' => 'kolejka_niedostepna']]);

        do_action(Sweeper::HOOK);

        self::assertCount(2, WpStub::$requests, 'the first batch got 503: no second batch');
        self::assertCount(Submission::MAX_ITEMS, $this->sentIds());
        foreach ($ids as $id) {
            self::assertTrue($this->pending($id));
            self::assertNull($this->field($id, Meta::SUBMITTED_AT));
        }
        self::assertSame($this->now + 2 * Submission::FIRST_BACKOFF_S, $this->plugin->log->sendingPause()['until'] ?? null,
            'the backoff doubles for the shared pause');
        do_action(Sweeper::HOOK);
        self::assertCount(2, WpStub::$requests, 'nothing during the pause');
    }

    public function testACommentRefusedAloneGetsTheFailureModeAndTheOthersGoAgainAtOnce(): void
    {
        $ids = $this->dueRetries(3);
        WpStub::answer(400, ['blad' => ['kod' => 'brak_tekstu', 'komunikat' => 'Element nie ma treści do oceny.', 'element' => 1]]);

        do_action(Sweeper::HOOK);

        self::assertSame(self::itemIds($ids), $this->sentIds(1));
        self::assertSame(self::itemIds([$ids[0], $ids[2]]), $this->sentIds(2), 'the others again, at once');
        self::assertSame([Meta::UNASSESSED, '0', 'brak_tekstu'],
            [$this->field($ids[1], Meta::STATUS), $this->field($ids[1], 'comment_approved'), $this->field($ids[1], Meta::ERROR)]);
        self::assertSame([400, 'brak_tekstu', $ids[1]], array_values(array_slice(end(WpStub::$options[Log::OPTION]) ?: [], 1)),
            'logged under the comment it refused');
        self::assertNull($this->plugin->log->lastConfigError(), 'one comment\'s fault, not the setup\'s');
        foreach ([$ids[0], $ids[2]] as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
        }
    }

    public function testARefusalOfTheKeysSetupGivesEveryCommentTheFailureModeAtOnce(): void
    {
        $ids = $this->dueRetries(3);
        WpStub::answer(403, ['blad' => ['kod' => 'profil_niedozwolony', 'element' => 0]]);

        do_action(Sweeper::HOOK);

        self::assertCount(2, WpStub::$requests, 'one request: the profile is every item\'s');
        foreach ($ids as $id) {
            self::assertSame([Meta::UNASSESSED, 'profil_niedozwolony'], [$this->field($id, Meta::STATUS), $this->field($id, Meta::ERROR)]);
        }
        self::assertSame('profil_niedozwolony', $this->plugin->log->lastConfigError()['code'] ?? null);
    }

    public function testFailOpenPublishesOnlyWhatTheGatewayRefusedForGood(): void
    {
        $ids = $this->dueRetries(2, ['failure_mode' => Settings::FAIL_OPEN]);
        WpStub::answer(413, ['blad' => ['kod' => 'limit_dlugosci', 'element' => 0]]);

        do_action(Sweeper::HOOK);

        self::assertSame(['1', '0'], [$this->field($ids[0], 'comment_approved'), $this->field($ids[1], 'comment_approved')],
            'the refused one is published by the failure mode, the other waits for its verdict');
        self::assertTrue($this->pending($ids[1]));
    }

    /**
     * Comments held while the gateway was unreachable, all due now: the first one's failed
     * request paused every submission.
     *
     * @param int                 $count    How many.
     * @param array<string,mixed> $settings Settings over the defaults.
     * @return array<int,int> Their ids, oldest first.
     */
    private function dueRetries(int $count, array $settings = []): array
    {
        $this->configure($settings);
        WpStub::networkFailure();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->post('Komentarz czekający na ponowienie numer ' . $i . '.');
        }
        self::assertCount(1, WpStub::$requests);
        $this->now += Submission::FIRST_BACKOFF_S;
        return $ids;
    }

    /**
     * Runs the one-off events that are due, oldest first, as WP-Cron does, each once.
     */
    private function runDueEvents(): void
    {
        $due = array_values(array_filter(WpStub::$events, function (array $event): bool {
            return $event['recurrence'] === null && $event['at'] <= $this->now;
        }));
        WpStub::$events = array_values(array_filter(WpStub::$events, function (array $event): bool {
            return $event['recurrence'] !== null || $event['at'] > $this->now;
        }));
        usort($due, static function (array $a, array $b): int {
            return $a['at'] <=> $b['at'];
        });
        foreach ($due as $event) {
            do_action($event['hook'], ...$event['args']);
        }
    }

    /**
     * @param array<int,int> $ids Comments.
     * @return array<int,string> Their item ids.
     */
    private static function itemIds(array $ids): array
    {
        return array_map(static function (int $id): string {
            return Submission::itemId($id, 0);
        }, $ids);
    }
}
