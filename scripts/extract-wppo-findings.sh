#!/usr/bin/env bash
# extract-wppo-findings.sh — tolerant extractor for the WordPress Feature
# Monitor research output.
#
# The research agent streams ALL of its messages to stdout (progress chatter
# plus the final findings JSON). CI must narrow that stream to exactly the
# LAST COMPLETE findings document. The inline workflow version anchors on
# `awk '/^\{"schema_version"/'`, which misses indented output, a UTF-8 BOM,
# CRLF line endings, and markdown fences — every one of which the model has
# emitted in failed weekly runs (issue #1563).
#
# This script is the canonical tolerant implementation the workflow should
# call (one-line adoption, see issue #1563). It intentionally lives
# outside `.github/` so it can be unit-tested without workflow permissions.
#
# Usage:
#   scripts/extract-wppo-findings.sh <raw-output> [clean-output]
#   scripts/extract-wppo-findings.sh < raw.txt > clean.json
#
# Exit 0 + clean JSON on stdout/file when the stream contains a complete
# findings object (`{"schema_version"...}` with a `findings` array).
# Exit 1 + human-readable jq error on stderr otherwise (fail-hard: callers
# must abort, never fall back to placeholders).
#
# @since NEXT
set -euo pipefail

INPUT="${1:-/dev/stdin}"
OUTPUT="${2:-}"

if [ "$INPUT" != "/dev/stdin" ] && [ ! -r "$INPUT" ]; then
	printf 'extract-wppo-findings: input not readable: %s\n' "$INPUT" >&2
	exit 1
fi

narrow="$(mktemp)"
norm="$(mktemp)"
trap 'rm -f -- "$narrow" "$norm"' EXIT

# 1. Normalise: strip UTF-8 BOM (first line only), CR bytes (CRLF files),
#    and markdown fences. Fences are stripped AFTER anchoring would
#    otherwise see them, so strip them first here. A fence that shares its
#    line with the JSON start (e.g. '```json {"schema_version"...') has the
#    marker stripped as a prefix so the document survives; bare fence lines
#    are deleted outright.
sed -e '1s/^\xEF\xBB\xBF//' -e 's/\r//g' \
	-e '/^[[:space:]]*```[[:space:]]*$/d' \
	-e 's/^[[:space:]]*```[a-zA-Z0-9_-]*[[:space:]]*//' "$INPUT" >"$norm"

# 2. Anchor on the FIRST '"schema_version":' line (quoted + colon-terminated
#    so prose chatter mentioning the field name cannot match), then walk back
#    at most 10 lines to the nearest opening brace. The backtrack is what
#    lets pretty-printed multi-line documents ('{\n  "schema_version"...')
#    extract; single-line compact JSON anchors directly. Starting from the
#    FIRST match (not the last) preserves last-complete-doc semantics below:
#    `jq -c` drops an incomplete truncated tail and `tail -1` keeps the final
#    complete document.
anchor="$(grep -n -E -m 1 '"schema_version"[[:space:]]*:' "$norm" | cut -d: -f1 || true)"

if [ -z "${anchor:-}" ]; then
	: >"$narrow"
else
	start="$anchor"
	anchor_line="$(sed -n "${anchor}p" "$norm")"
	case "$anchor_line" in
		*\{*'"schema_version"'*) ;;
		*)
			s="$anchor"
			while [ "$s" -gt 1 ] && [ $(( anchor - s )) -lt 10 ]; do
				s=$(( s - 1 ))
				prev="$(sed -n "${s}p" "$norm")"
				case "$prev" in
					*\{*) start="$s"; break ;;
				esac
			done
			;;
	esac
	tail -n +"$start" "$norm" >"$narrow"
fi

# 3. Keep the LAST COMPLETE document: repeated self-verification echoes and
#    multi-doc captures collapse to one doc. `jq -c` drops incomplete tails;
#    `tail -1` keeps the final complete one.
clean="$(jq -c . "$narrow" 2>/dev/null | tail -1 || true)"

if [ -z "$clean" ]; then
	err="$(jq -e . "$narrow" 2>&1 >/dev/null | head -c 500 || true)"
	if [ -z "$err" ]; then
		err='no JSON document found (output empty, or no "schema_version" field present)'
	fi
	printf '%s\n' "$err" >&2
	exit 1
fi

# 4. Shape gate: object with a findings array (mirrors the workflow check).
if ! printf '%s' "$clean" | jq -e 'type == "object" and (.findings | type == "array")' >/dev/null 2>&1; then
	printf 'output JSON is not an object with a findings array\n' >&2
	exit 1
fi

if [ -n "$OUTPUT" ]; then
	printf '%s\n' "$clean" >"$OUTPUT"
else
	printf '%s\n' "$clean"
fi
