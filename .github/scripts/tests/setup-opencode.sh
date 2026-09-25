#!/usr/bin/env bash
# Regression coverage for the shared OpenCode installer.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SETUP_SCRIPT="${ROOT_DIR}/.github/scripts/setup-opencode.sh"
COMMITTED_CHECKSUMS="${ROOT_DIR}/.github/scripts/opencode-checksums.txt"
VERSION="v1.18.29"
unset TAR_OPTIONS GZIP

if ! grep -Fq "OPENCODE_VERSION=\"\${OPENCODE_VERSION:-${VERSION}}\"" "${SETUP_SCRIPT}"; then
	echo "installer default version is not pinned to the tested release" >&2
	exit 1
fi
if grep -Fq "/tmp/opencode.tar.gz" "${SETUP_SCRIPT}" || grep -Eq 'tar .* -C /usr/local/bin' "${SETUP_SCRIPT}"; then
	echo "installer regressed to a fixed or final-directory archive path" >&2
	exit 1
fi

if [[ "$(uname -s)" != "Linux" ]]; then
	echo "test requires Linux" >&2
	exit 2
fi
case "$(uname -m)" in
	x86_64|amd64) ARCH="linux-x64" ;;
	aarch64|arm64) ARCH="linux-arm64" ;;
	*)
		echo "unsupported test architecture: $(uname -m)" >&2
		exit 2
		;;
esac
ASSET="opencode-${ARCH}.tar.gz"

if ! command -v python3 >/dev/null 2>&1; then
	echo "test requires python3 to build the traversal fixture" >&2
	exit 2
fi

for checksum_arch in linux-x64 linux-arm64; do
	mapfile -t committed_checksums < <(awk -v version="${VERSION}" -v arch="${checksum_arch}" '$1 == version && $2 == arch { print $3 }' "${COMMITTED_CHECKSUMS}")
	if [[ "${#committed_checksums[@]}" -ne 1 || ! "${committed_checksums[0]}" =~ ^[0-9a-f]{64}$ ]]; then
		echo "expected one valid committed checksum for ${VERSION} ${checksum_arch}" >&2
		exit 1
	fi
	case "${checksum_arch}" in
		linux-x64) expected_checksum="ea800b7ff56226b70952126c9fc1e2517ca4c4b5682fd9d3f9e87449697a1194" ;;
		linux-arm64) expected_checksum="70baf769395ca4e7a68924026530c390eace194f3b7e4919d4efcb2aa2eed3c0" ;;
	esac
	if [[ "${committed_checksums[0]}" != "${expected_checksum}" ]]; then
		echo "committed checksum drifted for ${VERSION} ${checksum_arch}" >&2
		exit 1
	fi
done

installer_pattern='^[[:space:]]*(\.github/scripts/setup-opencode\.sh|bash[[:space:]]+\.github/scripts/setup-opencode\.sh|run:[[:space:]]+bash[[:space:]]+\.github/scripts/setup-opencode\.sh)([[:space:]]|$)'
mapfile -t installer_workflows < <(grep -Rl --include='*.yml' -E "${installer_pattern}" "${ROOT_DIR}/.github/workflows" | sort)
if [[ "${#installer_workflows[@]}" -eq 0 ]]; then
	echo "no direct OpenCode installer workflows found" >&2
	exit 1
fi
assert_installer_checkouts_isolated() {
	python3 - "$1" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
blocks = []
current = None
in_jobs = False
for line in path.read_text().splitlines(keepends=True):
    if line == "jobs:\n":
        in_jobs = True
        continue
    if in_jobs and re.match(r"^\S", line):
        in_jobs = False
    if not in_jobs:
        continue
    match = re.match(r"^  ([A-Za-z0-9_-]+):\s*$", line)
    if match:
        if current is not None:
            blocks.append(current)
        current = [match.group(1), [line]]
    elif current is not None:
        current[1].append(line)
if current is not None:
    blocks.append(current)

call = re.compile(r"^\s*(?:run:\s*)?(?:\.github/scripts/setup-opencode\.sh|bash\s+\.github/scripts/setup-opencode\.sh)(?:\s|$)", re.MULTILINE)
for name, lines in blocks:
    text = "".join(lines)
    if call.search(text) and "persist-credentials: false" not in text:
        print(f"{path.name}: job {name} invokes the installer without isolated checkout credentials", file=sys.stderr)
        raise SystemExit(1)
PY
}

