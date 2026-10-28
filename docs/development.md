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
| `src/Submission.php` | `pre_comment_approved` → hold; `comment_post` / `rest_insert_comment` → `POST /api/v1/b2b/oceny`; the answers, the shared pause and the retries in batches of up to 20. |
| `src/Receiver.php` | The REST webhook `minos/v1/webhook`: `404` while switched off; raw body, `Signature::verify`, `WebhookPayload::parse`, dedupe. |
| `src/Outcome.php` | Applies a verdict (on time or late) or the failure mode; verified masked writes; the held-back moderator e-mail. |
| `src/Sweeper.php` | WP-Cron every 5 minutes: the receive timeout and due retries (at most 5 requests a run). |
| `src/Retention.php` | WP-Cron hourly: the `_minos_*` meta of a comment settled 30 days ago goes (shared rule D9.11). |
| `src/Text.php` | Plain text for the gateway (strip, keep `title`/`alt` text, block tags as line breaks, decode, trim, cut at 3000), link domains, whether a masked text fits, and back to safe HTML. |
| `src/Upgrade.php` | Brings data an earlier version stored up to date, once per site (`minos_moderation_schema`). |
| `src/Log.php` | The administrator's error log: codes and statuses, never content or secrets; the shared pause. |
| `src/Stats.php` | The last 7 days on the settings page: comments per outcome, P50/P95 of the time from `202` to the verdict, from the meta the plugin keeps. |
| `src/Notice.php` | The privacy (RODO) notice under the comment form. |
| `src/Admin/` | Settings → Minos, the "Minos" comments column and the admin notices. |
| `src/Meta.php` | Every `_minos_*` comment meta key (all removed on uninstall). |
| `tests/stubs/wordpress.php` | Hand-written stubs of the WordPress functions the plugin calls. |
| `tests/Unit/` | The real handlers on the stubs. |
| `tests/EndToEnd/` | The plugin against the mock gateway over real HTTP. |
| `tests/Integration/` | The plugin on a real WordPress and its test library (`phpunit-integration.xml.dist`, installed by `bin/install-wp-tests.sh`), against the mock gateway. |
| `tests/Repo/` | The Claude Code rules (their checker also runs on `fixtures/agents/`, agents it must reject) and the PHP 7.4 syntax guard. |
| `bin/build-zip.sh` | Builds `build/minos-moderation.zip` with `vendor/` inside. |
| `.github/workflows/release.yml` | On a `v*` tag: builds the zip, proves it with `.github/scripts/check-release-archive.sh` and attaches it to the GitHub Release. |

## How a comment travels

