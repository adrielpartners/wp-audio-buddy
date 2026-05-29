# PROJECT_RULES.md

Version: 1.1  
Project: WP Audio Buddy  
Repository: `wp-audio-buddy`  
System Type: WordPress Plugin (Native)  
Last Updated: 2026-05-26

---

# Purpose

This file defines repository-specific rules for AI agents and developers working on WP Audio Buddy.

This is not the architecture document. This file tells agents how to work inside this repo without creating drift, duplicate patterns, security problems, or unnecessary complexity.

Before making substantial changes, read:

1. `CODING_CONSTITUTION.md`
2. `AGENTS.md`
3. `ARCHITECTURE.md`
4. `DECISIONS.md`
5. `MODE_WORDPRESS_NATIVE.md`
6. `PROJECT_RULES.md`

---

# 1. Repository Role

This repo contains the WordPress plugin.

The plugin owns:

- WordPress admin experience
- plugin settings
- media attachment integration
- processing job records
- transcript and generated output storage
- local processing
- dispatching heavy jobs to `wpab-worker`
- receiving signed worker callbacks
- displaying final results to administrators

The plugin does not own:

- heavy audio chunking in worker mode
- worker queue internals
- worker deployment
- worker OpenAI key in worker mode
- durable data inside the worker

---

# 2. Absolute Rules

AI agents must follow these rules:

1. Do not turn this plugin into a SaaS dashboard.
2. Do not move durable transcript/job ownership to the worker.
3. Do not store large structured plugin data only in `postmeta`.
4. Do not put business logic in WordPress hooks.
5. Do not put SQL directly in admin views or controllers.
6. Do not process large audio synchronously during admin requests.
7. Do not accept unsigned worker callbacks.
8. Do not add a heavy frontend framework unless explicitly approved.
9. Do not invent new storage patterns when custom tables already exist or are planned.
10. Do not commit API keys, worker secrets, signed URL secrets, or credentials.

---

# 3. Architectural Flow

Use this flow for WordPress-side logic:

```text
Hook / Admin Action / REST Request
→ Controller
→ Service
→ Data Layer
→ WordPress APIs / Custom Tables
```

Use this flow for worker delegation:

```text
Controller
→ Processing Service
→ Job Repository
→ Worker Client
→ wpab-worker
→ Signed Callback Controller
→ Processing Service
→ Transcript Repository
```

## Hooks

Hooks should only connect WordPress to plugin code.

Good hook responsibilities:

- register admin menu
- register REST routes
- enqueue plugin assets
- register activation/deactivation logic
- register Action Scheduler actions
- connect media library actions

Bad hook responsibilities:

- transcription logic
- job routing decisions
- SQL queries
- OpenAI API calls
- worker API calls
- admin HTML rendering beyond bootstrapping

## Controllers

Controllers coordinate requests.

Good controller responsibilities:

- verify capability
- verify nonce
- validate request shape
- call a service
- return a redirect, REST response, or rendered view

Bad controller responsibilities:

- direct SQL
- direct OpenAI calls
- direct transcript generation
- heavy processing
- large condition-heavy workflows

## Services

Services own behavior.

Good service responsibilities:

- decide local vs worker processing
- create jobs
- call OpenAI integration
- call worker client
- handle worker callback result
- generate summaries and excerpts
- coordinate retries

## Data Layer

Repositories/data classes own persistence.

Good data layer responsibilities:

- custom table inserts
- custom table updates
- custom table queries
- schema-aware persistence
- prepared SQL

Bad data layer responsibilities:

- UI decisions
- worker calls
- OpenAI calls
- admin notices
- business orchestration

---

# 4. File and Folder Rules

Use this preferred structure as the target:

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

## File Placement

Place files according to responsibility:

- plugin bootstrap: `wp-audio-buddy.php`
- controllers: `src/Controllers/`
- business logic: `src/Services/`
- persistence/repositories: `src/Data/`
- request/result/domain DTOs: `src/Models/`
- OpenAI and worker clients: `src/Integrations/`
- Action Scheduler job handlers: `src/Jobs/`
- HMAC/nonce/capability helpers: `src/Security/`
- narrow generic utilities: `src/Support/`
- admin templates/views: `admin/views/`
- admin-only CSS/JS: `admin/assets/`
- schema/migration helpers: `migrations/`
- tests: `tests/`
- project docs: `docs/`

