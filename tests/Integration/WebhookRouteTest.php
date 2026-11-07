<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Integration;

use Minos\Client\Signature;
use Minos\WordPress\Meta;
use Minos\WordPress\Settings;

/**
 * The route `minos/v1/webhook` on WordPress's own REST server, fed the mock gateway's
 * deliveries byte for byte: the signature is checked on the raw body the server read, never
 * on the parameters it decoded.
 */
final class WebhookRouteTest extends IntegrationTestCase
{
    public function testTheMocksDeliveriesAreVerifiedOnTheRawBodyAndApplied(): void
    {
        $this->startGateway();
        $ids = [
            'safe'    => $this->post('Uprzejmy komentarz: zażółć gęślą jaźń.'),
            'blocked' => $this->post('Komentarz [minos:blokuj] [minos:kategoria=nekanie]'),
            'support' => $this->post('Komentarz testowy [minos:kategoria=samookaleczenie]'),
            // Its delivery carries `█`, raw: decoding and encoding it again changes the bytes.
            'masked'  => $this->post('To jest [[paskudny]] komentarz [minos:cenzuruj]'),
        ];

        $this->runMockWorker();
        $deliveries = $this->deliveries();
        self::assertCount(4, $deliveries);
        foreach ($deliveries as $delivery) {
            self::assertSame('POST', $delivery['method']);
            self::assertSame(200, $this->serveDelivery($delivery));
            self::assertSame($delivery['body'], rest_get_server()->last_request->get_body(), 'the raw body reached the route');
        }

        self::assertSame(['1', 'bezpieczne'], [self::row($ids['safe'])['comment_approved'], self::stored($ids['safe'], Meta::STATUS)]);
        self::assertSame(['0', 'zablokowane', 'nekanie'], [self::row($ids['blocked'])['comment_approved'],
            self::stored($ids['blocked'], Meta::STATUS), self::stored($ids['blocked'], Meta::CATEGORIES)]);
        self::assertSame('1', self::stored($ids['support'], Meta::SUPPORT));
        self::assertSame('ocenzurowane', self::stored($ids['masked'], Meta::STATUS));
    }

    public function testABodyReencodedOnTheWayIsRefusedAndTheOriginalStillApplies(): void
    {
        $this->startGateway();
        $id = $this->post('Komentarz o zażółć gęślą jaźń.');
        $this->runMockWorker();
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        $delivery = $deliveries[0];

        // The same payload, encoded again: the same parameters for the REST server, other bytes.
        $reencoded = (string)json_encode(json_decode($delivery['body'], true), JSON_PRETTY_PRINT);
        self::assertSame(json_decode($delivery['body'], true), json_decode($reencoded, true));
        self::assertNotSame($delivery['body'], $reencoded);
        self::assertSame(401, $this->serveWebhook($reencoded, $delivery['headers']));
        self::assertSame(Meta::PENDING, self::stored($id, Meta::STATUS));

        self::assertSame(200, $this->serveDelivery($delivery));
        self::assertSame('bezpieczne', self::stored($id, Meta::STATUS));
    }

    public function testABadSignatureIsRefusedAndARepeatedDeliveryChangesNothing(): void
    {
        $this->startGateway();
        $forged = $this->post('Podrobiony podpis [minos:zly-podpis]');
        $twice = $this->post('Doręczony dwa razy [minos:dwa-razy]');

        $this->runMockWorker();
        $this->runMockWorker();
        $statuses = [];
        foreach ($this->deliveries() as $delivery) {
            $statuses[] = $this->serveDelivery($delivery);
        }
        sort($statuses);

        self::assertSame([200, 200, 401], $statuses, 'two deliveries of one verdict, one forged');
        self::assertSame(['0', Meta::PENDING], [self::row($forged)['comment_approved'], self::stored($forged, Meta::STATUS)]);
        self::assertSame(['1', 'bezpieczne'], [self::row($twice)['comment_approved'], self::stored($twice, Meta::STATUS)]);
        self::assertCount(1, self::mails(), 'the post\'s author was mailed once, not once a delivery');
    }

    /**
     * Shared rule F10 on the real WordPress: `update_comment_meta` answers `false` both for a
     * write that stored nothing (its own short-circuit filter here, where a refusing database
     * gives the same answer) and for an unchanged value. The first is a verdict not stored:
     * `500`, and the gateway's next delivery stores it; the second is no failure.
     */
    public function testAVerdictWhoseStatusIsNotStoredAnswers500AndTheRedeliveryStoresIt(): void
    {
        $this->startGateway();
        $id = $this->post('Komentarz, którego werdyktu baza nie zapisała.');
        $this->runMockWorker();
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertFalse(update_comment_meta($id, Meta::WAITING, '1'), 'WordPress\'s answer for an unchanged value');
        $refuse = static function ($check, $objectId, $key) use ($id) {
            return (int)$objectId === $id && $key === Meta::STATUS ? false : $check;
        };

        add_filter('update_comment_metadata', $refuse, 10, 3);
        self::assertSame(500, $this->serveDelivery($deliveries[0]));
        remove_filter('update_comment_metadata', $refuse, 10);
        self::assertSame(Meta::PENDING, self::stored($id, Meta::STATUS));

        self::assertSame(200, $this->serveDelivery($deliveries[0]));
        self::assertSame(['1', 'bezpieczne'], [self::row($id)['comment_approved'], self::stored($id, Meta::STATUS)]);
        self::assertNull(self::stored($id, Meta::WAITING));
    }

    public function testTheRouteTakesOnlyPostAndAnswers404WhileSwitchedOff(): void
    {
        $this->configure();
        $body = (string)json_encode(self::verdict(1, 'bezpieczne'));
        $headers = [self::serverName(Signature::HEADER) => Signature::sign(self::SECRET, $body, time())];

        self::assertSame(404, $this->serveWebhook($body, $headers, 'GET'), 'no such route for GET');
        update_option(Settings::OPTION, ['enabled' => false] + Settings::defaults());
        self::assertSame(404, $this->serveWebhook($body, $headers));
    }
}