1. `pre_comment_approved` (priority 999, after the site's rules and spam filters) holds a
   comment the plugin moderates (`0`) and remembers WordPress's own decision (`1` or `0`).
   Not moderated: users with `moderate_comments`, anything WordPress or another plugin set
   to `spam`/`trash` or refused with a `WP_Error`, comment types other than `comment`, and
   everything while the plugin is off or lacks the key or the secret. WordPress 6.7+ runs
   the filter twice per comment; the last run wins.
2. `comment_post` (priority 5, before WordPress's own notifications at 10) or, for the REST
   API which does not fire `comment_post`, `rest_insert_comment` marks it `oczekuje` and
   sends it synchronously (`wp_remote_post`, 10 s, no redirects), unless the shared pause
   runs (step 3). The body in the author's request is one item:
   `{"id":"wp:<ID>","tekst":…,"profil":…,"meta":{"links":…,"link_domains":[…],"author_first_post":…}}`
   (`wp:<ID>.<n>` for the n-th revision, see below)
   (`link_domains` only when the content has links: at most 10 registrable domains, found
   with a short list of second-level suffixes instead of the Public Suffix List). A plain
   text over 3000 characters is marked `_minos_cut`.
3. The answer (shared rule D9.6), for every comment of the request:
   - `202` → a comment whose item id is in `przyjete` is accepted; any other is retried
     after its own backoff, and the others are not held up.
   - `429` (any code), `408` (as no answer), `5xx`, a `2xx` other than `202` (something on
     the way answered for the gateway, which took nothing; shared rule F5a), even one with
     every item in `przyjete`, or no answer → the shared pause
     (`Log::pause`, option `minos_moderation_paused`): nothing is sent until the gateway's
     `ponow_za_s` or, without it, a doubling backoff from 60 s (at most an hour, counted
     from the most failed attempts in the request) ends, and never more than a day
     (`Log::MAX_PAUSE_S`): while nothing is sent no `202` can end it. Each comment of the
     request is retried then (a one-off WP-Cron event, plus the sweep as a fallback:
     WordPress refuses a second event for the same comment within ten minutes of one it
     still holds, so a retry moved later than its event is sent by the sweep), but
     gets the failure mode at once when `ponow_za_s` as given (clamped at a year only to
     stay an integer) ends at or after its receive timeout (shared rule D9.3(a)). A pause
     running longer is never shortened by the answer to an earlier request; it ends with
     the first `202` that takes a comment, or when the administrator changes the key, the
     secret, the gateway's address or the switch (`Submission::onSettingsSaved`,
     `onCredentialsSaved`).
   - a refusal of one item (`blad.element` with `brak_tekstu`, `limit_dlugosci`,
     `bledny_identyfikator` or `powtorzony_identyfikator`) → that comment gets the failure
     mode, and the others are sent again at once (the gateway took none of them, and such
     a refusal costs the key nothing).
   - anything else (a `3xx`, a `4xx` other than `408` and `429`) → a configuration error for
     every comment of the request: the failure mode, a log entry and an admin notice.

   While the pause runs, a new or edited comment is not sent in the author's or editor's
   request: it stays held with its retry due when the pause ends, or gets the failure mode
   at once when the gateway's own `ponow_za_s` ends at or after its timeout (the plugin's
   backoff never does that). A retry event sends its comment first, with the other due
   ones, up to 20 items a request (`Submission::MAX_ITEMS`); the sweep sends due retries
   in at most `Sweeper::MAX_REQUESTS` (5) HTTP requests, a request sent again after a
   refused item counted too (a retry event: one). Both stop at the first answer that asks
   to wait, and both find the due retries by the meta key `_minos_retry_at` alone (set
   until the `202` or the settlement), so comments that only wait for their verdict never
   crowd them out. Every pause is shown until T on every admin screen and the settings
   page: a daily limit (`limit_dobowy_klucza`) and an overload (`limit_globalny_b2b`,
   `kolejka_pelna`) with their own words, any other with its code.
4. The webhook applies the verdict to a comment still marked `oczekuje`, or to one the
   plugin published under fail-open (`_minos_auto_published`), when the verdict is for the
   comment's current revision (`_minos_revision`) and the comment still reads as the text
   that was sent (`_minos_sent_hash`); anything else gets `200` and nothing else, and a
   comment whose text changed is reopened as its next revision, which WP-Cron sends (the
   webhook never calls the gateway before it answers). The moderator's e-mail
   WordPress held back goes out from WP-Cron.
5. The sweep gives the failure mode to a comment that waited past the timeout (at least
   20 minutes), counted from the gateway's `202`, or from the hold while it was never
   accepted. It finds waiting comments by the meta key `_minos_waiting` alone, set while a
   comment waits and removed when it is settled, never by a meta value (`_minos_status`
   stays, and grows with every comment ever assessed), and the due retries by
   `_minos_retry_at` alone (step 3). Each window is the oldest `Sweeper::BATCH` (200), so
   the timeout is checked in both: under a larger backlog the oldest waiting comments may
   all be ones the gateway accepted late and still in time, while newer ones it never
   accepted are past their timeout; those get the failure mode from the retry window, in
   the same pass.

