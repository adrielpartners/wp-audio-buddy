# WP Audio Buddy

**Version:** 0.2.0  
**License:** GPL-2.0-or-later  
**Requires PHP:** 8.0+  
**Requires WordPress:** 6.x  

A native WordPress plugin that uses AI to transcribe, summarize, and excerpt uploaded audio files. Supports both local WordPress processing and optional offloading to a companion `wpab-worker` service for large files.

---

## Features

- Transcribe audio attachments using OpenAI (gpt-4o-mini-transcribe, whisper-1, etc.)
- Generate summaries and excerpts from transcripts with customizable prompt templates
- Three processing modes: Auto, WordPress Only, Worker Only
- Automatic chunking for long audio files (via FFmpeg)
- Optional VPS worker for large file processing
- Bounded retry for transient failures (rate limits, server errors)
- Editable transcripts and excerpts in the WordPress media library
- Copy-to-clipboard buttons in the post editor and media attachment screens
- Frontend shortcodes for public display: `[wpab_transcript]` and `[wpab_excerpt]`
- Bulk transcription and excerpt generation tools
- Activity log with diagnostic information
- Full uninstall support with data retention setting

---

## Quick Start

1. **Install the plugin** — copy `wp-audio-buddy/` to `wp-content/plugins/`
2. **Generate the autoloader** — run `composer dump-autoload` in the plugin directory
3. **Activate** — go to **Plugins → Installed Plugins** and activate WP Audio Buddy
4. **Configure** — go to **WP Audio Buddy → Settings** and enter your OpenAI API key
5. **Transcribe** — go to **Media Library**, select an audio file, and click **Transcribe Audio**

---

## Requirements