for workflow_path in "${installer_workflows[@]}"; do
	installer_calls="$(grep -Ec "${installer_pattern}" "${workflow_path}")"
	pinned_calls="$(awk -v version="${VERSION}" '$1 == "OPENCODE_VERSION:" && $2 == version { count += 1 } END { print count + 0 }' "${workflow_path}")"
	if [[ "${installer_calls}" -ne "${pinned_calls}" ]]; then
		echo "workflow ${workflow_path##*/} has an installer call without the pinned version" >&2
		exit 1
	fi
	if ! assert_installer_checkouts_isolated "${workflow_path}"; then
		echo "workflow ${workflow_path##*/} can expose checkout credentials to the installer" >&2
		exit 1
	fi
done

tri_workflow="${ROOT_DIR}/.github/workflows/wppo-tri-merge-workflow.yml"
if grep -q 'CONTEXT7_API_KEY:' "${tri_workflow}" || grep -q 'git remote add origin-temp' "${tri_workflow}" || ! grep -q 'env -i' "${tri_workflow}" || ! grep -q 'rm -f .git/FETCH_HEAD' "${tri_workflow}"; then
	echo "tri-merge exposes repository credentials to its raw OpenCode process" >&2
	exit 1
fi

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/wppo-opencode-test.XXXXXX")"
trap 'rm -rf "${TMP_ROOT}"' EXIT
CURL_LOG="${TMP_ROOT}/curl.log"
STUB_BIN="${TMP_ROOT}/stub-bin"
INSTALLER_TMP_ROOT="${TMP_ROOT}/installer-tmp"
mkdir -p "${STUB_BIN}" "${INSTALLER_TMP_ROOT}"

cat > "${STUB_BIN}/curl" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
if [[ -n "${GITHUB_TOKEN:-}${GH_TOKEN:-}${GH_PAT:-}${OPENCODE_API_KEY:-}${OPENAI_API_KEY:-}${ANTHROPIC_API_KEY:-}${GEMINI_API_KEY:-}${CONTEXT7_API_KEY:-}${SVN_USERNAME:-}${SVN_PASSWORD:-}" ]]; then
	echo "stub: installer exposed a credential to curl" >&2
	exit 98
fi
printf '%s\n' "$*" >>"${CURL_LOG}"
output=""
previous=""
for argument in "$@"; do
	if [[ "${previous}" == "-o" || "${previous}" == "--output" ]]; then
		output="${argument}"
	fi
	previous="${argument}"
done
url="${!#}"
if [[ "${url}" == *api.github.com* ]]; then
	echo "stub: pinned installation called the GitHub API" >&2
	exit 42
fi
expected_version="${OPENCODE_VERSION#v}"
expected="https://github.com/anomalyco/opencode/releases/download/v${expected_version}/opencode-${OPENCODE_ARCH}.tar.gz"
if [[ "${url}" != "${expected}" ]]; then
	echo "stub: unexpected download URL: ${url}" >&2
	exit 43
fi
cp "${FAKE_ARCHIVE}" "${output}"
STUB
chmod +x "${STUB_BIN}/curl"

cat > "${STUB_BIN}/git" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB
chmod +x "${STUB_BIN}/git"

make_regular_archive() {
	local directory="$1"
	local archive="$2"
	mkdir -p "${directory}"
	cat >"${directory}/opencode" <<'STUB'
#!/usr/bin/env bash
if [[ -n "${GITHUB_TOKEN:-}${GH_TOKEN:-}${GH_PAT:-}${OPENCODE_API_KEY:-}${OPENAI_API_KEY:-}${ANTHROPIC_API_KEY:-}${GEMINI_API_KEY:-}${CONTEXT7_API_KEY:-}${SVN_USERNAME:-}${SVN_PASSWORD:-}" ]]; then
	echo "installer leaked a credential to the downloaded binary" >&2
	exit 97
fi
printf '%s\n' "$*" >>"${VERSION_LOG:?}"
touch "${VERSION_SUCCESS:?}"
echo "${OPENCODE_EXPECTED_VERSION:?}"
STUB
	chmod +x "${directory}/opencode"
	tar -czf "${archive}" -C "${directory}" opencode
}

