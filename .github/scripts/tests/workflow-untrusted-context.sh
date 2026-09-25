#!/usr/bin/env bash
#
# Regression: untrusted GitHub event context must never be interpolated into a
# `run:` body.
#
# Fork pull requests, issue comments, and issue titles are attacker-controlled.
# `$(...)`, backticks and `;` are legal in git ref names, so a workflow line
# such as
#
#   echo "ref=${{ github.event.pull_request.head.ref }}" >> "$GITHUB_OUTPUT"
#
# executes the attacker's command substitution on the runner before checkout,
# with whatever token the step environment exposes. The safe pattern is to pass
# the value through `env:` and read the variable inside the script.
#
# This test is hermetic (no network, no runner) and has three parts:
#   1. Static, block-aware scan of every workflow run body.
#   2. Positive assertions that the fixed env pattern is actually present.
#   3. Empirical canary + mutation control proving the detector really works.
#
set -euo pipefail

SCRIPT_DIR="${BASH_SOURCE[0]%/*}"
REPO_ROOT="${SCRIPT_DIR}/../../.."
WORKFLOW_DIR="${REPO_ROOT}/.github/workflows"
SCANNER="$(mktemp)"
WORK="$(mktemp -d)"
trap 'rm -rf "$SCANNER" "$WORK"' EXIT

fail() {
	printf 'FAIL: %s\n' "$1" >&2
	exit 1
}

# ---------------------------------------------------------------------------
# Helper: block-aware workflow run-body scanner / extractor.
# ---------------------------------------------------------------------------
cat >"$SCANNER" <<'PY'
import os
import re
import sys

# Contexts an untrusted actor can choose. Only free-form values are listed:
# git SHAs and numeric ids are validated by GitHub and cannot carry shell
# metacharacters, so policing those would add noise that gets the rule disabled.
UNTRUSTED = [
    r"github\.event\.pull_request\.(?:head\.ref|title|body|head\.label)",
    r"github\.event\.pull_request\.head\.repo\.[A-Za-z_]+",
    r"github\.event\.comment\.body",
    r"github\.event\.issue\.(?:body|title)",
    r"github\.event\.head_commit\.(?:message|author\.(?:name|email))",
    r"github\.event\.workflow_run\.(?:head_branch|display_title)",
    r"github\.(?:ref_name|head_ref)",
]
UNTRUSTED_RE = re.compile("|".join(UNTRUSTED))
EXPR_RE = re.compile(r"\$\{\{[^}]*\}\}")
STEP_NAME_RE = re.compile(r"^(\s*)-\s+name:\s*(.+?)\s*$")
STEP_USES_RE = re.compile(r"^(\s*)-\s+uses:")
RUN_KEY_RE = re.compile(r"^(\s*)run:\s*(.*)$")
BLOCK_MARKERS = {"", "|", ">", "|-", ">-", "|+", ">+"}


def run_blocks(lines):
    """Yield (lineno, step_name, body) for every `run:` body in a file."""
    step_name = None
    index = 0
    while index < len(lines):
        line = lines[index]
        name_match = STEP_NAME_RE.match(line)
        if name_match:
            step_name = name_match.group(2).strip().strip("'\"")
        elif STEP_USES_RE.match(line):
            step_name = None
        run_match = RUN_KEY_RE.match(line)
        if not run_match:
            index += 1
            continue
        start = index
        indent = len(run_match.group(1))
        value = run_match.group(2).strip()
        if value in BLOCK_MARKERS:
            index += 1
            body = []
            while index < len(lines):
                candidate = lines[index]
                if candidate.strip() == "":
                    body.append(candidate)
                    index += 1
                    continue
                if len(candidate) - len(candidate.lstrip()) <= indent:
                    break
                body.append(candidate)
                index += 1
            while body and body[-1].strip() == "":
                body.pop()
        else:
            body = [line[len(" " * indent) + len("run:"):].lstrip()]
            index += 1
        yield start + 1, step_name, body


