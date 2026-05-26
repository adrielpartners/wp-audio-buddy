# DECISIONS.md

Version: 1.0  
Project: WP Audio Buddy  
Repository: `wp-audio-buddy`  
Last Updated: 2026-05-25

---

# Purpose

This file records major architectural and product decisions for WP Audio Buddy.

Use this file to prevent future developers or AI agents from repeatedly re-litigating settled choices.

Each decision should include:

- decision
- rationale
- tradeoffs
- date adopted
- reversibility

---

# Decision 001: WP Audio Buddy is a WordPress-native plugin

## Decision

WP Audio Buddy is a native WordPress plugin. WordPress is the application host and primary product environment.

## Rationale

The plugin exists to help WordPress site owners work with audio files already uploaded to WordPress. The admin experience, attachment relationships, settings, job records, and final generated content belong inside WordPress.

This aligns with the product’s purpose and keeps the tool useful on normal WordPress sites.

## Tradeoffs

- WordPress hosting constraints must be respected.
- The plugin cannot assume persistent background workers on the WordPress host.
- The architecture must use WordPress security, admin, and data patterns.
- Some heavy processing must be delegated outside WordPress.

## Date Adopted

2026-05-25

## Reversibility

Hard to reverse after v1 data structures and admin workflows are built. A future SaaS version could be created, but the v1 plugin should remain WordPress-native.

---

# Decision 002: WordPress owns durable product data

## Decision

WP Audio Buddy stores durable product data in WordPress-owned storage.

This includes:

- processing jobs
- transcripts
- summaries
- excerpts
- generated outputs
- attachment relationships
- plugin settings

## Rationale

WordPress is the product host. Final output should remain available even if the worker is offline, replaced, or removed.

This also keeps backups simple because normal WordPress database backups include plugin data.

## Tradeoffs

- WordPress database schema must be designed carefully.
- Large transcript storage must be handled intentionally.
- The plugin must maintain its own data access layer.
- Data portability depends on plugin export/migration tools if needed later.

## Date Adopted

2026-05-25

## Reversibility

Moderately difficult. A future cloud backend could sync or mirror data, but WordPress remains the v1 source of truth.

---

# Decision 003: The worker is optional supporting infrastructure

## Decision

`wpab-worker` is an optional processing helper, not the core product.

WP Audio Buddy must remain usable without the worker for simple/small jobs when WordPress-only processing is configured or practical.

## Rationale

This prevents the worker from becoming a required SaaS dependency. It also allows the plugin to work in simpler environments and keeps development focused.

The worker exists to protect WordPress from heavy processing, not to replace WordPress as the application core.

## Tradeoffs

- The plugin must support both local and worker processing paths.
- Some code paths will need separate handling.
- Local processing may be limited by host resources.

## Date Adopted

2026-05-25

## Reversibility

Reversible. The plugin could later require the worker, but that would reduce portability and should be a deliberate future decision.

---

# Decision 004: Use Auto processing mode with local and worker paths

## Decision

The plugin should support a processing mode setting:

```text
Auto
WordPress only
Worker only
```

Default recommendation: `Auto`.

In Auto mode, small files can process locally and large files can be delegated to the worker.

## Rationale

Auto mode gives the best practical balance:

- simple files remain simple
- large files avoid WordPress resource limits
- the user has control if a specific environment requires local-only or worker-only behavior

## Tradeoffs

- Requires clear threshold logic.
- Requires both local and worker processing code paths.
- Requires admin settings to explain the behavior clearly.

## Date Adopted

2026-05-25

## Reversibility

Easy. The default can be changed later.

---

# Decision 005: WordPress decides when to use the worker

## Decision

WP Audio Buddy decides whether a job should run locally or through the worker.

The worker should not be responsible for product-level routing decisions.

## Rationale

The plugin knows:

- site configuration
- attachment metadata
- admin preference
- local processing mode
- file size threshold
- job type
- retry behavior

The worker should remain a processing service and should not decide product policy.

## Tradeoffs

- Plugin logic must be explicit and well-tested.
- Incorrect thresholds could route jobs poorly.
- Admin settings must be clear.

## Date Adopted

2026-05-25

## Reversibility

Easy. Routing rules can be adjusted over time.

---

# Decision 006: Final transcripts live in WordPress, not the worker

## Decision

The worker may create transcript output during processing, but final transcripts, summaries, excerpts, and generated outputs must be stored durably in WordPress.

## Rationale

The WordPress plugin owns the content. Storing final results in the worker would split the source of truth and make backup, recovery, editing, and display more complicated.