With `enabled` off the plugin does nothing: no hold, no submission, no retry, no sweep, and
the webhook answers `404`. Waiting comments stay in WordPress's queue for a person. Only the
moderator's e-mail of an outcome applied before and the 30-day retention still run.
Switched on without a usable key or secret (`Settings::usable`: what the settings form
accepts), the plugin holds every new comment and gives it the failure mode at once
(`brak_konfiguracji` in `_minos_error`), with an error notice on every admin screen (shared
rule D9.12; a fresh install starts switched off). Switching it on again
(`update_option_minos_moderation_settings`, `Submission::onSettingsSaved`) reopens every
waiting comment as its next revision with a fresh timeout and schedules its retry at once,
so WP-Cron sends them (shared rule D9.13; at most `RESUME_BATCH`); one a person decided
meanwhile is settled as `nieocenione` and left alone.

Decisions worth knowing before changing anything:

- **The plugin never publishes what WordPress itself would hold.** `_minos_wp_approved`
  records WordPress's decision; publishing (a verdict or fail-open) approves only when it
  is `1`.
- **A person's decision stands.** A comment that is no longer held when the verdict comes
  keeps its status; only the verdict is recorded.
- **Late verdicts.** Under fail-open the timeout publishes and sets
  `_minos_auto_published`; a verdict arriving later is applied (`zablokowane` → hold,
  `ocenzurowane` → per setting), except that a comment with `wsparcie` stays published
  (shared rule A7, below). `transition_comment_status` clears the flag, so a person's
  status change after the publication stands.
- **The support cue (shared rule A7).** `wsparcie` is recorded as `_minos_wsparcie` = `1`
  whatever the verdict, and a comment that carries it is never marked as spam nor taken
  off the page by the plugin: `zablokowane` holds it whatever `blocked_mode` says
  (`Outcome::action`), and a late verdict leaves a fail-open publication published
  (`Outcome::applyLate`; a fitting mask still replaces its content, one that does not
  waits in `_minos_masked`). The moderators see it in the "Minos" column, in the
  comments screen's notice (comments of the last 7 days) and, for a held comment, in the
  "awaiting moderation" e-mail (`comment_moderation_text`, `Outcome::filterModerationText`).
  Nothing goes to the comment's author.
- **Revisions.** A verdict is about the text that was sent. `Submission::onEdit`
  (`edit_comment`) sends a comment again as `wp:<ID>.<n>` (`_minos_revision`, absent = 0,
  fresh `_minos_held_at`) when its text changed while it waited for its verdict, or after
  the timeout left it unassessed (`_minos_timed_out`; a fail-open publication goes back to
  hold, as in the phpBB plugin). An edit of an assessed comment is the editor's decision
  and is not sent (reassessing those is a separate, postponed item). Before every verdict
  the receiver compares `_minos_sent_hash` with the current text; a mismatch (a write
  without `edit_comment`) reopens the next revision instead of applying the verdict, and a
  retry due at once sends it from WP-Cron (`Submission::reassessLater`). The
  plugin's own masked write is not an edit (`Outcome::writing`).
- **Block boundaries.** `Text::plain` turns block tags (`blockquote`, `p`, `div`, `li`,
  `ul`, `ol`, `pre`, `h1`–`h6`, `br`) into line breaks after the `title`/`alt` text is
  taken out, and drops inline tags without a trace: `<blockquote>Zgoda</blockquote>zakazane`
  is sent as two words, `zak<b>a</b>zane` as one. The changed text changes the hash of
  comments in flight during an update; their verdicts then send the next revision instead.
