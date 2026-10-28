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
        self::assertSame(self::itemIds($ids), self::sorted($this->sentIds()));
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

        self::assertSame(self::itemIds([$first, $next]), self::sorted($this->sentIds()), 'both in one request once it ends');
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

    /**
     * @return array<string,array{0:int}> A `ponow_za_s` far beyond any timeout.
     */
    public static function hugePauses(): array
    {
        return ['a billion seconds' => [1000000000], 'the largest integer' => [PHP_INT_MAX]];
    }

    /**
     * @dataProvider hugePauses
     */
    public function testAHugePauseIsKeptForADayAtMostThenTheGatewayIsAskedAgain(int $asked): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'limit_minutowy_klucza', 'ponow_za_s' => $asked]]);
        $first = $this->post('Komentarz, po którym brama każe czekać bardzo długo.');

        self::assertSame(Meta::UNASSESSED, $this->field($first, Meta::STATUS), 'judged by what the gateway said');
        self::assertSame($this->now + Log::MAX_PAUSE_S, $this->plugin->log->sendingPause()['until'] ?? null);
        $during = $this->post('Komentarz w czasie bardzo długiej pauzy.');
        self::assertSame([Meta::UNASSESSED, 'limit_minutowy_klucza'],
            [$this->field($during, Meta::STATUS), $this->field($during, Meta::ERROR)], 'its timeout ends first');

        $this->now += Log::MAX_PAUSE_S;
        $after = $this->post('Komentarz dzień później.');
        self::assertCount(2, WpStub::$requests, 'a day later the gateway is asked again');
        self::assertSame((string)$this->now, $this->field($after, Meta::SUBMITTED_AT));
    }

    /**
     * @return array<string,array{0:string,1:mixed}> An option and its new value.
     */
    public static function changesThatEndThePause(): array
    {
        return [
            'another key'             => [Settings::KEY_OPTION, 'wgb2b_inny_testowy_klucz_000000000'],
            'another secret'          => [Settings::SECRET_OPTION, 'inny-testowy-sekret-webhooka-0123'],
            'another gateway address' => [Settings::OPTION, ['gateway_url' => 'https://brama.example']],
        ];
    }

    /**
     * @dataProvider changesThatEndThePause
     * @param mixed $value
     */
    public function testAChangeOfTheConnectionEndsThePause(string $option, $value): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'limit_dobowy_klucza', 'ponow_za_s' => 50000]]);
        $this->post('Komentarz po wyczerpaniu limitu dobowego.');
        $this->now += 3600;
        update_option(Settings::OPTION, WpStub::$options[Settings::OPTION]);
        self::assertNotNull($this->plugin->log->sendingPause(), 'saving the same settings changes nothing');

        update_option($option, is_array($value) ? $value + WpStub::$options[Settings::OPTION] : $value);

        self::assertNull($this->plugin->log->sendingPause());
        self::assertNull($this->plugin->log->pausedUntil(), 'and its notice');
        $id = $this->post('Komentarz po zmianie połączenia.');
        self::assertCount(2, WpStub::$requests, 'sent at once');
        self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
    }

    public function testSwitchingOffAndOnEndsThePause(): void
    {
        $this->configure();
        WpStub::answer(429, ['blad' => ['kod' => 'limit_dobowy_klucza', 'ponow_za_s' => 50000]]);
        $this->post('Komentarz po wyczerpaniu limitu dobowego.');
        $settings = WpStub::$options[Settings::OPTION];

        update_option(Settings::OPTION, ['enabled' => false] + $settings);
        self::assertNull($this->plugin->log->sendingPause());
    }

    public function testASweepCountsEveryHttpRequestARefusedItemsResendIncluded(): void
    {
        $ids = $this->dueRetries(Sweeper::MAX_REQUESTS * Submission::MAX_ITEMS);
        for ($i = 0; $i < 2 * Sweeper::MAX_REQUESTS; $i++) {
            WpStub::answer(400, ['blad' => ['kod' => 'brak_tekstu', 'element' => 0]]);
        }

        do_action(Sweeper::HOOK);

        self::assertCount(1 + Sweeper::MAX_REQUESTS, WpStub::$requests, 'the sweep\'s requests, every resend counted');
        $failed = array_filter($ids, function (int $id): bool {
            return $this->field($id, Meta::ERROR) === 'brak_tekstu';
        });
        self::assertCount(Sweeper::MAX_REQUESTS, $failed, 'one refused item a request');
        $left = array_filter($ids, function (int $id): bool {
            return $this->plugin->submission->due($id);
        });
        self::assertCount(count($ids) - Sweeper::MAX_REQUESTS, $left, 'the others stay due for the next run');
    }

    public function testA408IsRetriedLikeNoAnswer(): void
    {
        $this->configure();
        WpStub::answer(408);
        $id = $this->post('Komentarz, którego żądanie przekroczyło czas.');

        self::assertTrue($this->pending($id));
        self::assertSame((string)($this->now + Submission::FIRST_BACKOFF_S), $this->field($id, Meta::RETRY_AT));
        self::assertNotNull($this->plugin->log->sendingPause());
        self::assertNull($this->plugin->log->lastConfigError(), 'not a configuration error');
    }

    /**
     * @return array<string,array{0:int}> A `2xx` that is not the gateway's `202`.
     */
    public static function otherSuccesses(): array
    {
        return ['200' => [200], '201' => [201], '204' => [204]];
    }

    /**
     * @dataProvider otherSuccesses
     */
    public function testOnly202AcceptsEvenWithEveryItemInPrzyjete(int $status): void
    {
        $this->configure();
        $next = 'wp:' . ((WpStub::$comments === [] ? 0 : max(array_keys(WpStub::$comments))) + 1);
        WpStub::answer($status, ['przyjete' => [$next]]);

        $id = $this->post('Komentarz z odpowiedzią spoza bramy.');

        self::assertSame('wp:' . $id, $next, 'the answer named this very comment');
        self::assertNull($this->field($id, Meta::SUBMITTED_AT), 'not accepted');
        self::assertSame([Meta::UNASSESSED, 'http_' . $status], [$this->field($id, Meta::STATUS), $this->field($id, Meta::ERROR)]);
        self::assertSame($status, $this->plugin->log->lastConfigError()['http'] ?? null, 'the administrator is told');
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

    /**
     * @return array<string,array{0:bool}> Whether WP-Cron runs the events of one moment in reverse.
     */
    public static function eventOrders(): array
    {
        // Before PHP 8 `usort` is not stable: events of the same moment may run in any order.
        return ['as scheduled' => [false], 'reversed' => [true]];
    }

    /**
     * @dataProvider eventOrders
     */
    public function testTwentyFiveDueRetriesGoOutInTwoRequestsFromTheirOwnEvents(bool $reversed): void
    {
        $ids = $this->dueRetries(25);

        $this->runDueEvents($reversed);

        self::assertSame(3, count(WpStub::$requests), 'the refused one, then 20 + 5');
        self::assertSame([Submission::MAX_ITEMS, 5], [count($this->sentIds(1)), count($this->sentIds(2))]);
        self::assertSame(self::itemIds($ids), self::sorted(array_merge($this->sentIds(1), $this->sentIds(2))),
            'each comment exactly once');
    }

    /**
     * @dataProvider eventOrders
     */
    public function testDueRetriesAreNotCrowdedOutByCommentsWaitingForTheirVerdicts(bool $reversed): void
    {
        $this->configure();
        for ($i = 0; $i < Sweeper::BATCH; $i++) {
            $this->post('Komentarz przyjęty, czeka na werdykt ' . $i . '.');
        }
        WpStub::networkFailure();
        $due = [];
        for ($i = 0; $i < 25; $i++) {
            $due[] = $this->post('Komentarz do ponowienia ' . $i . '.');
        }
        $this->now += Submission::FIRST_BACKOFF_S;
        $before = count(WpStub::$requests);

        $reversed ? $this->runDueEvents(true) : do_action(Sweeper::HOOK);

        $sizes = array_map(function (int $index): int {
            return count($this->sentIds($index));
        }, range($before, count(WpStub::$requests) - 1));
        self::assertSame([Submission::MAX_ITEMS, 5], $sizes, 'two requests, 20 + 5, behind 200 waiting for verdicts');
        foreach ($due as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
        }
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
     * Runs the one-off events that are due, oldest first, as WP-Cron does, each once. Events
     * of the same moment run as scheduled, or in reverse: a stable sort keeps them in order
     * on every PHP version, and a test that passes both ways depends on neither.
     *
     * @param bool $reversed Whether the events of one moment run last scheduled first.
     */
    private function runDueEvents(bool $reversed = false): void
    {
        $due = [];
        foreach (WpStub::$events as $index => $event) {
            if ($event['recurrence'] === null && $event['at'] <= $this->now) {
                $due[] = [$event['at'], $reversed ? -$index : $index, $event];
            }
        }
        WpStub::$events = array_values(array_filter(WpStub::$events, function (array $event): bool {
            return $event['recurrence'] !== null || $event['at'] > $this->now;
        }));
        usort($due, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });
        foreach ($due as [, , $event]) {
            do_action($event['hook'], ...$event['args']);
        }
    }

    /**
     * @param array<int,string> $ids Item ids.
     * @return array<int,string> The same, in the order of the comments' ids.
     */
    private static function sorted(array $ids): array
    {
        sort($ids, SORT_NATURAL);
        return $ids;
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
