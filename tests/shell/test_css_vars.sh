#!/bin/bash
set -uo pipefail
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

FAIL=0
# Sweep components and layout instead of just one file
for f in "$HERE/../../src/css/components/"*.scss "$HERE/../../src/css/layout/"*.scss; do
  [ -f "$f" ] || continue
  while IFS= read -r var; do
    # Skip component-scoped variable families like --wppo-progress (see _progress.scss:12-15) and --wppo-lqip-
    if echo "$var" | grep -qE "^--wppo-(progress|lqip-)"; then
      continue
    fi
    if ! grep -qF -- "$var:" "$HERE/../../src/css/abstracts/_variables.scss"; then
      echo "ERROR: CSS variable $var used in $(basename "$f") but not defined in _variables.scss"
      FAIL=1
    fi
  done < <(grep -oE "var\(--wppo-[a-zA-Z0-9-]+\b" "$f" | sed 's/var(//' | sort -u)
done

if [ "$FAIL" -ne 0 ]; then
  echo "Tests failed"
fi
exit "$FAIL"