- **No text to assess is the failure mode** (shared rules D9.5, F7). A comment whose plain
  text is empty (a link with a blank text, an image without `alt`), or whose own text is
  (`Text::ownText`: no text but that of `title` and `alt` attributes, as an image with its
  `alt` or a blank link with a `title`; the gateway assesses no image, and a verdict on its
  `alt` would stand for the image), or holds nothing a reader sees (`Text::hasOwnText`:
  whitespace, separators, format characters such as a zero-width space, a soft hyphen, a
  word joiner or a byte order mark, marks with nothing to combine with such as a lone
  U+034F or U+FE0F (a letter keeps its accent: `e` + U+0301 is text), controls, the blank
  fillers; when that check cannot run, the comment is sent), is held and gets the failure
  mode at once, without a request, with `_minos_error` `brak_tekstu_do_oceny` (the plugin's
  own code, not the gateway's `brak_tekstu`) shown in the "Minos" column; under fail-open
  the publication is marked as one without an assessment.
- **The plain text never goes empty by accident.** An empty text gets the failure mode,
  so a regex in `Text` that gives up (a PCRE limit returns null) falls back to its input,
  and trimming is a byte scan over the 26 characters PCRE calls whitespace, not a regex
  (`\s+$` is quadratic over interior runs).
- **A cut comment is never published by a verdict.** The gateway assessed only its first
  3000 characters: `bezpieczne` gets the failure mode, `ocenzurowane` is held.
- **The masked text is plain text, and verified.** `Outcome` escapes it with
  `htmlspecialchars` and writes it only when the comment still reads exactly as the text
  that was sent (`_minos_sent_chars` and `_minos_sent_hash`), the stored content is plain
  text (`Text::isPlain`: its plain text is what a reader sees, so no markup, link or
  attribute text is lost), the masked text fits the sent one (`Text::maskFits`: the same
  number of characters, each the sent one or `█`), and WordPress itself would publish the
  comment (`_minos_wp_approved` is `1`). Every other `ocenzurowane` is held with the
  gateway's masked text in `_minos_masked`, shown in the "Minos" column. It counts only when
  `wp_update_comment` reports success AND a re-read finds exactly the masked HTML: wpdb
  refuses a value over the `text` column's 65,535 bytes (escaping can multiply the length
  by six, though a fitting mask of 3000 characters stays far below) and filters may change
  it. On a failure the original is put back if needed,
  `_minos_original` goes once the content is the original again, the comment stays held
  and `zapis_zamaskowanej_nieudany` is recorded (meta and log).
- **Row isolation.** Every step for one comment runs in `try { … } catch (\Throwable $e)`:
  the sweep (per comment), the retry event, the moderator's e-mail event, the webhook's
  verdict and the submission in the author's or editor's request. A failure (another
  plugin's hook throwing) is recorded by `Outcome::failed` as the step and the comment id
  (`wyjatek_<step>` in the log and in `_minos_error`), never the exception's message or
  trace. The final status is written after the action and `_minos_retry_at` is removed
  only after a `202`, so the comment keeps waiting: the next sweep repeats a time-out or a
  submission, and the webhook answers `500`, so the gateway's next delivery of the verdict
  is applied rather than dropped as a repeat. A masked text written before a hook threw is
  recognised on that redelivery (`_minos_original` is the text that was sent). In a
  request that carries several comments, one that fails while its item is built is left
  out of it; a request that fails on the way (another plugin's HTTP hook) leaves every
  comment it carried due, recorded once under its first comment
  (`Outcome::failedTogether`), and the next request of the pass goes on. The stubbed
  `get_comment` runs core's `get_comment` filter, so a test can make one comment fail.
- **Slashes.** `wp_update_comment` and `update_comment_meta` unslash their input, so
  `Platform` slashes it; the stubs unslash too, and a test holds a backslash.
- **Notifications.** A `notify_moderator` filter suppresses the "awaiting moderation" e-mail
  while a comment is `oczekuje`; when the outcome holds it, `wp_new_comment_notify_moderator`
  runs from WP-Cron, and its text carries the support cue when the comment has one. The
  post's author is mailed by core itself on approval (`wp_set_comment_status` hooks
  `wp_new_comment_notify_postauthor`), so the plugin never sends that one.
- **Retention (shared rule D9.11).** `Outcome::settle` records `_minos_decided_at`;
  `Retention` (hourly, `Retention::BATCH` a run, also while switched off) removes every
  `_minos_*` key of a comment settled more than 30 days ago — the original of a masked
  comment, the held mask, the verdict, the categories. Its query compares
  `_minos_decided_at` by value (`meta_compare` `<`), once an hour, over the comments of the
  last 30 days; a reopened revision loses the key until its own verdict. `Upgrade` step 3
  dates comments settled before the update from `_minos_held_at`.
- **Queries by meta value.** Only `Retention` (hourly), `Upgrade` (once per site) and the
  settings page's 7-day numbers (`Stats`, each time an administrator opens Settings →
  Minos; `_minos_decided_at` ≥ a week ago, at most 2000 comments, their meta primed in one
  query) ask by a meta value. What runs often asks by the meta key alone, which WordPress indexes: the
  sweep and the retry events (`_minos_waiting`, `_minos_retry_at`) and the support notice on the comments screen
  (every view, `_minos_wsparcie`, written only as `1`, with the comment date for the 7 days).
