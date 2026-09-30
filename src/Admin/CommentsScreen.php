<?php

declare(strict_types=1);

namespace Minos\WordPress\Admin;

use Minos\WordPress\Log;
use Minos\WordPress\Meta;
use Minos\WordPress\Platform;
use Minos\WordPress\Settings;

defined('ABSPATH') || exit;

/**
 * What a moderator sees: the "Minos" column in the comments list and the admin notices.
 *
 * The support cue (`wsparcie`) is shown in the column and in a notice on the comments
 * screen. The plugin cannot reach the commenter — the gateway never receives the author's
 * contact data — so answering is the forum's decision.
 */
final class CommentsScreen
{
    /** The column's key. */
    public const COLUMN = 'minos';

    /** How far back the support notice looks. */
    public const SUPPORT_WINDOW_S = 7 * 86400;

    /** @var Platform */
    private $wp;

    /** @var Settings */
    private $settings;

    /** @var Log */
    private $log;

    /**
     * @param Platform $wp       The WordPress adapter.
     * @param Settings $settings The settings.
     * @param Log      $log      The administrator's log.
     */
    public function __construct(Platform $wp, Settings $settings, Log $log)
    {
        $this->wp = $wp;
        $this->settings = $settings;
        $this->log = $log;
    }

    /**
     * Hooks the column and the notices into the admin.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('manage_edit-comments_columns', [$this, 'addColumn']);
        add_action('manage_comments_custom_column', [$this, 'renderColumn'], 10, 2);
        add_action('admin_notices', [$this, 'renderNotices']);
    }

    /**
     * `manage_edit-comments_columns` filter.
     *
     * @param mixed $columns The columns so far.
     * @return mixed The columns with "Minos".
     */
    public function addColumn($columns)
    {
        if (is_array($columns)) {
            $columns[self::COLUMN] = 'Minos';
        }
        return $columns;
    }

    /**
     * `manage_comments_custom_column` action: the status, the categories, the support cue.
     *
     * @param string     $column    The column being printed.
     * @param int|string $commentId The comment.
     * @return void
     */
    public function renderColumn($column, $commentId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        $id = (int)$commentId;
        $status = $this->wp->meta($id, Meta::STATUS);
        if ($status === '') {
            echo '—';
            return;
        }
        $labels = self::statusLabels();
        echo '<strong>' . esc_html($labels[$status] ?? $status) . '</strong>';
        $categories = $this->wp->meta($id, Meta::CATEGORIES);
        if ($categories !== '') {
            echo '<br /><small>' . esc_html(str_replace(',', ', ', $categories)) . '</small>';
        }
        $error = $this->wp->meta($id, Meta::ERROR);
        if ($error !== '') {
            echo '<br /><small>' . esc_html(sprintf(__('błąd: %s', 'minos-moderation'), $error)) . '</small>';
        }
        if ($this->wp->meta($id, Meta::ORIGINAL) !== '') {
            echo '<br /><small>' . esc_html__('opublikowano wersję zamaskowaną; oryginał zachowano', 'minos-moderation') . '</small>';
        }
        if ($this->wp->meta($id, Meta::SUPPORT) === '1') {
            echo '<p><strong>' . esc_html__('Sygnał wsparcia:', 'minos-moderation') . '</strong> '
                . esc_html(self::supportAdvice()) . '</p>';
        }
    }

    /**
     * `admin_notices` action: a configuration error, a switched-on plugin that cannot work,
     * and recent support cues on the comments screen.
     *
     * @return void
     */
    public function renderNotices(): void
    {
        if ($this->wp->currentUserCan('manage_options')) {
            $error = $this->log->lastConfigError();
            if ($error !== null) {
                self::notice('error', __('Minos: brama odrzuciła komentarz i ponawianie nie pomoże.', 'minos-moderation')
                    . ' ' . self::errorAdvice($error['http'] ?? null, $error['code'] ?? null)
                    . ' ' . __('Do czasu poprawki komentarze trafiają do trybu „gdy brak werdyktu”.', 'minos-moderation'));
            }
            if ($this->settings->all()['enabled'] && !$this->settings->isActive()) {
                self::notice('warning', __('Minos: moderacja jest włączona, ale brakuje klucza API lub sekretu webhooka, więc komentarze nie są wysyłane do oceny.', 'minos-moderation'));
            }
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (is_object($screen) && ($screen->id ?? '') === 'edit-comments'
            && $this->wp->currentUserCan('moderate_comments')) {
            $count = $this->wp->supportFlagsSince($this->wp->now() - self::SUPPORT_WINDOW_S);
            if ($count > 0) {
                // No plural form: Polish needs three, and the source strings are Polish.
                self::notice('info', sprintf(__('Minos: komentarze z ostatnich 7 dni, które mogą dotyczyć samookaleczenia: %d (kolumna „Minos”).', 'minos-moderation'), $count)
                    . ' ' . self::supportAdvice());
            }
        }
    }

    /**
     * What a moderator can do about a support cue.
     *
     * @return string Polish text.
     */
    public static function supportAdvice(): string
    {
        return __('Autor może potrzebować pomocy. Wtyczka nie kontaktuje się z autorem — rozważ odpowiedź z informacją o wsparciu, np. telefon zaufania 116 123 (dorośli) lub 116 111 (dzieci i młodzież).', 'minos-moderation');
    }

    /**
     * The status labels of the column.
     *
     * @return array<string,string> Status → Polish label.
     */
    public static function statusLabels(): array
    {
        return [
            Meta::PENDING      => __('Czeka na ocenę', 'minos-moderation'),
            'bezpieczne'       => __('Bezpieczny', 'minos-moderation'),
            'ocenzurowane'     => __('Ocenzurowany', 'minos-moderation'),
            'zablokowane'      => __('Zablokowany', 'minos-moderation'),
            Meta::UNASSESSED   => __('Nieoceniony', 'minos-moderation'),
        ];
    }

    /**
     * What to do about a configuration error.
     *
     * @param int|null    $http The HTTP status.
     * @param string|null $code The gateway's code.
     * @return string Polish text.
     */
    public static function errorAdvice(?int $http, ?string $code): string
    {
        $advice = [
            'brak_klucza'         => __('Brama nie rozpoznaje klucza API — sprawdź klucz w Ustawienia → Minos.', 'minos-moderation'),
            'nie_ta_powierzchnia' => __('Ten klucz nie jest kluczem B2B — poproś operatora o klucz dla forum.', 'minos-moderation'),
            'brak_webhooka'       => __('Klucz nie ma adresu webhooka — przekaż operatorowi adres webhooka z Ustawienia → Minos.', 'minos-moderation'),
            'profil_niedozwolony' => __('Klucz nie ma dostępu do wybranego profilu — zmień profil albo poproś operatora o dostęp.', 'minos-moderation'),
            'nie_znaleziono'      => __('Pod tym adresem bramy nie ma trasy B2B — sprawdź adres bramy.', 'minos-moderation'),
        ];
        if ($code !== null && isset($advice[$code])) {
            return $advice[$code];
        }
        return sprintf(__('Odpowiedź bramy: HTTP %1$s, kod %2$s.', 'minos-moderation'),
            $http !== null ? (string)$http : '—', $code ?? '—');
    }

    /**
     * Prints an admin notice.
     *
     * @param string $type `error`, `warning` or `info`.
     * @param string $text The text.
     * @return void
     */
    private static function notice(string $type, string $text): void
    {
        printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($text));
    }
}