assert_installer_tmp_empty() {
	local install_dir="$1"
	if find "${INSTALLER_TMP_ROOT}" -mindepth 1 -print -quit | grep -q .; then
		echo "installer left temporary files behind" >&2
		return 1
	fi
	if [[ -d "${install_dir}" ]] && find "${install_dir}" -maxdepth 1 -name '.opencode.*' -print -quit | grep -q .; then
		echo "installer left a partial final temporary file behind" >&2
		return 1
	fi
}

run_installer() {
	local name="$1"
	local archive="$2"
	local digest="$3"
	local requested_version="${4:-${VERSION}}"
	local case_root="${TMP_ROOT}/${name}"
	local install_dir="${case_root}/install"
	local work_dir="${case_root}/work"
	local checksum_file="${case_root}/checksums.txt"
	local output_file="${case_root}/output.log"
	local version_log="${case_root}/version.log"
	local version_success="${case_root}/version.success"
	local github_path="${case_root}/github-path"
	mkdir -p "${work_dir}"
	printf '%s %s %s\n' "${VERSION}" "${ARCH}" "${digest}" >"${checksum_file}"
	local status=0
	(
		cd "${work_dir}"
		PATH="${STUB_BIN}:${PATH}" \
			CURL_LOG="${CURL_LOG}" \
			FAKE_ARCHIVE="${archive}" \
			OPENCODE_VERSION="${requested_version}" \
			OPENCODE_ARCH="${ARCH}" \
			OPENCODE_CHECKSUM_FILE="${checksum_file}" \
			OPENCODE_INSTALL_DIR="${install_dir}" \
			GITHUB_TOKEN="installer-test-token" \
			GH_PAT="installer-test-gh-pat" \
			SVN_USERNAME="installer-test-svn-user" \
			SVN_PASSWORD="installer-test-svn-password" \
			OPENCODE_API_KEY="installer-test-api-key" \
			OPENAI_API_KEY="installer-test-openai-key" \
			ANTHROPIC_API_KEY="installer-test-anthropic-key" \
			GEMINI_API_KEY="installer-test-gemini-key" \
			CONTEXT7_API_KEY="installer-test-context7-key" \
			GITHUB_PATH="${github_path}" \
			TMPDIR="${INSTALLER_TMP_ROOT}" \
			TAR_OPTIONS="--checkpoint=1 --checkpoint-action=exec=touch ${case_root}/tar-injected" \
			VERSION_LOG="${version_log}" \
			VERSION_SUCCESS="${version_success}" \
			bash "${SETUP_SCRIPT}"
	) >"${output_file}" 2>&1 || status=$?
	assert_installer_tmp_empty "${install_dir}" || status=1
	return "${status}"
}

GOOD_FIXTURE="${TMP_ROOT}/good-fixture"
GOOD_ARCHIVE="${TMP_ROOT}/opencode-good.tar.gz"
make_regular_archive "${GOOD_FIXTURE}" "${GOOD_ARCHIVE}"
GOOD_DIGEST="$(sha256sum "${GOOD_ARCHIVE}" | awk '{ print $1 }')"

if ! run_installer good "${GOOD_ARCHIVE}" "${GOOD_DIGEST}"; then
	cat "${TMP_ROOT}/good/output.log" >&2
	echo "expected pinned checksummed installation to succeed" >&2
	exit 1
fi
if [[ ! -x "${TMP_ROOT}/good/install/opencode" || -L "${TMP_ROOT}/good/install/opencode" ]]; then
	echo "installer did not create the expected regular executable" >&2
	exit 1
fi
if [[ "$(stat -c '%a' "${TMP_ROOT}/good/install/opencode")" != "755" ]]; then
	echo "installed OpenCode permissions are not 0755" >&2
	exit 1