- **Not done:** translation files (the source strings are Polish, in `__()` with the
  `minos-moderation` domain, and `languages/` is loaded when present).

## WordPress APIs

Checked against the WordPress sources at the `6.0` and `7.1.2` tags
(`github.com/WordPress/WordPress`; `developer.wordpress.org` was not reachable from the
build environment), and run on a real WordPress 6.0 and the latest by the integration suite
(see Tests): `pre_comment_approved` (in `wp_allow_comment` / `wp_check_comment_data`,
`$commentdata` as the second argument), `comment_post` (`$comment_id, $comment_approved,
$commentdata`), `rest_insert_comment` (`$comment, $request, $creating`), the REST server
setting the request body from `php://input`, `WP_REST_Request::get_body()` and
`get_header()` (canonicalised names), `register_rest_route` on `rest_api_init`,
`WP_REST_Response`, `rest_url`, `wp_remote_post` / `wp_remote_retrieve_*` / `is_wp_error`,
`wp_schedule_single_event`, `wp_schedule_event`, `wp_next_scheduled`, `wp_unschedule_hook`,
the `cron_schedules` filter, `get_comment`, `get_comments` (`status => any`, meta, `count`,
`user_id`, `author_email`, `date_query`, `update_comment_meta_cache`), `get_comment_meta` / `update_comment_meta` /
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
`update_option_{$option}` (old and new value, only on a change), `pre_http_request` and the
`schedule_event` filter (run inside `wp_remote_post` / `wp_schedule_single_event`; another
plugin's callback may throw there), `get_comments` with `meta_compare` / `meta_type`,
`wp_new_comment_notify_moderator`, `comment_moderation_text` (the text and the comment id,
in `wp_notify_moderator`; once per e-mail on 6.0, once per recipient on 7.1),
`wp_new_comment_notify_postauthor`,
`load_plugin_textdomain`, `register_activation_hook`, `register_deactivation_hook`,
`WP_UNINSTALL_PLUGIN`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI (`.github/workflows/tests.yml`) runs `php -l` over the plugin, the suite and the zip
build on PHP 7.4 and 8.3, and the integration suite in its own job, "WordPress
integration", on WordPress 6.0 with PHP 7.4 and the latest WordPress with PHP 8.3. Every `uses:` in the workflows is pinned to a full commit SHA with
its version in a trailing comment (`owner/action@<40-hex sha>  # v5.0.0`); to move a pin,
resolve the new release tag to its commit (`git ls-remote https://github.com/<owner>/<action>
refs/tags/<tag>^{}` for an annotated tag, the tag itself otherwise), check that the commit
belongs to that release, and change the SHA and the comment together in every workflow. The suite needs `ext-curl` and `ext-mbstring` and starts PHP's
built-in server for the end-to-end test.

- **Unit** (`tests/Unit/`): `PluginTestCase` hooks the real `Plugin` into the stubs with a
  fixed clock. `WpStub::postComment()` runs the filter (twice, as WordPress 6.7+), the
  content filters, the insert and `comment_post` as `wp_new_comment` does; `deliver()` signs a payload with `Signature::sign` and calls the
  real receiver; `WpStub::answer()` queues the gateway's answers (by default a `202`
  naming every item of the request in `przyjete`); every HTTP request,
  status change, cron event and e-mail is recorded in `WpStub`.
