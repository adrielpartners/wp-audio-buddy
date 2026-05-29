# ARCHITECTURE.md

Version: 1.0  
Project: WP Audio Buddy  
Repository: `wp-audio-buddy`  
System Type: WordPress Plugin (Native)

---

# Purpose

WP Audio Buddy is a native WordPress plugin that uses AI to transcribe, summarize, excerpt, and generate useful text from uploaded audio files.

The plugin is the primary product. WordPress owns the user experience, job records, plugin settings, attachment relationships, and final generated content.

The companion `wpab-worker` service exists only to offload heavy audio-processing work. It must not become the product core or the durable source of truth.

---

# 1. Project Identity

## Project Name

WP Audio Buddy

## One-Sentence Summary

WP Audio Buddy is a native WordPress plugin for WordPress site owners and content managers that helps them turn uploaded audio files into transcripts, summaries, excerpts, and reusable written content.

## Primary Audience

- WordPress site administrators
- Church and ministry site managers
- Podcast or sermon publishers
- Site owners with uploaded audio libraries
- Internal Adriel Partners users managing audio-heavy WordPress sites

## Core Problem

Audio content is hard to search, skim, quote, repurpose, and publish without transcription and text extraction.

Many WordPress site owners have valuable uploaded audio but no simple way to generate transcripts, summaries, excerpts, or related written content from that audio.

## Core Value

The plugin makes audio content more usable by turning it into structured text that can be searched, displayed, edited, copied, published, or repurposed.

---

# 2. System Type

## Classification

WordPress Plugin (Native)

## Why This Classification Is Correct

WP Audio Buddy is primarily a WordPress plugin. The main application experience lives inside WordPress. The plugin owns the admin interface, attachment relationships, processing jobs, generated content records, and user-facing workflow.

The worker service is a supporting processing service only. It does not own the product experience or durable content data.

This project must follow `MODE_WORDPRESS_NATIVE.md`.

---

# 3. Product Scope

## Version One Goals

- Detect or select uploaded WordPress audio attachments.
- Allow an administrator to request transcription for an audio file.
- Generate transcripts using AI transcription.
- Generate summaries or excerpts from transcripts.
- Store final generated text in WordPress-owned data.
- Track processing job status.
- Show clear success and failure states in the admin UI.
- Allow failed jobs to be retried.
- Use local WordPress processing for small/simple files when practical.
- Use `wpab-worker` for large/heavy audio files when configured.
- Keep the plugin usable even when the worker is disabled or unavailable.

## Explicit Non-Goals

- No public SaaS dashboard in v1.
- No billing system.
- No multi-customer worker management UI.
- No commercial marketplace licensing system.
- No complex team permissions beyond normal WordPress capability checks.
- No permanent transcript storage in the worker.
- No worker-owned product database.
- No real-time collaborative editing.
- No full media library replacement.

## Success Criteria

v1 is successful when:

- An admin can select an audio attachment and generate a transcript.
- The transcript is stored safely in WordPress.
- The admin can generate a useful summary or excerpt from the transcript.
- Long audio files can be processed through the worker without exhausting WordPress hosting resources.
- Failed processing attempts are visible and retryable.
- The plugin remains understandable to a future developer or AI agent.

---

# 4. Core Technology Stack

## Runtime

- WordPress 6.x
- PHP 8.1+ preferred
- WordPress admin
- WordPress REST API where needed
- Action Scheduler for local job orchestration when available

## AI Services

- OpenAI transcription and text generation models
- Worker-mediated OpenAI processing for large/heavy files when configured

## Storage

Preferred:

- Custom plugin tables for durable plugin-owned job and transcript data
- WordPress options for plugin settings
- Attachment meta only for lightweight attachment-level status or references

Avoid burying large, structured, plugin-owned data only in `postmeta`.

## Frontend/Admin UI

For v1:

- WordPress admin screens
- Simple, clean, server-rendered or lightly enhanced admin UI
- JavaScript only where it improves interaction meaningfully

For future complex interfaces:

- Vue may be used inside WordPress admin, but only if the complexity justifies it.

## Deviations From Standard Constitution

This project intentionally deviates from the default standalone app stack.

- It does not use Nuxt as the application framework.
- It does not use PostgreSQL as the primary database.
- It runs inside WordPress and uses the WordPress database.
- It follows WordPress-native plugin architecture.

Reason:

