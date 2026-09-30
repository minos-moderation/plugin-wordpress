# Minos for WordPress

A WordPress plugin that sends new comments to the Wergiliusz gateway and applies the verdict
the gateway delivers to a signed webhook. Part of Minos; its backlog item is
`minos-moderation/minos#3`. Layout, tests and the mock workflow: `docs/development.md`.

## The boundary
- The plugin talks only to the gateway, over HTTPS, with the forum's key. The key and the
  webhook secret never reach a log, a page (beyond a prefix) or a public file.
- The gateway decides and the plugin applies. It never guesses a verdict: `nieocenione`
  goes to the administrator's fail-open or fail-closed setting.
- The contract is `docs/contract.md` in `minos-moderation/client-php`; its receiving
  checklist is binding: read the raw body first, verify the signature, drop repeated
  deliveries, answer 2xx fast.
- A PHP plugin bundles `minos-moderation/client-php` at a pinned version and never forks
  its verification. Until the client's `v0.1.0` tag exists, `composer.lock` is the pin
  (`dev-main` at a reviewed commit): move it only with a reviewed `composer update`.
  Never send an e-mail, IP address or author id.
- Every WordPress call of the logic goes through `src/Platform.php`; tests stub those
  functions in `tests/stubs/wordpress.php`, so a new call needs a stub.

## Code
- Code, comments and commits in English. Everything an administrator or a user reads is
  in Polish, in `__()` with the `minos-moderation` text domain.
- Wire strings (JSON keys, codes, headers, enum values) are Polish and never renamed.
- The oldest versions the plugin promises: WordPress 6.0 and PHP 7.4, tested in CI on PHP
  7.4 and 8.3. No PHP 8 syntax (`tests/Repo/Php74SyntaxTest.php` guards the obvious).
- No real posts in tests: use the mock gateway from `minos-moderation/client-php`.
- An assertion asks about a property; a pattern that may match nothing needs a guard.
- Never `git add -A`. Sessions open PRs; only the owner or the coordinating session merges.
- Model-pinned agents in `.claude/agents/`, copied from `client-php` and fitted to the
  plugin's paths (`programista-prosty`'s risk list above all).
- This file stays small (`tests/Repo/ClaudeRulesTest.php` pins its size).