- **End to end** (`tests/EndToEnd/`): the mock gateway from
  `vendor/minos-moderation/client-php/mock-gateway` on one built-in server, a stand-in site
  (`fixtures/site.php`) running the real receiver on another, the comments posted through
  the plugin's hooks with `wp_remote_post` sending for real, the mock's worker delivering.
  Servers start through array-form `proc_open`, and `tearDown` asserts every port closed.
- **Stubs**: a new WordPress call goes through `Platform` and gets a stub that keeps
  WordPress's observable behaviour (slashing, status words, header names), and a test in
  `tests/Integration/` that runs it on the real WordPress. What the real WordPress showed
  the stubs got wrong is fixed there: `pre_comment_approved` runs twice from 6.7 on (once
  before 6.7), `wp_schedule_single_event` refuses an event with the same action and
  arguments within ten minutes of one it holds, `wp_update_comment` answers `0` when the
  comment already read so. kses is not stubbed: a test posts content as WordPress would
  store it.
- **Integration** (`tests/Integration/`, `phpunit-integration.xml.dist`): the plugin on a
  real WordPress, loaded by its main file, with WordPress's own PHPUnit test library
  (`WP_UnitTestCase`, `yoast/phpunit-polyfills`) and a MySQL database whose changes each
  test rolls back. Comments come in through the comment form's handler
  (`wp_handle_comment_submission`) or the REST API, verdicts through WordPress's own REST
  server: the mock gateway's worker delivers to a capture server
  (`fixtures/capture.php`), and the test serves each delivery byte for byte where its
  database transaction is. The plugin's clock is the real one, so a test that needs time
  to pass moves the moments the plugin stored back. One test at least for each of: the
  double `pre_comment_approved`, kses and the stored `comment_content` with the column's
  limit, `wp_set_comment_status` / `wp_update_comment` / `transition_comment_status` and
  the e-mails sent in the webhook's request, the REST route with the raw body, WP-Cron's
  duplicate refusal, `get_comments` with meta and date queries, and uninstalling. To run
  it, with a MySQL server and a user that may create the database:

  ```bash
  bin/install-wp-tests.sh wordpress_test <db-user> <db-password> 127.0.0.1:3306 6.0   # or: latest
  vendor/bin/phpunit -c phpunit-integration.xml.dist
  ```

  The script takes WordPress from the release tag of its Git mirror
  (`github.com/WordPress/WordPress`) and the test library from the release's tag of
  `github.com/WordPress/wordpress-develop`, each fetched by its full tag ref, never a
  branch: the mirror names the first release of a branch `6.0`, wordpress-develop `6.0.0`
  (its `6.0` is the moving 6.0 branch), so `6.0` here means the release 6.0 for both,
  unlike the standard script, which reads it as the 6.0 branch's newest test library. A
  release missing from either repository is refused, and the resolved tags and commits
  are printed. It installs into `WP_CORE_DIR` and `WP_TESTS_DIR` (by default
  `/tmp/wordpress` and `/tmp/wordpress-tests-lib`), each missing or empty: it removes no
  directory but its own staging ones, refuses one it did not install that holds anything,
  and reuses its own only for the same release and test configuration (another release
  or database needs both directories removed first). The plain `vendor/bin/phpunit` never needs MySQL.
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
reproducible. The lock IS the pin: commit `fc4beb7` of the client's `main`, from before
the client's first release. The client has no release tag yet; its first two are to stand
at `7d58a76` (`v0.1.0`) and `e5df7d6` (`v0.2.0`, which only adds to 0.1.0). The plugin
moves to them in a pull request of its own: replace `dev-main` with `^0.2`, run
`composer update minos-moderation/client-php` and commit the lock. Until then, move the
pin only with the same update at a reviewed commit of the client's `main`. The plugin
never forks the client's verification.

