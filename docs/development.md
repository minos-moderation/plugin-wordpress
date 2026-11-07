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
| `src/Retention.php` | WP-Cron hourly: the `_minos_*` meta of a comment settled 30 days ago goes (shared rule A8). |
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
   with a short list of second-level suffixes instead of the Public Suffix List). A link is
   a link's `href` or an address in the text (also in `title`/`alt` text, which is sent);
   an image's `src` or `srcset` and a quote's `cite` are not (shared rules F7, S2). A plain
   text over 3000 characters is marked `_minos_cut`.
3. The answer (shared rules F5a, F5b), for every comment of the request:
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
     stay an integer) ends at or after its receive timeout (shared rule F5c). A pause
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
rule F8; a fresh install starts switched off). Switching it on again
(`update_option_minos_moderation_settings`, `Submission::onSettingsSaved`) reopens every
waiting comment as its next revision with a fresh timeout and schedules its retry at once,
so WP-Cron sends them (shared rule F9; at most `RESUME_BATCH`); one a person decided
meanwhile is settled as `nieocenione` and left alone.

Decisions worth knowing before changing anything:

- **The plugin never publishes what WordPress itself would hold.** `_minos_wp_approved`
  records WordPress's decision; publishing (a verdict or fail-open) approves only when it
  is `1`.
- **A person's decision stands.** A comment that is no longer held when the verdict comes
  keeps its status; only the verdict is recorded, and its `wsparcie` as the support cue
  noted as one after the decision (below).
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
  Nothing about it goes to the comment's author. WordPress mails that e-mail to the post's
  author too when they may edit the comment, so `comment_moderation_recipients`
  (`Outcome::filterModerationRecipients`, at `PHP_INT_MAX` so no later filter puts the
  author back) takes the comment's address and its account's current one
  (`get_userdata`) off the recipients, compared without case. A guest who types the
  site's address (`admin_email`, in any letter case) as their own takes it off the
  recipients themselves; when nobody is left (such a comment under a post by the
  administrator, whom WordPress mails at that address only), no e-mail goes out, and the
  comment stays held with the cue in the column (`StatusAndMailTest`). The cue goes
  only into an e-mail whose recipients that filter checked in the same request, so a
  replaced `wp_notify_moderator` that skips the filter sends WordPress's text alone.
  The cue is a signal whatever happened to the comment (the owner's decision of
  2026-11-01): a verdict the plugin no longer applies — a person decided (approved, held,
  spammed, trashed or edited the comment), its text changed since it was sent (an older
  revision, a write without `edit_comment`), or the failure mode settled it after the
  timeout — still records its `wsparcie` (`Outcome::supportAfterDecision`, from
  `Receiver::handle`): `_minos_wsparcie` and `_minos_wsparcie_po_decyzji` = `1`, the note
  "after the moderator's decision" in the column and the e-mail, and for a comment that is
  held and no longer waits the moderator's e-mail from WP-Cron. Nothing else of that
  verdict applies (status, content, categories). Once only: a comment that already carries
  the cue gets nothing more, so a repeated delivery adds no second e-mail; the cue is
  written last, so a hook that throws first answers `500` and the redelivery records it.
  A comment that carries the cue from an earlier revision's verdict is treated as one with
  the cue by every later verdict (never spam, never unpublished).
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
- **No text to assess is the failure mode** (shared rule F7). A comment whose plain
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
- **A text read in part is never sent** (shared rules F7, S1). `Text::extract` answers
  null when the content is not valid UTF-8 or one of its regexes gives up (a PCRE limit
  returns null): the comment is held and gets the failure mode at once, without a request,
  with `_minos_error` `tekst_nieodczytany` shown in the "Minos" column, because a step
  skipped would send what a reader never sees (a `script`'s content) or less than they see
  (attribute text dropped, two words joined at a block boundary). Trimming is a byte scan
  over the 26 characters PCRE calls whitespace, not a regex (`\s+$` is quadratic over
  interior runs), so a long run of spaces never makes a text unreadable. What is asked
  only after the text was read whole still reads more when its regex gives up, never
  less: `Text::ownText` and `hasOwnText` count the comment as having text, and the link
  count and domains (`Text::links`) read the whole markup.
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
  A meta write the database refuses is such a failure too: `Platform::setMeta` throws when
  `update_comment_meta` stored nothing (wpdb answers `false` and throws nothing; the same
  `false` means an unchanged value, so the value is read back first). Where no step
  isolates it, in a person's request (the author's `comment_post`, an `edit_comment`, a
  `transition_comment_status`) and in `Upgrade` on `init`, it is caught there: the comment
  stays held, and an upgrade runs again on the next request. A comment whose marks could not
  all be written in the author's request loses them (`Submission::forget`): left with
  `oczekuje` but without `_minos_waiting`, the sweep would never find it, and WordPress's
  e-mail to the moderator would stay held back; without them a person decides on it. A
  person's hold whose settlement the database refuses (`Outcome::onStatusChange`) drops
  `_minos_status`, `_minos_waiting` and `_minos_retry_at` instead, so neither a redelivery
  nor the sweep's timeout under fail-open publishes the comment over that hold. The stubbed
  `update_comment_meta` runs core's `update_comment_metadata` short-circuit, so a test can
  refuse one write.
