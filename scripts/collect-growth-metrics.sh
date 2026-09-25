#!/usr/bin/env bash
#
# Collect evidence-based growth metrics from public sources only.
#
# Sources (all public, no visitor/site tracking):
#   - WordPress.org plugin information API (slug: performance-optimisation)
#   - WordPress.org listing page + asset URLs (HTTP status only)
#   - GitHub REST API (repo, releases; traffic only when owner token permits)
#   - Local repository evidence (readme.txt, includes/, src/, docs/)
#
# Output:
#   docs/growth/metrics/<YYYY-MM-DD>.json and docs/growth/metrics/latest.json
#
# The collector never exits nonzero on missing data: unavailable metrics are
# recorded as {"status": "unavailable", ...} per docs/growth/METRICS.md.
#
set -u
set -o pipefail

PLUGIN_SLUG="performance-optimisation"
GITHUB_REPO="${GITHUB_REPOSITORY:-nilesh32236/performance-optimisation}"
OUT_DIR="docs/growth/metrics"
DATE="$(date -u +%F)"
OUT_FILE="${OUT_DIR}/${DATE}.json"
LATEST_FILE="${OUT_DIR}/latest.json"
TMP_DIR="$(mktemp -d)"

cleanup() {
	rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

mkdir -p "${OUT_DIR}"

need() {
	command -v "$1" >/dev/null 2>&1 || {
		echo "collect-growth-metrics: required command '$1' not found" >&2
		return 1
	}
}

# jq is required for JSON emission; curl is required for remote fetches.
# When jq is missing we still emit a minimal unavailable snapshot so the
# scheduled job never fails the workflow on tooling drift.
if ! need jq; then
	printf '{"generated_at":"%s","status":"unavailable","reason":"jq not installed"}\n' "$(date -u +%FT%TZ)" >"${OUT_FILE}"
	cp "${OUT_FILE}" "${LATEST_FILE}"
	echo "collect-growth-metrics: jq missing, wrote unavailable snapshot" >&2
	exit 0
fi

fetch_json() {
	# $1 = URL, $2 = output file, $3 = optional "Authorization: Bearer ..." header.
	local url="$1"
	local out="$2"
	local auth="${3:-}"
	if ! command -v curl >/dev/null 2>&1; then
		return 1
	fi
	if [ -n "${auth}" ]; then
		curl -fsSL --max-time 30 -H "${auth}" \
			-H "Accept: application/vnd.github+json" \
			-H "User-Agent: wppo-growth-metrics" \
			"${url}" -o "${out}" 2>/dev/null
	else
		curl -fsSL --max-time 30 \
			-H "User-Agent: wppo-growth-metrics" \
			"${url}" -o "${out}" 2>/dev/null
	fi
}

http_status() {
	# $1 = URL. Prints "200", "404", or "unavailable". Never fails.
	local url="$1"
	if ! command -v curl >/dev/null 2>&1; then
		echo "unavailable"
		return 0
	fi
	local code
	code="$(curl -o /dev/null -s -w "%{http_code}" --max-time 20 -A "wppo-growth-metrics" "${url}" 2>/dev/null || echo "000")"
	case "${code}" in
		200|301|302) echo "${code}" ;;
		404) echo "404" ;;
		000) echo "unavailable" ;;
		*) echo "${code}" ;;
	esac
	return 0
}

