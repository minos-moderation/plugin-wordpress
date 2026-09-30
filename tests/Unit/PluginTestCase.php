<?php

declare(strict_types=1);

namespace Minos\WordPress\Tests\Unit;

use Minos\Client\Signature;
use Minos\WordPress\Meta;
use Minos\WordPress\Platform;
use Minos\WordPress\Plugin;
use Minos\WordPress\Settings;
use PHPUnit\Framework\TestCase;
use WpStub;

/**
 * A stubbed WordPress with the plugin hooked in, a fixed clock and helpers to post a
 * comment, read what was sent and deliver a signed verdict. Every comment here is
 * invented test text.
 */
abstract class PluginTestCase extends TestCase
{
    /** A key shaped like a real one; not a real one. */
    protected const KEY = 'wgb2b_testowy_klucz_000000000000';

    /** A webhook secret for tests only. */
    protected const SECRET = 'testowy-sekret-webhooka-0123456789';

    /** @var int The clock, in unix seconds. */
    protected $now = 1800000000;

    /** @var Plugin */
    protected $plugin;

    protected function setUp(): void
    {
        WpStub::reset();
        $this->plugin = new Plugin(new Platform(function (): int {
            return $this->now;
        }));
        $this->plugin->hook(__DIR__ . '/../../minos-moderation.php');
    }

    /**
     * Switches the plugin on with a key and a secret.
     *
     * @param array<string,mixed> $settings Settings over the defaults.
     */
    protected function configure(array $settings = []): void
    {
        WpStub::$options[Settings::OPTION] = $settings + ['enabled' => true] + Settings::defaults();
        WpStub::$options[Settings::KEY_OPTION] = self::KEY;
        WpStub::$options[Settings::SECRET_OPTION] = self::SECRET;
    }

    /**
     * Posts a comment through the WordPress hooks; the gateway answers `202`.
     *
     * @param string              $text     The comment's content.
     * @param int|string          $decision What WordPress's own rules decided.
     * @param array<string,mixed> $fields   Other fields.
     * @return int The comment id.
     */
    protected function post(string $text, $decision = 1, array $fields = []): int
    {
        $fields += ['comment_date_gmt' => gmdate('Y-m-d H:i:s', $this->now)];
        return WpStub::postComment(['comment_content' => $text] + $fields, $decision);
    }

    /**
     * A comment's column or its meta.
     *
     * @param int    $id   The comment.
     * @param string $name A column (`comment_approved`, …) or a meta key.
     * @return string|null The value, or null when there is none.
     */
    protected function field(int $id, string $name): ?string
    {
        if (strpos($name, '_minos_') === 0) {
            return WpStub::$meta[$id][$name] ?? null;
        }
        return WpStub::$comments[$id][$name] ?? null;
    }

    /**
     * The JSON body of a request the plugin sent.
     *
     * @param int $index Which request (the last by default).
     * @return array<string,mixed> The decoded body.
     */
    protected function sentBody(int $index = -1): array
    {
        self::assertNotEmpty(WpStub::$requests, 'the plugin sent nothing');
        $request = array_slice(WpStub::$requests, $index, 1)[0];
        return json_decode($request['args']['body'], true);
    }

    /**
     * Delivers a payload to the webhook, signed like the gateway signs it.
     *
     * @param array<string,mixed>|string $payload The payload, or an exact body.
     * @param string|null                $secret  The signing secret (the configured one).
     * @param int|null                   $at      The signing moment (now).
     * @return int The HTTP status the webhook answered.
     */
    protected function deliver($payload, ?string $secret = null, ?int $at = null): int
    {
        $body = is_string($payload) ? $payload : (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
        $request = new \WP_REST_Request('POST', '/minos/v1/webhook');
        $request->set_body($body);
        $request->set_header(Signature::HEADER, Signature::sign($secret ?? self::SECRET, $body, $at ?? $this->now));
        return $this->plugin->receiver->handle($request)->get_status();
    }

    /**
     * A verdict payload for a comment, as the gateway builds it.
     *
     * @param int                 $id           The comment.
     * @param string              $qualification `bezpieczne`, `ocenzurowane` or `zablokowane`.
     * @param array<string,mixed> $extra        Fields over the defaults.
     * @return array<string,mixed> The payload.
     */
    protected static function verdict(int $id, string $qualification, array $extra = []): array
    {
        return $extra + [
            'id'           => 'wp:' . $id,
            'status'       => 'ocenione',
            'kwalifikacja' => $qualification,
            'kategorie'    => [],
            'wsparcie'     => false,
            'wersja'       => '3f0c9a41d2b7e8c5',
        ];
    }

    /**
     * Whether a comment is still waiting for its verdict.
     *
     * @param int $id The comment.
     * @return bool The answer.
     */
    protected function pending(int $id): bool
    {
        return $this->field($id, Meta::STATUS) === Meta::PENDING;
    }

    /**
     * The scheduled events of one action.
     *
     * @param string $hook The action.
     * @return array<int,array{at:int,hook:string,args:array,recurrence:string|null}>
     */
    protected static function events(string $hook): array
    {
        return array_values(array_filter(WpStub::$events, static function (array $event) use ($hook): bool {
            return $event['hook'] === $hook;
        }));
    }
}