- **Slashes.** `wp_update_comment` and `update_comment_meta` unslash their input, so
  `Platform` slashes it; the stubs unslash too, and a test holds a backslash.
- **Notifications.** A `notify_moderator` filter suppresses the "awaiting moderation" e-mail
  while a comment is `oczekuje`; when the outcome holds it, `wp_new_comment_notify_moderator`
  runs from WP-Cron, and its text carries the support cue when the comment has one. The
  post's author is mailed by core itself on approval (`wp_set_comment_status` hooks
  `wp_new_comment_notify_postauthor`), so the plugin never sends that one.
- **Retention (shared rule A8).** `Outcome::settle` records `_minos_decided_at`;
  `Retention` (hourly, `Retention::BATCH` a run, also while switched off, but not while the
  plugin is deactivated: deactivation unschedules its hook, activation schedules it again,
  and the README says so for the forum's privacy notice) removes every
  `_minos_*` key of a comment settled more than 30 days ago — the original of a masked
  comment, the held mask, the verdict, the categories. Its query compares
  `_minos_decided_at` by value (`meta_compare` `<`), once an hour, over the comments of the
  last 30 days; a reopened revision loses the key until its own verdict. `Upgrade` step 3
  dates comments settled before the update from `_minos_held_at`.
- **Queries by meta value.** Only `Retention` (hourly), `Upgrade` (once per site) and the
  settings page's 7-day numbers (`Stats`, each time an administrator opens Settings → Minos;
  `_minos_decided_at` ≥ a week ago, at most 2000 comments, their meta primed in one query)
  ask by a meta value. What runs often asks by the meta key alone, which WordPress indexes:
  the sweep and the retry events (`_minos_waiting`, `_minos_retry_at`) and the support
  notice on the comments screen (every view, `_minos_wsparcie`, written only as `1`, with
  the comment date for the 7 days).
- **Not done:** translation files (the source strings are Polish, in `__()` with the
  `minos-moderation` domain, and `languages/` is loaded when present). Shared rule F5d
  (sending again a comment the failure mode published because the gateway refused it,
  once the limit renews): not implemented, an optional rule (off by default where a
  plugin has it), so not a deviation.

## The shared rules

The rules every Minos plugin shares are client-php's `docs/plugin-behaviour.md`; the
entries above say how this plugin carries them out. This table maps every identifier to
this plugin's mechanism and the tests that prove it, to `odstępstwo: …` with the issue
that closes it, or, for the optional F5d, to `nie wdrożono, reguła opcjonalna` (D1). It
follows the rules as client-php's tag `v0.2.1` states them, F10 and the 2026-11-05 wording
included; the lock is at that tag.

