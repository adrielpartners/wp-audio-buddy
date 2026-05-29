# WP Audio Buddy — Monorepo

> Transcribe audio attachments with AI and generate reusable excerpts for WordPress.

**Canonical repository:** [github.com/adrielpartners/wp-audio-buddy](https://github.com/adrielpartners/wp-audio-buddy)

## Structure

```
wp-audio-buddy/
├── plugin/          # WordPress plugin (wp-audio-buddy.php)
│   ├── src/         # PHP application code
│   ├── admin/       # Admin views & assets
│   ├── config/      # Plugin configuration
│   ├── vendor/      # Composer dependencies
│   └── tests-plugin/# PHPUnit tests
├── worker/          # Private worker service (Python/FastAPI)
│   ├── app/         # FastAPI application
│   ├── tests/       # Python test suite
│   ├── Dockerfile   # Worker Docker image
│   └── docker-compose.yml
├── docs/            # Documentation
│   ├── plugin/      # Plugin architecture & decisions
│   └── worker/      # Worker architecture & decisions
├── scripts/         # Build & deploy utilities
└── dist/            # Build output (gitignored)
```

## Quick Start

### Plugin (WordPress)

1. Copy `plugin/` to your WordPress `wp-content/plugins/wp-audio-buddy/` directory.
2. Run `cd plugin && composer install` to install PHP dependencies.
3. Activate the plugin from the WordPress admin.

### Plugin Zip Build

```bash
bash scripts/build-plugin-zip.sh
```

Output: `dist/wp-audio-buddy.zip` (a clean WordPress plugin zip from `plugin/` only).

### Worker (Docker)

```bash
bash scripts/deploy-worker.sh build    # Build image
bash scripts/deploy-worker.sh up       # Start services
bash scripts/deploy-worker.sh down     # Stop services
```

Or manually:

```bash
cd worker
docker compose up -d
```

## Previous Repositories

| Component | Old Repo | Status |
|-----------|----------|--------|
| Plugin | `github.com/adrielpartners/wp-audio-buddy` | **Active** (canonical, now monorepo) |
| Worker | `github.com/adrielpartners/wpab-worker` | **Archived** — code lives at `worker/` in this repo |

## Development

See `docs/` for architecture, decisions, and implementation plans for each component.