## Naming Rules

Use clear class names that describe responsibility.

Examples:

```text
ProcessingController
WorkerCallbackController
AudioProcessingService
TranscriptionService
SummaryService
WorkerClient
JobRepository
TranscriptRepository
SignatureVerifier
```

Avoid vague names:

```text
Manager
Helper
Utils
Processor
Handler
Common
Functions
```

Use a vague name only if no more precise responsibility exists.

---

# 5. WordPress-Specific Rules

## Capabilities

Every admin action must check capability.

Default for v1:

```php
current_user_can('manage_options')
```

If a custom capability is introduced, use:

```text
manage_wp_audio_buddy
```

and document the decision in `DECISIONS.md`.

## Nonces

Every admin write action must verify a nonce.

Never rely on UI visibility as authorization.

## Sanitization

Sanitize all input at the boundary.

Common functions:

```php
absint()
sanitize_key()
sanitize_text_field()
sanitize_textarea_field()
esc_url_raw()
wp_unslash()
```

## Escaping

Escape all output in admin views.

Common functions:

```php
esc_html()
esc_attr()
esc_url()
wp_kses_post()
```

Use `wp_kses_post()` only when HTML is intentionally allowed.

## SQL

All direct SQL must use `$wpdb->prepare()` unless there are no variables.

Custom table names must be assembled safely.

Never concatenate untrusted input into SQL.

## Assets

Only enqueue plugin CSS/JS on WP Audio Buddy admin screens.

Do not load plugin assets across all wp-admin pages unless required and approved.

---

# 6. Data Rules

## Custom Tables

Use custom tables for plugin-owned operational data.

Expected starting tables:

```text
wp_wpab_jobs
wp_wpab_transcripts
```

Possible future tables:

```text
wp_wpab_generated_outputs
wp_wpab_job_logs
```

## Attachment Meta

Attachment meta may be used for lightweight references, such as:

- latest transcript ID
- latest job status
- simple flags
- attachment-level settings

Do not store large transcript bodies only in attachment meta.

## Options

Use WordPress options for settings only.

Expected options may include:

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

## Secrets

Secrets must never be logged or displayed.

Secret options include:

- OpenAI API key
- worker shared secret
- signed URL secret if separate

When showing saved secrets in UI, mask them.

---

# 7. Processing Rules

## Processing Modes

Support these processing modes:

```text
Auto
WordPress only
Worker only
```

## Auto Mode

In Auto mode:

- small files may process locally
- large files should use the worker if configured
- if the worker is unavailable, the plugin should fail gracefully
- do not silently attempt large local processing if it is likely to exceed host limits

## Local Processing

Local processing may be used for:

- small audio files
- development/testing
- environments without worker access

Local processing must run through background jobs, not admin request-response cycles.

## Worker Processing

Worker processing should be used for:

- large audio files
- long audio files
- files likely to exceed WordPress host limits
- worker-only mode

The plugin must create a durable local job before dispatching to the worker.

## Retry Rules

Retries should be explicit and visible.

Do not create unbounded retry loops.

Recommended defaults:

- transient local API failure: retry up to 2 times
- worker dispatch network failure: retry up to 2 times
- invalid attachment: no retry
- invalid worker signature: no retry
- unsupported audio format: no retry unless corrected

---

# 8. Worker Integration Rules

## Required Security

Worker requests and callbacks must use HMAC signing.

Expected headers:

```text
X-WPAB-Site-ID
X-WPAB-Timestamp
X-WPAB-Signature
```

## WordPress-to-Worker

The plugin sends:

- job ID
- attachment ID
- signed temporary audio URL
- callback URL
- requested operation
- processing options
- timestamp
- signature

The plugin must not send:

- WordPress admin cookies
- user passwords
- unnecessary user data
- OpenAI key in worker mode

