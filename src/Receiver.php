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
 * the authentication. While moderation is switched off the route answers `404` and reads
 * nothing: the plugin does nothing, and the gateway retries within its delivery window.
 * Otherwise the contract's receiving checklist, in order:
 * 1. the raw body, as the bytes arrived (`WP_REST_Request::get_body()` is `php://input`),
 *    never the re-encoded parameters;
 * 2. `X-Wergiliusz-Podpis` verified by the bundled `Signature::verify`, with a secret that
 *    passes the same check as saving it (`Settings::validSecret`) — `401` otherwise, and
 *    the gateway retries;
 * 3. `WebhookPayload::parse` — `400` for a body that is not a payload; an id the verdict can
 *    no longer change (unknown, handled already — deliveries repeat —, decided by a person,
 *    or another revision than the comment's current one) gets `200` and nothing else;
 * 4. the verdict applied ({@see Outcome}) — only while the comment still reads as the text
 *    that was sent; a comment edited since is held for its next revision instead, which
 *    WP-Cron sends (the webhook itself never calls the gateway);
 * 5. `200` at once: the only slow step, the e-mail, goes to WP-Cron. When applying fails
 *    with an exception (another plugin's hook), `500`: the comment still waits, so the
 *    gateway's next delivery of the verdict is applied, not dropped as a repeat.
 */
final class Receiver
{
    /** The REST namespace. */
    public const REST_NAMESPACE = 'minos/v1';

    /** The route inside it. */
    public const ROUTE = '/webhook';

    /** The item id the plugin sends: `wp:<comment id>`, then `wp:<comment id>.<revision>`. */
    private const ID = '/^wp:([1-9][0-9]{0,17})(?:\.([1-9][0-9]{0,8}))?\z/';

    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /** @var Outcome */
    private $outcome;

    /** @var Submission */
    private $submission;

    /**
     * @param Platform   $wp         The WordPress adapter.
     * @param Settings   $settings   The settings.
     * @param Outcome    $outcome    What applies a verdict.
     * @param Submission $submission What sends an edited comment again.
     */
    public function __construct(Platform $wp, Settings $settings, Outcome $outcome, Submission $submission)
    {
        $this->wp = $wp;
        $this->settings = $settings;
        $this->outcome = $outcome;
        $this->submission = $submission;
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
     * @return \WP_REST_Response `200`, `400`, `401`, `404` or `500`, without a body worth reading.
     */
    public function handle($request): \WP_REST_Response
    {
        if (!$this->settings->all()['enabled']) {
            return $this->wp->response(404);
        }
        $body = (string)$request->get_body();
        $header = $request->get_header(Signature::HEADER);
        $secret = $this->settings->webhookSecret();
        // The same check as saving the secret: one written straight into the option (too
        // short, empty, with spaces) authenticates nothing.
        if (!Settings::validSecret($secret) || !is_string($header)
            || !Signature::verify($secret, $header, $body, $this->wp->now())) {
            return $this->wp->response(401);
        }
        $payload = WebhookPayload::parse($body);
        if ($payload === null) {
            return $this->wp->response(400);
        }
        if (preg_match(self::ID, $payload['id'], $match) === 1) {
            $id = (int)$match[1];
            if ($this->outcome->awaitsVerdict($id, (int)($match[2] ?? 0)) && !$this->apply($id, $payload)) {
                return $this->wp->response(500);
            }
        }
        return $this->wp->response(200);
    }

    /**
     * Applies a verdict to the text it is about, before anything else: a comment that no
     * longer reads as the text that was sent gets no verdict, and is sent again.
     *
     * @param int                 $id      A comment {@see Outcome::awaitsVerdict} accepts.
     * @param array<string,mixed> $payload The parsed payload.
     * @return bool False when it failed with an exception: recorded by step and comment id,
     *     never by its message.
     */
    private function apply(int $id, array $payload): bool
    {
        try {
            if ($this->outcome->readsAsSent($id)) {
                $this->outcome->verdict($id, $payload);
            } elseif (!$this->submission->reassessLater($id)) {
                // Sent from WP-Cron: the webhook answers at once and never calls the gateway.
                $this->outcome->abandon($id);
            }
        } catch (\Throwable $e) {
            $this->outcome->failed(Log::STEP_VERDICT, $id);
            return false;
        }
        return true;
    }
}
