#!/bin/bash
# Installs the PHP dependencies in a Claude Code on the web session, so that
# `vendor/bin/phpunit` works from the first turn instead of costing turns of setup. The
# bundled client comes from GitHub (a VCS repository): when its zip cannot be downloaded,
# composer falls back to a git clone.
# Local sessions manage their own vendor/. Idempotent: composer is a no-op when
# vendor/ already matches.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-.}"
if ! command -v composer >/dev/null 2>&1; then
  echo "session-start: composer is not installed; run the tests after installing it" >&2
  exit 0
fi
composer install --no-interaction --no-progress --prefer-dist >&2
