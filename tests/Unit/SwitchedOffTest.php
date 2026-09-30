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

    public function testNewCommentsAreLeftAsWordPressDecidedAndTheModeratorIsNotSilenced(): void
    {
        [$accepted] = $this->waitingThenSwitchedOff();

        $new = $this->post('Nowy komentarz po wyłączeniu.');
        self::assertSame('1', $this->field($new, 'comment_approved'));
        self::assertNull($this->field($new, Meta::STATUS));
        self::assertTrue(apply_filters('notify_moderator', true, $accepted));
    }
}