fi
if [[ ! -f "${TMP_ROOT}/good/version.success" ]] || ! grep -Fxq -- "--version" "${TMP_ROOT}/good/version.log"; then
	echo "installed OpenCode version check did not run successfully" >&2
	exit 1
fi
if ! grep -Fxq -- "${TMP_ROOT}/good/install" "${TMP_ROOT}/good/github-path"; then
	echo "installer did not publish the verified install directory to GITHUB_PATH" >&2
	exit 1
fi
if grep -Fq "api.github.com" "${CURL_LOG}"; then
	echo "pinned installation unexpectedly called the GitHub API" >&2
	exit 1
fi
if [[ "$(wc -l <"${CURL_LOG}")" -ne 1 ]]; then
	echo "pinned installation did not make exactly one direct download" >&2
	exit 1
fi

if ! run_installer unprefixed-version "${GOOD_ARCHIVE}" "${GOOD_DIGEST}" "1.18.29"; then
	cat "${TMP_ROOT}/unprefixed-version/output.log" >&2
	echo "expected an unprefixed stable version to normalize to v1.18.29" >&2
	exit 1
fi
if [[ ! -x "${TMP_ROOT}/unprefixed-version/install/opencode" || -L "${TMP_ROOT}/unprefixed-version/install/opencode" ]]; then
	echo "normalized unprefixed version did not install a regular fixture" >&2
	exit 1
fi
if [[ ! -f "${TMP_ROOT}/unprefixed-version/version.success" ]] || ! grep -Fxq -- "--version" "${TMP_ROOT}/unprefixed-version/version.log"; then
	echo "normalized unprefixed version did not run the installed fixture" >&2
	exit 1
fi
if [[ "$(wc -l <"${CURL_LOG}")" -ne 2 ]]; then
	echo "normalized unprefixed installation did not make exactly one direct download" >&2
	exit 1
fi

if run_installer invalid-version "${GOOD_ARCHIVE}" "${GOOD_DIGEST}" "v1.18"; then
	echo "invalid release version was accepted" >&2
	exit 1
fi
if ! grep -Fq "OPENCODE_VERSION must be vMAJOR.MINOR.PATCH" "${TMP_ROOT}/invalid-version/output.log"; then
	echo "invalid release version did not fail with the expected diagnostic" >&2
	exit 1
fi
if run_installer version-injection "${GOOD_ARCHIVE}" "${GOOD_DIGEST}" $'v1.18.29\n::error::injected'; then
	echo "version control-character injection was accepted" >&2
	exit 1
fi
if grep -Fq "injected" "${TMP_ROOT}/version-injection/output.log"; then
	echo "installer echoed an untrusted version value" >&2
	exit 1
fi

mkdir -p "${TMP_ROOT}/checksum-mismatch/install"
printf '%s\n' "previous OpenCode binary" >"${TMP_ROOT}/checksum-mismatch/install/opencode"
if run_installer checksum-mismatch "${GOOD_ARCHIVE}" "0000000000000000000000000000000000000000000000000000000000000000"; then
	echo "checksum mismatch was accepted" >&2
	exit 1
fi
if ! grep -Fq "OpenCode archive checksum mismatch" "${TMP_ROOT}/checksum-mismatch/output.log"; then
	echo "checksum mismatch did not fail with the expected diagnostic" >&2
	exit 1
fi
if [[ "$(cat "${TMP_ROOT}/checksum-mismatch/install/opencode")" != "previous OpenCode binary" ]]; then
	echo "checksum failure replaced the previous OpenCode binary" >&2
	exit 1
fi
if [[ -s "${TMP_ROOT}/checksum-mismatch/github-path" ]]; then
	echo "checksum failure published an unverified install path" >&2
	exit 1
fi

