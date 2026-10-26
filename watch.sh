#!/usr/bin/env bash
# Rebuild the field editor and the Templates screen on every change; Ctrl-C stops both.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"

pids=()
cleanup() { kill "${pids[@]}" 2>/dev/null || true; }
trap cleanup EXIT INT TERM

for dir in assets/editor-plugin assets/templates-app; do
  ( cd "$ROOT/$dir"
    [ -d node_modules ] || npm ci --no-audit --no-fund
    exec npx vite build --watch ) &
  pids+=( $! )
done
wait
