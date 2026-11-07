<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Admin\CommentsScreen;
use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Outcome;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
use Minos\WordPress\Upgrade;
use WpStub;

/**
 * One comment that fails (another plugin's hook throwing) stops nothing else: it is logged
 * by step and comment id — never by the exception's message — and it keeps waiting, so the
 * next sweep, retry or delivery does its step again. Invented comments only.
 */
final class FailureIsolationTest extends PluginTestCase
{
    /** What the failing hook's exception says: it must reach no log, meta or page. */
    private const MESSAGE = 'tajna treść komentarza w komunikacie wyjątku';

    /** @var array<int,int> */
    private $ids = [];

    /** @var int How many more times the broken hook throws (-1: always). */
    private $throws = 0;

    /**
     * Three comments, waiting for their verdicts.
     *
     * @param array<string,mixed> $settings Settings over the defaults.
     */
    private function threeComments(array $settings): void
    {
        $this->configure($settings);
        for ($i = 0; $i < 3; $i++) {
            $this->ids[] = $this->post('Testowy komentarz numer ' . $i . '.');
        }
    }

    /**
     * Makes a hook throw for the second comment, a given number of times.
     *
     * @param string   $hook   The hook.
     * @param callable $isTheBroken Whether a call is about the second comment (gets the hook's arguments).
     * @param int      $times  How many times (-1: always).
     */
    private function breakFor(string $hook, callable $isTheBroken, int $times = 1): void
    {
        $this->throws = $times;
        add_filter($hook, function (...$args) use ($isTheBroken) {
            if ($this->throws !== 0 && $isTheBroken(...$args)) {
                $this->throws--;
                throw new \RuntimeException(self::MESSAGE);
            }
            return $args[0] ?? null;
        }, 10, 3);
    }

