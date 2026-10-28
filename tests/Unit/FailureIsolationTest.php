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
