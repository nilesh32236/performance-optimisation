#!/usr/bin/env python3
"""Collect and compare public growth signals for Performance Optimisation.

The collector reads only the public WordPress.org plugin API and public or
owner-scoped GitHub repository metadata. It never reads WordPress visitor data,
site settings, cookies, or authenticated application traffic.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import statistics
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
METRICS_DIR = ROOT / "docs" / "growth" / "metrics"
REPORT = ROOT / "docs" / "growth" / "METRICS.md"
BASELINE = METRICS_DIR / "baseline.json"
LATEST = METRICS_DIR / "latest.json"
ALERT = METRICS_DIR / "alert.json"
PLUGIN_SLUG = "performance-optimisation"
REPO = "nilesh32236/performance-optimisation"
PLUGIN_API = "https://api.wordpress.org/plugins/info/1.2/"

FEATURES = {
    "Page Cache": "page-cache",
    "CSS": "minify-combine",
    "JS": "defer-delay-js",
    "HTML": "minify-combine",
    "Images": "images-webp-avif",
    "WebP": "images-webp-avif",
    "AVIF": "images-webp-avif",
    "Lazy Load": "images-webp-avif",
    "Core Web Vitals": "core-web-vitals",
    "PageSpeed": "monitoring-rum",
    "RUM": "monitoring-rum",
    "Redis": "redis-object-cache",
    "Database": "database-cleanup",
    "WooCommerce": "troubleshooting",
    "LiteSpeed": "litespeed",
    "CDN": "cdn",
    "Critical CSS": "used-critical-css",
    "Used CSS": "used-critical-css",
}

SEARCH_INTENTS = {
    "enable page cache": ("page cache", "page-cache", "Page Cache"),
    "improve LCP": ("lcp", "core-web-vitals", "Critical Assets Preloading"),
    "improve Core Web Vitals": ("core web vitals", "core-web-vitals", "Performance Audit"),
    "optimize images": ("image optimization", "images-webp-avif", "Image Optimization"),
    "convert images to WebP": ("webp", "images-webp-avif", "Image Optimization"),
    "optimize CSS": ("css", "minify-combine", "File Optimization"),
    "optimize JavaScript": ("javascript", "defer-delay-js", "File Optimization"),
    "lazy load images": ("lazy", "images-webp-avif", "Image Optimization"),
    "enable Redis": ("redis", "redis-object-cache", "Object Cache"),
    "configure CDN": ("cdn", "cdn", "File Optimization / Edge Cache"),
    "critical CSS missing styles": ("critical css", "used-critical-css", "Used and Critical CSS"),
    "WooCommerce cart cache": ("woocommerce", "troubleshooting", "WooCommerce safe mode"),
    "LiteSpeed coexistence": ("litespeed", "litespeed", "LiteSpeed mode"),
    "site broken after optimization": ("unsaved", "troubleshooting", "Disable narrow feature and clear cache"),
}

CHANGE_IMPACT = [
    {"date": "2026-09-25", "change": "Phase E documentation consolidation", "why": "Reduce beginner friction and improve search coverage", "causality": "Unknown; public baseline and later snapshots required"},
    {"date": "2026-09-25", "change": "Phase F trust and conversion", "why": "Clarify free/open-source value, safe onboarding, and support recovery", "causality": "Unknown; no causal claim"},
    {"date": "2026-09-25", "change": "Phase D feature discoverability", "why": "Map tasks to existing controls", "causality": "Unknown; no causal claim"},
]


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def fetch_json(url: str, token: str | None = None) -> Any:
    headers = {"Accept": "application/vnd.github+json", "User-Agent": "performance-optimisation-growth-monitor"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    request = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))


def safe_call(url: str, token: str | None = None) -> tuple[Any, str | None]:
    try:
        return fetch_json(url, token), None
    except (urllib.error.URLError, TimeoutError, ValueError) as error:
        return None, type(error).__name__


def wordpress_metrics() -> tuple[dict[str, Any], list[str]]:
    query = urllib.parse.urlencode({"action": "plugin_information", "request[slug]": PLUGIN_SLUG})
    try:
        data = fetch_json(f"{PLUGIN_API}?{query}")
        unavailable: list[str] = []
        if data.get("downloaded") is None:
            unavailable.append("WordPress.org downloads unavailable")
        return {
            "version": data.get("version"),
            "requires_wordpress": data.get("requires"),
            "tested_wordpress": data.get("tested"),
            "requires_php": data.get("requires_php"),
            "rating_score": data.get("rating"),
            "rating_count": data.get("num_ratings"),
            "support_threads": data.get("support_threads"),
            "active_installs": data.get("active_installs"),
            "downloads": data.get("downloaded"),
            "last_updated": data.get("last_updated"),
            "added": data.get("added"),
        }, unavailable
    except Exception as error:  # pragma: no cover - network failure path
        return {"error": type(error).__name__}, ["WordPress.org API unavailable"]


def github_repo_metrics(token: str | None) -> tuple[dict[str, Any], list[str]]:
    base = f"https://api.github.com/repos/{REPO}"
    data, repo_error = safe_call(base, token)
    if data is None:
        return {"error": repo_error}, ["GitHub repository API unavailable"]
    unavailable: list[str] = []
    releases, rel_error = safe_call(f"{base}/releases?per_page=10", token)
    if releases is None:
        releases = []
        unavailable.append("GitHub releases unavailable")
    open_issues, issue_error = safe_call(
        f"https://api.github.com/search/issues?q=repo%3A{urllib.parse.quote(REPO)}%20is%3Aissue%20is%3Aopen",
        token,
    )
    open_prs, pr_error = safe_call(
        f"https://api.github.com/search/issues?q=repo%3A{urllib.parse.quote(REPO)}%20is%3Apr%20is%3Aopen",
        token,
    )
    if issue_error:
        unavailable.append("GitHub open issue count unavailable")
    if pr_error:
        unavailable.append("GitHub open PR count unavailable")
    traffic_views, view_error = safe_call(f"{base}/traffic/views", token)
    traffic_clones, clone_error = safe_call(f"{base}/traffic/clones", token)
    if view_error or clone_error:
        unavailable.append("GitHub traffic requires owner-scoped API access")
    latest_release = releases[0] if releases else None
    release_dates = [r.get("published_at") for r in releases if r.get("published_at")]
    release_gaps = []
    for older, newer in zip(release_dates[1:], release_dates[:-1]):
        release_gaps.append((dt.datetime.fromisoformat(newer.replace("Z", "+00:00")) - dt.datetime.fromisoformat(older.replace("Z", "+00:00"))).total_seconds() / 86400)
    return {
        "stars": data.get("stargazers_count"),
        "forks": data.get("forks_count"),
        "open_issues_search": open_issues.get("total_count") if open_issues else None,
        "open_prs_search": open_prs.get("total_count") if open_prs else None,
        "updated_at": data.get("updated_at"),
        "pushed_at": data.get("pushed_at"),
        "latest_release": latest_release.get("tag_name") if latest_release else None,
        "latest_release_at": latest_release.get("published_at") if latest_release else None,
        "release_count_recent": len(releases),
        "release_gap_days_median": round(statistics.median(release_gaps), 2) if release_gaps else None,
        "traffic_views_14d": traffic_views.get("count") if traffic_views else None,
        "traffic_view_uniques_14d": traffic_views.get("uniques") if traffic_views else None,
        "traffic_clones_14d": traffic_clones.get("count") if traffic_clones else None,
        "traffic_clone_uniques_14d": traffic_clones.get("uniques") if traffic_clones else None,
    }, unavailable


def manifest_slugs() -> set[str]:
    path = ROOT / "docs" / "site" / "manifest.json"
    try:
        return {item["slug"] for item in json.loads(path.read_text())}
    except (OSError, ValueError, KeyError):
        return set()


def coverage_metrics() -> dict[str, Any]:
    slugs = manifest_slugs()
    readme = (ROOT / "readme.txt").read_text(errors="ignore").lower() if (ROOT / "readme.txt").exists() else ""
    features = {name: {"doc_slug": slug, "covered": slug in slugs, "readme_term": term.lower() in readme} for name, (slug, term) in {k: (v, k.lower()) for k, v in FEATURES.items()}.items()}
    # Use explicit terms where product spelling differs from the heading.
    terms = {
        "Page Cache": "page caching", "CSS": "css", "JS": "javascript", "HTML": "html", "Images": "image optimization",
        "WebP": "webp", "AVIF": "avif", "Lazy Load": "lazy", "Core Web Vitals": "core web vitals", "PageSpeed": "pagespeed",
        "RUM": "rum", "Redis": "redis", "Database": "database", "WooCommerce": "woocommerce", "LiteSpeed": "litespeed",
        "CDN": "cdn", "Critical CSS": "critical css", "Used CSS": "used css",
    }
    for name, item in features.items():
        item["readme_term"] = terms[name] in readme
    intents = {}
    for intent, (term, slug, product) in SEARCH_INTENTS.items():
        intents[intent] = {"readme_term": term.lower() in readme, "doc_slug": slug, "doc_covered": slug in slugs, "product_feature": product, "uncovered": not (term.lower() in readme and slug in slugs)}
    return {"features": features, "search_intents": intents}


def collect(token: str | None) -> dict[str, Any]:
    wordpress, wp_unavailable = wordpress_metrics()
    github, gh_unavailable = github_repo_metrics(token)
    return {
        "schema": 1,
        "captured_at": utc_now(),
        "privacy": "Public WordPress.org and GitHub repository data only; no WordPress visitor or site-owner data collected.",
        "wordpress_org": wordpress,
        "github": github,
        "coverage": coverage_metrics(),
        "operational": {
            "release_frequency": "Derived from the last 10 GitHub releases",
            "regression_count": "Unavailable: not yet exported as a structured historical series",
            "issue_to_merge_time": "Unavailable: current repository snapshot has no issue-to-merge event history in this report",
            "merge_to_release_time": "Unavailable: current release list has no merge-event correlation",
            "verification_failures": "Unavailable: not yet exported as a structured historical series",
            "playwright_failures": "Unavailable: not yet exported as a structured historical series",
        },
        "unavailable": wp_unavailable + gh_unavailable,
        "change_impact": CHANGE_IMPACT,
    }


def read_json(path: Path) -> dict[str, Any] | None:
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError):
        return None


def number_delta(current: Any, previous: Any) -> Any:
    if isinstance(current, (int, float)) and isinstance(previous, (int, float)):
        return current - previous
    return None


def material_reasons(previous: dict[str, Any] | None, current: dict[str, Any]) -> list[str]:
    if not previous:
        return []
    reasons: list[str] = []
    old_wp, new_wp = previous.get("wordpress_org", {}), current.get("wordpress_org", {})
    old_gh, new_gh = previous.get("github", {}), current.get("github", {})
    checks = [
        ("rating_score", 0.5, "WordPress.org rating fell by 0.5 or more"),
        ("support_threads", 5, "WordPress.org support threads rose by 5 or more"),
        ("active_installs", 100, "WordPress.org active installs rose by 100 or more"),
        ("stars", 5, "GitHub stars rose by 5 or more"),
        ("open_issues_search", 10, "GitHub open issues rose by 10 or more"),
    ]
    for key, threshold, reason in checks:
        old = old_wp.get(key) if key in old_wp else old_gh.get(key)
        new = new_wp.get(key) if key in new_wp else new_gh.get(key)
        delta = number_delta(new, old)
        if delta is not None and abs(delta) >= threshold:
            reasons.append(reason)
    if old_wp.get("version") != new_wp.get("version"):
        reasons.append("WordPress.org listing version changed")
    if old_wp.get("last_updated") != new_wp.get("last_updated"):
        reasons.append("WordPress.org last-updated timestamp changed")
    if old_gh.get("traffic_views_14d") is not None and new_gh.get("traffic_views_14d") is not None:
        old_views, new_views = old_gh["traffic_views_14d"], new_gh["traffic_views_14d"]
        if old_views >= 50 and abs(new_views - old_views) / old_views >= 0.25:
            reasons.append("GitHub 14-day views changed by 25% or more")
    return reasons


def delta_line(label: str, old: Any, new: Any) -> str:
    delta = number_delta(new, old)
    if delta is None:
        return f"| {label} | {new if new is not None else 'Unavailable'} | {old if old is not None else 'Baseline'} | N/A |"
    return f"| {label} | {new} | {old} | {delta:+} |"


def render_report(current: dict[str, Any], previous: dict[str, Any] | None, reasons: list[str]) -> str:
    wp, gh = current["wordpress_org"], current["github"]
    old_wp = previous.get("wordpress_org", {}) if previous else {}
    old_gh = previous.get("github", {}) if previous else {}
    lines = [
        "# Phase G — Measurement & Growth Monitoring",
        "",
        f"Captured: `{current['captured_at']}`",
        "",
        "This report uses public WordPress.org and GitHub repository metadata. It does not collect WordPress visitors, site-owner settings, cookies, or private application traffic.",
        "",
        "## Public product metrics",
        "",
        "| Metric | Current | Previous | Delta |",
        "| --- | ---: | ---: | ---: |",
        delta_line("WordPress.org active installs", old_wp.get("active_installs"), wp.get("active_installs")),
        delta_line("WordPress.org downloads", old_wp.get("downloads"), wp.get("downloads")),
        delta_line("WordPress.org rating score", old_wp.get("rating_score"), wp.get("rating_score")),
        delta_line("WordPress.org rating count", old_wp.get("rating_count"), wp.get("rating_count")),
        delta_line("WordPress.org support threads", old_wp.get("support_threads"), wp.get("support_threads")),
        f"| WordPress.org version | {wp.get('version', 'Unavailable')} | {old_wp.get('version', 'Baseline')} | N/A |",
        f"| WordPress.org last updated | {wp.get('last_updated', 'Unavailable')} | {old_wp.get('last_updated', 'Baseline')} | N/A |",
        delta_line("GitHub stars", old_gh.get("stars"), gh.get("stars")),
        delta_line("GitHub forks", old_gh.get("forks"), gh.get("forks")),
        delta_line("GitHub open issues", old_gh.get("open_issues_search"), gh.get("open_issues_search")),
        delta_line("GitHub open PRs", old_gh.get("open_prs_search"), gh.get("open_prs_search")),
        delta_line("GitHub 14-day views", old_gh.get("traffic_views_14d"), gh.get("traffic_views_14d")),
        delta_line("GitHub 14-day view uniques", old_gh.get("traffic_view_uniques_14d"), gh.get("traffic_view_uniques_14d")),
        delta_line("GitHub 14-day clones", old_gh.get("traffic_clones_14d"), gh.get("traffic_clones_14d")),
        delta_line("GitHub 14-day clone uniques", old_gh.get("traffic_clone_uniques_14d"), gh.get("traffic_clone_uniques_14d")),
        "",
        "## Operational metrics",
        "",
        f"- Latest release: `{gh.get('latest_release') or 'Unavailable'}` ({gh.get('latest_release_at') or 'Unavailable'}).",
        f"- Median release gap across the last 10 releases: `{gh.get('release_gap_days_median') if gh.get('release_gap_days_median') is not None else 'Unavailable'}` days.",
        "- Regression count, issue-to-merge time, merge-to-release time, structured verification failures, and Playwright failure history are not yet exported as series.",
        "",
        "## Feature coverage",
        "",
        "| Feature | Documentation | Readme terminology |",
        "| --- | --- | --- |",
    ]
    for name, item in current["coverage"]["features"].items():
        lines.append(f"| {name} | {'Covered' if item['covered'] else 'Gap'} ({item['doc_slug']}) | {'Present' if item['readme_term'] else 'Gap'} |")
    lines += ["", "## Searchability coverage", "", "| User intent | Readme | Documentation | Product feature | Status |", "| --- | --- | --- | --- | --- |"]
    for intent, item in current["coverage"]["search_intents"].items():
        status = "Covered" if not item["uncovered"] else "Gap"
        lines.append(f"| {intent} | {'Yes' if item['readme_term'] else 'No'} | {'Yes' if item['doc_covered'] else 'No'} | {item['product_feature']} | {status} |")
    lines += ["", "## Change impact", "", "The following changes are tracked for later comparison. Movement after a change does not establish causation.", "", "| Date | Change | Why | Causality |", "| --- | --- | --- | --- |"]
    for item in current["change_impact"]:
        lines.append(f"| {item['date']} | {item['change']} | {item['why']} | {item['causality']} |")
    lines += ["", "## Unavailable metrics and limits", ""]
    for item in current.get("unavailable", []):
        lines.append(f"- {item}")
    if not current.get("unavailable"):
        lines.append("- None in the current collection run.")
    lines += ["", "## Material-change gate", "", "The scheduled workflow creates an issue only for sustained listing changes, rating/support regressions, GitHub issue growth, release changes, or a 25% traffic movement on a meaningful baseline. It does not create alerts for daily noise.", ""]
    if reasons:
        lines += ["Current material reasons:", ""] + [f"- {reason}" for reason in reasons] + [""]
    else:
        lines += ["Current run: no material change from the previous snapshot.", ""]
    lines += ["## Privacy boundary", "", "The collector sends no WordPress data to an external analytics service. GitHub traffic is aggregate owner-scoped repository data returned by the GitHub API; no visitor identities or URLs are stored.", ""]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--initialize", action="store_true", help="write the first baseline without comparing")
    args = parser.parse_args()
    METRICS_DIR.mkdir(parents=True, exist_ok=True)
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    current = collect(token)
    previous = read_json(LATEST)
    if args.initialize:
        BASELINE.write_text(json.dumps(current, indent=2) + "\n")
        previous = None
    reasons = material_reasons(previous, current)
    LATEST.write_text(json.dumps(current, indent=2) + "\n")
    REPORT.write_text(render_report(current, previous, reasons))
    if reasons:
        ALERT.write_text(json.dumps({"captured_at": current["captured_at"], "reasons": reasons}, indent=2) + "\n")
    elif ALERT.exists():
        ALERT.unlink()
    print(json.dumps({"captured_at": current["captured_at"], "material": bool(reasons), "reasons": reasons, "report": str(REPORT)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
