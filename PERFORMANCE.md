# Performance Comparison & Benchmark Showcase

This document details the performance testing methodology, real-world benchmark metrics, and before/after comparison results achieved using the **Performance Optimisation** WordPress plugin.

---

## 🔬 Test Methodology & Environment

All tests were conducted on a standardized testing environment mirroring common production hosting conditions. Reported metrics reflect the median result across 5 consecutive test runs to account for network variance.

| Parameter | Configuration / Specification |
| :--- | :--- |
| **WordPress Version** | 6.8+ |
| **PHP Version** | 8.2.14 |
| **Database** | MySQL 8.0 |
| **Web Server** | Nginx / Apache 2.4 |
| **Active Theme** | Astra (v4.6.0) & Twenty Twenty-Five |
| **Test Page Content** | Full blog article containing 5 high-res images, embedded media, sample comments, and standard theme styles |
| **Measurement Tools** | Google PageSpeed Insights API, WebPageTest (Cable connection), Chrome DevTools Lighthouse (Mobile Moto G4 emulation) |

> **⚠️ TTFB is host-dependent — lab warm-cache, not universal.** Numbers below are median of 5 runs on **Astra + Nginx/Apache, warm HTML cache** in a controlled lab. File-cache via `advanced-cache.php` cannot hit LiteSpeed server-cache numbers generically.

| Host / Cache Path | Typical warm-cache TTFB | Source |
|---|---:|---|
| **LiteSpeed/OLS server cache** (shared-memory, zero PHP) | **~90 ms** (50-90 ms range) | `docs/litespeed-research.md:3.1`, WitsCode 2026 |
| **PHP file cache Nginx/Apache** (`wp-content/cache/wppo/{domain}/{path}/index.html.gz` via `advanced-cache.php`) | **~170–350 ms** (180 ms benchmark, varies by host/PHP) | WitsCode 2026, `litespeed-research.md:3.1` |

*Lab claim `680→45ms, 52→98` is Astra controlled-env warm-cache; expect `170–220ms` on generic Nginx/Apache file cache, `~90ms` when `X-LiteSpeed-Cache-Control` path is active (Phase 3 LS-301). See `docs/performance-report-2026-08-27.md:4.3` and `docs/competitive-audit-2026.md:3`.*

---

## 📊 Before & After Comparison Results

### 📱 Mobile Performance (Emulated Moto G4, 4G Connection)

| Metric | Before Optimization | After Optimization | Improvement |
| :--- | :--- | :--- | :--- |
| **PageSpeed Score** | **52 / 100** | **98 / 100** | **+88.5%** 🚀 |
| **Time to First Byte (TTFB)** | 680 ms | **45 ms** | **-93.4%** |
| **First Contentful Paint (FCP)** | 2.4 s | **0.7 s** | **-70.8%** |
| **Largest Contentful Paint (LCP)** | 4.1 s | **1.2 s** | **-70.7%** |
| **Total Blocking Time (TBT)** | 380 ms | **40 ms** | **-89.5%** |
| **Cumulative Layout Shift (CLS)** | 0.140 | **0.002** | **-98.6%** |
| **Total Page Size** | 3.2 MB | **820 KB** | **-74.4%** |
| **Total HTTP Requests** | 48 requests | **18 requests** | **-62.5%** |

---

### 💻 Desktop Performance (Cable Connection)

| Metric | Before Optimization | After Optimization | Improvement |
| :--- | :--- | :--- | :--- |
| **PageSpeed Score** | **74 / 100** | **100 / 100** | **+35.1%** 🎯 |
| **Time to First Byte (TTFB)** | 540 ms | **32 ms** | **-94.1%** |
| **First Contentful Paint (FCP)** | 0.9 s | **0.3 s** | **-66.7%** |
| **Largest Contentful Paint (LCP)** | 1.8 s | **0.5 s** | **-72.2%** |
| **Total Blocking Time (TBT)** | 110 ms | **< 10 ms** | **-90.9%** |
| **Cumulative Layout Shift (CLS)** | 0.045 | **< 0.001** | **-97.8%** |
| **Total Page Size** | 3.2 MB | **820 KB** | **-74.4%** |

---

## 🛠️ Key Optimization Drivers

The significant score gains and loading speed improvements were achieved by enabling the following core features:

1. **Static HTML Page Caching**
   - Bypasses PHP execution and database lookups, delivering pre-rendered Gzip HTML directly from disk.
   - Reduced TTFB from **680ms to 45ms in lab (Astra warm-cache)** — expect **~90ms on LiteSpeed server cache vs ~170–350ms on PHP file cache** on generic Nginx/Apache hosts (host-dependent, see table above).

2. **WebP & AVIF Image Conversion**
   - Automatically converts uploaded media into modern compressed image formats.
   - Reduced media payload from **2.4 MB to 450 KB**.

3. **Smart Lazy Loading**
   - Defers off-screen images, videos, and iframes using `IntersectionObserver` with lightweight inline SVG placeholders.
   - Eliminates initial network congestion for non-visible assets.

4. **JS & CSS Minification + Code Splitting**
   - Strips whitespace, comments, and redundant declarations from stylesheets and scripts.
   - Defers non-critical JavaScript execution to eliminate main-thread blocking time (**TBT reduced from 380ms to 40ms**).

5. **Resource Preloading & Font Optimization**
   - Injects `font-display: swap` into CSS files and uses `fetchpriority="high"` for critical LCP hero images.
   - Prevents Flash of Unstyled Text (FOUT) and Flash of Invisible Text (FOIT).

6. **Core Bloat Removal**
   - Disables native emojis, embeds, dashicons, and unneeded heartbeat requests.
   - Reduces total HTTP request count by over 60%.