The plugin is a native WordPress application. It must respect WordPress hosting, APIs, security patterns, and admin conventions.

---

# 5. Hosting and Portability

## Hosting Model

The plugin must run on normal WordPress hosting.

Expected environments include:

- shared hosting
- managed WordPress hosting
- VPS-hosted WordPress
- local development environments

The optional worker runs separately and is configured by URL and shared secret.

## Portability Requirement

The plugin must remain portable across standard WordPress installations.

It must not require:

- root access
- custom system packages
- background daemons on the WordPress host
- specialized hosting features
- direct shell access

## Infrastructure Constraints

Assume:

- limited CPU
- limited memory
- slow disk
- unreliable WP-Cron
- file upload limits
- request timeout limits
- no persistent background process on the WordPress host

This is why large audio processing may be delegated to `wpab-worker`.

---

# 6. Domain Model

## Audio Attachment

- Represents a WordPress media library audio file.
- Durable: yes
- Stored in: WordPress attachments table and media metadata
- Owned by: WordPress core
- WP Audio Buddy stores plugin-specific relationships and processing state for it.

## Processing Job

- Represents a requested transcription, summary, excerpt, or related AI operation.
- Durable: yes
- Stored in: plugin-owned custom table
- Owned by: WP Audio Buddy
- May be executed locally or delegated to the worker.

## Transcript

- Represents the final text transcript of an audio file.
- Durable: yes
- Stored in: plugin-owned custom table, with attachment relationship
- Owned by: WP Audio Buddy
- Worker may create it temporarily but must not remain the source of truth.

## Transcript Segment

- Represents optional timestamped transcript segments.
- Durable: optional for v1
- Stored in: plugin-owned custom table or structured field if used
- Owned by: WP Audio Buddy

## Summary

- Represents AI-generated condensed text from a transcript.
- Durable: yes
- Stored in: plugin-owned custom table or transcript-related record
- Owned by: WP Audio Buddy

## Excerpt

- Represents AI-generated short excerpts, pull quotes, or reusable snippets.
- Durable: yes
- Stored in: plugin-owned custom table or transcript-related record
- Owned by: WP Audio Buddy

## Plugin Settings

- Represents configuration such as worker URL, site ID, shared secret, processing thresholds, default models, and feature toggles.
- Durable: yes
- Stored in: WordPress options table
- Owned by: WP Audio Buddy

## Worker Connection

- Represents the configured link between WordPress and the worker.
- Durable: yes
- Stored in: WordPress options table
- Owned by: WP Audio Buddy
- Includes worker base URL, site ID, shared secret, and worker-enabled status.

---

# 7. System Layers

## Actual Flow

Native WordPress plugin flow:

```text
WordPress Hook / Admin Action / REST Request
→ Controller
→ Service
→ Data Layer
→ WordPress Database / WordPress APIs
```

For worker jobs:

```text
Admin Action
→ Controller
→ Processing Service
→ Local Job Record
→ Worker Client
→ wpab-worker
→ Signed WordPress Callback
→ Callback Controller
→ Processing Service
→ Data Layer
→ WordPress Database
```

## Hooks

Hooks should connect WordPress to plugin code.

Hooks may:

- register admin menus
- register REST routes
- register activation/deactivation handlers
- enqueue scoped assets
- register Action Scheduler jobs
- connect media actions to controllers

Hooks must not contain business logic.

## Controllers

Controllers coordinate requests.

Controllers may:

- check capabilities
- verify nonces
- parse request data
- call services
- return admin redirects or REST responses

Controllers must not:

- perform transcription logic
- contain SQL queries
- call OpenAI directly unless the service layer owns that call through a service
- handle large business workflows inline

## Services

Services own plugin behavior.

Services may:

- decide whether to process locally or through worker
- create processing jobs
- call OpenAI service wrappers
- call the worker client
- handle callback results
- generate summaries and excerpts
- coordinate retries
- return domain-level success or failure results

Services must not:

- render admin HTML
- directly echo output
- contain view templates
- become generic catch-all utilities

## Data Layer

The data layer owns persistence.

Data layer may:

- insert and update job records
- insert and update transcript records
- fetch attachment-related plugin data
- manage plugin custom tables
- run prepared SQL queries

Data layer must not:

- contain UI rules
- call OpenAI
- call the worker
- decide product policy beyond persistence constraints

## Views/Admin UI

Views own presentation.

Views may:

- render admin screens
- display job statuses
- show transcripts and summaries
- provide buttons/forms for actions
- show user-safe error messages

Views must not:

- run database queries directly
- contain business rules
- call OpenAI or worker APIs

---

# 8. Folder Structure

The final folder structure should be documented here once the repo is normalized.

Recommended shape:

```text
wp-audio-buddy/
  wp-audio-buddy.php
  src/
    Controllers/
    Services/
    Data/
    Models/
    Integrations/
    Jobs/
    Security/
    Support/
  admin/
    views/
    assets/
      css/
      js/
  migrations/
  tests/
  docs/
```

## Folder Responsibilities

- `wp-audio-buddy.php` - plugin bootstrap only
- `src/Controllers` - admin, REST, callback, and action controllers
- `src/Services` - business behavior and workflow coordination
- `src/Data` - custom table repositories and persistence
- `src/Models` - simple domain/data objects if used
- `src/Integrations` - OpenAI and worker clients
- `src/Jobs` - Action Scheduler job handlers
- `src/Security` - HMAC verification, nonce helpers, capability helpers if needed
- `src/Support` - narrow utilities only when they do not belong elsewhere
- `admin/views` - server-rendered admin views
- `admin/assets` - scoped admin CSS/JS
- `migrations` - custom table schema changes
- `tests` - automated tests if/when added
- `docs` - supporting documentation

Do not create vague folders such as `helpers`, `misc`, `stuff`, or `temp`.

---

# 9. Request and Data Flows

## Small File Local Transcription

```text
Admin selects audio attachment
→ Admin clicks Transcribe
→ Controller verifies capability and nonce
→ Processing Service creates local job
→ Action Scheduler runs job
→ Local Transcription Service sends audio to OpenAI
→ Transcript is returned
→ Transcript Data Layer stores result
→ Job marked completed
→ Admin sees result
```

## Large File Worker Transcription

```text
Admin selects audio attachment
→ Admin clicks Transcribe
→ Controller verifies capability and nonce
→ Processing Service creates local job
→ Processing Service decides worker is required
→ Worker Client sends signed job request to worker
→ Worker downloads audio using short-lived signed URL
→ Worker chunks audio
→ Worker transcribes chunks
→ Worker stitches transcript
→ Worker sends signed callback to WordPress
→ Callback Controller verifies signature
→ Processing Service validates result
→ Transcript Data Layer stores result
→ Job marked completed
→ Admin sees result
```

## Worker Failure Flow

```text
Worker fails job
→ Worker sends signed failure callback if possible
→ Callback Controller verifies signature
→ Processing Service marks job failed
→ Failure details logged server-side
→ User-safe failure shown in admin
→ Admin may retry job
```

If the worker cannot send a callback, the plugin should eventually detect timeout or stale job state and mark the job failed or needs attention.

## Summary / Excerpt Flow

```text
Admin requests summary or excerpt
→ Controller verifies capability and nonce
→ Service confirms transcript exists
→ AI Text Service generates requested output
→ Data Layer stores generated output
→ Admin sees generated text
```

---

# 10. Authentication and Authorization

## Does This System Have Accounts?

The plugin relies on WordPress accounts.

## Authentication Method

- WordPress admin authentication
- WordPress REST authentication for REST endpoints when used
- HMAC request signing for worker communication

## Roles and Capabilities

Use WordPress capability checks.

Default capability for admin actions:

```text
manage_options
```

A custom capability may be introduced later:

```text
manage_wp_audio_buddy
```

If introduced, it should be granted to administrators on activation.

## Authorization Boundary

Authorization must happen in controllers before service execution.

Worker callbacks must be authenticated through HMAC verification before any job state is changed.

---

# 11. Validation Strategy

## Boundary Validation

Validate all external input at the controller or REST boundary.

This includes:

- attachment IDs
- job IDs
- action names
- model names
- generation options
- worker callback payloads
- signed URL requests
- settings updates

## Validation Tooling

Use WordPress-native validation and sanitization patterns:

- `absint`
- `sanitize_text_field`
- `sanitize_key`
- `esc_url_raw`
- `wp_verify_nonce`
- capability checks
- strict allowlists for action/model/mode names

## Validation Rule

Controllers validate request shape and permissions.

Services validate domain rules.

Data layer validates persistence constraints where necessary.

---

# 12. Error Handling Pattern

## Admin Errors

Admin UI should show user-safe messages:

- "Transcription failed. Please check the job log and try again."
- "Worker is unavailable."
- "Audio file could not be accessed."
- "Invalid worker callback signature."

Do not show stack traces, raw API errors, secrets, request signatures, or internal file paths.

## Service Errors

Services should return or throw consistent domain errors with:

- code
- user-safe message
- technical context for logs

Example codes:

```text
AUDIO_NOT_FOUND
AUDIO_TOO_LARGE_FOR_LOCAL_PROCESSING
WORKER_NOT_CONFIGURED
WORKER_REQUEST_FAILED
WORKER_CALLBACK_INVALID
TRANSCRIPTION_FAILED
SUMMARY_FAILED
EXCERPT_FAILED
OPENAI_REQUEST_FAILED
```

## Logging

Log technical details server-side only.

Never log:

- OpenAI API keys
- worker shared secrets
- signed URL tokens
- full HMAC secrets
- private file paths unnecessarily
- sensitive user data

---

# 13. Background Jobs and Async Processing

## Do We Use Background Jobs?

Yes.

## What Runs Async?

- transcription jobs
- worker dispatch jobs
- summary generation when slow
- excerpt generation when slow
- stale job cleanup
- retry operations

## Job Tooling

Preferred:

- Action Scheduler for WordPress-side background work

Fallback:

- WP-Cron only for simple cleanup if Action Scheduler is not available

## Retry Strategy

Retries should be explicit.

Recommended defaults:

- local transient API failures: retry up to 2 times
- worker dispatch failures: retry up to 2 times
- invalid input or invalid attachment: no retry
- authentication/signature failures: no retry
- OpenAI validation or unsupported format failure: no retry unless corrected

## Job Ownership

WordPress owns durable job records.

The worker may own temporary active job state but must not be the durable job source of truth.

---

# 14. External Services and Integrations

## OpenAI

- Purpose: transcription, summarization, excerpts, and text generation
- Called from: WordPress service for small/local jobs; worker for large jobs
- Failure behavior: mark job failed, log technical detail, show user-safe message, allow retry when appropriate
- Critical: high

## WP AB Worker

- Purpose: heavy audio processing, especially chunking and large file transcription
- Called from: Worker Client inside WP Audio Buddy
- Failure behavior: local job remains failed or pending retry; admin can retry
- Critical: optional but important for large audio

## WordPress Media Library

- Purpose: audio file source and attachment relationship
- Called from: plugin services/data layer through WordPress APIs
- Failure behavior: reject job if attachment/file is invalid
- Critical: high

---

# 15. Design System and Visual Identity

## Visual Tone

Clean, calm, modern, and admin-friendly.

## Typography Direction

Use normal WordPress admin typography unless a more complex custom UI is later introduced.

## Spacing Density

Balanced and practical.

## Radius and Surface Style

Modern but restrained. Avoid making the plugin feel like an unrelated SaaS app inside WordPress.

## Interaction Feel

Fast, clear, and confidence-building.

Admins should always know:

- what audio item is being processed
- what stage the job is in
- whether the result succeeded or failed
- what to do next

## Theme Modes

WordPress admin compatibility first. Do not invent an independent theme system for v1.

## Primitive Component Set

For server-rendered v1, reusable UI partials may include:

- status badge
- job row
- transcript panel
- action button group
- settings field row
- notice block

If Vue is introduced later, define primitives before building feature components.

---

# 16. Testing Strategy

## Test Runner

Not decided yet.

For future testing:

- PHPUnit for PHP unit/integration tests
- WordPress test suite for plugin integration behavior
- targeted manual smoke tests for admin workflows

## Priority Test Targets

- HMAC signing and verification
- worker callback validation
- job state transitions
- attachment validation
- service routing between local and worker processing
- data layer insert/update behavior
- settings sanitization

## Things We Intentionally Will Not Over-Test

- trivial view rendering
- WordPress core behavior
- simple getter/setter methods
- cosmetic admin UI details in v1

---

# 17. Browser and Device Support

## Primary Device Context

Desktop-first WordPress admin.

## Browser Baseline

Current versions of:

- Chrome
- Safari
- Firefox
- Edge

## Accessibility Baseline

- semantic form labels
- visible focus states
- keyboard-usable actions
- readable status messages
- avoid color-only status indicators

---

# 18. Performance Strategy

## Performance Priorities

