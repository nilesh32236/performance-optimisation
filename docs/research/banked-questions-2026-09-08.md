# Banked questions (asked at end of batch, per user instruction)
1. removeQueryStrings end-state: hard-delete toggle after 2-release deprecation window, or keep hidden-legacy indefinitely?
2. Orphaned REST routes: any known external consumers of crawler/crawler_status/get_page_assets (integrations, tutorials) before removal?
3. Auto-revert v1: notify-only acceptable first release, or require actual auto-restore above regression threshold? (plan: notify-only)
4. Host detection policy: on Kinsta/WPE-class host, auto-disable our static page cache by default or notify-only with opt-in? (plan: notify-only)
