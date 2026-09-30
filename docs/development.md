# Developing Minos for WordPress

The administrator's manual is `README.md` (Polish). This page is for developers.

## Layout

| Path | What |
|---|---|
| `minos-moderation.php` | The plugin header and bootstrap: loads `vendor/autoload.php`, calls `Plugin::boot()`. |
| `uninstall.php` | Runs `Uninstaller` when the plugin is deleted. |
| `src/Plugin.php` | Builds the objects and hooks them into WordPress (priorities live here). |
| `src/Platform.php` | The one adapter: every WordPress function the logic calls. |
| `src/Settings.php` | The settings, normalised to known values; the key and secret options. |
| `src/Submission.php` | `pre_comment_approved` → hold; `comment_post` / `rest_insert_comment` → `POST /api/v1/b2b/oceny`; the answers and the retries. |
| `src/Receiver.php` | The REST webhook `minos/v1/webhook`: `404` while switched off; raw body, `Signature::verify`, `WebhookPayload::parse`, dedupe. |
| `src/Outcome.php` | Applies a verdict (on time or late) or the failure mode; verified masked writes; the held-back moderator e-mail. |
| `src/Sweeper.php` | WP-Cron every 5 minutes: the receive timeout and due retries. |
| `src/Text.php` | Plain text for the gateway (strip, keep `title`/`alt` text, decode, trim, cut at 3000), link domains, and back to safe HTML. |
| `src/Log.php` | The administrator's error log: codes and statuses, never content or secrets. |
| `src/Notice.php` | The privacy (RODO) notice under the comment form. |
| `src/Admin/` | Settings → Minos, the "Minos" comments column and the admin notices. |
| `src/Meta.php` | Every `_minos_*` comment meta key (all removed on uninstall). |
| `tests/stubs/wordpress.php` | Hand-written stubs of the WordPress functions the plugin calls. |
| `tests/Unit/` | The real handlers on the stubs. |
| `tests/EndToEnd/` | The plugin against the mock gateway over real HTTP. |
| `tests/Repo/` | The Claude Code rules and the PHP 7.4 syntax guard. |
| `bin/build-zip.sh` | Builds `build/minos-moderation.zip` with `vendor/` inside. |

## How a comment travels