FAIL_FIXTURE="${TMP_ROOT}/version-failure-fixture"
FAIL_ARCHIVE="${TMP_ROOT}/opencode-version-failure.tar.gz"
mkdir -p "${FAIL_FIXTURE}"
cat >"${FAIL_FIXTURE}/opencode" <<'STUB'
#!/usr/bin/env bash
exit 42
STUB
chmod +x "${FAIL_FIXTURE}/opencode"
tar -czf "${FAIL_ARCHIVE}" -C "${FAIL_FIXTURE}" opencode
FAIL_DIGEST="$(sha256sum "${FAIL_ARCHIVE}" | awk '{ print $1 }')"
mkdir -p "${TMP_ROOT}/version-failure/install"
printf '%s\n' "previous OpenCode binary" >"${TMP_ROOT}/version-failure/install/opencode"
if run_installer version-failure "${FAIL_ARCHIVE}" "${FAIL_DIGEST}"; then
	echo "failing downloaded binary was installed" >&2
	exit 1
fi
if ! grep -Fq "Downloaded OpenCode binary failed its version check" "${TMP_ROOT}/version-failure/output.log"; then
	echo "version failure did not fail with the expected diagnostic" >&2
	exit 1
fi
if [[ "$(cat "${TMP_ROOT}/version-failure/install/opencode")" != "previous OpenCode binary" ]]; then
	echo "version failure replaced the previous OpenCode binary" >&2
	exit 1
fi
if [[ -s "${TMP_ROOT}/version-failure/github-path" ]]; then
	echo "version failure published an unverified install path" >&2
	exit 1
fi

WRONG_VERSION_FIXTURE="${TMP_ROOT}/wrong-version-fixture"
WRONG_VERSION_ARCHIVE="${TMP_ROOT}/opencode-wrong-version.tar.gz"
mkdir -p "${WRONG_VERSION_FIXTURE}"
cat >"${WRONG_VERSION_FIXTURE}/opencode" <<'STUB'
#!/usr/bin/env bash
echo "9.9.9"
STUB
chmod +x "${WRONG_VERSION_FIXTURE}/opencode"
tar -czf "${WRONG_VERSION_ARCHIVE}" -C "${WRONG_VERSION_FIXTURE}" opencode
WRONG_VERSION_DIGEST="$(sha256sum "${WRONG_VERSION_ARCHIVE}" | awk '{ print $1 }')"
if run_installer wrong-version "${WRONG_VERSION_ARCHIVE}" "${WRONG_VERSION_DIGEST}"; then
	echo "binary with an unexpected version was accepted" >&2
	exit 1
fi
if ! grep -Fq "Downloaded OpenCode binary reported an unexpected version" "${TMP_ROOT}/wrong-version/output.log"; then
	echo "wrong-version binary did not fail with the expected diagnostic" >&2
	exit 1
fi
if [[ -e "${TMP_ROOT}/wrong-version/install/opencode" || -L "${TMP_ROOT}/wrong-version/install/opencode" ]]; then
	echo "wrong-version binary was installed" >&2
	exit 1
fi
if [[ -s "${TMP_ROOT}/wrong-version/github-path" ]]; then
	echo "wrong-version failure published an install path" >&2
	exit 1
fi

SYMLINK_FIXTURE="${TMP_ROOT}/symlink-fixture"
SYMLINK_ARCHIVE="${TMP_ROOT}/opencode-symlink.tar.gz"
mkdir -p "${SYMLINK_FIXTURE}"
ln -s /bin/true "${SYMLINK_FIXTURE}/opencode"
tar -czf "${SYMLINK_ARCHIVE}" -C "${SYMLINK_FIXTURE}" opencode
SYMLINK_DIGEST="$(sha256sum "${SYMLINK_ARCHIVE}" | awk '{ print $1 }')"
if run_installer symlink-entry "${SYMLINK_ARCHIVE}" "${SYMLINK_DIGEST}"; then
	echo "symlink archive entry was accepted" >&2
	exit 1
fi
if [[ -e "${TMP_ROOT}/symlink-entry/install/opencode" || -L "${TMP_ROOT}/symlink-entry/install/opencode" ]]; then
	echo "symlink archive installed a binary" >&2
	exit 1
fi
if ! grep -Fq "OpenCode archive entry must be a regular file" "${TMP_ROOT}/symlink-entry/output.log"; then
	echo "symlink archive did not fail with the expected diagnostic" >&2
	exit 1
fi