`config.platform-check` is off: WordPress's `Requires PHP: 7.4` header already refuses
older hosts, and Composer's check would stop the whole site instead.

## Building the zip

```bash
bin/build-zip.sh            # → build/minos-moderation.zip
```

It copies the entry points, `src/`, `LICENSE`, `README.md` and the composer files into a
staging directory, runs `composer install --no-dev --classmap-authoritative`, removes
`composer.json`, `composer.lock` and `vendor/composer/installed.json` (static files, readable
as text where the plugin directory is served, that list the exact versions; nothing reads
them at run time), keeps `vendor/composer/installed.php` (PHP code returning an array, which
`Composer\InstalledVersions` reads when asked for the installed versions; requested over
HTTP it prints nothing, and it names only the commit of the public, MIT-licensed client
library), strips the client library down to its `src/` and `LICENSE` (its mock
gateway has a `public/index.php` that must never be reachable on a forum's server),
refuses to package any development or composer file, lints every PHP file, and zips
`minos-moderation/`.

## Releasing

The version lives in ONE place the release workflow checks: the `Version:` header of `minos-moderation.php`. The repository
keeps no changelog; the release notes are GitHub's generated ones. The workflow, not a
person, creates the release, and only after its checks pass.

1. Bump `Version:` in `minos-moderation.php` in a pull request, and merge it. On every pull request the `release-archive` job
   of `tests.yml` already runs the release build and checks against the declared version.
2. The dry run on `main`: Actions → Release → "Run workflow", branch `main`, the tag to
   be (`v0.2.0`). It builds and checks exactly as a tag push does and keeps the zip
   as the run's `release-archive` artifact (7 days); see it green.
3. The owner creates and pushes the TAG ONLY, on the commit the dry run checked, from a
   local clone: `git tag -a v0.2.0 -m v0.2.0 <commit>` and `git push origin v0.2.0`.
   Not GitHub's "Draft a new release" form: a tag created there is published together
   with its release, before any check. Tag pushes from Claude Code sessions are refused.
4. The tag's push starts `.github/workflows/release.yml`, which builds the zip with `bin/build-zip.sh` on PHP 7.4 and lists it,
   failing on any hit: `composer.lock`, `tests/`, `phpunit*`, the mock gateway, `.git*`, `CLAUDE.md`, `.claude/`, `.github/`, Composer's `installed.json`; it also fails unless `minos-moderation/LICENSE` is inside and not
   blank, and the zip's `Version:` header equals the tag without the `v` (the message names both). Only
   then the `publish` job creates the release with `minos-moderation.zip` attached (`--verify-tag`,
   generated notes, a pre-release for a tag with `-`). gh creates it as a draft, uploads,
   then publishes, so a failed upload leaves a draft, never a release without its asset.

When a release for the tag already exists at that point, `publish` fails and attaches
nothing: such a release was published unchecked. Delete it (keep the tag) and re-run the
failed jobs. Nothing replaces an asset (`--clobber` is never used), so a second upload
fails loudly. A failed check publishes nothing: delete the tag, fix `main`, start again
from step 1. By hand: `bin/build-zip.sh && .github/scripts/check-release-archive.sh build/minos-moderation.zip v0.1.0`.

Nothing in this repository enforces that only the owner tags (the session refusal lives
outside GitHub): the owner should add a tag ruleset on `v*` that only they may bypass, or
a `release` environment with a required reviewer on the `publish` job.
