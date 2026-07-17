#!/usr/bin/env bash
# check-latest-release.sh
# Checks current plugin version, latest GitHub release, and determines next version.
#
# Usage:
#   check-latest-release.sh <repo>
#
# Outputs to stdout (caller pipes to $GITHUB_OUTPUT):
#   current_version=<semver>
#   latest_release_tag=<tag>
#   latest_release_version=<semver>
#   next_version=<semver>
#   needs_release=true|false

set -euo pipefail

REPO="$1"

PLUGIN_FILE="performance-optimisation.php"
if [ ! -f "$PLUGIN_FILE" ]; then
  echo "Error: ${PLUGIN_FILE} not found in current directory" >&2
  exit 1
fi

CURRENT_VERSION=$(grep "^[[:space:]]*\* Version:" "$PLUGIN_FILE" | head -1 | awk '{print $NF}' | tr -d '\r')
echo "Current plugin version: ${CURRENT_VERSION}" >&2

LATEST_RELEASE=$(gh release list --repo "$REPO" --exclude-drafts --exclude-pre-releases --limit 1 --json tagName --jq '.[0].tagName // empty' 2>/dev/null || echo "")
LATEST_VERSION=""

if [ -n "$LATEST_RELEASE" ]; then
  LATEST_VERSION="${LATEST_RELEASE#v}"
  echo "Latest release tag: ${LATEST_RELEASE} (version: ${LATEST_VERSION})" >&2
else
  echo "No production release found" >&2
fi

CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
if [ -n "$LATEST_RELEASE" ]; then
  RELEASE_COMMIT=$(git rev-list -n 1 "$LATEST_RELEASE" 2>/dev/null || echo "")
  if [ -n "$RELEASE_COMMIT" ] && [ "$CURRENT_COMMIT" = "$RELEASE_COMMIT" ]; then
    echo "Current HEAD is the same as latest release. No changes to release." >&2
    echo "current_version=${CURRENT_VERSION}"
    echo "latest_release_tag=${LATEST_RELEASE}"
    echo "latest_release_version=${LATEST_VERSION}"
    echo "next_version="
    echo "needs_release=false"
    exit 0
  fi
fi

if [ -z "$LATEST_VERSION" ]; then
  NEXT_VERSION="$CURRENT_VERSION"
elif [ "$CURRENT_VERSION" != "$LATEST_VERSION" ]; then
  NEXT_VERSION="$CURRENT_VERSION"
else
  IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION" || true
  if [ -n "$MAJOR" ] && [ -n "$MINOR" ] && [ -n "$PATCH" ]; then
    PATCH=$((PATCH + 1))
    NEXT_VERSION="${MAJOR}.${MINOR}.${PATCH}"
  else
    echo "Error: Cannot parse version ${CURRENT_VERSION}" >&2
    exit 1
  fi
fi

echo "Next release version: ${NEXT_VERSION}" >&2

echo "current_version=${CURRENT_VERSION}"
echo "latest_release_tag=${LATEST_RELEASE}"
echo "latest_release_version=${LATEST_VERSION}"
echo "next_version=${NEXT_VERSION}"
echo "needs_release=true"
