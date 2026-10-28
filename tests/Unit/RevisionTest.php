<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\WordPress\Meta;
use Minos\WordPress\Settings;
use Minos\WordPress\Sweeper;
use WpStub;

/**
 * A verdict is about the text that was sent: an edit of a comment that was never assessed
 * sends it again as `wp:<id>.<n>`, a verdict for another revision changes nothing, and a
 * comment that no longer reads as the sent text gets no verdict. Invented comments only.
 */
final class RevisionTest extends PluginTestCase
{
    /**
     * The ids of the requests sent since an index.
     *
     * @param int $from The first request to look at.
     * @return array<int,string> The item ids, in order.
     */
    private function idsSentFrom(int $from): array
    {
        $ids = [];
        foreach (array_slice(WpStub::$requests, $from) as $request) {
            $ids[] = json_decode($request['args']['body'], true)['elementy'][0]['id'];
        }
        return $ids;
    }

    public function testAnEditWhileWaitingIsSentAsTheNextRevisionAndTheOldVerdictChangesNothing(): void
    {
        $this->configure();
        $id = $this->post('Grzeczny komentarz testowy.');
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Zmieniona treść testowa, której brama nie widziała.']);

        self::assertSame(['wp:' . $id . '.1'], $this->idsSentFrom($before), 'exactly one new request, the next revision');
        self::assertSame('Zmieniona treść testowa, której brama nie widziała.', $this->sentBody()['elementy'][0]['tekst']);
        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('1', $this->field($id, Meta::REVISION));

        // The audit's reproduction: `bezpieczne` for the old id used to publish the new text.
        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne')));
        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertTrue($this->pending($id));
        self::assertSame([], WpStub::$statusChanges);

        self::assertSame(200, $this->deliver(['id' => 'wp:' . $id . '.1'] + self::verdict($id, 'bezpieczne')));
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($id, Meta::STATUS));
    }

    /**
     * @return array<string,array{0:string,1:array<string,mixed>}>
     */
    public static function verdictsForTheOldText(): array
    {
        return [
            'bezpieczne'   => ['bezpieczne', []],
            'zablokowane'  => ['zablokowane', []],
            'ocenzurowane' => ['ocenzurowane', ['ocenzurowany' => 'Grzeczny ███████ testowy.']],
            'nieocenione'  => ['nieocenione', []],
        ];
    }

    /**
     * @dataProvider verdictsForTheOldText
     * @param array<string,mixed> $extra
     */
    public function testAVerdictForATextTheCommentNoLongerHasIsNeverApplied(string $verdict, array $extra): void
    {
        $this->configure(['blocked_mode' => Settings::BLOCKED_SPAM, 'failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post('Grzeczny komentarz testowy.');
        // Changed without `edit_comment` (another plugin's direct write): only the hash tells.
        WpStub::$comments[$id]['comment_content'] = 'Podmieniona treść testowa.';
        $before = count(WpStub::$requests);

        $payload = $verdict === 'nieocenione' ? ['id' => 'wp:' . $id, 'status' => 'nieocenione']
            : self::verdict($id, $verdict, $extra);
        self::assertSame(200, $this->deliver($payload));

        self::assertSame('0', $this->field($id, 'comment_approved'));
        self::assertSame('Podmieniona treść testowa.', $this->field($id, 'comment_content'));
        self::assertSame([], WpStub::$statusChanges);
        self::assertSame(['wp:' . $id . '.1'], $this->idsSentFrom($before));
        self::assertSame('Podmieniona treść testowa.', $this->sentBody()['elementy'][0]['tekst']);
        self::assertTrue($this->pending($id));
    }

    public function testAVerdictForAnotherRevisionIs200AndChangesNothing(): void
    {
        $this->configure();
        $id = $this->post('Komentarz w pierwszej wersji.');

        foreach (['wp:' . $id . '.1', 'wp:' . $id . '.0', 'wp:' . $id . '.', 'wp:' . $id . '.01'] as $other) {
            self::assertSame(200, $this->deliver(['id' => $other] + self::verdict($id, 'bezpieczne')), $other);
        }

        self::assertTrue($this->pending($id));
        self::assertSame([], WpStub::$statusChanges);
    }

    public function testACommentWaitingFromBeforeRevisionsIsRevisionZero(): void
    {
        $this->configure();
        $id = $this->post('Komentarz wysłany przed aktualizacją wtyczki.');
        unset(WpStub::$meta[$id][Meta::REVISION]);

        self::assertSame(200, $this->deliver(self::verdict($id, 'bezpieczne')));
        self::assertSame('1', $this->field($id, 'comment_approved'));
    }

    public function testAnEditThatLeavesTheTextAloneSendsNothing(): void
    {
        $this->configure();
        $id = $this->post('Komentarz, któremu poprawiono podpis.');
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $id, 'comment_author' => 'Inny podpis']);

        self::assertSame([], $this->idsSentFrom($before));
        self::assertNull($this->field($id, Meta::REVISION));
    }

    public function testAnEditAfterAFailClosedTimeoutIsSentAgain(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_CLOSED]);
        $id = $this->post('Komentarz bez werdyktu w czasie.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS));
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Komentarz poprawiony po czasie.']);

        self::assertSame(['wp:' . $id . '.1'], $this->idsSentFrom($before));
        self::assertTrue($this->pending($id));
        self::assertSame((string)$this->now, $this->field($id, Meta::HELD_AT), 'a fresh timeout');
        self::assertNull($this->field($id, Meta::TIMED_OUT));
        $this->deliver(['id' => 'wp:' . $id . '.1'] + self::verdict($id, 'bezpieczne'));
        self::assertSame('1', $this->field($id, 'comment_approved'));
    }

    public function testAnEditAfterAFailOpenPublicationHoldsItWhileTheNewTextIsAssessed(): void
    {
        $this->configure(['failure_mode' => Settings::FAIL_OPEN]);
        $id = $this->post('Komentarz opublikowany bez werdyktu.');
        $this->now += Settings::DEFAULT_TIMEOUT_MIN * 60;
        do_action(Sweeper::HOOK);
        self::assertSame(['1', '1'], [$this->field($id, 'comment_approved'), $this->field($id, Meta::AUTO_PUBLISHED)]);
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Komentarz zmieniony po publikacji.']);

        self::assertSame(['wp:' . $id . '.1'], $this->idsSentFrom($before));
        self::assertSame('0', $this->field($id, 'comment_approved'), 'held again while the new text is assessed');
        self::assertNull($this->field($id, Meta::AUTO_PUBLISHED));
        self::assertTrue($this->pending($id));

        $this->deliver(self::verdict($id, 'zablokowane'));
        self::assertTrue($this->pending($id), 'the old text\'s late verdict changes nothing');
        $this->deliver(['id' => 'wp:' . $id . '.1'] + self::verdict($id, 'bezpieczne'));
        self::assertSame('1', $this->field($id, 'comment_approved'));
    }

    public function testAnEditOfAnAssessedCommentIsTheEditorsDecision(): void
    {
        $this->configure();
        $id = $this->post('Komentarz oceniony i opublikowany.');
        $this->deliver(self::verdict($id, 'bezpieczne'));
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Komentarz poprawiony przez moderatora.']);

        self::assertSame([], $this->idsSentFrom($before));
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame('bezpieczne', $this->field($id, Meta::STATUS));
    }

    public function testThePluginsOwnMaskedWriteIsNotAnEdit(): void
    {
        $this->configure(['censored_mode' => Settings::CENSORED_PUBLISH]);
        $id = $this->post('Tekst z brzydkim słowem testowym.');

        $this->deliver(self::verdict($id, 'ocenzurowane', ['ocenzurowany' => 'Tekst z ████████ słowem testowym.']));

        self::assertCount(1, WpStub::$requests);
        self::assertSame(['1', 'ocenzurowane'], [$this->field($id, 'comment_approved'), $this->field($id, Meta::STATUS)]);
        self::assertNull($this->field($id, Meta::REVISION));
    }

    public function testAChangedTextAPersonDecidedOnIsLeftToThePerson(): void
    {
        $this->configure();
        $id = $this->post('Komentarz zatwierdzony ręcznie po zmianie.');
        WpStub::$comments[$id]['comment_content'] = 'Treść zmieniona bez haka.';
        wp_set_comment_status($id, 'approve');
        $before = count(WpStub::$requests);

        self::assertSame(200, $this->deliver(self::verdict($id, 'zablokowane')));

        self::assertSame([], $this->idsSentFrom($before));
        self::assertSame('1', $this->field($id, 'comment_approved'));
        self::assertSame(Meta::UNASSESSED, $this->field($id, Meta::STATUS), 'the verdict was for another text');
    }

    public function testEditsWhileTheModerationIsOffSendNothing(): void
    {
        $this->configure();
        $id = $this->post('Komentarz przed wyłączeniem.');
        $this->configure(['enabled' => false]);
        $before = count(WpStub::$requests);

        wp_update_comment(['comment_ID' => $id, 'comment_content' => 'Komentarz po wyłączeniu.']);

        self::assertSame([], $this->idsSentFrom($before));
        self::assertTrue($this->pending($id));
    }
}