- PHP 8.0 or later
- WordPress 6.x
- [Composer](https://getcomposer.org/) (for autoloader generation)
- OpenAI API key ([get one here](https://platform.openai.com/api-keys))
- cURL extension (enabled by default in most PHP installations)
- For long audio files: [FFmpeg](https://ffmpeg.org/) (optional, for chunking)

---

## Configuration

### Settings Page

Navigate to **WP Audio Buddy → Settings** to configure:

| Section | Setting | Description |
|---|---|---|
| OpenAI | API Key | Your OpenAI API key |
| OpenAI | Transcription model | Model for audio transcription |
| OpenAI | Excerpt model | Model for text generation |
| Processing Mode | Auto | Small files locally, large files via worker |
| Processing Mode | WordPress Only | Always process locally |
| Processing Mode | Worker Only | Always use VPS worker |
| Worker | Worker URL | Base URL of your wpab-worker instance |
| Worker | Worker Site ID | Optional identifier for multi-site setups |
| Worker | Shared Secret | HMAC signing secret for worker communication |
| Worker | Chunk seconds | Duration of each audio chunk sent to worker |
| Worker | File size threshold | Files above this size (bytes) go to worker in Auto mode |
| Automation | Auto-transcribe on upload | Automatically transcribe MP3 uploads |
| Automation | Auto-generate excerpt | Generate excerpts after transcription completes |
| Automation | Auto-format transcript | Use AI to format raw transcripts into paragraphs |
| Excerpt | Type / Prompt / Max words | Template-driven excerpt generation |
| Editor Integration | Copy buttons | Enable copy-to-clipboard in post editor |
| Data Management | Delete data on uninstall | When enabled, all plugin data is removed on uninstall |

### Processing Modes

- **Auto** (recommended) — Small files (below the threshold) are processed locally on WordPress. Large files are sent to the worker when configured.
- **WordPress Only** — All audio is processed on the WordPress server. The worker is never used regardless of file size.
- **Worker Only** — All audio is sent to the VPS worker. Requires a configured worker URL and shared secret.

---

## File Structure

```
wp-audio-buddy/
├── wp-audio-buddy.php          # Plugin bootstrap (constants + autoload + boot)
├── composer.json                # PSR-4 autoloading
├── uninstall.php                # Cleanup on plugin deletion
├── phpunit.xml.dist             # PHPUnit configuration
├── .gitignore
├── src/
│   ├── Plugin.php               # Central boot class
│   ├── Controllers/             # Request handling
│   │   ├── BulkToolsController.php
│   │   ├── EditorController.php
│   │   ├── FrontendController.php
│   │   ├── LogsController.php
│   │   ├── MediaController.php
│   │   ├── SettingsController.php
│   │   └── WorkerCallbackController.php
│   ├── Services/                # Business logic
│   │   ├── ExcerptService.php
│   │   ├── QueueService.php
│   │   └── TranscriptionService.php
│   ├── Data/                    # Persistence layer
│   │   ├── GeneratedOutputRepository.php
│   │   ├── JobRepository.php
│   │   ├── LoggerRepository.php
│   │   ├── Meta.php
│   │   ├── Schema.php
│   │   └── TranscriptRepository.php
│   ├── Integrations/            # External service clients
│   │   ├── OpenAIClient.php
│   │   └── WorkerClient.php
│   ├── Security/                # Auth and signing
│   │   └── SignatureService.php
│   └── Support/                 # Utilities
│       ├── AudioChunker.php
│       └── template-functions.php
├── admin/
│   └── assets/
│       ├── admin.css            # Admin styles
│       └── frontend.css         # Shortcode display styles
├── migrations/                  # Schema migrations (future use)
├── tests/
│   ├── bootstrap.php
│   └── Unit/                    # Unit tests
│       └── OpenAIClientTest.php
└── docs/                        # Project documentation
```

---

## Custom Database Tables

| Table | Purpose |
|---|---|
| `{prefix}wpab_jobs` | Processing job records with status, error tracking, and worker references |
| `{prefix}wpab_transcripts` | Generated transcript text with metadata |
| `{prefix}wpab_generated_outputs` | AI-generated excerpts and summaries |
| `{prefix}wpab_logs` | Operational event log for diagnostics |

Tables are created on plugin activation via `dbDelta()` and checked again on plugin boot when `wpab_db_version` changes.

---

## Shortcodes

Use these in any post, page, or widget to display generated content on the frontend:

```
[wpab_transcript attachment_id="42"]   — Display transcript
[wpab_excerpt attachment_id="42"]      — Display excerpt/summary
```

### Template Functions

```php
wpab_get_transcript(42);        // Returns raw transcript text
wpab_get_excerpt(42);           // Returns raw excerpt text
wpab_render_transcript(42);     // Returns escaped HTML
wpab_render_excerpt(42);        // Returns escaped HTML
```

---

## Worker Configuration

WP Audio Buddy can offload heavy audio processing to a companion `wpab-worker` service. To configure:

1. Deploy `wpab-worker` on a VPS with Docker
2. In WordPress, go to **WP Audio Buddy → Settings → VPS Worker Mode**
3. Enter the worker URL (e.g., `https://worker.example.com/`)
4. Enter the shared secret (must match the worker's `WORKER_SHARED_SECRET`)
5. Optionally set a **Worker Site ID** if managing multiple WordPress sites

Worker communication uses HMAC-SHA256 signing. All outgoing requests include `X-WPAB-Signature`, `X-WPAB-Timestamp`, and `X-WPAB-Site-ID` headers. Callbacks from the worker are verified before any data is written.

The worker callback URL is:

```text
POST /wp-json/wpab/v1/worker-callback
```

Worker audio access uses a short-lived signed download URL generated for the local job. The URL is bound to the attachment ID, job UUID, expiration timestamp, and shared secret.

---

## Local Development

```bash
# Clone and enter the plugin directory
cd wp-audio-buddy

# Install PHP dependencies and generate autoloader
composer install

# Run PHP syntax check on all source files
find src -name '*.php' -exec php -l {} \;

# Run unit tests (requires PHPUnit)
composer test-unit

# Rebuild autoloader after adding new classes
composer dump-autoload
```

---

## Known Limitations

- **FFmpeg or worker required for long files** — Audio files over the local threshold require FFmpeg for local chunking or a configured worker. Without either, the plugin fails gracefully instead of attempting risky local processing.
- **Latest generated output is displayed by default** — Generated output records are kept in the custom table, while shortcodes and template functions display the latest excerpt/summary for the attachment.
- **No WordPress Multisite explicit support** — The plugin has not been specifically tested on multisite installations.
- **Transcription from worker only** — The worker path is a one-way dispatch. The worker does not stream progress back to WordPress.

---

## Roadmap

- [x] Phase 0-1: Repository baseline and structure normalization
- [x] Phase 2: Settings foundation with processing mode
- [x] Phase 3-4: Custom tables and job workflow
- [x] Phase 5: Local transcription path with OpenAI client and bounded retry
- [x] Phase 6-7: Worker dispatch and callback handling
- [x] Phase 8-9: Transcript display and excerpt generation
- [x] Phase 10: Frontend shortcodes and template functions
- [x] Phase 11: Testing and hardening
- [x] Phase 12: Documentation and handoff
- [ ] FFmpeg availability check with admin notice
- [x] Signed temporary download URLs for worker
- [ ] WP-CLI commands for batch operations
- [ ] REST API endpoints for external integrations
- [ ] Transcript version history
- [ ] Multisite support