def extract_run(path, wanted):
    """Print the run body of the first step whose name matches `wanted`."""
    lines = open(path, encoding="utf-8").read().splitlines()
    for _, step_name, body in run_blocks(lines):
        if step_name == wanted:
            print("\n".join(body))
            return 0
    sys.stderr.write("step not found: %s in %s\n" % (wanted, path))
    return 2


def render(path, placeholder, value):
    text = open(path, encoding="utf-8").read()
    if placeholder not in text:
        sys.stderr.write("placeholder not found: %s\n" % placeholder)
        return 2
    sys.stdout.write(text.replace(placeholder, value))
    return 0


def scan(target):
    if os.path.isdir(target):
        paths = []
        for base, _, names in os.walk(target):
            paths.extend(
                os.path.join(base, n) for n in sorted(names) if n.endswith((".yml", ".yaml"))
            )
    else:
        paths = [target]
    violations = []
    for path in sorted(paths):
        lines = open(path, encoding="utf-8").read().splitlines()
        for offset, (start, _, body) in enumerate(run_blocks(lines)):
            del offset
            for line in body:
                if EXPR_RE.search(line) and UNTRUSTED_RE.search(line):
                    violations.append("%s:%d: %s" % (path, start, line.strip()))
    for violation in violations:
        sys.stderr.write("UNTRUSTED CONTEXT IN RUN BODY -> %s\n" % violation)
    return 1 if violations else 0


command = sys.argv[1]
if command == "scan":
    raise SystemExit(scan(sys.argv[2]))
if command == "extract-run":
    raise SystemExit(extract_run(sys.argv[2], sys.argv[3]))
if command == "render":
    raise SystemExit(render(sys.argv[2], sys.argv[3], os.environ["CANARY"]))
raise SystemExit("unknown command: %s" % command)
PY

printf '1/7 static scan of %s ...\n' "$WORKFLOW_DIR"
python3 "$SCANNER" scan "$WORKFLOW_DIR" || fail "untrusted GitHub context is interpolated into a run body"

AI_REVIEW="${WORKFLOW_DIR}/wppo-ai-review.yml"
PSALM="${WORKFLOW_DIR}/psalm-wpcs-check.yml"
TRI_MERGE="${WORKFLOW_DIR}/wppo-tri-merge-workflow.yml"

printf '2/7 positive assertions ...\n'
[ "$(grep -c 'PR_HEAD_REF: ${{ github.event.pull_request.head.ref }}' "$AI_REVIEW")" = "3" ] \
	|| fail "expected 3 env-passed PR_HEAD_REF resolve-ref steps"
[ "$(grep -c 'REF="\$PR_HEAD_REF"' "$AI_REVIEW")" = "3" ] \
	|| fail "expected 3 resolve-ref steps to read PR_HEAD_REF from the environment"
[ "$(grep -c 'git check-ref-format' "$AI_REVIEW")" = "3" ] \
	|| fail "expected every resolve-ref step to validate the ref format"
[ "$(grep -c 'PR_TITLE: ${{ github.event.pull_request.title }}' "$AI_REVIEW")" = "1" ] \
	|| fail "expected the merge notification to pass PR_TITLE through env"
[ "$(grep -c 'BRANCH: ${{ github.ref_name }}' "$PSALM")" = "1" ] \
	|| fail "expected psalm-wpcs-check to pass BRANCH through env"
[ "$(grep -c 'PR_TITLE="\${{ github.event.pull_request.title }}"' "$AI_REVIEW")" = "0" ] \
	|| fail "PR_TITLE is still interpolated into a run body"

printf '3/7 empirical canary on the real resolve-ref step ...\n'
# A valid git ref that also carries a command substitution. It reaches the
# workflow through a single-quoted env assignment, so the fixed step must copy
# it literally; the vulnerable form would execute `id` and print `uid=`.
export CANARY='x$(id)'
python3 "$SCANNER" extract-run "$AI_REVIEW" "Resolve PR ref" >"$WORK/resolve-ref.sh" \
	|| fail "could not extract the Resolve PR ref step"
