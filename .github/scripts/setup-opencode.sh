#!/usr/bin/env bash
# setup-opencode.sh
# Installs a pinned OpenCode CLI release with repository-owned integrity checks.

set -euo pipefail
umask 077
unset GITHUB_TOKEN GH_TOKEN GH_PAT OPENCODE_API_KEY OPENAI_API_KEY ANTHROPIC_API_KEY GEMINI_API_KEY CONTEXT7_API_KEY SVN_USERNAME SVN_PASSWORD TAR_OPTIONS GZIP

OPENCODE_VERSION="${OPENCODE_VERSION:-v1.18.29}"
OPENCODE_INSTALL_DIR="${OPENCODE_INSTALL_DIR:-/usr/local/bin}"
RELEASE_REPOSITORY="anomalyco/opencode"
SCRIPT_DIR="${BASH_SOURCE[0]%/*}"
if [[ "${SCRIPT_DIR}" == "${BASH_SOURCE[0]}" ]]; then
	SCRIPT_DIR="."
fi
SCRIPT_DIR="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
CHECKSUM_FILE="${OPENCODE_CHECKSUM_FILE:-${SCRIPT_DIR}/opencode-checksums.txt}"
if [[ "${OPENCODE_INSTALL_DIR}" == *$'\n'* || "${OPENCODE_INSTALL_DIR}" == *$'\r'* || "${OPENCODE_INSTALL_DIR}" == *$'\t'* ]]; then
	echo "::error::OPENCODE_INSTALL_DIR contains a control character" >&2
	exit 1
fi

for required_command in awk curl git head install mkdir mktemp mv rm sha256sum stat tar uname; do
	if ! command -v "${required_command}" >/dev/null 2>&1; then
		echo "::error::Required OpenCode installer command is missing: ${required_command}" >&2
		exit 1
	fi
done

if [[ "$(uname -s)" != "Linux" ]]; then
	echo "::error::setup-opencode.sh supports Linux runners only" >&2
	exit 1
fi
case "$(uname -m)" in
	x86_64|amd64) ARCH="linux-x64" ;;
	aarch64|arm64) ARCH="linux-arm64" ;;
	*)
		echo "::error::Unsupported OpenCode runner architecture: $(uname -m)" >&2
		exit 1
		;;
esac
ASSET_NAME="opencode-${ARCH}.tar.gz"

