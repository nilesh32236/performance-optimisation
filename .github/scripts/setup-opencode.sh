#!/usr/bin/env bash
# setup-opencode.sh
# Installs and configures OpenCode for use in GitHub Actions workflows.
#
# Usage:
#   source setup-opencode.sh
#   # Then run: opencode run --model <model> --prompt "<prompt>"
#
# Environment:
#   GITHUB_TOKEN — required for GitHub API access
#   OPENCODE_VERSION — optional, defaults to "latest"

set -euo pipefail

OPENCODE_VERSION="${OPENCODE_VERSION:-latest}"

echo "::group::Setting up OpenCode ${OPENCODE_VERSION}"

ARCH="linux-x64"
case "$(uname -m)" in
  aarch64|arm64) ARCH="linux-arm64" ;;
  x86_64|amd64)  ARCH="linux-x64" ;;
esac

echo "Downloading opencode ${OPENCODE_VERSION} (${ARCH})..."

DOWNLOAD_URL=""
if [ "$OPENCODE_VERSION" = "latest" ]; then
  RELEASE_URL="https://api.github.com/repos/anomalyco/opencode/releases/latest"
  RELEASE_JSON=$(curl -fsSL "$RELEASE_URL" 2>/dev/null || echo '{"message":"API error"}')
  if echo "$RELEASE_JSON" | jq -e '.message' >/dev/null 2>&1; then
    echo "Error: GitHub API returned: $(echo "$RELEASE_JSON" | jq -r '.message')" >&2
    exit 1
  fi
  DOWNLOAD_URL=$(echo "$RELEASE_JSON" | jq -r '.assets[] | select(.name == "opencode-'"${ARCH}"'.tar.gz") | .browser_download_url')
else
  RELEASE_URL="https://api.github.com/repos/anomalyco/opencode/releases/tags/${OPENCODE_VERSION}"
  RELEASE_JSON=$(curl -fsSL "$RELEASE_URL" 2>/dev/null || echo '{"message":"API error"}')
  if echo "$RELEASE_JSON" | jq -e '.message' >/dev/null 2>&1; then
    echo "Error: GitHub API returned: $(echo "$RELEASE_JSON" | jq -r '.message')" >&2
    exit 1
  fi
  DOWNLOAD_URL=$(echo "$RELEASE_JSON" | jq -r '.assets[] | select(.name == "opencode-'"${ARCH}"'.tar.gz") | .browser_download_url')
fi

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" = "null" ]; then
  echo "Error: Could not find opencode binary for ${ARCH}" >&2
  exit 1
fi

echo "Downloading from: ${DOWNLOAD_URL}"
curl -fsSL "$DOWNLOAD_URL" -o /tmp/opencode.tar.gz || {
  echo "Error: Failed to download opencode from ${DOWNLOAD_URL}" >&2
  exit 1
}
tar -xzf /tmp/opencode.tar.gz -C /usr/local/bin/
chmod +x /usr/local/bin/opencode
rm /tmp/opencode.tar.gz

opencode --version 2>&1 || true
echo "OpenCode installed at: $(which opencode)"

git config user.name "${GIT_USER_NAME:-performance-optimisation[bot]}"
git config user.email "${GIT_USER_EMAIL:-performance-optimisation[bot]@users.noreply.github.com}"

mkdir -p .opencode

echo "::endgroup::"
echo "OpenCode setup complete."
