<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Submission;
use Minos\WordPress\Sweeper;
use WpStub;

/**
 * Moderation switched off: the plugin does nothing, and comments already waiting stay in
 * WordPress's moderation queue for a person.
 */
final class SwitchedOffTest extends PluginTestCase
{
    /**
     * A comment waiting for its verdict, a second one waiting for a retry, and the plugin
     * then switched off.
     *
     * @return array{0:int,1:int} The two comments.
     */
    private function waitingThenSwitchedOff(): array
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $accepted = $this->post('Komentarz przyjęty przez bramę.');
        WpStub::networkFailure();
        $retrying = $this->post('Komentarz czekający na ponowienie.');
        $this->configure(['enabled' => false, 'failure_mode' => Settings::FAIL_OPEN]);
        return [$accepted, $retrying];
    }

    public function testTheWebhookAnswers404AndChangesNothing(): void
    {
        [$accepted] = $this->waitingThenSwitchedOff();

        self::assertSame(404, $this->deliver(self::verdict($accepted, 'bezpieczne')));
        self::assertSame(404, $this->deliver('nawet nie JSON', 'zły sekret nie ma znaczenia'));
        self::assertTrue($this->pending($accepted));
        self::assertSame('0', $this->field($accepted, 'comment_approved'));
    }

    public function testNoRetryAndNoSweepRun(): void
    {
        [$accepted, $retrying] = $this->waitingThenSwitchedOff();
        $requests = count(WpStub::$requests);

        // Due, and well within the receive timeout.
        $this->now += 2 * Submission::FIRST_BACKOFF_S;
        do_action(Submission::HOOK_RETRY, $retrying);
        do_action(Sweeper::HOOK);
        // Past it.
        $this->now += 3600;
        do_action(Sweeper::HOOK);

        self::assertCount($requests, WpStub::$requests);
        foreach ([$accepted, $retrying] as $id) {
            self::assertTrue($this->pending($id), 'no failure mode either: a person decides');
            self::assertSame('0', $this->field($id, 'comment_approved'));
        }
    }

    public function testSwitchingOnAgainSendsWhatWasWaitingWithAFreshTimeout(): void
    {
        [$accepted, $retrying] = $this->waitingThenSwitchedOff();
        // A third comment waited too, and a moderator approved it while the plugin was off.
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $decided = $this->post('Komentarz zatwierdzony ręcznie w czasie przerwy.');
        $this->configure(['enabled' => false, 'failure_mode' => Settings::FAIL_OPEN]);
        wp_set_comment_status($decided, 'approve');
        // Long past the timeout: the old rule published them all unassessed at the first sweep.
        $this->now += 3 * 3600;
        $requests = count(WpStub::$requests);

        update_option(Settings::OPTION, ['enabled' => true, 'failure_mode' => Settings::FAIL_OPEN] + Settings::defaults());

        foreach ([$accepted, $retrying] as $id) {
            self::assertTrue($this->pending($id));
            self::assertSame('1', $this->field($id, Meta::REVISION), 'sent again as its next revision');
            self::assertSame((string)$this->now, $this->field($id, Meta::HELD_AT), 'with a fresh timeout');
        }
        $due = array_values(array_filter(self::events(Submission::HOOK_RETRY), function (array $event): bool {
            return $event['at'] === $this->now;
        }));
        self::assertSame([[$accepted], [$retrying]], array_column($due, 'args'));
        self::assertCount($requests, WpStub::$requests, 'from WP-Cron, not in the administrator\'s request');
        self::assertSame(Meta::UNASSESSED, $this->field($decided, Meta::STATUS), 'a person\'s decision is left alone');
        self::assertSame('1', $this->field($decided, 'comment_approved'));

        do_action(Sweeper::HOOK);

        $sent = array_map(static function (array $request): string {
            return json_decode($request['args']['body'], true)['elementy'][0]['id'];
        }, array_slice(WpStub::$requests, $requests));
        self::assertSame(['wp:' . $accepted . '.1', 'wp:' . $retrying . '.1'], $sent);
        foreach ([$accepted, $retrying] as $id) {
            self::assertTrue($this->pending($id), 'not published unassessed');
            self::assertSame('0', $this->field($id, 'comment_approved'));
        }
    }

    public function testSavingTheSettingsWhileSwitchedOnSendsNothingAgain(): void
    {
        $this->configure();
        $id = $this->post('Komentarz czekający przy zapisie ustawień.');

        update_option(Settings::OPTION, ['enabled' => true, 'timeout_min' => 30] + Settings::defaults());

        self::assertNull($this->field($id, Meta::REVISION));
        self::assertSame([], self::events(Submission::HOOK_RETRY));
    }

    public function testNewCommentsAreLeftAsWordPressDecidedAndTheModeratorIsNotSilenced(): void
    {
        [$accepted] = $this->waitingThenSwitchedOff();

        $new = $this->post('Nowy komentarz po wyłączeniu.');
        self::assertSame('1', $this->field($new, 'comment_approved'));
        self::assertNull($this->field($new, Meta::STATUS));
        self::assertTrue(apply_filters('notify_moderator', true, $accepted));
    }
}