| Id | In short | WordPress: mechanism, or deviation |
|---|---|---|
| `F1` | fail-closed by default | `Settings::defaults` (`failure_mode` fail-closed); `nieocenione`, the receive timeout and a verdict the plugin cannot apply go to `Outcome::withoutVerdict` / `failureMode`, never a guess (`SettingsTest::testTheDefaults`, `ReceiverTest::testAnUnassessedCommentFollowsTheFailureMode`, `testAnUnknownQualificationIsNeverReadAsAVerdict`, `SweeperTest::testFailClosedLeavesATimedOutCommentHeld`) |
| `F2` | a time-out of at least 20 minutes | `Settings::normalise` keeps `timeout_min` within `MIN_TIMEOUT_MIN` (20) and `MAX_TIMEOUT_MIN` (1440), counted from the gateway's `202` (`SettingsTest::testTheTimeoutIsNeverShorterThanTheGatewaysDeliveryWindowWithGrace`, `SweeperTest::testTheTimeoutCountsFromTheGatewaysAcceptance`) |
| `F3` | switched off: 404, and nothing happens | `Receiver::handle` answers `404` before reading anything; `holdForAssessment`, `submit`, `sendDue` and `Sweeper::run` do nothing; waiting comments stay in WordPress's queue (`SwitchedOffTest::testTheWebhookAnswers404AndChangesNothing`, `testNoRetryAndNoSweepRun`, `testNewCommentsAreLeftAsWordPressDecidedAndTheModeratorIsNotSilenced`, `WebhookRouteTest::testTheRouteTakesOnlyPostAndAnswers404WhileSwitchedOff`). Only the 30-day retention (A8) and an e-mail of an outcome applied before still run |
| `F4` | 401 without a usable secret | `Receiver::handle` checks the stored secret with `Settings::validSecret` (16–256 visible ASCII characters, as the form) before `Signature::verify` (`ReceiverTest::testWithoutAConfiguredSecretEveryDeliveryIs401`, `testASecretWrittenStraightIntoTheOptionIsCheckedAsTheSettingsCheckIt`) |
| `F5a` | retry 429, 5xx, 408, network failures and a 2xx that accepts nothing; only `202` accepts | `Submission::handleAnswer` / `waitForGateway` and the shared pause (`Log::pause`); an item missing from `przyjete` is retried alone (`PauseAndBatchTest::testEveryAnswerThatAsksToWaitHoldsTheNextCommentsForWpCron`, `testA408IsRetriedLikeNoAnswer`, `testAnother2xxIsRetriedAndOnlyThe202AfterItAccepts`, `testACommentTheGatewayDidNotTakeIsRetriedAndTheOthersWait`) |
| `F5b` | any other 4xx is a configuration error | `Submission::handleAnswer`: the failure mode, a log entry with the code only and an admin notice (`SubmissionTest::testAConfigurationErrorAppliesTheFailureModeAndTellsTheAdministrator`, `testNeitherTheKeyNorTheSecretNorTheContentReachesTheLog`, `PauseAndBatchTest::testARefusalOfTheKeysSetupGivesEveryCommentTheFailureModeAtOnce`) |
| `F5c` | a `ponow_za_s` past the time-out: the failure mode at once | `Submission::wait` compares the gateway's `ponow_za_s` as given with the comment's deadline (`SubmissionTest::testADailyLimitTellsTheAdministratorUntilWhenAndAppliesTheFailureModeAtOnce`, `testTheGatewaysPauseIsComparedAsGivenNotAsTheRetryIsCapped`, `PauseAndBatchTest::testThePluginsOwnBackoffNeverGivesTheFailureModeAtOnce`) |
| `F5d` | optional: re-send what fail-open published unassessed once the limit renews | nie wdrożono, reguła opcjonalna |
| `F6` | a failing row never stops a loop | every step for one comment in `try`, recorded by `Outcome::failed` as the step and the comment id, never the message ("Row isolation" above; `FailureIsolationTest::testATimeoutThatFailsLeavesTheOtherTimeoutsToTheSamePassAndRunsAgain`, `testACommentThatFailsWhileItsRequestIsBuiltIsLeftOutAndTheOthersGo`, `testAModeratorEmailThatFailsDoesNotStopTheOthers`, `testNoExceptionMessageOrTraceIsReadInThePluginsCode`) |
| `F7` | no text to assess, or a failed extraction: the failure mode; a bare URL the reader sees, and visible punctuation, are text | `Submission::assessable`: a text `Text::extract` cannot read whole gets `tekst_nieodczytany`, an empty plain text or one with no own text (`Text::hasOwnText`: an image with or without `alt`, a blank link, invisible characters) `brak_tekstu_do_oceny`; the failure mode at once, no request (`SubmissionTest::testACommentWithNoTextToAssessGetsTheFailureModeAtOnceAndSaysWhy`, `TextTest::testATextReadOnlyInPartGetsTheFailureModeAndNothingIsSent`, `testVisiblePunctuationAndABareAddressAloneAreText`, `ContentTest::testAnInvisibleCharacterBesideAttributeTextIsNoTextToAssess`). A comment has no title of its own, so the title clause does not apply |
| `F8` | switched on without a usable configuration: the failure mode; a fresh install is off | `Settings::defaults` (`enabled` off), `Settings::usable`; `Submission::assessable` gives `brak_konfiguracji`, and every admin screen says why; an unusable gateway address reads as the default, so it never blocks (`SubmissionTest::testSwitchedOnWithoutAUsableKeyOrSecretEveryNewCommentGetsTheFailureMode`, `SettingsTest::testASwitchedOnPluginWithoutAUsableSecretSaysWhyOnEveryAdminScreen`) |
| `F9` | switched on again: what waited is re-sent with a fresh time-out | `Submission::onSettingsSaved` reopens every waiting comment as its next revision with a fresh `_minos_held_at` and sends it from WP-Cron (`SwitchedOffTest::testSwitchingOnAgainSendsWhatWasWaitingWithAFreshTimeout`) |
| `F10` | the receiver answers `2xx` only for a stored verdict; a platform page in its place must be a non-`2xx` | `Receiver::handle` answers `200` only once the verdict is applied and stored, or once there is nothing to store. Applying it (`Receiver::apply`) and recording a late support cue (`Receiver::supportOnly` → `Outcome::supportAfterDecision`) run in `try`: an exception (another plugin's hook) and a meta write the database refused (`Platform::setMeta` throws when `update_comment_meta` stored nothing, an unchanged value excepted) answer `500`, and the gateway's next delivery stores the verdict (`FailureIsolationTest::testAWebhookThatFailsAnswers500AndTheRedeliveryIsApplied`, `testAVerdictWhoseStatusIsNotStoredAnswers500AndTheRedeliveryStoresIt`, `testALateSupportCueThatIsNotStoredAnswers500AndTheRedeliveryStoresIt`, `testALateSupportCueThatFailsAnswers500AndTheRedeliveryRecordsIt`; on the real WordPress `WebhookRouteTest::testAVerdictWhoseStatusIsNotStoredAnswers500AndTheRedeliveryStoresIt`). A repeat, another revision or an unknown comment answers `200` with nothing to store (`ReceiverTest::testARepeatedDeliveryIs200AndChangesNothing`, `testAnUnknownIdIs200AndChangesNothing`, `RevisionTest::testAVerdictForAnotherRevisionIs200AndChangesNothing`). The platform's page: WordPress's maintenance mode answers before any plugin loads, with `503` (`tests/Integration/MaintenanceTest.php`); a site's own `wp-content/maintenance.php` sends its own code, and the README tells the administrator it must answer `503` |
| `S1` | `tekst` is what the reader sees, `title`/`alt` included; nothing hidden (an `href`, an image's address) is added | `Text::extract`: tags and `script`/`style` content removed, `title`/`alt` text in its place, block tags as line breaks, entities decoded; a link's `href` is never added (`SubmissionTest::testTheTextOfTitleAndAltAttributesIsAssessed`, `TextTest::testABlockBoundarySeparatesWordsAndAnInlineTagJoinsNone`, `testAnAttributeRightAfterAQuotedValueIsStillRead`, `ContentTest::testWhatIsSentIsTheTextOfTheContentKsesLeft`). A quote is a `blockquote` whose text is sent like any other |
| `S2` | links, domains, first post; never account data; an image's address is not a link | `Submission::item`: `links`, `link_domains` (`Text::linkDomains`, at most 10), `author_first_post`; the e-mail serves only the local first-comment query (`SubmissionTest::testNothingAboutTheAuthorTravels`, `testLinkDomainsAreRegistrableDomainsFromTheContentOnly`, `testAnImagesAddressIsNotALink`, `ContentTest::testAnImagesAddressIsNotSentAsALink`, `QueryTest::testTheFirstCommentSignalCountsTheAuthorsApprovedComments`) |
| `S3` | an edit of a waiting post is `<id>.<n>`; an older revision's verdict is ignored | `Submission::onEdit` / `reassess` (`wp:<ID>.<n>`, `_minos_revision`), also after a time-out; `Outcome::awaitsVerdict` answers `200` without applying another revision's verdict (`RevisionTest::testAnEditWhileWaitingIsSentAsTheNextRevisionAndTheOldVerdictChangesNothing`, `testAnEditAfterAFailClosedTimeoutIsSentAgain`, `testAnEditAfterAFailOpenPublicationHoldsItWhileTheNewTextIsAssessed`, `testAVerdictForAnotherRevisionIs200AndChangesNothing`, `StatusAndMailTest::testAnEditWithWpUpdateCommentSendsTheNextRevisionAndTheOldVerdictChangesNothing`) |
| `S4a` | above 3000 characters: the beginning is assessed, the decision is `nieocenione` | `Text::cut`, `_minos_cut`; `Outcome::action` turns `bezpieczne` on a cut comment into the failure mode (`SubmissionTest::testALongCommentIsMarkedAsCut`, `ReceiverTest::testASafeCutCommentGetsTheFailureMode`, `testABlockedCutCommentIsBlocked`) |
| `S4b` | the note about a cut post | the "Minos" column says "wpis dłuższy niż 3000 znaków — oceniono początek"; under fail-open the publication is marked (`ReceiverTest::testASafeCutCommentGetsTheFailureMode`) |
| `S4c` | `ocenzurowane` on a cut post is never applied automatically | `Outcome::action` holds it (`ReceiverTest::testACensoredCutCommentIsHeldWithItsOriginal`) |
| `S5` | a topic's title (a visible reply subject, a poll) is part of `tekst`; a title is never changed | does not apply: a WordPress comment has no title, subject or poll of its own (the post's title is not part of it and is never sent), and the plugin changes no title |
| `S6` | an edit of a published post is reassessed (after the capacity decision) | waits for the gateway's capacity decision, as the rule says: an edit of an assessed comment is not sent (`RevisionTest::testAnEditOfAnAssessedCommentIsTheEditorsDecision`); only one that was never assessed is (S3) |
| `S7` | a post the platform holds is sent and annotated (after the capacity decision) | a comment WordPress itself holds is sent (`_minos_wp_approved` = `0`), its verdict shown in the "Minos" column and never publishing it; `zablokowane` may mark it as spam per `blocked_mode` (`SubmissionTest::testACommentWordPressItselfHeldIsRecordedAsSuch`, `ReceiverTest::testNoVerdictPublishesACommentWordPressItselfHeld`, `testAMaskForACommentWordPressItselfHoldsLeavesItsContent`, `CommentHoldTest::testManualApprovalKeepsACommentHeldAfterASafeVerdict`) |
| `A1` | the digest of the sent text is checked before applying | `_minos_sent_hash` of `Text::cut(Text::plain(...))`, the same normalisation at sending and before every verdict (`Outcome::readsAsSent`); a mismatch reopens the next revision (`RevisionTest::testAVerdictForATextTheCommentNoLongerHasIsNeverApplied`, `LateVerdictTest::testTheSupportCueOfATextChangedOnTheQuietIsRecorded`) |
| `A2a` | a mask has the sent length and differs only in `█` | `Text::maskFits` (`TextTest::testAMaskFitsOnlyAsTheSentTextWithCharactersTurnedIntoBlocks`, `ReceiverTest::testAMaskThatDoesNotFitTheSentTextIsHeldWithTheMaskKept`) |
| `A2b` | what was sent is what is stored | `Outcome::replaceWithMasked` checks `_minos_sent_chars`, `_minos_sent_hash` and `Text::isPlain` (`ReceiverTest::testAMaskedTextForAnEditedCommentIsNotPublished`, `testACensoredCommentWithMarkupIsHeldWithTheMaskInTheColumn`) |
| `A2c` | the write is checked and read back | `wp_update_comment`'s answer and a re-read of exactly the masked HTML (`ReceiverTest::testAnUpdateAFilterRefusesKeepsTheCommentHeld`, `testAMaskedTextStoredDifferentlyIsNotPublishedAndTheOriginalComesBack`, `ContentTest::testAMaskedWriteTheDatabaseRefusesLeavesTheOriginalHeld`) |
| `A2d` | the mask is shown to the moderator | `Outcome::keepMask` (`_minos_masked`) in the "Minos" column (`ReceiverTest::testAHeldCensoredCommentKeepsItsMaskForTheModerator`) |
| `A2e` | never the original instead of a failed mask | a failed mask holds the comment; the original goes back only in place of the mask (`ReceiverTest::testElevenThousandApostrophesAreNeverPublishedInTheOriginal`, `testAMaskTooLongToStoreIsNeverWritten`, `testTheOriginalIsKeptWhenItCannotBePutBack`); a published comment with `wsparcie` stays as it is (A7) |
| `A3` | fail-open is marked; a late verdict applies | `_minos_auto_published`, `Outcome::applyLate`; a person's status change clears the mark (`Outcome::onStatusChange`) (`LateVerdictTest::testALateBlockedVerdictHoldsTheComment`, `testALateCensoredVerdictFollowsTheSetting`, `testAPersonsDecisionAfterThePublicationStands`, `StatusAndMailTest::testALateVerdictHoldsAFailOpenPublicationNobodyTouched`) |
| `A4` | notifications once, sent by the plugin where approval sends none | `notify_moderator` holds WordPress's e-mail back while a comment waits; a hold sends it from WP-Cron; an approval mails the post's author from core, once (`ReceiverTest::testEachEmailGoesOutOnce`, `testAHeldCommentApprovedBeforeTheCronRunsMailsTheAuthorOnce`, `SubmissionTest::testTheModeratorsEmailWaitsForTheVerdict`, `StatusAndMailTest::testASafeVerdictPublishesAndCoreMailsThePostsAuthorInsideTheWebhookRequest`) |
| `A5` | a hold is the platform's own state | `pre_comment_approved` → `0`, WordPress's moderation queue; no copy of the comment (`CommentHoldTest::testTheDecisionOfTheLastRunIsWordPressesOwnAndAVerdictNeverPublishesPastIt`, `SubmissionTest::testANewCommentIsHeldAndSentToTheGatewayOnce`) |
| `A6` | a late verdict also applies to a post the failure mode held | odstępstwo: under fail-closed the receive timeout settles the comment as `nieocenione` (`Outcome::timedOut`), and `Outcome::awaitsVerdict` accepts only a waiting comment or a fail-open publication, so a late verdict for a comment the failure mode held records only its `wsparcie` (A7) and the comment waits for a person (`LateVerdictTest::testAFailClosedTimeoutIsNotReopenedByALateVerdict`); the phpBB and MyBB plugins apply it as if it came in time. The safe direction: nothing is published without a verdict or a person. Kept for 0.1.0, minos-moderation/minos#136 (the fix: `awaitsVerdict` also for `nieocenione` with `_minos_timed_out` on a comment of the current revision still held, the on-time path for it, and no second moderator's e-mail for a late `nieocenione`) |
| `A7` | `wsparcie` is an active signal for moderators, also after a person's decision, never an automatic removal; a mask is still published | "The support cue" above: the "Minos" column and the comments screen's notice, the moderator's e-mail for a held comment, never spam or unpublished by the plugin, recorded after a decision too (`ReceiverTest::testABlockedCommentWithTheSupportCueIsHeldNeverMarkedAsSpam`, `testTheModeratorsEmailWithTheSupportCueNeverGoesToTheCommentsAuthor`, `LateVerdictTest::testALateVerdictWithTheSupportCueLeavesThePublicationInPlace`, `testALateSupportCueAfterAModeratorsApprovalIsASignalAndNothingElse`, `testTheSupportCueOfAnEarlierRevisionKeepsALaterFailOpenPublicationPublished`, `EndToEndTest::testALateSupportCueAfterAModeratorsApprovalIsRecordedOnceOverHttp`) |
| `A8` | the plugin's own data (the original, the verdicts) is kept 30 days; the platform's records follow the forum's rules | `Retention`, hourly, also while switched off, 30 days after `_minos_decided_at`; the README names what stays (the comment, its masked version) and that deactivation pauses it (`RetentionTest::testTheDataOfASettledCommentGoesThirtyDaysAfterItWasSettled`, `testItRunsWhileModerationIsOffAndAtMostABatchARun`, `QueryTest::testRetentionComparesTheSettlementMomentAsANumber`) |
| `P1` | the archive carries no lock file, tests or mock, and pins the client | `bin/build-zip.sh` and `.github/scripts/check-release-archive.sh`, run by the `release-archive` job of `tests.yml` on every pull request and by `release.yml`; `composer.lock` pins the client's tag `v0.2.1` ("The client dependency") |
| `D1` | this file is the one source; `CLAUDE.md` points at it, `docs/development.md` maps it | `CLAUDE.md` (one line), this table; `tests/Repo/ClaudeRulesTest.php` |
| `D2` | shared compliance scenarios in `scenarios/` | odstępstwo: client-php has no scenarios yet (`v0.2.1` ships none, nor does `main`), so the harness has none to run; minos-moderation/minos#44, step 5 |

## WordPress APIs

Checked against the WordPress sources at the `6.0` and `7.1.2` tags
(`github.com/WordPress/WordPress`; `developer.wordpress.org` was not reachable from the
build environment), and run on a real WordPress 6.0 and the latest by the integration suite
(see Tests): `pre_comment_approved` (in `wp_allow_comment` / `wp_check_comment_data`,
`$commentdata` as the second argument), `comment_post`
(`$comment_id, $comment_approved, $commentdata`), `rest_insert_comment`
(`$comment, $request, $creating`), the REST server setting the request body from
`php://input`, `WP_REST_Request::get_body()` and `get_header()` (canonicalised names),
`register_rest_route` on `rest_api_init`, `WP_REST_Response`, `rest_url`, `wp_remote_post` /
`wp_remote_retrieve_*` / `is_wp_error`, `wp_schedule_single_event`, `wp_schedule_event`,
`wp_next_scheduled`, `wp_unschedule_hook`, the `cron_schedules` filter, `get_comment`,
`get_comments` (`status => any`, meta, `count`, `user_id`, `author_email`, `date_query`,
`update_comment_meta_cache`), `get_comment_meta` / `update_comment_meta` /
`delete_comment_meta` / `delete_metadata` (`update_comment_meta` answers `false` both for a
refused write and for an unchanged value, after its `update_comment_metadata`
short-circuit), `wp_set_comment_status` (false when nothing changed; approval mails the
post's author), `transition_comment_status`, `wp_update_comment` (unslashes; returns
`1`/`0`, or `false`/`WP_Error`; runs `pre_comment_content`, `comment_save_pre` and
`wp_update_comment_data`, then `edit_comment`), wpdb refusing a value longer than its column
(`text`: 65,535 bytes), `wp_slash`, `user_can`, `current_user_can`, `get_userdata` (false
for no user), `get_option` / `update_option` / `add_option` (autoload `false`) /
`delete_option`, `register_setting` (`type`, `sanitize_callback`, `default`),
`add_options_page`, `add_settings_section`, `add_settings_field`, `add_settings_error`,
`settings_fields`, `do_settings_sections`, `submit_button`, `checked`, `selected`, `esc_*`,
`wp_date`, `sanitize_textarea_field`, `manage_edit-comments_columns`,
`manage_comments_custom_column`, `admin_notices`, `get_current_screen`, `comment_form`,
`notify_moderator`, `update_option_{$option}` (old and new value, only on a change),
`pre_http_request` and the `schedule_event` filter (run inside `wp_remote_post` /
`wp_schedule_single_event`; another plugin's callback may throw there), `get_comments` with
`meta_compare` / `meta_type`, `wp_new_comment_notify_moderator`,
`comment_moderation_recipients` (the list and the comment id, in `wp_notify_moderator`:
`admin_email`, and the post's author when they may `edit_comment` and have another address;
before the text), `comment_moderation_text` (the text and the comment id, in
`wp_notify_moderator`; once per e-mail on 6.0, once per recipient on 7.1),
`wp_new_comment_notify_postauthor`, `load_plugin_textdomain`, `register_activation_hook`,
`register_deactivation_hook`, `WP_UNINSTALL_PLUGIN`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI (`.github/workflows/tests.yml`) runs `php -l` over the plugin, the suite and the zip
build on PHP 7.4 and 8.3, and the integration suite in its own job, "WordPress integration",
on WordPress 6.0 with PHP 7.4 and the latest WordPress with PHP 8.3. Every `uses:` in the
workflows is pinned to a full commit SHA with its version in a trailing comment
(`owner/action@<40-hex sha>  # v5.0.0`); to move a pin, resolve the new release tag to its
commit (`git ls-remote https://github.com/<owner>/<action> refs/tags/<tag>^{}` for an
annotated tag, the tag itself otherwise), check that the commit belongs to that release, and
change the SHA and the comment together in every workflow. The suite needs `ext-curl` and
`ext-mbstring` and starts PHP's built-in server for the end-to-end test.

- **Unit** (`tests/Unit/`): `PluginTestCase` hooks the real `Plugin` into the stubs with a
  fixed clock. `WpStub::postComment()` runs the filter (twice, as WordPress 6.7+), the
  content filters, the insert and `comment_post` as `wp_new_comment` does; `deliver()` signs
  a payload with `Signature::sign` and calls the real receiver; `WpStub::answer()` queues
  the gateway's answers (by default a `202` naming every item of the request in `przyjete`);
  every HTTP request, status change, cron event and e-mail is recorded in `WpStub`.
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
  server: the mock gateway's worker delivers to a capture server (`fixtures/capture.php`),
  and the test serves each delivery byte for byte where its database transaction is. The
  plugin's clock is the real one, so a test that needs time to pass moves the moments the
  plugin stored back. One test at least for each of: the double `pre_comment_approved`, kses
  and the stored `comment_content` with the column's limit, `wp_set_comment_status` /
  `wp_update_comment` / `transition_comment_status` and the e-mails sent in the webhook's
  request, the REST route with the raw body, the maintenance mode's `503`
  (`MaintenanceTest`), WP-Cron's duplicate refusal, `get_comments` with meta and date
  queries, and uninstalling. To run it, with a MySQL server and a user that may create the
  database:

  ```bash
  bin/install-wp-tests.sh wordpress_test <db-user> <db-password> 127.0.0.1:3306 6.0   # or: latest
  vendor/bin/phpunit -c phpunit-integration.xml.dist
  ```

  The script takes WordPress from the release tag of its Git mirror
  (`github.com/WordPress/WordPress`) and the test library from the release's tag of
  `github.com/WordPress/wordpress-develop`, each fetched by its full tag ref, never a
  branch: the mirror names the first release of a branch `6.0`, wordpress-develop `6.0.0`
  (its `6.0` is the moving 6.0 branch), so `6.0` here means the release 6.0 for both, unlike
  the standard script, which reads it as the 6.0 branch's newest test library. A release
  missing from either repository is refused, and the resolved tags and commits are printed.
  It installs into `WP_CORE_DIR` and `WP_TESTS_DIR` (by default `/tmp/wordpress` and
  `/tmp/wordpress-tests-lib`), each missing or empty: it removes no directory but its own
  staging ones, refuses one it did not install that holds anything, and reuses its own only
  for the same release and test configuration (another release or database needs both
  directories removed first). The plain `vendor/bin/phpunit` never needs MySQL.
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

`composer.json` requires `minos-moderation/client-php` at `^0.2` from its GitHub
repository (a `vcs` repository entry: the client is not on Packagist); `composer.lock` pins
the exact tag and commit, so a build is reproducible. The lock IS the pin: the client's
tag `v0.2.1`, commit `56d5780`. Moving to a newer client is a pull request of its own
with a reviewed `composer update minos-moderation/client-php` to a tag (widen the
constraint first when the tag is outside `^0.2`), and the lock diff shows only that
package's entry and the content hash. Never pin a commit of the client's `main`. The
plugin never forks the client's verification.

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

The version lives in ONE place the release workflow checks: the `Version:` header of
`minos-moderation.php`. The repository keeps no changelog; the release notes are GitHub's
generated ones. The workflow, not a person, creates the release, and only after its checks
pass.

1. Bump `Version:` in `minos-moderation.php` in a pull request, and merge it. On every pull
   request the `release-archive` job of `tests.yml` already runs the release build and
   checks against the declared version.
2. The dry run on `main`: Actions → Release → "Run workflow", branch `main`, the tag to
   be (`v0.2.0`). It builds and checks exactly as a tag push does and keeps the zip
   as the run's `release-archive` artifact (7 days); see it green.
3. The owner creates and pushes the TAG ONLY, on the commit the dry run checked, from a
   local clone: `git tag -a v0.2.0 -m v0.2.0 <commit>` and `git push origin v0.2.0`.
   Not GitHub's "Draft a new release" form: a tag created there is published together
   with its release, before any check. Tag pushes from Claude Code sessions are refused.
4. The tag's push starts `.github/workflows/release.yml`, which builds the zip with
   `bin/build-zip.sh` on PHP 7.4 and lists it, failing on any hit: `composer.lock`,
   `tests/`, `phpunit*`, the mock gateway, `.git*`, `CLAUDE.md`, `.claude/`, `.github/`,
   Composer's `installed.json`; it also fails unless `minos-moderation/LICENSE` is inside
   and not blank, and the zip's `Version:` header equals the tag without the `v` (the
   message names both). Only then the `publish` job creates the release with
   `minos-moderation.zip` attached (`--verify-tag`, generated notes, a pre-release for a tag
   with `-`). gh creates it as a draft, uploads, then publishes, so a failed upload leaves a
   draft, never a release without its asset.

When a release for the tag already exists at that point, `publish` fails and attaches
nothing: such a release was published unchecked. Delete it (keep the tag) and re-run the
failed jobs. Nothing replaces an asset (`--clobber` is never used), so a second upload
fails loudly. A failed check publishes nothing: delete the tag, fix `main`, start again
from step 1. By hand: `bin/build-zip.sh && .github/scripts/check-release-archive.sh build/minos-moderation.zip v0.1.0`.

Nothing in this repository enforces that only the owner tags (the session refusal lives
outside GitHub): the owner should add a tag ruleset on `v*` that only they may bypass, or
a `release` environment with a required reviewer on the `publish` job.