EXTRA_FIXTURE="${TMP_ROOT}/extra-fixture"
EXTRA_ARCHIVE="${TMP_ROOT}/opencode-extra.tar.gz"
make_regular_archive "${EXTRA_FIXTURE}" "${EXTRA_ARCHIVE}"
printf 'unexpected\n' >"${EXTRA_FIXTURE}/extra.txt"
tar -czf "${EXTRA_ARCHIVE}" -C "${EXTRA_FIXTURE}" opencode extra.txt
EXTRA_DIGEST="$(sha256sum "${EXTRA_ARCHIVE}" | awk '{ print $1 }')"
if run_installer extra-entry "${EXTRA_ARCHIVE}" "${EXTRA_DIGEST}"; then
	echo "archive with extra entries was accepted" >&2
	exit 1
fi
if [[ -e "${TMP_ROOT}/extra-entry/install/opencode" || -L "${TMP_ROOT}/extra-entry/install/opencode" ]]; then
	echo "archive with extra entries installed a binary" >&2
	exit 1
fi
if ! grep -Fq "OpenCode archive must contain exactly one opencode entry" "${TMP_ROOT}/extra-entry/output.log"; then
	echo "extra-entry archive did not fail with the expected diagnostic" >&2
	exit 1
fi

TRAVERSAL_FIXTURE="${TMP_ROOT}/traversal-fixture"
TRAVERSAL_ARCHIVE="${TMP_ROOT}/opencode-traversal.tar.gz"
mkdir -p "${TRAVERSAL_FIXTURE}"
printf '%s\n' "#!/usr/bin/env bash" "echo traversal" >"${TRAVERSAL_FIXTURE}/opencode"
printf '%s\n' "escape" >"${TRAVERSAL_FIXTURE}/escape"
printf '%s\n' "outside-sentinel" >"${TMP_ROOT}/outside-sentinel"
python3 - "${TRAVERSAL_ARCHIVE}" "${TRAVERSAL_FIXTURE}/opencode" "${TRAVERSAL_FIXTURE}/escape" <<'PY'
import sys
import tarfile

archive, binary, payload = sys.argv[1:]
with tarfile.open(archive, "w:gz") as handle:
    for name, source in (("opencode", binary), ("../../escape", payload)):
        info = handle.gettarinfo(source, arcname=name)
        info.uid = info.gid = 0
        info.mode = 0o755 if name == "opencode" else 0o644
        with open(source, "rb") as stream:
            handle.addfile(info, stream)
PY
TRAVERSAL_DIGEST="$(sha256sum "${TRAVERSAL_ARCHIVE}" | awk '{ print $1 }')"
if run_installer traversal-entry "${TRAVERSAL_ARCHIVE}" "${TRAVERSAL_DIGEST}"; then
	echo "archive traversal entry was accepted" >&2
	exit 1
fi
if ! grep -Fq "OpenCode archive must contain exactly one opencode entry" "${TMP_ROOT}/traversal-entry/output.log"; then
	echo "traversal archive did not fail with the expected diagnostic" >&2
	exit 1
fi
if [[ "$(cat "${TMP_ROOT}/outside-sentinel")" != "outside-sentinel" || -e "${INSTALLER_TMP_ROOT}/escape" || -L "${INSTALLER_TMP_ROOT}/escape" ]]; then
	echo "archive traversal escaped the staging directory" >&2
	exit 1
fi

if grep -Fq "api.github.com" "${CURL_LOG}"; then
	echo "a pinned installation called the GitHub API" >&2
	exit 1
fi
if find "${TMP_ROOT}" -name 'tar-injected' -print -quit | grep -q .; then
	echo "TAR_OPTIONS reached tar despite the installer safeguard" >&2
	exit 1
fi
if grep -r -Eq 'Authorization:|installer-test-token|installer-test-gh-pat|installer-test-svn-user|installer-test-svn-password|installer-test-api-key|installer-test-openai-key|installer-test-anthropic-key|installer-test-gemini-key|installer-test-context7-key' "${TMP_ROOT}"; then
	echo "installer test token or authorization header appeared in captured output" >&2
	exit 1
fi

printf '%s\n' "PASS: pinned OpenCode installation is deterministic, checksummed, and archive-safe"
