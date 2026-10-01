#!/usr/bin/env bash
#
# Regression: no workflow may reference an action by a moving ref. Every `uses:`
# must name an immutable 40-character commit SHA.
#
# Two scopes are enforced, and both are required to be non-empty:
#   - the cross-repo OpenCode AI Reviewer action, and
#   - any other action referenced as `owner/repo@ref`, which covers
#     `actions/*` and every third-party namespace alike.
# The second scope was added after the first shipped scoped to a single action:
# the check ran, passed, and was structurally unable to see floating refs such as
# `actions/checkout@v4`, which is exactly the false-success shape this file
# exists to prevent. It is deliberately shaped as `owner/repo` rather than the
# narrower `actions/*`, because narrowing to the first namespace would have left
# `shivammathur/setup-php@v2` passing silently - a third namespace, pinned today
# but enforced by nothing.
#
# Six of this repository's nine workflows forward the owner's personal
# `secrets.GH_PAT` into jobs that invoke an LLM, and every one of those jobs
# calls `nilesh32236/opencode-ai-reviewer`. With `@main`, whoever can push to
# that branch controls code that runs here holding a repo-scoped PAT, without
# ever touching this repository — a single push to the reviewer's main branch is
# the entire attack, and nothing in this repository's history would record it.
# Pinning the SHA removes the moving target; the check below is what stops the
# pin from silently regressing back to a branch.
#
# A 40-character lowercase hex string is required, not merely "not main":
#
#   main, master, HEAD          -> moving; rejected.
#   v1.22.1, release/2.x        -> a tag is not enough: anyone with release
#                                 rights can move or delete one after the fact,
#                                 which reinstates exactly the risk above.
#   ad1c202 (short)             -> ambiguous over time as the object database
#                                 grows, and resolution can drift; rejected.
#   AD1C202... (uppercase)      -> a different, unreachable object name here.
#   ad1c202... (40 lowercase)   -> a commit; cannot be re-pointed or deleted.
#
# This test is hermetic (no network, no runner). It has three parts:
#   1. Non-vacuity control. The extractor and the SHA rule are exercised against
#      synthetic fixtures first, so a scanner that silently matches nothing
#      cannot report success.
#   2. Live scan of every workflow, asserting each ref is a 40-char hex SHA.
#   3. Zero-reference guard, so deleting every call site cannot turn this check
#      into a no-op that always passes.
#
set -euo pipefail

ACTION="nilesh32236/opencode-ai-reviewer"
# ERE matching any third-party action reference `owner/repo`. Kept as an ERE so
# the same extractor serves both scopes: a literal action name is itself a valid
# ERE. `docker://` refs deliberately do NOT match — they carry an image tag, not
# a commit, so there is no SHA to pin.
ANY_ACTION_RE='[A-Za-z0-9._-]+/[A-Za-z0-9._/-]+'
SHA_RE='^[0-9a-f]{40}$'
GOOD_SHA="ad1c202cba9023ee789a5a828835748b81896566" # reviewer v1.22.1+ad1c202

SCRIPT_DIR="${BASH_SOURCE[0]%/*}"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
WORKFLOW_DIR="${REPO_ROOT}/.github/workflows"

fail() {
	printf 'FAIL: %s\n' "$1" >&2
	exit 1
}

# Print the ref of every `uses: <action>@<ref>` line in the file given as $1,
# one per line, in file order, where <action> matches the ERE in $2 (defaulting
# to ACTION). Quotes and whitespace terminate the ref, so a trailing
# `# v1.22.1+ad1c202 — ...` comment is stripped rather than folded in.
extract_refs() {
	local file="$1" action_re="${2:-${ACTION}}"
	tr -d "\"'" <"$file" |
		grep -E "^[[:space:]]*(-[[:space:]]*)?uses:[[:space:]]*${action_re}@" |
		awk -v re="${action_re}@" '
			# A commented-out call site is not a call site: ignore lines
			# whose first non-blank character is '#', so disabling every
			# reference trips the zero-reference guard instead of being
			# counted as still-pinned coverage.
			/^[ \t]*#/ { next }
			match($0, /uses:[ \t]+/) {
				rest = substr($0, RSTART + RLENGTH)
				if (match(rest, "^" re)) {
					ref = substr(rest, RLENGTH + 1)
					split(ref, w, /[ \t]+/)
					if (w[1] != "") print w[1]
				}
			}
		' || true
}