    /**
     * The log has exactly one failure, of this step for the broken comment, and the
     * exception's message reached nothing the plugin stores.
     */
    private function assertLoggedOnce(string $step): void
    {
        $failures = array_values(array_filter($this->plugin->log->entries(), static function (array $entry): bool {
            return Log::failedStep($entry['code']) !== null;
        }));
        self::assertSame([[null, Log::failureCode($step), $this->ids[1]]], array_map(static function (array $entry): array {
            return [$entry['http'], $entry['code'], $entry['comment']];
        }, $failures));
        self::assertSame(Log::failureCode($step), $this->field($this->ids[1], Meta::ERROR));
        $stored = json_encode([WpStub::$options, WpStub::$meta], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('tajna', (string)$stored);
    }

    public function testATimeoutThatFailsLeavesTheOtherTimeoutsToTheSamePassAndRunsAgain(): void
    {
        $this->threeComments(['failure_mode' => Settings::FAIL_OPEN]);
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;

        do_action(Sweeper::HOOK);

        foreach ([$this->ids[0], $this->ids[2]] as $id) {
            self::assertSame(['1', Meta::UNASSESSED], [$this->field($id, 'comment_approved'), $this->field($id, Meta::STATUS)]);
        }
        self::assertTrue($this->pending($this->ids[1]), 'no final status without the action: it still waits');
        $this->assertLoggedOnce(Log::STEP_TIMEOUT);

        do_action(Sweeper::HOOK);

        self::assertSame(Meta::UNASSESSED, $this->field($this->ids[1], Meta::STATUS), 'the next pass finishes it');
        self::assertSame(['1', '1'], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::AUTO_PUBLISHED)]);
    }

    public function testAFailClosedTimeoutThatFailsStaysHeldAndGetsItsEmailInTheNextPass(): void
    {
        $this->threeComments(['failure_mode' => Settings::FAIL_CLOSED]);
        $this->breakFor('schedule_event', function ($event): bool {
            return is_object($event) && $event->hook === Outcome::HOOK_NOTIFY && $event->args === [$this->ids[1]];
        });
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;

        do_action(Sweeper::HOOK);

        self::assertSame([[$this->ids[0]], [$this->ids[2]]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'));
        self::assertSame('0', $this->field($this->ids[1], 'comment_approved'), 'held');
        self::assertTrue($this->pending($this->ids[1]));
        $this->assertLoggedOnce(Log::STEP_TIMEOUT);

        do_action(Sweeper::HOOK);
        foreach (self::events(Outcome::HOOK_NOTIFY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }

        self::assertSame(Meta::UNASSESSED, $this->field($this->ids[1], Meta::STATUS));
        self::assertContains(['moderator', $this->ids[1]], WpStub::$emails, 'its e-mail went out');
    }

    /**
     * Three comments held while the gateway was unreachable: the first one's failed request
     * paused every submission, so all three are due together.
     */
    private function threeDue(): void
    {
        $this->configure();
        WpStub::networkFailure();
        for ($i = 0; $i < 3; $i++) {
            $this->ids[] = $this->post('Komentarz czekający na ponowienie numer ' . $i . '.');
        }
        self::assertCount(1, WpStub::$requests);
        $this->now += Submission::FIRST_BACKOFF_S;
    }

    public function testACommentThatFailsWhileItsRequestIsBuiltIsLeftOutAndTheOthersGo(): void
    {
        $this->threeDue();
        $this->breakFor('get_comment', function ($comment): bool {
            return is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });

        do_action(Sweeper::HOOK);

        self::assertSame(['wp:' . $this->ids[0], 'wp:' . $this->ids[2]], $this->sentIds(), 'one request, without it');
        foreach ([$this->ids[0], $this->ids[2]] as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT), 'sent in the same pass');
        }
        self::assertNull($this->field($this->ids[1], Meta::SUBMITTED_AT));
        self::assertTrue($this->plugin->submission->due($this->ids[1]), 'still due');
        $this->assertLoggedOnce(Log::STEP_SUBMIT);

        do_action(Sweeper::HOOK);
        self::assertSame((string)$this->now, $this->field($this->ids[1], Meta::SUBMITTED_AT), 'sent in the next pass');
    }

    public function testARequestThatFailsLeavesItsCommentsDueAndTheNextRequestGoes(): void
    {
        $this->configure();
        WpStub::networkFailure();
        for ($i = 0; $i < Submission::MAX_ITEMS + 3; $i++) {
            $this->ids[] = $this->post('Komentarz w kolejce ponowień numer ' . $i . '.');
        }
        $this->now += Submission::FIRST_BACKOFF_S;
        // The first request of the pass fails on the way (another plugin's HTTP hook).
        $this->breakFor('pre_http_request', static function (): bool {
            return true;
        });

        do_action(Sweeper::HOOK);

        $failed = array_slice($this->ids, 0, Submission::MAX_ITEMS);
        $next = array_slice($this->ids, Submission::MAX_ITEMS);
        self::assertSame(array_map(static function (int $id): string {
            return 'wp:' . $id;
        }, $next), $this->sentIds(), 'the next request went');
        foreach ($failed as $id) {
            self::assertNull($this->field($id, Meta::SUBMITTED_AT));
            self::assertTrue($this->plugin->submission->due($id), 'still due');
            self::assertSame(Log::failureCode(Log::STEP_SUBMIT), $this->field($id, Meta::ERROR));
        }
        $failures = array_values(array_filter($this->plugin->log->entries(), static function (array $entry): bool {
            return Log::failedStep($entry['code']) !== null;
        }));
        self::assertSame([[Log::failureCode(Log::STEP_SUBMIT), $failed[0]]], array_map(static function (array $entry): array {
            return [$entry['code'], $entry['comment']];
        }, $failures), 'logged once, under the request\'s first comment');
        self::assertStringNotContainsString('tajna', (string)json_encode([WpStub::$options, WpStub::$meta], JSON_UNESCAPED_UNICODE));

        do_action(Sweeper::HOOK);
        foreach ($failed as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT), 'sent in the next pass');
        }
    }

    public function testARetryEventThatFailsDoesNotStopWpCronAndItsCommentsStayDue(): void
    {
        $this->threeDue();
        $this->breakFor('pre_http_request', static function (): bool {
            return true;
        });

        // Its request carries all three due comments, and fails on the way.
        do_action(Submission::HOOK_RETRY, $this->ids[1]);

        foreach ($this->ids as $id) {
            self::assertNull($this->field($id, Meta::SUBMITTED_AT));
            self::assertTrue($this->plugin->submission->due($id), 'still due');
        }
        $this->assertLoggedOnce(Log::STEP_SUBMIT);

        do_action(Submission::HOOK_RETRY, $this->ids[0]);
        do_action(Submission::HOOK_RETRY, $this->ids[2]);

        self::assertCount(2, WpStub::$requests, 'the next event sent all three; the last found nothing left');
        foreach ($this->ids as $id) {
            self::assertSame((string)$this->now, $this->field($id, Meta::SUBMITTED_AT));
        }
    }

    public function testAFailureWhileTheAuthorPostsLeavesTheCommentDueForTheSweep(): void
    {
        $this->configure();
        $this->ids = [0, 0];
        $this->breakFor('pre_http_request', static function (): bool {
            return true;
        });

        $this->ids[1] = $this->post('Komentarz, którego wysłanie się nie udało.');

        self::assertTrue($this->pending($this->ids[1]));
        self::assertSame('0', $this->field($this->ids[1], 'comment_approved'));
        self::assertSame((string)($this->now + Submission::FIRST_BACKOFF_S), $this->field($this->ids[1], Meta::RETRY_AT));
        $this->assertLoggedOnce(Log::STEP_SUBMIT);

        $this->now += Submission::FIRST_BACKOFF_S;
        do_action(Sweeper::HOOK);
        self::assertSame((string)$this->now, $this->field($this->ids[1], Meta::SUBMITTED_AT));
    }

    public function testAWebhookThatFailsAnswers500AndTheRedeliveryIsApplied(): void
    {
        $this->threeComments([]);
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });

        self::assertSame(200, $this->deliver(self::verdict($this->ids[0], 'bezpieczne')));
        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'bezpieczne')));
        self::assertTrue($this->pending($this->ids[1]), 'not settled: the redelivery must not look like a repeat');
        $this->assertLoggedOnce(Log::STEP_VERDICT);
        self::assertSame(200, $this->deliver(self::verdict($this->ids[2], 'bezpieczne')));

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'bezpieczne')));
        self::assertSame(['1', 'bezpieczne'], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::STATUS)]);
        self::assertCount(1, array_filter(WpStub::$statusChanges, function (array $change): bool {
            return $change[0] === $this->ids[1];
        }), 'approved once');
    }

    public function testALateVerdictWhoseHoldFailsStillTellsTheModerator(): void
    {
        $this->threeComments(['failure_mode' => Settings::FAIL_OPEN]);
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        self::assertSame(['1', '1'], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::AUTO_PUBLISHED)]);
        WpStub::$events = [];
        // WordPress holds the comment, then another plugin's hook on the change throws.
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return $new === 'unapproved' && is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });

        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'zablokowane')));
        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'zablokowane')));

        self::assertSame(['0', 'zablokowane'], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::STATUS)]);
        foreach (self::events(Outcome::HOOK_NOTIFY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }
        self::assertContains(['moderator', $this->ids[1]], WpStub::$emails, 'the moderator still gets the e-mail');
        $this->assertLoggedOnce(Log::STEP_VERDICT);
    }

    /**
     * Shared rule A7: a late support cue for a held comment whose e-mail cannot be
     * scheduled (another plugin's hook) answers 500 and is not yet recorded, so the
     * gateway's next delivery records it and schedules the e-mail.
     */
    public function testALateSupportCueThatFailsAnswers500AndTheRedeliveryRecordsIt(): void
    {
        $this->threeComments(['failure_mode' => Settings::FAIL_CLOSED]);
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        WpStub::$events = [];
        $this->breakFor('schedule_event', function ($event): bool {
            return is_object($event) && $event->hook === Outcome::HOOK_NOTIFY && $event->args === [$this->ids[1]];
        });

        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'zablokowane', ['wsparcie' => true])));
        self::assertNull($this->field($this->ids[1], Meta::SUPPORT), 'not recorded: the redelivery must not look like a repeat');
        $this->assertLoggedOnce(Log::STEP_VERDICT);

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'zablokowane', ['wsparcie' => true])));
        self::assertSame(['0', '1'], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::SUPPORT)]);
        self::assertSame([[$this->ids[1]]], array_column(self::events(Outcome::HOOK_NOTIFY), 'args'));
    }

    public function testAMaskedWriteThatFailsBeforeTheUpdateIsDoneByTheRedelivery(): void
    {
        $this->threeComments(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->breakFor('wp_update_comment_data', function ($data, $old): bool {
            return is_array($old) && (int)$old['comment_ID'] === $this->ids[1];
        });
        $masked = 'Testowy █████████ numer 1.';

        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        self::assertSame('Testowy komentarz numer 1.', $this->field($this->ids[1], 'comment_content'));
        self::assertTrue($this->pending($this->ids[1]));

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        self::assertSame(['1', $masked], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], 'comment_content')]);
        self::assertSame('Testowy komentarz numer 1.', $this->field($this->ids[1], Meta::ORIGINAL));
    }

    public function testAMaskedWriteThatFailsAfterTheUpdateIsFinishedByTheRedelivery(): void
    {
        $this->threeComments(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->breakFor('edit_comment', function ($commentId): bool {
            return (int)$commentId === $this->ids[1];
        });
        $masked = 'Testowy █████████ numer 1.';
        $requests = count(WpStub::$requests);

        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        self::assertSame($masked, $this->field($this->ids[1], 'comment_content'), 'written before the hook threw');
        self::assertTrue($this->pending($this->ids[1]));

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        self::assertSame(['1', 'ocenzurowane'], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::STATUS)]);
        self::assertSame('Testowy komentarz numer 1.', $this->field($this->ids[1], Meta::ORIGINAL));
        self::assertCount($requests, WpStub::$requests, 'its own masked text is never sent as a new revision');
    }

    public function testAnEditAfterAHalfFinishedMaskedPublicationIsSentAgainNotSettledOnTheOldVerdict(): void
    {
        $this->threeComments(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return $new === 'approved' && is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });
        $masked = 'Testowy █████████ numer 1.';
        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        self::assertSame(['1', $masked], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], 'comment_content')]);
        self::assertTrue($this->pending($this->ids[1]));
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $this->ids[1], 'comment_content' => 'Całkiem nowa treść, nieoceniona.']);

        self::assertCount($before + 1, WpStub::$requests, 'the edited text is sent');
        self::assertSame('wp:' . $this->ids[1] . '.1', $this->sentBody()['elementy'][0]['id']);
        self::assertSame('0', $this->field($this->ids[1], 'comment_approved'), 'held while it is assessed');
        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        self::assertTrue($this->pending($this->ids[1]), 'the old text\'s verdict settles nothing');
        self::assertSame(['0', 'Całkiem nowa treść, nieoceniona.'],
            [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], 'comment_content')]);
    }

    public function testAHalfFinishedPublicationIsFinishedByTheRedelivery(): void
    {
        $this->threeComments(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return $new === 'approved' && is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });
        $masked = 'Testowy █████████ numer 1.';
        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        $requests = count(WpStub::$requests);

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));

        self::assertSame(['1', 'ocenzurowane', $masked], [$this->field($this->ids[1], 'comment_approved'),
            $this->field($this->ids[1], Meta::STATUS), $this->field($this->ids[1], 'comment_content')]);
        self::assertNull($this->field($this->ids[1], Meta::AUTO_PUBLISHED));
        self::assertSame('Testowy komentarz numer 1.', $this->field($this->ids[1], Meta::ORIGINAL));
        self::assertCount($requests, WpStub::$requests, 'its own mask is not taken for an edit');
    }

    public function testARevisionThatSentAMaskIsSettledByItsVerdictNotReopenedAgain(): void
    {
        $this->threeComments(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return $new === 'approved' && is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });
        $masked = 'Testowy █████████ numer 1.';
        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'ocenzurowane', ['ocenzurowany' => $masked])));
        // Switched off and on before the redelivery: the half-finished publication is sent
        // again as revision 1, and what it sends is the mask itself.
        $settings = WpStub::$options[Settings::OPTION];
        update_option(Settings::OPTION, ['enabled' => false] + $settings);
        update_option(Settings::OPTION, ['enabled' => true] + $settings);
        foreach (self::events(Submission::HOOK_RETRY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }
        $sent = [];
        foreach ($this->sentItems() as [, $item]) {
            $sent[$item['id']] = $item['tekst'];
        }
        self::assertSame($masked, $sent['wp:' . $this->ids[1] . '.1'] ?? null, 'revision 1 sent the mask');
        $requests = count(WpStub::$requests);
        WpStub::$events = [];

        self::assertSame(200, $this->deliver(['id' => 'wp:' . $this->ids[1] . '.1'] + self::verdict($this->ids[1], 'bezpieczne')));
        foreach (self::events(Submission::HOOK_RETRY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }

        self::assertSame(['bezpieczne', '1'], [$this->field($this->ids[1], Meta::STATUS), $this->field($this->ids[1], Meta::REVISION)],
            'the verdict for the text revision 1 sent is applied: no further revision');
        self::assertSame('1', $this->field($this->ids[1], 'comment_approved'));
        self::assertCount($requests, WpStub::$requests);
    }

    public function testTheColumnSaysTheContentWasMaskedOnlyWhileItIs(): void
    {
        $this->threeComments(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $masked = 'Testowy █████████ numer 0.';
        $this->deliver(self::verdict($this->ids[0], 'ocenzurowane', ['ocenzurowany' => $masked]));
        self::assertStringContainsString('treść zastąpiona wersją zamaskowaną', $this->column($this->ids[0]));

        // A moderator edits the published masked comment: the kept original stays, the mask is gone.
        wp_update_comment(['comment_ID' => $this->ids[0], 'comment_content' => 'Treść poprawiona przez moderatora.']);

        self::assertSame('Testowy komentarz numer 0.', $this->field($this->ids[0], Meta::ORIGINAL));
        self::assertStringNotContainsString('treść zastąpiona wersją zamaskowaną', $this->column($this->ids[0]));
    }

    /**
     * What the "Minos" column shows for a comment.
     */
    private function column(int $id): string
    {
        ob_start();
        (new CommentsScreen($this->plugin->wp, $this->plugin->settings, $this->plugin->log))
            ->renderColumn(CommentsScreen::COLUMN, $id);
        return (string)ob_get_clean();
    }

    public function testAPersonsHoldOfAHalfFinishedPublicationStandsAgainstTheRedelivery(): void
    {
        $this->threeComments([]);
        $this->breakFor('transition_comment_status', function ($new, $old, $comment): bool {
            return $new === 'approved' && is_object($comment) && (int)$comment->comment_ID === $this->ids[1];
        });
        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'bezpieczne')));
        self::assertSame('1', $this->field($this->ids[1], 'comment_approved'), 'WordPress approved it before the hook threw');

        wp_set_comment_status($this->ids[1], 'hold');

        self::assertSame(Meta::UNASSESSED, $this->field($this->ids[1], Meta::STATUS), 'settled: it waits no longer');
        self::assertNull($this->field($this->ids[1], Meta::WAITING));
        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'bezpieczne')));
        self::assertSame('0', $this->field($this->ids[1], 'comment_approved'), 'the person\'s hold stands');
    }

    public function testAPersonsHoldOfAFailOpenPublicationOnlyEndsTheWaitForALateVerdict(): void
    {
        $this->threeComments(['failure_mode' => Settings::FAIL_OPEN]);
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        $decidedAt = $this->field($this->ids[1], Meta::DECIDED_AT);
        $this->now += 60;

        wp_set_comment_status($this->ids[1], 'hold');

        self::assertSame([Meta::UNASSESSED, $decidedAt], [$this->field($this->ids[1], Meta::STATUS), $this->field($this->ids[1], Meta::DECIDED_AT)]);
        self::assertNull($this->field($this->ids[1], Meta::AUTO_PUBLISHED));
    }

    public function testAModeratorEmailThatFailsDoesNotStopTheOthers(): void
    {
        $this->threeComments(['blocked_mode' => Settings::HOLD]);
        foreach ($this->ids as $id) {
            $this->deliver(self::verdict($id, 'zablokowane'));
        }
        $this->breakFor('notify_moderator', function ($notify, $commentId): bool {
            return (int)$commentId === $this->ids[1];
        });

        foreach (self::events(Outcome::HOOK_NOTIFY) as $event) {
            do_action($event['hook'], ...$event['args']);
        }

        self::assertSame([['moderator', $this->ids[0]], ['moderator', $this->ids[2]]], WpStub::$emails);
        $this->assertLoggedOnce(Log::STEP_NOTIFY);
    }

    /**
     * Makes the database refuse a comment meta write, a given number of times: wpdb answers
     * `false` and throws nothing, so `update_comment_meta` answers `false`.
     *
     * @param int    $id    The comment.
     * @param string $key   The meta key.
     * @param int    $times How many times (-1: always).
     */
    private function refuseMeta(int $id, string $key, int $times = 1): void
    {
        add_filter('update_comment_metadata', static function ($check, $objectId, $metaKey) use ($id, $key, &$times) {
            if ($times !== 0 && (int)$objectId === $id && $metaKey === $key) {
                $times--;
                return false;
            }
            return $check;
        }, 10, 3);
    }

    /**
     * `update_comment_meta` answers `false` also for a value already stored; that is no
     * failure. A value the database refused is one, and it throws without the value.
     */
    public function testAnUnchangedMetaValueIsStoredAndARefusedOneThrows(): void
    {
        $id = WpStub::insertComment([]);
        $wp = $this->plugin->wp;
        $wp->setMeta($id, Meta::STATUS, Meta::PENDING);
        self::assertFalse(update_comment_meta($id, Meta::STATUS, Meta::PENDING), 'WordPress\'s answer for an unchanged value');
        $wp->setMeta($id, Meta::STATUS, Meta::PENDING);
        $this->refuseMeta($id, Meta::STATUS, -1);
        $wp->setMeta($id, Meta::STATUS, Meta::PENDING);

        $thrown = null;
        try {
            $wp->setMeta($id, Meta::STATUS, 'tajna treść');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $thrown, 'a refused write must throw');
        self::assertStringNotContainsString('tajna', $thrown->getMessage(), 'the value stays out of the exception');
        self::assertSame(Meta::PENDING, $this->field($id, Meta::STATUS));
    }

    /**
     * Shared rule F10: the gateway counts any `2xx` as delivered. A verdict whose final
     * status the database refused (wpdb answers `false`, no exception) is not stored, so the
     * webhook answers `500`, and the gateway's next delivery stores it.
     */
    public function testAVerdictWhoseStatusIsNotStoredAnswers500AndTheRedeliveryStoresIt(): void
    {
        $this->threeComments([]);
        $this->refuseMeta($this->ids[1], Meta::STATUS);

        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'bezpieczne', ['kategorie' => ['spam']])));
        self::assertTrue($this->pending($this->ids[1]), 'not settled: the redelivery must not look like a repeat');
        $this->assertLoggedOnce(Log::STEP_VERDICT);

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'bezpieczne', ['kategorie' => ['spam']])));
        self::assertSame(['1', 'bezpieczne', 'spam'], [$this->field($this->ids[1], 'comment_approved'),
            $this->field($this->ids[1], Meta::STATUS), $this->field($this->ids[1], Meta::CATEGORIES)]);
        self::assertNull($this->field($this->ids[1], Meta::WAITING));
        self::assertCount(1, array_filter(WpStub::$statusChanges, function (array $change): bool {
            return $change[0] === $this->ids[1];
        }), 'approved once');
    }

    /**
     * The same for a late support cue (shared rules A7, F10): a cue the database refused is
     * not recorded, so `500`, and the redelivery records it.
     */
    public function testALateSupportCueThatIsNotStoredAnswers500AndTheRedeliveryStoresIt(): void
    {
        $this->threeComments(['failure_mode' => Settings::FAIL_CLOSED]);
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        $this->refuseMeta($this->ids[1], Meta::SUPPORT);

        self::assertSame(500, $this->deliver(self::verdict($this->ids[1], 'zablokowane', ['wsparcie' => true])));
        self::assertNull($this->field($this->ids[1], Meta::SUPPORT));
        $this->assertLoggedOnce(Log::STEP_VERDICT);

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'zablokowane', ['wsparcie' => true])));
        self::assertSame(['1', '1'], [$this->field($this->ids[1], Meta::SUPPORT), $this->field($this->ids[1], Meta::SUPPORT_AFTER_DECISION)]);
    }

    /**
     * A refused write never escapes into a person's request: the author's comment stays
     * held and nothing is sent; an edit and a person's status change finish, recorded by
     * step and comment id.
     */
    public function testARefusedWriteInAPersonsRequestIsRecordedAndNeverEscapes(): void
    {
        $this->configure();
        $this->ids = [0, 0];
        // The id the next comment gets: the write fails while it is being posted.
        $next = WpStub::$comments === [] ? 1 : max(array_keys(WpStub::$comments)) + 1;
        $this->refuseMeta($next, Meta::STATUS);

        $this->ids[1] = $this->post('Komentarz, którego stanu baza nie zapisała.');

        self::assertSame($next, $this->ids[1]);
        self::assertSame(['0', null], [$this->field($this->ids[1], 'comment_approved'), $this->field($this->ids[1], Meta::STATUS)]);
        self::assertSame([], WpStub::$requests, 'nothing sent for a comment the plugin could not mark');
        $this->assertLoggedOnce(Log::STEP_SUBMIT);

        $edited = $this->post('Komentarz edytowany, gdy baza odmawia zapisu.');
        $this->refuseMeta($edited, Meta::REVISION);
        wp_update_comment(['comment_ID' => $edited, 'comment_content' => 'Zmieniona treść komentarza.']);
        self::assertSame(['0', Log::failureCode(Log::STEP_SUBMIT)], [$this->field($edited, 'comment_approved'), $this->field($edited, Meta::ERROR)]);

        // A publication left half-finished (WordPress approved, the plugin had not settled it).
        $held = $this->post('Komentarz wstrzymany przez osobę, gdy baza odmawia zapisu.');
        WpStub::$comments[$held]['comment_approved'] = '1';
        WpStub::$meta[$held][Meta::AUTO_PUBLISHED] = '1';
        $this->refuseMeta($held, Meta::STATUS);
        self::assertTrue(wp_set_comment_status($held, 'hold'));
        self::assertSame(['0', Log::failureCode(Log::STEP_VERDICT)], [$this->field($held, 'comment_approved'), $this->field($held, Meta::ERROR)]);
    }

    /**
     * A write the database refuses after the status was written, in the author's request:
     * the comment is left to WordPress and a person, not marked as waiting where the sweep
     * never finds it. WordPress's own e-mail to the moderator goes out, and nothing is sent.
     */
    public function testAWriteRefusedAfterTheStatusLeavesTheCommentToTheModerator(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $this->ids = [0, 0];
        $next = WpStub::$comments === [] ? 1 : max(array_keys(WpStub::$comments)) + 1;
        $this->refuseMeta($next, Meta::WAITING);

        $this->ids[1] = $this->post('Komentarz, którego znacznika oczekiwania baza nie zapisała.');

        self::assertSame($next, $this->ids[1]);
        self::assertSame(['0', null, null], [$this->field($this->ids[1], 'comment_approved'),
            $this->field($this->ids[1], Meta::STATUS), $this->field($this->ids[1], Meta::WAITING)]);
        self::assertSame([], WpStub::$requests);
        $this->assertLoggedOnce(Log::STEP_SUBMIT);
        wp_new_comment_notify_moderator($this->ids[1]);
        self::assertContains(['moderator', $this->ids[1]], WpStub::$emails, 'not silenced as one waiting for its verdict');
    }

    /**
     * A person holds a comment whose publication on its verdict was left unfinished, and the
     * database refuses the write that ends its wait: the person's hold still stands, against
     * the gateway's redelivery and against the sweep's timeout under fail-open.
     */
    public function testAPersonsHoldWhoseSettlementIsRefusedStandsAgainstTheRedeliveryAndTheSweep(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $this->ids = [0, $this->post('Komentarz wstrzymany przez osobę, gdy baza odmawia zapisu.')];
        // WordPress approved it on its verdict, then another plugin's hook threw.
        WpStub::$comments[$this->ids[1]]['comment_approved'] = '1';
        WpStub::$meta[$this->ids[1]][Meta::AUTO_PUBLISHED] = '1';
        $this->refuseMeta($this->ids[1], Meta::STATUS);

        self::assertTrue(wp_set_comment_status($this->ids[1], 'hold'));
        $this->assertLoggedOnce(Log::STEP_VERDICT);
        $changes = WpStub::$statusChanges;

        self::assertSame(200, $this->deliver(self::verdict($this->ids[1], 'bezpieczne')));
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);

        self::assertSame('0', $this->field($this->ids[1], 'comment_approved'), 'the person\'s hold stands');
        self::assertSame($changes, WpStub::$statusChanges);
    }

    /**
     * An update whose write the database refused runs on `init`: nothing escapes, the data
     * version stays, and the next request runs the steps again.
     */
    public function testAnUpdateWhoseWriteIsRefusedRunsAgainOnTheNextRequest(): void
    {
        $this->configure();
        $waiting = $this->post('Komentarz czekający przed aktualizacją.');
        unset(WpStub::$meta[$waiting][Meta::WAITING], WpStub::$options[Upgrade::OPTION]);
        $this->refuseMeta($waiting, Meta::WAITING);

        do_action('init');

        self::assertArrayNotHasKey(Upgrade::OPTION, WpStub::$options);
        self::assertNull($this->field($waiting, Meta::WAITING));
        do_action('init');
        self::assertSame([Upgrade::VERSION, '1'], [WpStub::$options[Upgrade::OPTION], $this->field($waiting, Meta::WAITING)]);
    }

    public function testNoExceptionMessageOrTraceIsReadInThePluginsCode(): void
    {
        $files = 0;
        $catches = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src',
            \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $code = (string)file_get_contents($file->getPathname());
            $catches += preg_match_all('/catch \(\\\\Throwable \$/', $code);
            self::assertDoesNotMatchRegularExpression('/->\s*(getMessage|getTrace|getTraceAsString|getPrevious)\s*\(/',
                $code, $file->getPathname());
        }
        self::assertGreaterThanOrEqual(10, $files, 'the scan must find the plugin\'s files: it is broken');
        self::assertGreaterThanOrEqual(6, $catches, 'the scan must find the isolation it guards: it is broken');
    }
}