## Tradeoffs

- Large transcript writes must be handled carefully in WordPress.
- Callback payload sizes must be managed.
- Future export tools may be needed.

## Date Adopted

2026-05-25

## Reversibility

Difficult after launch. A future cloud sync layer could exist, but WordPress should remain the v1 durable store.

---

# Decision 007: Prefer custom tables for plugin-owned operational data

## Decision

Use custom database tables for plugin-owned operational data such as jobs and transcripts.

Expected starting tables:

```text
wp_wpab_jobs
wp_wpab_transcripts
```

Potential future tables:

```text
wp_wpab_generated_outputs
wp_wpab_job_logs
```

## Rationale

Processing jobs, transcripts, summaries, and operational records are structured plugin data. They should not be scattered across `options`, `postmeta`, or other WordPress default tables unless the data naturally belongs there.

This aligns with the user preference for clean, understandable plugin-owned database structure.

## Tradeoffs

- Requires schema creation and migrations.
- Requires indexes and data access code.
- Requires uninstall/retention decisions.
- Slightly more initial development than storing everything in post meta.

## Date Adopted

2026-05-25

## Reversibility

Moderate. Data can be migrated, but it is better to choose the right storage model early.

---

# Decision 008: Use WordPress options only for settings

## Decision

Use the WordPress options table for plugin configuration and small settings only.

Examples:

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

## Rationale

Options are appropriate for settings, feature flags, and connection configuration. They are not appropriate for large transcripts or high-volume operational records.

## Tradeoffs

- Settings need proper sanitization.
- Secrets need careful handling.
- Some settings may need migration if names change.

## Date Adopted

2026-05-25

## Reversibility

Easy.

---

# Decision 009: Use Action Scheduler for local background work

## Decision

Use Action Scheduler as the preferred WordPress-side background job runner.

## Rationale

Transcription, summary generation, worker dispatch, retry operations, and cleanup should not happen during normal admin page loads or form submissions.

Action Scheduler is a proven WordPress background processing pattern and is better suited than raw WP-Cron for job visibility and control.

## Tradeoffs

- Requires Action Scheduler dependency or bundled/available installation.
- Requires clear job naming and retry rules.
- Hosts with broken cron may still require manual or server cron configuration.

## Date Adopted

2026-05-25

## Reversibility

Moderate. Another job runner could be adopted later, but it would require migration of job handling.

---

# Decision 010: Worker communication requires HMAC signing

## Decision

All WordPress-to-worker requests and worker-to-WordPress callbacks must use HMAC signatures.

Expected headers:

```text
X-WPAB-Site-ID
X-WPAB-Timestamp
X-WPAB-Signature
```

## Rationale

The worker and plugin exchange sensitive job instructions and processing results. HMAC signing protects against unauthorized job submission and forged callbacks.

A shared secret alone in a URL is not sufficient.

## Tradeoffs

- Requires careful implementation on both sides.
- Requires clock tolerance handling.
- Requires clear signature payload documentation.
- Requires per-site secret management.

## Date Adopted

2026-05-25

## Reversibility

Should not be reversed. The exact signing format can evolve, but signed communication should remain required.

---

# Decision 011: Use short-lived signed URLs for worker audio access

## Decision

When the worker needs to process an audio file, WordPress should provide a short-lived signed download URL rather than uploading the entire file through the initial job request.

## Rationale

Large audio uploads through admin requests are fragile. A signed download URL allows the worker to fetch the file directly while keeping access controlled and temporary.

## Tradeoffs

- Requires signed URL generation in WordPress.
- Requires expiration handling.
- Requires the worker to handle download failures and timeouts.
- Some hosting environments may restrict direct file access.

## Date Adopted

2026-05-25

## Reversibility

Easy to moderate. Direct upload could be added later as an alternate mode.

---

# Decision 012: OpenAI key ownership depends on processing mode

## Decision

For WordPress-only local processing, WordPress may store and use an OpenAI API key.

For worker processing, the worker should own and use its own OpenAI API key.

## Rationale

When the worker performs transcription, it is safer and cleaner for the worker to own the OpenAI key. This avoids sending API keys from WordPress to the worker in job payloads.

WordPress may still need its own key if local processing is enabled.

## Tradeoffs

- Two possible OpenAI key locations exist.
- Admin settings must make this clear.
- Worker deployment requires environment-level secret management.
- Sites using only worker mode may not need a WordPress OpenAI key.

## Date Adopted

2026-05-25

## Reversibility

Easy. The policy can be refined later.