: >"$WORK/out.txt"
PR_HEAD_REF="$CANARY" EVENT_NAME="pull_request" ISSUE_PR="" ISSUE_NUMBER="" \
	REPOSITORY="nilesh32236/performance-optimisation" GITHUB_OUTPUT="$WORK/out.txt" \
	bash "$WORK/resolve-ref.sh" || fail "the real resolve-ref step rejected a valid canary ref"
grep -qF "ref=${CANARY}" "$WORK/out.txt" \
	|| fail "resolve-ref did not preserve the canary ref literally (command substitution executed)"

printf '4/7 mutation control: reintroduce the vulnerable line ...\n'
mkdir -p "$WORK/mutant"
cp "$AI_REVIEW" "$WORK/mutant/wppo-ai-review.yml"
python3 - "$WORK/mutant/wppo-ai-review.yml" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read()
safe = 'echo "ref=$REF" >> "$GITHUB_OUTPUT"'
vulnerable = 'echo "ref=${{ github.event.pull_request.head.ref }}" >> "$GITHUB_OUTPUT"'
if safe not in text:
    sys.exit("safe line not found; update the mutation")
open(path, "w", encoding="utf-8").write(text.replace(safe, vulnerable))
PY
if python3 "$SCANNER" scan "$WORK/mutant" >/dev/null 2>&1; then
	fail "the scanner did not flag the reintroduced vulnerable line"
fi
python3 "$SCANNER" extract-run "$WORK/mutant/wppo-ai-review.yml" "Resolve PR ref" >"$WORK/mutant.sh" \
	|| fail "could not extract the mutated Resolve PR ref step"
python3 "$SCANNER" render "$WORK/mutant.sh" '${{ github.event.pull_request.head.ref }}' >"$WORK/mutant-rendered.sh" \
	|| fail "could not render the mutated step"
: >"$WORK/mutant-out.txt"
PR_HEAD_REF="$CANARY" EVENT_NAME="pull_request" ISSUE_PR="" ISSUE_NUMBER="" \
	REPOSITORY="nilesh32236/performance-optimisation" GITHUB_OUTPUT="$WORK/mutant-out.txt" \
	bash "$WORK/mutant-rendered.sh" || true
if grep -qF "ref=${CANARY}" "$WORK/mutant-out.txt"; then
	fail "the canary payload is not live; the mutation control proves nothing"
fi
if ! grep -q "uid=" "$WORK/mutant-out.txt"; then
	fail "the vulnerable line did not execute the canary payload as expected"
fi

printf '5/7 shell syntax ...\n'
bash -n "$WORK/resolve-ref.sh" "$WORK/mutant-rendered.sh" || fail "extracted run bodies are not valid shell"

# ---------------------------------------------------------------------------
# Merge-gate regressions: the auto-merge rollup must fail closed.
# ---------------------------------------------------------------------------
printf '6/7 auto-merge check-rollup gate ...\n'
python3 "$SCANNER" extract-run "$AI_REVIEW" "Wait for green checks, then squash merge PR" >"$WORK/auto-merge.sh" \
	|| fail "could not extract the auto-merge step"
python3 - "$WORK/auto-merge.sh" <<'PY'
import re
import sys

