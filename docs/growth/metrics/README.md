# Growth metrics snapshots

- `baseline.json` is the first reproducible Phase G snapshot.
- `latest.json` is the most recent scheduled snapshot.
- `alert.json` appears only when the comparison gate finds a material change. The workflow removes it on a clean run.

Run the collector from the repository root:

```bash
python3 scripts/growth-monitor.py
```

The collector reads the public WordPress.org plugin API and GitHub repository API. Set `GITHUB_TOKEN` in CI to include owner-scoped aggregate GitHub traffic. The workflow supplies `github.token` and does not send WordPress data anywhere.