- never block admin page loads with heavy audio processing
- avoid loading assets outside WP Audio Buddy admin screens
- avoid unbounded queries
- keep database tables indexed for job and attachment lookups
- avoid large transcript processing inside request-response cycles

## Known Bottlenecks or Risks

- large audio files
- shared hosting limits
- request timeouts
- slow disk/file access
- unreliable cron
- OpenAI API latency
- worker availability

## Caching Strategy

No complex caching initially.

Possible future caching:

- cache transcript metadata for listing screens
- transient status checks for worker health

---

# 19. Observability and Monitoring

## Logging

Log important events:

- job created
- job dispatched to worker
- local processing started
- worker callback received
- transcription completed
- summary/excerpt completed
- job failed
- retry scheduled

## Monitoring

v1 may rely on:

- WordPress admin job status screen
- server logs
- plugin debug log setting

## Alerting

No alerting in v1 unless specifically added.

Future option:

- admin email or dashboard notice for repeated worker failures

---

# 20. Deployment Architecture

## Local Development

```text
WordPress local environment
→ WP Audio Buddy plugin
→ optional local or remote wpab-worker
→ OpenAI API
```

## Production Without Worker

```text
WordPress site
→ WP Audio Buddy plugin
→ OpenAI API
→ WordPress database
```

## Production With Worker

```text
WordPress site
→ WP Audio Buddy plugin
→ signed request to wpab-worker
→ OpenAI API
→ signed callback to WordPress
→ WordPress database
```

Worker architecture is documented separately in the `wpab-worker` repository.

---

# 21. Environment Configuration

## WordPress Options

Expected settings:

```text
wpab_processing_mode
wpab_worker_enabled
wpab_worker_base_url
wpab_worker_site_id
wpab_worker_shared_secret
wpab_worker_file_size_threshold
wpab_openai_api_key
wpab_default_transcription_model
wpab_default_summary_model
wpab_delete_data_on_uninstall
```

If the worker owns the OpenAI key, then `wpab_openai_api_key` may be omitted or used only for WordPress-only mode.

## Secrets

Secrets must never be committed.

Secret values include:

- OpenAI API keys
- worker shared secret
- signed URL secret
- callback signing secret if separate

---

# 22. Data Durability and Backup Strategy

## Durable Data

Durable data includes:

- processing jobs
- transcripts
- summaries
- excerpts
- job logs or failure records
- plugin settings
- attachment relationships

## Backup Strategy

Because data lives in WordPress, normal WordPress database backups should include plugin custom tables.

Plugin custom tables must be named clearly and documented.

## Verification Strategy

Backup verification is outside the plugin in v1, but table names and data ownership must be documented.

## Deletion Policy

Admin should control whether plugin data is deleted on uninstall.

Default recommendation:

- preserve data on uninstall unless the admin explicitly chooses deletion

---

# 23. WordPress-Specific Architecture

## Native Plugin Pattern

WP Audio Buddy follows:

```text
Hook
→ Controller
→ Service
→ Data Layer
```

## Main Hooks

Expected hooks include:

- activation hook for table creation/capability setup
- deactivation hook for cleanup of scheduled jobs if appropriate
- admin menu registration
- admin asset enqueueing
- REST API route registration
- Action Scheduler job registration
- media attachment integration hooks as needed

## Custom Tables

### `{$wpdb->prefix}wpab_jobs`

Durable record of every processing job (transcribe, summarize, excerpt).

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `job_uuid` | VARCHAR(36) | Unique UUID for external reference |
| `attachment_id` | BIGINT UNSIGNED | WordPress attachment ID |
| `operation` | VARCHAR(50) | `transcribe`, `summarize`, `excerpt` |
| `processing_mode` | VARCHAR(20) | `local` or `worker` |
| `status` | VARCHAR(20) | `pending`, `queued`, `running`, `waiting_for_worker`, `completed`, `failed`, `retryable`, `cancelled` |
| `source` | VARCHAR(20) | `manual`, `auto`, `bulk` |
| `attempt_count` | INT UNSIGNED | Retry counter |
| `error_code` | VARCHAR(50) | Machine-readable error code |
| `error_message` | TEXT | User-safe error description |
| `worker_job_id` | VARCHAR(100) | Worker-side job reference |
| `created_at` | DATETIME | Row creation timestamp |
| `updated_at` | DATETIME | Last update timestamp |
| `started_at` | DATETIME NULL | When processing began |
| `completed_at` | DATETIME NULL | When processing completed |
| `failed_at` | DATETIME NULL | When processing failed |