if [[ ! "${OPENCODE_VERSION}" =~ ^v?([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
	echo "::error::OPENCODE_VERSION must be vMAJOR.MINOR.PATCH" >&2
	exit 1
fi
OPENCODE_VERSION="v${BASH_REMATCH[1]}"
if [[ ! -r "${CHECKSUM_FILE}" ]]; then
	echo "::error::OpenCode checksum file is not readable" >&2
	exit 1
fi
mapfile -t CHECKSUMS < <(awk -v version="${OPENCODE_VERSION}" -v arch="${ARCH}" '$1 == version && $2 == arch { print $3 }' "${CHECKSUM_FILE}")
if [[ "${#CHECKSUMS[@]}" -ne 1 || ! "${CHECKSUMS[0]}" =~ ^[0-9a-f]{64}$ ]]; then
	echo "::error::No unique trusted SHA-256 for ${OPENCODE_VERSION} ${ARCH}" >&2
	exit 1
fi
EXPECTED_SHA256="${CHECKSUMS[0]}"
EXPECTED_URL_PREFIX="https://github.com/${RELEASE_REPOSITORY}/releases/download/${OPENCODE_VERSION}/"
DOWNLOAD_URL="${EXPECTED_URL_PREFIX}${ASSET_NAME}"

echo "::group::Setting up OpenCode ${OPENCODE_VERSION} (${ARCH})"

CURL_RETRY=(
	--fail
	--silent
	--show-error
	--location
	--retry 3
	--retry-delay 2
	--retry-max-time 150
	--retry-all-errors
	--retry-connrefused
	--proto '=https'
	--proto-redir '=https'
)

TEMP_DIR="$(mktemp -d)"
ARCHIVE_PATH="${TEMP_DIR}/opencode.tar.gz"
EXTRACT_DIR="${TEMP_DIR}/extract"
FINAL_TMP=""
cleanup() {
	[[ -z "${FINAL_TMP}" ]] || rm -f -- "${FINAL_TMP}"
	rm -rf -- "${TEMP_DIR}"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

echo "Downloading from: ${DOWNLOAD_URL}"
if ! curl "${CURL_RETRY[@]}" --connect-timeout 10 --max-time 180 --max-filesize 104857600 --output "${ARCHIVE_PATH}" "${DOWNLOAD_URL}"; then
	echo "::error::Failed to download the pinned OpenCode release" >&2
	exit 1
fi

ACTUAL_SHA256="$(sha256sum "${ARCHIVE_PATH}" | awk '{ print $1 }')"
if [[ "${ACTUAL_SHA256}" != "${EXPECTED_SHA256}" ]]; then
	echo "::error::OpenCode archive checksum mismatch" >&2
	exit 1
fi

ARCHIVE_LIST="${TEMP_DIR}/archive.list"
ARCHIVE_DETAILS="${TEMP_DIR}/archive.details"
if ! tar -tzf "${ARCHIVE_PATH}" >"${ARCHIVE_LIST}" || ! tar -tvzf "${ARCHIVE_PATH}" >"${ARCHIVE_DETAILS}"; then
	echo "::error::OpenCode archive could not be inspected" >&2
	exit 1
fi
mapfile -t ARCHIVE_ENTRIES <"${ARCHIVE_LIST}"
if [[ "${#ARCHIVE_ENTRIES[@]}" -ne 1 || "${ARCHIVE_ENTRIES[0]}" != "opencode" ]]; then
	echo "::error::OpenCode archive must contain exactly one opencode entry" >&2
	exit 1
fi
ARCHIVE_DETAIL_LINE="$(head -n 1 "${ARCHIVE_DETAILS}")"
if [[ "${ARCHIVE_DETAIL_LINE:0:1}" != "-" || "${ARCHIVE_DETAIL_LINE}" != *" opencode" ]]; then
	echo "::error::OpenCode archive entry must be a regular file" >&2
	exit 1
fi

mkdir -p "${EXTRACT_DIR}"
tar -xzf "${ARCHIVE_PATH}" --no-same-owner --no-same-permissions -C "${EXTRACT_DIR}" opencode
if [[ ! -f "${EXTRACT_DIR}/opencode" || -L "${EXTRACT_DIR}/opencode" ]]; then
	echo "::error::OpenCode archive did not contain the expected regular file" >&2
	exit 1
fi
EXTRACTED_BYTES="$(stat -c '%s' "${EXTRACT_DIR}/opencode")"
if [[ "${EXTRACTED_BYTES}" -le 0 || "${EXTRACTED_BYTES}" -gt 268435456 ]]; then
	echo "::error::OpenCode archive extracted an unsupported binary size" >&2
	exit 1
fi

mkdir -p "${OPENCODE_INSTALL_DIR}"
INSTALL_DIR="$(cd "${OPENCODE_INSTALL_DIR}" && pwd -P)"
FINAL_BIN="${INSTALL_DIR}/opencode"
if [[ -d "${FINAL_BIN}" && ! -L "${FINAL_BIN}" ]]; then
	echo "::error::OpenCode install target is a directory" >&2
	exit 1
fi
FINAL_TMP="$(mktemp "${INSTALL_DIR}/.opencode.XXXXXX")"
install -m 0755 "${EXTRACT_DIR}/opencode" "${FINAL_TMP}"
VERSION_TMPDIR="${TEMP_DIR}/version-tmp"
mkdir -p "${VERSION_TMPDIR}"
if ! VERSION_OUTPUT="$(TMPDIR="${VERSION_TMPDIR}" OPENCODE_EXPECTED_VERSION="${OPENCODE_VERSION#v}" "${FINAL_TMP}" --version)"; then
	echo "::error::Downloaded OpenCode binary failed its version check" >&2
	exit 1
fi
if [[ "${VERSION_OUTPUT}" != "${OPENCODE_VERSION#v}" ]]; then
	echo "::error::Downloaded OpenCode binary reported an unexpected version" >&2
	exit 1
fi
mv -fT -- "${FINAL_TMP}" "${FINAL_BIN}"
FINAL_TMP=""
echo "OpenCode installed at: ${FINAL_BIN}"
if [[ -n "${GITHUB_PATH:-}" ]]; then
	printf '%s\n' "${INSTALL_DIR}" >>"${GITHUB_PATH}"
fi

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	git config user.name "${GIT_USER_NAME:-performance-optimisation[bot]}"
	git config user.email "${GIT_USER_EMAIL:-performance-optimisation[bot]@users.noreply.github.com}"
fi
mkdir -p .opencode

echo "::endgroup::"
echo "OpenCode setup complete."
