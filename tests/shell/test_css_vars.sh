#!/bin/bash
FAIL=0
for var in $(grep -oE "var\(--wppo-[a-zA-Z0-9-]+\b" src/css/components/_message-region.scss | sed 's/var(//' | sort -u); do
  if ! grep -q "$var:" src/css/abstracts/_variables.scss; then
    echo "ERROR: CSS variable $var used in _message-region.scss but not defined in _variables.scss"
    FAIL=1
  fi
done
if [ $FAIL -ne 0 ]; then
  # tests failed
  echo "Tests failed"
fi