## Worker-to-WordPress

The worker sends a signed callback.

The plugin must verify:

- site ID
- timestamp
- signature
- job ID exists
- job is in a callback-eligible state
- attachment ID matches expected record
- payload shape is valid

## Callback Endpoint

Expected route:

```text
POST /wp-json/wpab/v1/worker-callback
```

This route must not update job or transcript records until signature verification succeeds.

---

# 9. OpenAI Integration Rules

OpenAI calls must be wrapped in a service/integration class.

Do not scatter OpenAI HTTP calls throughout the codebase.

Local mode:

- WordPress may use a WordPress-stored OpenAI API key.

Worker mode:

- worker owns the OpenAI API key.
- WordPress does not send OpenAI keys to the worker.

OpenAI errors must be normalized into user-safe plugin errors.

Never expose raw provider errors to admin UI unless explicitly sanitized.

---

# 10. Background Job Rules

Use Action Scheduler where practical.

Job handlers should live in:

```text
src/Jobs/
```

Job handlers should be thin.

They should:

- load job data
- call the relevant service
- update job state
- record failure/success

They should not:

- render admin UI
- contain business logic directly
- perform direct SQL outside repositories
- call external services without service wrappers

---

# 11. Admin UI Rules

The admin UI should be:

- clear
- simple
- WordPress-friendly
- scoped to plugin pages
- not overbuilt

Every job status screen should make clear:

- what audio file is involved
- what processing step is happening
- whether the job is pending, running, completed, failed, or retryable
- what the admin can do next

Use user-safe error messages.

Keep technical details in logs or advanced diagnostics, not primary UI.

---

# 12. Error Handling Rules

Use consistent internal error codes.

Recommended codes:

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

Admin-facing messages should be simple and actionable.

Technical context should be logged safely.

Do not swallow errors silently.

---

# 13. Testing and Verification Rules

When changing processing, security, or data behavior, run or add relevant tests if the test framework exists.

Priority test areas:

- capability checks
- nonce verification
- HMAC verification
- worker callback validation
- local vs worker routing
- job state transitions
- repository insert/update logic
- settings sanitization

If automated tests are not available, perform a manual smoke test and report what was tested.

Do not claim tests passed if they were not run.

---

# 14. Git and Agent Workflow

Before work:

```bash
git status
```

If there are existing changes, do not overwrite them.

Preferred workflow:

```bash
git pull
git checkout -b feature/short-description
# make changes
git status
# run checks
git add .
git commit -m "Short clear message"
git push
```

Do not use destructive commands such as:

```bash
git reset --hard
git clean -fd
git checkout -- .
```

unless explicitly instructed.

---

# 15. Documentation Update Rules

Update documentation when changing:

- data tables
- processing modes
- worker API contract
- callback payloads
- HMAC signing
- OpenAI model usage
- settings
- admin screen structure
- background jobs
- deployment assumptions

Update:

- `ARCHITECTURE.md` for current system facts
- `DECISIONS.md` for major choices
- `PROJECT_RULES.md` for repo-specific rules

---

# 16. Definition of Done

A task is done when:

- the change matches the request
- code is in the correct layer
- capability checks are present where needed
- nonce checks are present for admin writes
- external input is sanitized
- output is escaped
- database access uses prepared statements
- no secrets are exposed
- job behavior is visible and retryable where appropriate
- relevant checks were run or honestly reported
- docs were updated if the architecture or rules changed

---

# 17. Agent Work Summary Format

At the end of a coding task, summarize:

```text
Summary:
- Changed ...
- Added ...
- Updated ...

Verification:
- Ran ...
- Not run: ... because ...

Docs:
- Updated ...
- Not updated because ...

Notes:
- Assumptions ...
- Risks ...
- Follow-up ...
```

Mention important files changed.

Mention whether processing, storage, security, or worker behavior was affected.

---

# Final Rule

Keep WP Audio Buddy a clean WordPress-native plugin.

WordPress owns the product. The worker helps with heavy processing. Do not blur that boundary.