1. `pre_comment_approved` (priority 999, after the site's rules and spam filters) holds a
   comment the plugin moderates (`0`) and remembers WordPress's own decision (`1` or `0`).
   Not moderated: users with `moderate_comments`, anything WordPress or another plugin set
   to `spam`/`trash` or refused with a `WP_Error`, comment types other than `comment`, and
   everything while the plugin is off or lacks the key or the secret. WordPress 6.7+ runs
   the filter twice per comment; the last run wins.
2. `comment_post` (priority 5, before WordPress's own notifications at 10) or, for the REST
   API which does not fire `comment_post`, `rest_insert_comment` marks it `oczekuje` and
   sends it synchronously (`wp_remote_post`, 10 s, no redirects). The body is one item:
   `{"id":"wp:<ID>","tekst":…,"profil":…,"meta":{"links":…,"link_domains":[…],"author_first_post":…}}`
   (`link_domains` only when the content has links: at most 10 registrable domains, found
   with a short list of second-level suffixes instead of the Public Suffix List). A plain
   text over 3000 characters is marked `_minos_cut`.
3. The answer: `202` → accepted; `429`, `5xx` or no answer → a retry after `ponow_za_s`
   or a doubling backoff from 60 s (a one-off WP-Cron event, plus the sweep as a
   fallback); anything else → a configuration error: the failure mode, a log entry and an
   admin notice.
4. The webhook applies the verdict to a comment still marked `oczekuje`, or to one the
   plugin published under fail-open (`_minos_auto_published`); anything else gets `200`
   and nothing else. The moderator's e-mail WordPress held back goes out from WP-Cron.
5. The sweep gives the failure mode to a comment that waited past the timeout (at least
   20 minutes), counted from the gateway's `202`, or from the hold while it was never
   accepted.

With `enabled` off the plugin does nothing: no hold, no submission, no retry, no sweep, and
the webhook answers `404`. Waiting comments stay in WordPress's queue for a person.

Decisions worth knowing before changing anything:

- **The plugin never publishes what WordPress itself would hold.** `_minos_wp_approved`
  records WordPress's decision; publishing (a verdict or fail-open) approves only when it
  is `1`.
- **A person's decision stands.** A comment that is no longer held when the verdict comes
  keeps its status; only the verdict is recorded.
- **Late verdicts.** Under fail-open the timeout publishes and sets
  `_minos_auto_published`; a verdict arriving later is applied (`zablokowane` → hold,
  `ocenzurowane` → per setting). `transition_comment_status` and `edit_comment` clear the
  flag, so a person's status change or edit after the publication stands.
- **A cut comment is never published by a verdict.** The gateway assessed only its first
  3000 characters: `bezpieczne` gets the failure mode, `ocenzurowane` is held.
- **The masked text is plain text, and verified.** `Outcome` escapes it with
  `htmlspecialchars` and writes it only when the comment still reads exactly as the text
  that was sent (`_minos_sent_chars` and `_minos_sent_hash`). It counts only when
  `wp_update_comment` reports success AND a re-read finds exactly the masked HTML: wpdb
  refuses a value over the `text` column's 65,535 bytes (escaping can multiply the length
  by six) and filters may change it. On a failure the original is put back if needed,
  `_minos_original` goes once the content is the original again, the comment stays held
  and `zapis_zamaskowanej_nieudany` is recorded (meta and log).
- **Slashes.** `wp_update_comment` and `update_comment_meta` unslash their input, so
  `Platform` slashes it; the stubs unslash too, and a test holds a backslash.
- **Notifications.** A `notify_moderator` filter suppresses the "awaiting moderation" e-mail
  while a comment is `oczekuje`; when the outcome holds it, `wp_new_comment_notify_moderator`
  runs from WP-Cron. The post's author is mailed by core itself on approval
  (`wp_set_comment_status` hooks `wp_new_comment_notify_postauthor`), so the plugin never
  sends that one.
- **Not done:** batching several comments into one request (every request carries one
  item); translation files (the source strings are Polish, in `__()` with the
  `minos-moderation` domain, and `languages/` is loaded when present).

## WordPress APIs

Checked against the WordPress sources at the `6.0` and `7.1.2` tags
(`github.com/WordPress/WordPress`; `developer.wordpress.org` was not reachable from the
build environment): `pre_comment_approved` (in `wp_allow_comment` / `wp_check_comment_data`,
`$commentdata` as the second argument), `comment_post` (`$comment_id, $comment_approved,
$commentdata`), `rest_insert_comment` (`$comment, $request, $creating`), the REST server
setting the request body from `php://input`, `WP_REST_Request::get_body()` and
`get_header()` (canonicalised names), `register_rest_route` on `rest_api_init`,
`WP_REST_Response`, `rest_url`, `wp_remote_post` / `wp_remote_retrieve_*` / `is_wp_error`,
`wp_schedule_single_event`, `wp_schedule_event`, `wp_next_scheduled`, `wp_unschedule_hook`,
the `cron_schedules` filter, `get_comment`, `get_comments` (`status => any`, meta, `count`,
`user_id`, `author_email`, `date_query`), `get_comment_meta` / `update_comment_meta` /
`delete_comment_meta` / `delete_metadata`, `wp_set_comment_status` (false when nothing
changed; approval mails the post's author), `transition_comment_status`,
`wp_update_comment` (unslashes; returns `1`/`0`, or `false`/`WP_Error`; runs
`pre_comment_content`, `comment_save_pre` and `wp_update_comment_data`, then
`edit_comment`), wpdb refusing a value longer than its column (`text`: 65,535 bytes),
`wp_slash`, `user_can`, `current_user_can`,
`get_option` / `update_option` / `add_option` (autoload `false`) / `delete_option`,
`register_setting` (`type`, `sanitize_callback`, `default`), `add_options_page`,
`add_settings_section`, `add_settings_field`, `add_settings_error`, `settings_fields`,
`do_settings_sections`, `submit_button`, `checked`, `selected`, `esc_*`, `wp_date`,
`sanitize_textarea_field`, `manage_edit-comments_columns`, `manage_comments_custom_column`,
`admin_notices`, `get_current_screen`, `comment_form`, `notify_moderator`,
`wp_new_comment_notify_moderator`, `wp_new_comment_notify_postauthor`,
`load_plugin_textdomain`, `register_activation_hook`, `register_deactivation_hook`,
`WP_UNINSTALL_PLUGIN`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI (`.github/workflows/tests.yml`) runs `php -l` over the plugin, the suite and the zip
build on PHP 7.4 and 8.3. The suite needs `ext-curl` and `ext-mbstring` and starts PHP's
built-in server for the end-to-end test.

- **Unit** (`tests/Unit/`): `PluginTestCase` hooks the real `Plugin` into the stubs with a
  fixed clock. `WpStub::postComment()` runs the filter, the insert and `comment_post` as
  `wp_new_comment` does; `deliver()` signs a payload with `Signature::sign` and calls the
  real receiver; `WpStub::answer()` queues the gateway's answers; every HTTP request,
  status change, cron event and e-mail is recorded in `WpStub`.
- **End to end** (`tests/EndToEnd/`): the mock gateway from
  `vendor/minos-moderation/client-php/mock-gateway` on one built-in server, a stand-in site
  (`fixtures/site.php`) running the real receiver on another, the comments posted through
  the plugin's hooks with `wp_remote_post` sending for real, the mock's worker delivering.
  Servers start through array-form `proc_open`, and `tearDown` asserts every port closed.
- **Stubs**: a new WordPress call goes through `Platform` and gets a stub that keeps
  WordPress's observable behaviour (slashing, status words, header names).
- **PHP 7.4**: no `match`, `?->`, attributes, enums, `readonly`, union or `mixed` types,
  promoted constructors, named arguments, trailing commas in parameter lists,
  non-capturing `catch`, `str_contains` / `str_starts_with` / `str_ends_with`.
  `tests/Repo/Php74SyntaxTest.php` catches the token-level ones on any PHP; CI's 7.4 job
  has the final word.

## Working with the mock gateway by hand

The mock is part of the bundled client. With a local WordPress at `http://localhost:8080`
that has this plugin (run `composer install` in the plugin directory):

```bash
cd vendor/minos-moderation/client-php
export MINOS_MOCK_WEBHOOK_URL='http://localhost:8080/wp-json/minos/v1/webhook'
php -S 127.0.0.1:8100 -t mock-gateway/public   # terminal 1: the API
php mock-gateway/bin/worker.php                # terminal 2: the deliveries (same variable)
```

In Settings → Minos: gateway URL `http://127.0.0.1:8100`, key
`wgb2b_atrapa_minos_0000000000000000`, secret `atrapa-minos-sekret-webhooka-tylko-lokalnie`
(the mock's defaults), moderation on. Choose a verdict with markers in the comment:
`[minos:blokuj]`, `[minos:cenzuruj]` with `[[fragment]]`, `[minos:nieocenione]`,
`[minos:kategoria=samookaleczenie]`, `[minos:dwa-razy]`, `[minos:zly-podpis]`,
`[minos:cisza]` (the client's README lists them all). The mock keeps its queue as plain
JSON on disk: never send real comments to it.

## The client dependency

`composer.json` requires `minos-moderation/client-php` at `dev-main` from its GitHub
repository (a `vcs` repository entry); `composer.lock` pins the exact commit, so a build is
reproducible. Until the client's `v0.1.0` tag exists, the lock IS the pin (commit
`fc4beb7`); once it does, replace `dev-main` with `^0.1` and run
`composer update minos-moderation/client-php`. To move the pin before that, run the same
update after reviewing the client's change, and commit the lock. The plugin never forks
the client's verification.

`config.platform-check` is off: WordPress's `Requires PHP: 7.4` header already refuses
older hosts, and Composer's check would stop the whole site instead.

## Building the zip

```bash
bin/build-zip.sh            # → build/minos-moderation.zip
```

It copies the entry points, `src/`, `LICENSE`, `README.md` and the composer files into a
staging directory, runs `composer install --no-dev --classmap-authoritative`, removes
`composer.json` and `composer.lock` (the plugin directory is served, and they would publish
the exact versions), strips the client library down to its `src/` and `LICENSE` (its mock
gateway has a `public/index.php` that must never be reachable on a forum's server),
refuses to package any development or composer file, lints every PHP file, and zips
`minos-moderation/`.
