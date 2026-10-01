#!/bin/bash
set -uo pipefail

if ! HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"; then
  echo "ERROR: failed to cd" >&2
  exit 2
fi

if [ ! -d "$HERE/../../src/css/abstracts" ]; then
  echo "ERROR: cannot locate src/css from $HERE" >&2
  exit 2
fi

FAIL=0
# Sweep components and layout instead of just one file
for f in "$HERE/../../src/css/components/"*.scss "$HERE/../../src/css/layout/"*.scss; do
  [ -f "$f" ] || continue
  while IFS= read -r var; do
    case "$var" in
      --wppo-progress|--wppo-lqip-*) continue ;;
    esac

    if ! grep -qF -- "$var:" "$HERE/../../src/css/abstracts/_variables.scss" "$f"; then
      echo "ERROR: CSS variable $var used in $(basename "$f") but not defined in _variables.scss or locally"
      FAIL=1
    fi
  done < <(grep -oE "var\(--wppo-[a-zA-Z0-9-]+\b" "$f" | sed 's/var(//' | sort -u)
done

if [ "$FAIL" -ne 0 ]; then
  echo "Tests failed"
fi
exit "$FAIL"