Indexes: `id` (PK), `job_uuid` (unique), `attachment_id`, `status`, `operation`, `created_at`.

### `{$wpdb->prefix}wpab_transcripts`

Stores the final transcript text generated from an audio file.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `attachment_id` | BIGINT UNSIGNED | WordPress attachment ID |
| `job_id` | BIGINT UNSIGNED NULL | Job that produced this transcript |
| `transcript_text` | LONGTEXT | Full transcript body |
| `segments_json` | LONGTEXT NULL | Optional timestamped segments |
| `metadata_json` | LONGTEXT NULL | Model, duration, etc. |
| `created_at` | DATETIME | Row creation timestamp |
| `updated_at` | DATETIME | Last update timestamp |

Indexes: `id` (PK), `attachment_id`, `job_id`, `created_at`.

### `{$wpdb->prefix}wpab_logs`

Internal event log for debugging and diagnostics. Created by LoggerRepository.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `created_at` | DATETIME | Event timestamp |
| `level` | VARCHAR(20) | `info` or `error` |
| `operation` | VARCHAR(120) | Operation name |
| `attachment_id` | BIGINT UNSIGNED NULL | Related attachment |
| `message` | TEXT | Log message |
| `context` | LONGTEXT NULL | JSON-encoded context |

Indexes: `id` (PK), `level`, `operation`, `attachment_id`, `created_at`.

### `{$wpdb->prefix}wpab_generated_outputs`

Stores AI-generated excerpts, summaries, and other written outputs produced from transcripts.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `attachment_id` | BIGINT UNSIGNED | WordPress attachment ID |
| `job_id` | BIGINT UNSIGNED NULL | Job that produced this output |
| `output_type` | VARCHAR(20) | `excerpt`, `summary` |
| `prompt_type` | VARCHAR(50) NULL | `informative`, `engaging`, `custom` |
| `output_text` | LONGTEXT | Generated output body |
| `metadata_json` | LONGTEXT NULL | Model, parameters, etc. |
| `created_at` | DATETIME | Row creation timestamp |
| `updated_at` | DATETIME | Last update timestamp |

Indexes: `id` (PK), `attachment_id`, `job_id`, `output_type`, `created_at`.

### Schema Versioning

Schema version is tracked in the `wpab_db_version` WordPress option. On plugin activation, `Schema::install()` runs `dbDelta()` against all table definitions and updates the version. Future schema changes should compare against this version and run incremental migrations.

## Options

Use options for:

- plugin settings
- worker connection settings
- feature flags
- uninstall preference

## Admin UI Strategy

Start with a simple WordPress admin UI.

Do not build a heavy JavaScript app unless the admin workflow becomes complex enough to justify it.

## Background Processing

Use Action Scheduler for durable local processing orchestration.

WordPress should never do heavy audio processing during normal page loads or admin form submissions.

## External Worker Boundary

The worker may process audio and return results.

The worker must not:

- own final transcripts
- own WordPress attachment relationships
- own plugin settings
- become the product database
- require WordPress to trust unsigned callbacks

---

# 24. Architectural Decisions

Maintain a companion file:

```text
DECISIONS.md
```

Initial decisions to record:

1. WP Audio Buddy is WordPress-native.
2. Worker is a supporting processing service, not the product core.
3. WordPress owns durable transcript and job data.
4. Worker owns heavy temporary processing only.
5. HMAC signatures are required for worker communication.
6. WordPress should support local processing for small files and worker processing for large files.

---

# 25. Implementation Readiness Checklist

Before major coding continues, confirm:

- custom table schema
- exact processing mode settings
- exact worker threshold default
- Action Scheduler availability and fallback behavior
- OpenAI key ownership
- worker callback route
- HMAC signing format
- transcript storage format
- admin screen structure
- uninstall behavior
- retry rules

---

# 26. Maintenance Rule

Update this document when:

- data ownership changes
- worker communication changes
- job processing changes
- custom tables change
- OpenAI model strategy changes
- admin UI architecture changes
- plugin settings change
- security model changes

An outdated architecture document is worse than none.

---

# Final Principle

WP Audio Buddy should remain a clear, durable WordPress-native plugin.

The worker is a helper, not the product.

When in doubt, keep WordPress as the source of truth, keep the worker narrow, and keep every processing step visible, retryable, and understandable.
