<?php

declare(strict_types=1);

namespace Minos\WordPress;

use Minos\Client\Signature;
use Minos\Client\WebhookPayload;

defined('ABSPATH') || exit;

/**
 * The webhook the gateway delivers verdicts to: `POST /wp-json/minos/v1/webhook`.
 *
 * The route is public (`permission_callback` is `__return_true`) because the signature IS
 * the authentication. The contract's receiving checklist, in order:
 * 1. the raw body, as the bytes arrived (`WP_REST_Request::get_body()` is `php://input`),
 *    never the re-encoded parameters;
 * 2. `X-Wergiliusz-Podpis` verified by the bundled `Signature::verify` — `401` otherwise, and
 *    the gateway retries;
 * 3. `WebhookPayload::parse` — `400` for a body that is not a payload; an id that is not a
 *    comment waiting for its verdict (unknown, or handled already: deliveries repeat) gets
 *    `200` and nothing else;
 * 4. the verdict applied ({@see Outcome});
 * 5. `200` at once: the only slow step, the e-mail, goes to WP-Cron.
 */
final class Receiver
{
    /** The REST namespace. */
    public const REST_NAMESPACE = 'minos/v1';

    /** The route inside it. */
    public const ROUTE = '/webhook';

    /** The item id the plugin sends: `wp:<comment id>`. */
    private const ID = '/^wp:([1-9][0-9]{0,17})\z/';

    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /** @var Outcome */
    private $outcome;

    /**
     * @param Platform $wp       The WordPress adapter.
     * @param Settings $settings The settings.
     * @param Outcome  $outcome  What applies a verdict.
     */
    public function __construct(Platform $wp, Settings $settings, Outcome $outcome)
    {
        $this->wp = $wp;
        $this->settings = $settings;
        $this->outcome = $outcome;
    }

    /**
     * The webhook's URL, to register with the key.
     *
     * @return string The URL.
     */
    public function url(): string
    {
        return $this->wp->restUrl(self::REST_NAMESPACE . self::ROUTE);
    }

    /**
     * Handles one delivery.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response `200`, `400` or `401`, without a body worth reading.
     */
    public function handle($request): \WP_REST_Response
    {
        $body = (string)$request->get_body();
        $header = $request->get_header(Signature::HEADER);
        $secret = $this->settings->webhookSecret();
        if ($secret === '' || !is_string($header)
            || !Signature::verify($secret, $header, $body, $this->wp->now())) {
            return $this->wp->response(401);
        }
        $payload = WebhookPayload::parse($body);
        if ($payload === null) {
            return $this->wp->response(400);
        }
        if (preg_match(self::ID, $payload['id'], $match) === 1) {
            $id = (int)$match[1];
            if ($this->wp->meta($id, Meta::STATUS) === Meta::PENDING) {
                $this->outcome->verdict($id, $payload);
            }
        }
        return $this->wp->response(200);
    }
}
