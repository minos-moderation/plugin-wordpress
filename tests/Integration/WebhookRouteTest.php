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
