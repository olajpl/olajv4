#!/usr/bin/env bash
set -euo pipefail

ROOT="$HOME/olaj"
PHP_BIN="$(command -v php || echo /usr/bin/php)"

LOCK_DIR="$ROOT/var/locks"
LOG_DIR="$ROOT/var/log"
mkdir -p "$LOCK_DIR" "$LOG_DIR"

LOCK_FILE="$LOCK_DIR/live_comments.lock"
LOG_FILE="$LOG_DIR/live_comments_worker.log"

cd "$ROOT"

if command -v flock >/dev/null 2>&1; then
  flock -n "$LOCK_FILE" "$PHP_BIN" "$ROOT/bin/worker_live_comments.php" >> "$LOG_FILE" 2>&1
else
  if mkdir "${LOCK_FILE}.d" 2>/dev/null; then
    trap 'rmdir "${LOCK_FILE}.d"' EXIT
    "$PHP_BIN" "$ROOT/bin/worker_live_comments.php" >> "$LOG_FILE" 2>&1
  fi
fi