body = open(sys.argv[1], encoding="utf-8").read()
start = body.index("--jq '") + len("--jq '")
program = body[start:body.index("'", start)]
open(sys.argv[1].replace(".sh", ".jq"), "w", encoding="utf-8").write(program)
PY
# SKIPPED/NEUTRAL used to count as green; an empty rollup used to look green.
assert_rollup() {
	local label="$1" rollup="$2" want_total="$3" want_failed="$4" got
	got=$(printf '{"headRefOid":"sha","statusCheckRollup":%s}' "$rollup" | jq -c -f "$WORK/auto-merge.jq")
	[ "$(printf '%s' "$got" | jq -r .total)" = "$want_total" ] \
		|| fail "${label}: total mismatch (got ${got})"
	[ "$(printf '%s' "$got" | jq -r .failed)" = "$want_failed" ] \
		|| fail "${label}: failed mismatch (got ${got})"
	printf '  %-34s total=%s failed=%s ok\n' "$label" "$want_total" "$want_failed"
}
assert_rollup "empty rollup" '[]' 0 0
assert_rollup "one success" '[{"__typename":"CheckRun","status":"COMPLETED","conclusion":"SUCCESS"}]' 1 0
assert_rollup "skipped" '[{"__typename":"CheckRun","status":"COMPLETED","conclusion":"SKIPPED"}]' 1 1
assert_rollup "neutral" '[{"__typename":"CheckRun","status":"COMPLETED","conclusion":"NEUTRAL"}]' 1 1
assert_rollup "in progress" '[{"__typename":"CheckRun","status":"IN_PROGRESS","conclusion":null}]' 1 0
assert_rollup "failure" '[{"__typename":"CheckRun","status":"COMPLETED","conclusion":"FAILURE"}]' 1 1
assert_rollup "legacy pending" '[{"__typename":"StatusContext","state":"PENDING"}]' 1 0
assert_rollup "legacy success" '[{"__typename":"StatusContext","state":"SUCCESS"}]' 1 0
assert_rollup "legacy failure" '[{"__typename":"StatusContext","state":"FAILURE"}]' 1 1
# The guard that turns total=0 into a refusal, and the head-SHA binding.
grep -q 'if \[ "\$TOTAL" -eq 0 \]' "$WORK/auto-merge.sh" \
	|| fail "auto-merge no longer refuses a PR with zero checks"
grep -q -- '--match-head-commit "\$PR_HEAD_SHA"' "$WORK/auto-merge.sh" \
	|| fail "auto-merge is not bound to the reviewed head commit"
grep -q 'Auto-merge skipped — head moved' "$WORK/auto-merge.sh" \
	|| fail "auto-merge no longer aborts when the head moves during polling"

printf '7/7 release version bump + tag safety ...\n'
grep -q 'Refusing to delete or re-create a published tag' "$TRI_MERGE" \
	|| fail "tri-merge can still delete a published release tag"
grep -q 'git tag -d' "$TRI_MERGE" && fail "tri-merge still force-deletes release tags"
grep -q 'is not MAJOR.MINOR.PATCH' "$TRI_MERGE" \
	|| fail "tri-merge no longer validates the version before rewriting files"
grep -q 'Version mismatch after bump' "$TRI_MERGE" \
	|| fail "tri-merge no longer asserts the version sources agree"
grep -q 'needs: \[verify, resolve-conflicts, merge-all\]' "$TRI_MERGE" \
	|| fail "the release job is not gated on merge-all"
# Exercise the real bump expression against a header fixture.
python3 - "$TRI_MERGE" <<'PY'
import re
import subprocess
import sys
import tempfile
import os

text = open(sys.argv[1], encoding="utf-8").read()
sed = None
for line in text.splitlines():
    if "sed -i -E" in line and "Version:" in line and "performance-optimisation.php" in line:
        sed = line.strip().replace("${NEXT_VERSION}", "9.9.9")
        break
if sed is None:
    sys.exit("could not locate the version bump expression")
header = (
    "<?php\n/**\n * Plugin Name:       Performance Optimisation\n"
    " * Version:           2.4.0\n * Author:            Nilesh Kanzariya\n */\n"
)
with tempfile.TemporaryDirectory() as tmp:
    target = os.path.join(tmp, "performance-optimisation.php")
    open(target, "w", encoding="utf-8").write(header)
    subprocess.run(["bash", "-c", sed], cwd=tmp, check=True)
    bumped = open(target, encoding="utf-8").read()
    # The header is " * Version:  ..."; a column-zero pattern never matches,
    # which is what froze the published version and re-tagged v2.4.0.
    if " * Version:           9.9.9" not in bumped:
        sys.exit("the version bump did not update the plugin header:\n%s" % bumped)
    if bumped.count("Version:") != 1:
        sys.exit("the version bump touched unrelated header lines:\n%s" % bumped)
PY

printf '%s\n' WORKFLOW_UNTRUSTED_CONTEXT_OK