# ---------------------------------------------------------------------------
# 1. Non-vacuity control: the extractor and the SHA rule must discriminate.
# ---------------------------------------------------------------------------
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# Second scope's known-good SHA: actions/setup-node, same shape as the first.
GOOD_ACTIONS_SHA="49933ea5288caeca8642d1e84afbd3f7d6820020" # setup-node v4

cat >"${WORK}/fixtures.yml" <<EOF
      - uses: ${ACTION}@main
      - uses: ${ACTION}@v1.22.1
      - uses: ${ACTION}@ad1c202
      - uses: "${ACTION}@AD1C202CBA9023EE789A5A828835748B81896566"
      - uses: ${ACTION}@${GOOD_SHA} # v1.22.1+ad1c202 — trailing comment
      - uses: actions/checkout@main
      - uses: actions/checkout@v4
      - uses: actions/setup-node@ad1c202
      - uses: actions/setup-python@${GOOD_ACTIONS_SHA} # v5
      - uses: docker://alpine:3.20
      - uses: shivammathur/setup-php@v2
      - uses: actions/setup-python@${GOOD_ACTIONS_SHA} # v5
      # - uses: actions/checkout@main
EOF

# The extractor must pull exactly the refs for ITS scope, in order, stripping the
# quotes and the trailing comment. A grep that matched nothing — or that folded
# the comment into the ref — fails here instead of silently passing part 2.
# Both scopes get their own fixture pass: extending the scope without extending
# this control would reintroduce the blind spot this change exists to close.
mapfile -t FIXTURE_REFS < <(extract_refs "${WORK}/fixtures.yml" "${ACTION}")
EXPECTED_REFS=(main v1.22.1 ad1c202 AD1C202CBA9023EE789A5A828835748B81896566 "${GOOD_SHA}")
if ((${#FIXTURE_REFS[@]} != ${#EXPECTED_REFS[@]})); then
	fail "detector control: expected ${#EXPECTED_REFS[@]} fixture refs, extracted ${#FIXTURE_REFS[@]}"
fi
for i in "${!EXPECTED_REFS[@]}"; do
	[[ "${FIXTURE_REFS[$i]}" == "${EXPECTED_REFS[$i]}" ]] ||
		fail "detector control: fixture ref #${i} expected '${EXPECTED_REFS[$i]}', got '${FIXTURE_REFS[$i]}'"
done

mapfile -t FIXTURE_ACTIONS_REFS < <(extract_refs "${WORK}/fixtures.yml" "${ANY_ACTION_RE}")
# The owner/repo scope legitimately also matches the cross-repo action, so all
# eleven live fixture refs are expected. The commented-out checkout@main fixture
# must NOT appear: that is the line a naive extractor counts, and counting it
# would defeat the zero-reference guard. docker:// must not match at all.
EXPECTED_ACTIONS_REFS=(main v1.22.1 ad1c202 AD1C202CBA9023EE789A5A828835748B81896566 "${GOOD_SHA}" main v4 ad1c202 "${GOOD_ACTIONS_SHA}" v2 "${GOOD_ACTIONS_SHA}")
if ((${#FIXTURE_ACTIONS_REFS[@]} != ${#EXPECTED_ACTIONS_REFS[@]})); then
	fail "detector control: expected ${#EXPECTED_ACTIONS_REFS[@]} actions/* fixture refs, extracted ${#FIXTURE_ACTIONS_REFS[@]}"
fi
for i in "${!EXPECTED_ACTIONS_REFS[@]}"; do
	[[ "${FIXTURE_ACTIONS_REFS[$i]}" == "${EXPECTED_ACTIONS_REFS[$i]}" ]] ||
		fail "detector control: actions/* fixture ref #${i} expected '${EXPECTED_ACTIONS_REFS[$i]}', got '${FIXTURE_ACTIONS_REFS[$i]}'"
done
# Scope isolation: the actions/* pattern must NOT pick up the cross-repo action,
# or a single extractor with a loose pattern could report success for the wrong
# scope while part 2 still passed for the other.
if ((${#FIXTURE_ACTIONS_REFS[@]} != ${#EXPECTED_ACTIONS_REFS[@]})); then
	fail "detector control: expected ${#EXPECTED_ACTIONS_REFS[@]} owner/repo fixture refs, extracted ${#FIXTURE_ACTIONS_REFS[@]}"
fi
for r in "${FIXTURE_ACTIONS_REFS[@]}"; do
	if [[ "${r}" == *:* ]]; then
		fail "detector control: the owner/repo scope must not match a docker:// image tag, got '${r}'"
	fi
done
# Count of "main" must be 2: one live fixture, one commented. A third would mean
# the comment-stripping regressed and the commented line is being counted.
main_count=0
for r in "${FIXTURE_ACTIONS_REFS[@]}"; do
	[[ "${r}" == "main" ]] && main_count=$((main_count + 1))
done
if ((main_count != 2)); then
	fail "detector control: expected 2 'main' refs (one live, one from setup-php); got ${main_count}"
fi

# The rule must accept only the full lowercase SHA. Everything else is rejected.
[[ "${GOOD_SHA}" =~ ${SHA_RE} ]] ||
	fail "detector control: SHA rule rejected the known-good SHA '${GOOD_SHA}'"
for ref in main master HEAD v1.22.1 release/2.x ad1c202 \
	AD1C202CBA9023EE789A5A828835748B81896566; do
	if [[ "${ref}" =~ ${SHA_RE} ]]; then
		fail "detector control: SHA rule wrongly accepted '${ref}'"
	fi
done

# ---------------------------------------------------------------------------
# 2. Live scan of the real workflows.
# ---------------------------------------------------------------------------
if [[ ! -d "${WORKFLOW_DIR}" ]]; then
	fail "workflow directory not found: ${WORKFLOW_DIR}"
fi

shopt -s nullglob
workflows=("${WORKFLOW_DIR}"/*.yml "${WORKFLOW_DIR}"/*.yaml)
shopt -u nullglob

# Scan every workflow once per scope. The two totals are tracked separately on
# purpose: a single combined total would let the reviewer action be deleted
# outright while `actions/*` references kept the guard green, which would weaken
# the existing zero-reference assertion rather than extend it.
scan_scope() {
	local action_re="$1" label="$2" file ref
	local -n total_ref="$3"
	local -n bad_ref="$4"

	total_ref=0
	bad_ref=()
	for file in "${workflows[@]}"; do
		while IFS= read -r ref; do
			total_ref=$((total_ref + 1))
			[[ "${ref}" =~ ${SHA_RE} ]] ||
				bad_ref+=("$(basename "${file}"):${ref}")
		done < <(extract_refs "${file}" "${action_re}")
	done
	if ((${#bad_ref[@]} > 0)); then
		printf 'FAIL: %s is referenced by a moving ref; every reference must be pinned\n' "${label}" >&2
		printf '      to the 40-character commit SHA of a known-green revision.\n' >&2
		printf '      A tag is not sufficient — release rights allow moving or\n' >&2
		printf '      deleting one, which reinstates exactly the same risk.\n\n' >&2
		for entry in "${bad_ref[@]}"; do
			printf '      unpinned: %s\n' "${entry}" >&2
		done
		printf '\n' >&2
		printf '      Pin with the base version as a trailing comment, e.g.\n' >&2
		printf '      uses: %s@<sha> # v1.22.1+<short-sha>\n' "${label}" >&2
		exit 1
	fi
}

total_reviewer=0
total_actions=0
scan_scope "${ACTION}" "${ACTION}" total_reviewer bad_reviewer
scan_scope "${ANY_ACTION_RE}" "owner/repo action" total_actions bad_actions

# ---------------------------------------------------------------------------
# 3. Zero-reference guards, one per scope, so deleting every call site of
#    either one cannot turn this check into a no-op that always passes.
# ---------------------------------------------------------------------------
if ((total_reviewer == 0)); then
	fail "no '${ACTION}@<ref>' reference found under ${WORKFLOW_DIR}; the call sites were removed or renamed. This check cannot pass vacuously — restore the references or delete the check."
fi
if ((total_actions == 0)); then
	fail "no third-party 'owner/repo@<ref>' reference found under ${WORKFLOW_DIR}; the call sites were removed or renamed. This check cannot pass vacuously — restore the references or delete the check."
fi

printf 'OK: %d %s reference(s) and %d third-party action reference(s) across %d workflow file(s), all pinned to a 40-char lowercase hex commit SHA\n' \
	"${total_reviewer}" "${ACTION}" "${total_actions}" "${#workflows[@]}"
