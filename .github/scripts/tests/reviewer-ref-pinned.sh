#!/usr/bin/env bash
#
# Regression: the cross-repo OpenCode AI Reviewer action must be referenced by an
# immutable commit SHA, never by a moving ref.
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
SHA_RE='^[0-9a-f]{40}$'
GOOD_SHA="ad1c202cba9023ee789a5a828835748b81896566" # reviewer v1.22.1+ad1c202

SCRIPT_DIR="${BASH_SOURCE[0]%/*}"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
WORKFLOW_DIR="${REPO_ROOT}/.github/workflows"

fail() {
	printf 'FAIL: %s\n' "$1" >&2
	exit 1
}

# Print the ref of every `uses: <ACTION>@<ref>` line in the file given as $1,
# one per line, in file order. Quotes and whitespace terminate the ref, so a
# trailing `# v1.22.1+ad1c202 — ...` comment is stripped rather than folded in.
extract_refs() {
	grep -hE "uses:[[:space:]]*[\"']?${ACTION}@" "$1" 2>/dev/null |
		tr -d "\"'" |
		awk -v a="${ACTION}@" '
			index($0, a) {
				rest = substr($0, index($0, a) + length(a))
				split(rest, w, /[[:space:]]+/)
				if (w[1] != "") print w[1]
			}
		' || true
}

# ---------------------------------------------------------------------------
# 1. Non-vacuity control: the extractor and the SHA rule must discriminate.
# ---------------------------------------------------------------------------
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

cat >"${WORK}/fixtures.yml" <<EOF
      - uses: ${ACTION}@main
      - uses: ${ACTION}@v1.22.1
      - uses: ${ACTION}@ad1c202
      - uses: "${ACTION}@AD1C202CBA9023EE789A5A828835748B81896566"
      - uses: ${ACTION}@${GOOD_SHA} # v1.22.1+ad1c202 — trailing comment
EOF

# The extractor must pull exactly the five fixture refs, in order, stripping the
# quotes and the trailing comment. A grep that matched nothing — or that folded
# the comment into the ref — fails here instead of silently passing part 2.
mapfile -t FIXTURE_REFS < <(extract_refs "${WORK}/fixtures.yml")
EXPECTED_REFS=(main v1.22.1 ad1c202 AD1C202CBA9023EE789A5A828835748B81896566 "${GOOD_SHA}")
if ((${#FIXTURE_REFS[@]} != ${#EXPECTED_REFS[@]})); then
	fail "detector control: expected ${#EXPECTED_REFS[@]} fixture refs, extracted ${#FIXTURE_REFS[@]}"
fi
for i in "${!EXPECTED_REFS[@]}"; do
	[[ "${FIXTURE_REFS[$i]}" == "${EXPECTED_REFS[$i]}" ]] ||
		fail "detector control: fixture ref #${i} expected '${EXPECTED_REFS[$i]}', got '${FIXTURE_REFS[$i]}'"
done

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

total=0
bad=()
for wf in "${workflows[@]}"; do
	while IFS= read -r ref; do
		total=$((total + 1))
		[[ "${ref}" =~ ${SHA_RE} ]] ||
			bad+=("$(basename "${wf}"):${ref}")
	done < <(extract_refs "${wf}")
done

if ((${#bad[@]} > 0)); then
	printf 'FAIL: %s is referenced by a moving ref; every reference must be pinned\n' "${ACTION}" >&2
	printf '      to the 40-character commit SHA of a known-green revision.\n' >&2
	printf '      A tag is not sufficient — release rights allow moving or\n' >&2
	printf '      deleting one, which reinstates exactly the same risk.\n\n' >&2
	for entry in "${bad[@]}"; do
		printf '      unpinned: %s\n' "${entry}" >&2
	done
	printf '\n' >&2
	printf '      Pin with the base version as a trailing comment, e.g.\n' >&2
	printf '      uses: %s@<sha> # v1.22.1+<short-sha>\n' "${ACTION}" >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# 3. Zero-reference guard: no matches at all would mean the check has gone
#    blind rather than passed.
# ---------------------------------------------------------------------------
if ((total == 0)); then
	fail "no '${ACTION}@<ref>' reference found under ${WORKFLOW_DIR}; the call sites were removed or renamed. This check cannot pass vacuously — restore the references or delete the check."
fi

printf 'OK: %d %s reference(s) across %d workflow file(s), all pinned to a 40-char lowercase hex commit SHA\n' \
	"${total}" "${ACTION}" "${#workflows[@]}"