---

# Decision 013: Keep admin UI simple in v1

## Decision

Use a simple, clean WordPress admin UI in v1. Do not build a heavy SPA unless complexity requires it later.

## Rationale

The core value is reliable audio processing and generated text. A heavy UI adds complexity before the processing model is fully proven.

The plugin should feel modern and clear, but still behave like a good WordPress admin tool.

## Tradeoffs

- Some interactions may be less dynamic initially.
- More advanced transcript editing may require richer UI later.
- Future Vue admin screens may need a design-system pass.

## Date Adopted

2026-05-25

## Reversibility

Easy. A richer admin UI can be added later.

---

# Decision 014: Preserve plugin data on uninstall by default

## Decision

By default, plugin data should be preserved on uninstall unless the administrator explicitly enables deletion.

## Rationale

Transcripts and summaries may be valuable content. Accidental deletion would be costly.

## Tradeoffs

- Uninstall may leave custom tables behind by default.
- Admin UI must clearly explain the data retention setting.
- Full cleanup requires explicit opt-in.

## Date Adopted

2026-05-25

## Reversibility

Easy.

---

# Decision 015: Maintain separate architecture docs for plugin and worker

## Decision

`wp-audio-buddy` and `wpab-worker` each maintain their own `ARCHITECTURE.md`, `PROJECT_RULES.md`, and `DECISIONS.md`.

## Rationale

The plugin and worker have different responsibilities, stacks, and constraints. Separate docs prevent agents from applying WordPress-native assumptions to the worker or backend-worker assumptions to the plugin.

## Tradeoffs

- Some boundary decisions are duplicated across repos.
- Docs must be kept aligned.
- Changes to the integration contract may require updates in both repos.

## Date Adopted

2026-05-25

## Reversibility

Easy, but not recommended.

|---

# Decision 016: Normalize plugin to PSR-4 namespaced structure with src/ directory

## Decision

The plugin was restructured from flat global-namespace classes in `includes/` to a PSR-4 Composer-autoloaded structure under `src/`, organized by architectural layer (Controllers, Services, Data, Support).

## Rationale

The flat `includes/` structure with manual `require_once` was the original pattern but drifted from the documented target structure in both `ARCHITECTURE.md` and `PROJECT_RULES.md`. The new structure:

- Enables PSR-4 autoloading via Composer
- Uses proper PHP namespaces (`AdrielPartners\WpAudioBuddy\{Layer}\`)
- Separates code into clear architectural layers (Controllers handle orchestration, Services own business logic, Data handles persistence, Support holds narrow utilities)
- Reduces the main plugin file from 87 lines to 23 lines (bootstrap only)
- Aligns with the documented target folder layout

## Tradeoffs

- Requires `composer dump-autoload` to generate the autoloader before the plugin works
- Old `includes/` files remain on disk (not loaded) as a safety reference until verified
- Existing WordPress sites with stored option keys are unaffected (option key `wpab_settings` preserved)
- All action/filter hook names, REST route names, and admin page slugs are preserved — no breaking changes to external integrations

## Date Adopted

2026-05-25

## Reversibility

Easy. The old structure is preserved on disk (includes/) and could be restored by reverting wp-audio-buddy.php and removing composer.json.

|---

# Decision 017: Add explicit processing mode setting with allowlist validation

## Decision

Added a `processing_mode` setting with three validated modes — `auto`, `wordpress_only`, and `worker_only` — replacing the previous implicit worker-detection logic that checked only whether worker URL and secret were configured.

## Rationale

The previous logic treated a configured worker URL as "worker always available," which meant there was no way to force WordPress-only processing or force worker-only processing. The plugin's architecture documents explicitly call for three modes. This change also moves the file-size threshold from a hardcoded constant (20 MB) into a user-configurable setting, and adds `worker_site_id` for multi-site identification.

## Tradeoffs

- Existing installations with worker URL set will continue in `auto` mode by default (backwards compatible)
- The `worker_enabled` method was renamed to `worker_is_configured()` to distinguish configuration from routing policy
- MediaController's worker indicator now respects the processing mode — it won't show "Processing on VPS worker" in `wordpress_only` mode

## Date Adopted

2026-05-26

## Reversibility

Easy. Default to `auto` matches pre-existing behavior.

|---

# Decision 018: Implement custom tables for jobs and transcripts

## Decision

Created two plugin-owned custom database tables — `wp_wpab_jobs` and `wp_wpab_transcripts` — with corresponding `JobRepository` and `TranscriptRepository` classes. The existing `wpab_logs` table (created by LoggerRepository) was documented alongside them.

## Rationale

The architecture documents and Decision 007 explicitly call for custom tables instead of postmeta for plugin-owned operational data. The new tables:

- Store structured job records with proper column types, indexes, and UUID references
- Store transcript bodies in LONGTEXT columns instead of serialized postmeta
- Support efficient queries by status, attachment, operation, and date
- Follow WordPress naming conventions with prefix and dbDelta() creation

## Tradeoffs

- Existing data in postmeta is NOT migrated — this phase creates the infrastructure only
- The postmeta-driven workflows (MediaController, TranscriptionService, etc.) still use the old storage until Phase 4+
- Activation now creates three tables (logs, jobs, transcripts) which may slightly increase activation time
- Uninstall respects the `delete_data_on_uninstall` setting and removes all three tables + options + meta

## Date Adopted

2026-05-26

## Reversibility

Moderate. Tables could be dropped and the plugin would fall back to postmeta-only operation, but data in custom tables would be lost.

|---

# Decision 019: Wire JobRepository into service layer for dual-write job tracking

## Decision

Injected `JobRepository` into `QueueService`, `TranscriptionService`, `WorkerCallbackController`, `MediaController`, and `BulkToolsController`. Also injected `TranscriptRepository` into `TranscriptionService`. The plugin now creates and updates job records in the custom `wpab_jobs` table while continuing to write postmeta for backward compatibility.

## Rationale

Phase 3 created the infrastructure (tables + repositories). Phase 4 wires them into the active code paths. The flow is:

1. **Job creation**: `QueueService::enqueue_transcription()` and `enqueue_excerpt()` insert a job record with status `queued`, operation, and processing mode
2. **Job progress**: `TranscriptionService::handle()` sets `running`, `save_final_transcript()` sets `completed`, `fail()` sets `failed` with error info
3. **Worker path**: `TranscriptionService::dispatch_to_worker()` sets `waiting_for_worker`; `WorkerCallbackController` sets `completed` or `failed` on callback
4. **Transcript storage**: `save_final_transcript()` inserts into the `wpab_transcripts` table alongside the existing postmeta write
5. **Display**: `MediaController` reads job status from the job table (falling back to postmeta) and maps statuses for display
6. **Bulk counts**: `BulkToolsController` counts from both postmeta and job tables, taking the max

## Tradeoffs

- Dual-write increases write operations slightly (postmeta + custom table)
- Jobs without a custom table record fall back gracefully to postmeta display
- The `update_by_attachment()` convenience method was added to JobRepository to support the callback workflow
- TranscriptionService now carries 7 constructor dependencies — a sign that further refactoring (e.g., dedicated processing service) may be warranted later

## Date Adopted

2026-05-26

## Reversibility

Moderate. Tables retain the data but the code paths are committed.

|---

# Decision 020: Frontend shortcodes, generated outputs table, and final documentation

## Decision

Added public-facing shortcodes (`[wpab_transcript]`, `[wpab_excerpt]`), global template functions (`wpab_get_transcript`, `wpab_get_excerpt`), a `wp_wpab_generated_outputs` custom table with `GeneratedOutputRepository`, bounded retry for excerpt generation, PHPUnit test scaffolding, and a comprehensive `README.md`.

## Rationale

All 12 phases of the implementation plan are now complete. These final additions close the remaining gaps:

- Shortcodes and template functions let site owners display generated content on the public site without depending on a specific page builder
- The `wpab_generated_outputs` table provides structured storage for excerpts/summaries alongside the existing jobs and transcripts tables
- Bounded retry for excerpts (2 attempts via Action Scheduler) mirrors the retry behavior already present for transcription
- Test scaffolding enables future development with PHPUnit
- The README documents setup, configuration, custom tables, shortcodes, worker setup, and known limitations

## Tradeoffs

- Shortcodes read from postmeta (not custom tables), which means they show the editable version of transcripts/excerpts — consistent with the admin UI behavior
- Excerpt retry uses WordPress transients for attempt counting rather than the `attempt_count` column on the jobs table, since excerpt jobs don't currently use the jobs table for state tracking

## Date Adopted

2026-05-26

## Reversibility

Easy. Shortcodes and template functions can be removed without affecting stored data.

---

# Maintenance Rule

Update this file when:

- a major architectural decision is made
- a previous decision is reversed
- data ownership changes
- worker boundaries change
- processing mode changes
- security model changes
- OpenAI key ownership changes
- storage strategy changes

Do not let major decisions live only in chat history.