# ---------------------------------------------------------------- WordPress.org
WP_API_URL="https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=${PLUGIN_SLUG}"
WP_RAW="${TMP_DIR}/wporg.json"
if fetch_json "${WP_API_URL}" "${WP_RAW}"; then
	WPORG_JSON="$(jq -c '{
		status: "ok",
		name: (.name // "unavailable"),
		slug: (.slug // "performance-optimisation"),
		version: (.version // "unavailable"),
		last_updated: (.last_updated // "unavailable"),
		active_installs: (.active_installs // "unavailable"),
		downloaded: (.downloaded // "unavailable"),
		rating_count: ((.ratings // []) | add // "unavailable"),
		rating_breakdown: (.ratings // "unavailable"),
		num_ratings: (.num_ratings // "unavailable"),
		support_threads: (.support_threads // "unavailable"),
		support_threads_resolved: (.support_threads_resolved // "unavailable")
	}' "${WP_RAW}")"
else
	WPORG_JSON='{"status":"unavailable","reason":"wp.org plugin-information API unreachable"}'
fi

# Rating score 0-100 as reported by the API ("rating" field) plus a 1-5
# weighted score derived from the 1-5 breakdown when present.
RATING_JSON="$(echo "${WPORG_JSON}" | jq -c '{
	status: (if .status == "ok" then "ok" else "unavailable" end),
	score_100: "unavailable",
	score_5: "unavailable"
}' 2>/dev/null || echo '{"status":"unavailable"}')"
if [ -f "${WP_RAW}" ]; then
	# The API returns either an array [n1..n5] or an object {"1":..,"5":..}.
	RATING_JSON="$(jq -c '{
		status: (if has("rating") then "ok" else "unavailable" end),
		score_100: (.rating // "unavailable"),
		score_5: (
			if (.ratings | type) == "array" and ((.ratings | length) == 5) then
				((.ratings[4]*5 + .ratings[3]*4 + .ratings[2]*3 + .ratings[1]*2 + .ratings[0]*1)
					/ ((.ratings | add | select(. > 0)) // 1))
			elif (.ratings | type) == "object" then
				((((.ratings["5"] // 0)*5 + (.ratings["4"] // 0)*4 + (.ratings["3"] // 0)*3
					+ (.ratings["2"] // 0)*2 + (.ratings["1"] // 0)*1)
					/ (((.ratings["5"] // 0) + (.ratings["4"] // 0) + (.ratings["3"] // 0)
						+ (.ratings["2"] // 0) + (.ratings["1"] // 0)) | select(. > 0) // 1)))
			else "unavailable" end
		)
	}' "${WP_RAW}" 2>/dev/null || echo '{"status":"unavailable"}')"
fi

LISTING_STATUS="$(http_status "https://wordpress.org/plugins/${PLUGIN_SLUG}/")"
BANNER_STATUS="$(http_status "https://ps.w.org/${PLUGIN_SLUG}/assets/banner-1544x500.png")"
ICON_STATUS="$(http_status "https://ps.w.org/${PLUGIN_SLUG}/assets/icon-256x256.png")"

# ---------------------------------------------------------------- GitHub repo
GH_API="https://api.github.com/repos/${GITHUB_REPO}"
GH_AUTH=""
if [ -n "${GITHUB_TOKEN:-}" ]; then
	GH_AUTH="Authorization: Bearer ${GITHUB_TOKEN}"
fi

GH_RAW="${TMP_DIR}/gh_repo.json"
if fetch_json "${GH_API}" "${GH_RAW}" "${GH_AUTH}"; then
	GITHUB_JSON="$(jq -c '{
		status: "ok",
		stars: (.stargazers_count // "unavailable"),
		forks: (.forks_count // "unavailable"),
		open_issues: (.open_issues_count // "unavailable"),
		default_branch: (.default_branch // "unavailable"),
		pushed_at: (.pushed_at // "unavailable")
	}' "${GH_RAW}")"
else
	GITHUB_JSON='{"status":"unavailable","reason":"GitHub repository API unreachable"}'
fi

REL_RAW="${TMP_DIR}/gh_releases.json"
if fetch_json "${GH_API}/releases?per_page=5" "${REL_RAW}" "${GH_AUTH}"; then
	RELEASES_JSON="$(jq -c '{
		status: "ok",
		count_sampled: (length),
		latest_tag: (.[0].tag_name // "unavailable"),
		latest_published_at: (.[0].published_at // "unavailable"),
		tags: ([.[].tag_name] | map(select(. != null)))
	}' "${REL_RAW}")"
else
	RELEASES_JSON='{"status":"unavailable","reason":"GitHub releases API unreachable"}'
fi

# Aggregate traffic endpoints require push access; record unavailable on 403/404.
TRAFFIC_RAW="${TMP_DIR}/gh_traffic.json"
if fetch_json "${GH_API}/traffic/views" "${TRAFFIC_RAW}" "${GH_AUTH}"; then
	TRAFFIC_JSON="$(jq -c '{status: "ok", views_count: (.count // "unavailable"), uniques: (.uniques // "unavailable")}' "${TRAFFIC_RAW}")"
else
	TRAFFIC_JSON='{"status":"unavailable","reason":"traffic API requires owner push access or is unreachable"}'
fi

# ---------------------------------------------------------------- Local repo evidence
PLUGIN_VERSION="$(grep -m1 "^ \\* Version:" performance-optimisation.php 2>/dev/null | awk '{print $NF}' || echo "unavailable")"
README_TAGS="$(grep -m1 "^Tags:" readme.txt 2>/dev/null | sed 's/^Tags:[[:space:]]*//' || echo "unavailable")"
README_STABLE="$(grep -m1 "^Stable tag:" readme.txt 2>/dev/null | awk '{print $NF}' || echo "unavailable")"

coverage_for() {
	# $1 = label, $2.. = grep -ri patterns (first match wins). Prints JSON row.
	local label="$1"
	shift
	local evidence="none"
	local target="none"
	local pat
	for pat in "$@"; do
		if grep -rqi --include="*.txt" --include="*.php" --include="*.js" -e "${pat}" readme.txt includes/ src/ 2>/dev/null; then
			evidence="readme+code"
			target="$(grep -rli --include="*.txt" --include="*.php" --include="*.js" -e "${pat}" readme.txt includes/ src/ 2>/dev/null | head -n 3 | jq -R . | jq -s -c .)"
			break
		fi
	done
	if [ "${evidence}" = "none" ]; then
		jq -n -c --arg f "${label}" '{feature: $f, status: "gap", evidence: "none"}'
	else
		jq -n -c --arg f "${label}" --argjson t "${target}" '{feature: $f, status: "covered", evidence: $t}'
	fi
}

COVERAGE_JSON="$({
	coverage_for "Page Cache" "page.cach" "static HTML cache";
	coverage_for "CSS" "minif.*css\|combine.*css\|used.css\|critical.css";
	coverage_for "JS" "defer.*js\|delay.*js\|minif.*js";
	coverage_for "HTML" "html.min\|html_min";
	coverage_for "Images" "image.optim\|lazy.?load\|picture";
	coverage_for "WebP" "webp";
	coverage_for "AVIF" "avif";
	coverage_for "Lazy Load" "lazy.?load\|loading=.lazy";
	coverage_for "Core Web Vitals" "core.web.vitals\|largest.contentful\|cumulative.layout\|interaction.to.next";
	coverage_for "PageSpeed" "pagespeed\|runPagespeed";
	coverage_for "RUM" "real.user\|_rum\b\|class-rum";
	coverage_for "Redis" "redis\|object.cache";
	coverage_for "Database" "database.cleanup\|db.cleanup\|CLEANUP_METHOD_MAP";
	coverage_for "WooCommerce" "woocommerce\|woo.detect\|wc-ajax";
	coverage_for "LiteSpeed" "litespeed\|lscache\|openlitespeed";
	coverage_for "CDN" "cdn\|cloudflare\|bunny\|varnish";
	coverage_for "Critical CSS" "critical.css\|critical_css\|ccss";
	coverage_for "Used CSS" "used.css\|used_css";
} | jq -s -c .)"

# Operational signals: best-effort gh CLI reads, all optional.
# Counts use the search API total (bounded single request); listing commands
# are capped at 5 items for merge/release timing evidence.
OPS_ISSUES="unavailable"
OPS_PRS="unavailable"
OPS_CI="unavailable"
if command -v gh >/dev/null 2>&1; then
	ISSUE_TOTAL="$(gh api "search/issues?q=repo:${GITHUB_REPO}+state:open" --jq '.total_count' 2>/dev/null || echo "")"
	[ -n "${ISSUE_TOTAL}" ] && OPS_ISSUES="${ISSUE_TOTAL}"
	PR_TOTAL="$(gh api "search/issues?q=repo:${GITHUB_REPO}+is:pr+state:open" --jq '.total_count' 2>/dev/null || echo "")"
	[ -n "${PR_TOTAL}" ] && OPS_PRS="${PR_TOTAL}"
	CI_LINE="$(gh run list --limit 5 --json conclusion,name,createdAt -q '.' 2>/dev/null | head -c 2000 || echo "")"
	[ -n "${CI_LINE}" ] && OPS_CI="${CI_LINE}"
fi
if [ "${OPS_CI}" != "unavailable" ]; then
	OPS_CI_JSON="$(echo "${OPS_CI}" | jq -c '.' 2>/dev/null || echo '"unavailable"')"
else
	OPS_CI_JSON='"unavailable"'
fi

# ---------------------------------------------------------------- Emit snapshot
jq -n \
	--arg generated_at "$(date -u +%FT%TZ)" \
	--arg slug "${PLUGIN_SLUG}" \
	--arg repo "${GITHUB_REPO}" \
	--argjson wporg "${WPORG_JSON}" \
	--argjson rating "${RATING_JSON}" \
	--arg listing_status "${LISTING_STATUS}" \
	--arg banner_status "${BANNER_STATUS}" \
	--arg icon_status "${ICON_STATUS}" \
	--argjson github "${GITHUB_JSON}" \
	--argjson releases "${RELEASES_JSON}" \
	--argjson traffic "${TRAFFIC_JSON}" \
	--arg plugin_version "${PLUGIN_VERSION}" \
	--arg readme_tags "${README_TAGS}" \
	--arg readme_stable "${README_STABLE}" \
	--argjson coverage "${COVERAGE_JSON}" \
	--arg open_issues_local "${OPS_ISSUES}" \
	--arg open_prs_local "${OPS_PRS}" \
	--argjson recent_runs "${OPS_CI_JSON}" \
	'{
		schema: "docs/growth/metrics.schema.json",
		generated_at: $generated_at,
		slug: $slug,
		repository: $repo,
		privacy: "public sources only; no visitor, owner, or authenticated site data collected",
		wordpress_org: ($wporg + {
			rating: $rating,
			listing_http: $listing_status,
			assets_http: { banner_1544x500: $banner_status, icon_256x256: $icon_status }
		}),
		github: ($github + { releases: $releases, traffic: $traffic }),
		repository_evidence: {
			status: "ok",
			plugin_version: $plugin_version,
			readme_tags: $readme_tags,
			readme_stable_tag: $readme_stable
		},
		coverage: $coverage,
		operational: {
			open_issues_cli: $open_issues_local,
			open_prs_cli: $open_prs_local,
			recent_workflow_runs: $recent_runs,
			status_note: "best-effort gh CLI evidence; unavailable outside authenticated runners"
		},
		limits: [
			"Public listing and repository data only; no ranking, conversion, or causality claims.",
			"Daily fluctuations are noise; alerts use sustained thresholds in docs/growth/METRICS.md.",
			"Traffic aggregates are unavailable without owner push access and are recorded as unavailable.",
			"Change-impact language must not infer causality from post-change movement."
		]
	}' >"${OUT_FILE}"

cp "${OUT_FILE}" "${LATEST_FILE}"
echo "collect-growth-metrics: wrote ${OUT_FILE} and ${LATEST_FILE}"
exit 0
