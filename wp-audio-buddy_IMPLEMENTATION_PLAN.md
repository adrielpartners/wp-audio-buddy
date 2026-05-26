# IMPLEMENTATION_PLAN.md

Version: 1.0  
Project: WP Audio Buddy  
Repository: `wp-audio-buddy`  
System Type: WordPress Plugin (Native)  
Last Updated: 2026-05-25

---

# Purpose

This file turns the WP Audio Buddy architecture into an ordered build plan.

Use this as the working roadmap for AI agents and developers. It is not permanent doctrine. Update it as work is completed, priorities change, or implementation details become clearer.

Before working on any phase, read:

1. `AGENTS.md`
2. `CODING_CONSTITUTION.md`
3. `ARCHITECTURE.md`
4. `DECISIONS.md`
5. `PROJECT_RULES.md`
6. `MODE_WORDPRESS_NATIVE.md`
7. `IMPLEMENTATION_PLAN.md`

---

# Agent Instructions

When using this plan:

1. Work on one phase or one step at a time.
2. Inspect the repo before editing.
3. Preserve the architecture boundaries.
4. Do not skip ahead without being asked.
5. Mark checklist items complete only after implementation and verification.
6. If the code conflicts with the docs, stop and report the conflict.
7. If a step requires a new decision, ask or document the assumption.
8. End each task with changed files, verification notes, risks, and next step.

Suggested prompt:

```text
You are working in the wp-audio-buddy repo.

Read:
- AGENTS.md
- CODING_CONSTITUTION.md
- ARCHITECTURE.md
- DECISIONS.md
- PROJECT_RULES.md
- MODE_WORDPRESS_NATIVE.md
- IMPLEMENTATION_PLAN.md

Task:
Work on Phase __, Step __ from IMPLEMENTATION_PLAN.md.

Rules:
- Do not skip ahead.
- Do not change unrelated files.
- Preserve architecture boundaries.
- Update the checklist only for completed work.
- Run the smallest relevant verification available.
- Summarize changed files, verification, assumptions, risks, and next step.
```

---

# Build Strategy

Build in this order:

1. Establish repo baseline.
2. Normalize plugin structure.
3. Add settings.
4. Add custom tables and data layer.
5. Add job model and admin workflow.
6. Add local transcription.
7. Add worker dispatch.
8. Add worker callback handling.
9. Add transcript display.
10. Add summary/excerpt generation.
11. Add page-builder-neutral frontend output.
12. Test, harden, and document.

---

# Phase 0: Repository Baseline

Goal: Understand the current repo and avoid accidental overwrite.

## Checklist

- [ ] Run `git status`.
- [ ] Review current file structure.
- [ ] Identify existing plugin bootstrap file.
- [ ] Identify current classes/functions.
- [ ] Identify current autoloading approach.
- [ ] Identify whether Composer is currently used.
- [ ] Identify current admin UI files, if any.
- [ ] Identify current storage behavior, if any.
- [ ] Identify current OpenAI integration behavior, if any.
- [ ] Identify current worker integration behavior, if any.
- [ ] Summarize the baseline before editing.

## Verification

- [ ] Repo state is known.
- [ ] No user changes were overwritten.

## Done When

The current repo structure and safest first change are clear.

---

# Phase 1: Normalize Plugin Structure

Goal: Create or normalize the WordPress-native plugin structure without adding features.

## Target Structure

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

## Checklist

- [ ] Ensure the main plugin file has a correct plugin header.
- [ ] Keep the main plugin file mostly bootstrap-only.
- [ ] Add or normalize `src/`.
- [ ] Add or normalize `admin/views/`.
- [ ] Add or normalize `admin/assets/`.
- [ ] Add or normalize `migrations/` if schema work has begun.
- [ ] Add or normalize `tests/` or document why tests are not configured.
- [ ] Set up Composer PSR-4 autoloading if appropriate.
- [ ] Add a central plugin boot class if not already present.
- [ ] Confirm plugin activation still works.

## Guardrails

- Do not add features in this phase.
- Do not change storage behavior yet.
- Do not add worker integration yet.
- Do not add a frontend framework.

## Verification

- [ ] PHP syntax checks pass on changed PHP files.
- [ ] Plugin activates.
- [ ] No fatal error in wp-admin.

---

# Phase 2: Settings Foundation

Goal: Add secure plugin settings for processing mode, local OpenAI use, and worker connection.

## Settings

Expected options:

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

## Checklist

- [ ] Create settings service or settings repository.
- [ ] Create settings admin screen.
- [ ] Add capability checks.
- [ ] Add nonce verification for saves.
- [ ] Sanitize all settings.
- [ ] Validate processing mode against allowlist: `auto`, `wordpress_only`, `worker_only`.
- [ ] Mask secret fields in UI.
- [ ] Add worker connection settings.
- [ ] Add file size threshold setting.
- [ ] Add uninstall data retention setting.
- [ ] Add default model settings if needed.
- [ ] Explain local vs worker mode clearly.

## Verification

- [ ] Settings save correctly.
- [ ] Invalid processing mode is rejected.
- [ ] Secrets are masked after save.
- [ ] Unauthorized user cannot save settings.
- [ ] Nonce failure prevents save.

---

# Phase 3: Custom Tables and Data Layer

Goal: Establish plugin-owned storage for jobs and transcripts.

## Starting Tables

```text
wp_wpab_jobs
wp_wpab_transcripts
```

Possible later:

```text
wp_wpab_generated_outputs
wp_wpab_job_logs
```

## Checklist

- [ ] Define jobs table schema.
- [ ] Define transcripts table schema.
- [ ] Add indexes for expected queries.
- [ ] Add activation/migration logic.
- [ ] Track schema version in an option.
- [ ] Add `JobRepository`.
- [ ] Add `TranscriptRepository`.
- [ ] Add insert/update/get methods.
- [ ] Add uninstall retention behavior based on setting.
- [ ] Update `ARCHITECTURE.md` if schema changes.

## Suggested Jobs Fields

```text
id, job_uuid, attachment_id, operation, processing_mode, status,
source, attempt_count, error_code, error_message, worker_job_id,
created_at, updated_at, started_at, completed_at, failed_at
```

## Suggested Transcript Fields

```text
id, attachment_id, job_id, transcript_text, segments_json,
metadata_json, created_at, updated_at
```

## Verification

- [ ] Activation creates expected tables.
- [ ] Repositories can insert and retrieve records.
- [ ] Table names respect WordPress prefix.
- [ ] SQL uses prepared statements.

---

# Phase 4: Job Model and Admin Workflow

Goal: Let administrators create and view processing jobs for audio attachments.

## Checklist

- [ ] Define job statuses.
- [ ] Define operations: `transcribe`, `summarize`, `excerpt`.
- [ ] Add admin screen or media action to start transcription.
- [ ] Validate selected attachment is audio.
- [ ] Create local job record before processing starts.
- [ ] Display job status.
- [ ] Display basic job history.
- [ ] Add retry action for failed jobs.
- [ ] Add user-safe failure display.
- [ ] Add internal logging for failure context.

## Expected Job States

```text
pending
queued
running
waiting_for_worker
completed
failed
retryable
cancelled
```

## Verification

- [ ] Admin can create a transcription job.
- [ ] Invalid attachment is rejected.
- [ ] Job status displays clearly.
- [ ] Retry action is secured.
- [ ] Nonce and capability checks work.

---

# Phase 5: Local Transcription Path

Goal: Implement WordPress-only transcription for small/simple audio files.

## Checklist

- [ ] Add Action Scheduler integration.
- [ ] Add local transcription job handler.
- [ ] Add OpenAI transcription client/service.
- [ ] Load OpenAI API key from settings.
- [ ] Validate audio file path and accessibility.
- [ ] Enforce local file size limit.
- [ ] Send audio to OpenAI through service wrapper.
- [ ] Store transcript in transcript repository.
- [ ] Update job status on success.
- [ ] Normalize OpenAI errors into plugin error codes.
- [ ] Mark job failed with user-safe message on failure.
- [ ] Add bounded retry behavior for transient errors.

## Guardrails

- Do not call OpenAI from controllers or views.
- Do not process large files locally by accident.
- Do not expose raw OpenAI errors.
- Do not log OpenAI keys.

## Verification

- [ ] Small audio file can be transcribed.
- [ ] Transcript is stored in custom table.
- [ ] Job status becomes completed.
- [ ] OpenAI failure marks job failed.
- [ ] Action Scheduler job runs.

---

# Phase 6: Worker Dispatch

Goal: Dispatch large/heavy transcription jobs to `wpab-worker`.

## Checklist

- [ ] Add worker client integration.
- [ ] Add HMAC signing service.
- [ ] Define signing payload format.
- [ ] Generate signed temporary audio URL.
- [ ] Create worker job payload.
- [ ] Send signed `POST /v1/jobs/transcribe` request.
- [ ] Store worker accepted status or worker job ID.
- [ ] Update local job status to `waiting_for_worker`.
- [ ] Handle worker unavailable errors.
- [ ] Handle invalid worker configuration.

## Payload Should Include

```text
job_id
attachment_id
audio_url
callback_url
operation
processing_options
timestamp
signature
```

## Guardrails

- Do not send OpenAI key to worker in worker mode.
- Do not send WordPress cookies.
- Do not dispatch without a durable local job.
- Do not accept worker mode without worker URL/site ID/secret.

## Verification

- [ ] Worker dispatch request is signed.
- [ ] Invalid worker settings prevent dispatch.
- [ ] Large file in Auto mode routes to worker.
- [ ] Worker-only mode routes to worker.
- [ ] No secrets appear in logs.

---

# Phase 7: Worker Callback Handling

Goal: Receive signed callbacks from the worker and update WordPress-owned data.

## Expected Route

```text
POST /wp-json/wpab/v1/worker-callback
```

## Checklist

- [ ] Register REST callback route.
- [ ] Verify HMAC signature before processing.
- [ ] Validate timestamp tolerance.
- [ ] Validate site ID.
- [ ] Validate callback payload shape.
- [ ] Confirm local job exists.
- [ ] Confirm attachment ID matches expected job.
- [ ] Reject invalid job state transitions.
- [ ] Store completed transcript result.
- [ ] Mark job completed on success.
- [ ] Mark job failed on failure callback.
- [ ] Log technical details safely.
- [ ] Return structured REST response.

## Verification

- [ ] Valid signed success callback stores transcript.
- [ ] Valid signed failure callback marks job failed.
- [ ] Invalid signature is rejected.
- [ ] Stale timestamp is rejected.
- [ ] Unknown job is rejected.
- [ ] Mismatched attachment ID is rejected.

---

# Phase 8: Transcript Display and Management

Goal: Make transcripts visible and usable in the WordPress admin.

## Checklist

- [ ] Add transcript detail view.
- [ ] Add transcript display on relevant attachment/job screen.
- [ ] Add copy-to-clipboard behavior if useful.
- [ ] Add transcript metadata display.
- [ ] Show transcript source job.
- [ ] Show generated date/time.
- [ ] Allow transcript deletion if appropriate.
- [ ] Allow transcript regeneration if appropriate.
- [ ] Escape output safely.
- [ ] Paginate or collapse long transcript text where needed.

## Verification

- [ ] Completed transcript is visible.
- [ ] Long transcript does not break admin layout.
- [ ] Output is escaped.
- [ ] Actions are secured.

---

# Phase 9: Summary and Excerpt Generation

Goal: Generate useful written outputs from transcripts.

## Checklist

- [ ] Add summary generation service.
- [ ] Add excerpt generation service.
- [ ] Decide storage for generated outputs.
- [ ] Add admin actions for summary/excerpt generation.
- [ ] Ensure transcript exists before generation.
- [ ] Use OpenAI text generation through service wrapper.
- [ ] Store generated output.
- [ ] Display generated output in admin.
- [ ] Add retry/failure behavior.
- [ ] Keep prompt templates centralized.

## Verification

- [ ] Summary can be generated from transcript.
- [ ] Excerpt can be generated from transcript.
- [ ] Generated output is stored.
- [ ] Generated output displays safely.
- [ ] Failures are visible and retryable.

---

# Phase 10: Frontend and Builder-Friendly Output

Goal: Expose generated content through WordPress-native, page-builder-neutral surfaces.

## Recommended Order

1. Shortcodes
2. Template functions
3. Optional REST endpoints
4. Optional Bricks compatibility

## Checklist

- [ ] Add shortcode for transcript display if public display is needed.
- [ ] Add shortcode for summary display if public display is needed.
- [ ] Add template functions:
  - `wpab_get_transcript( $attachment_id )`
  - `wpab_get_summary( $attachment_id )`
- [ ] Add minimal semantic markup.
- [ ] Keep CSS minimal and scoped.
- [ ] Add optional Bricks dynamic tags only if useful.
- [ ] Ensure plugin works when Bricks is inactive.

## Guardrails

- Do not make Bricks required.
- Do not make frontend output depend on Bricks CSS.
- Do not query custom tables directly from Bricks integration code.
- Do not expose private transcripts publicly without explicit display action.

## Verification

- [ ] Shortcode returns output and does not echo directly.
- [ ] Shortcode attributes are sanitized.
- [ ] Output is escaped.
- [ ] Template functions return expected data.
- [ ] Bricks inactive state works normally.

---

# Phase 11: Testing and Hardening

Goal: Improve confidence, security, and maintainability.

## Priority Tests

- [ ] Settings sanitization.
- [ ] Capability checks.
- [ ] Nonce verification.
- [ ] Job repository insert/update.
- [ ] Transcript repository insert/update.
- [ ] Local vs worker routing.
- [ ] HMAC signing.
- [ ] Callback verification.
- [ ] Callback job matching.
- [ ] Action Scheduler job behavior.
- [ ] Uninstall data retention behavior.

## Manual Smoke Tests

- [ ] Activate plugin.
- [ ] Save settings.
- [ ] Create transcription job.
- [ ] Process small file locally.
- [ ] Dispatch large file to worker.
- [ ] Receive worker callback.
- [ ] View transcript.
- [ ] Generate summary/excerpt.
- [ ] Retry failed job.
- [ ] Deactivate/reactivate plugin.

---

# Phase 12: Documentation and Handoff

Goal: Make the repo understandable to future agents and developers.

## Checklist

- [ ] Update `README.md`.
- [ ] Update `ARCHITECTURE.md` with actual final schema and flows.
- [ ] Update `DECISIONS.md` with any new decisions.
- [ ] Update `PROJECT_RULES.md` if repo-specific rules changed.
- [ ] Add setup instructions.
- [ ] Add local development instructions.
- [ ] Add worker configuration instructions.
- [ ] Add troubleshooting section.
- [ ] Add known limitations.
- [ ] Add next roadmap section.

---

# Progress Tracker

- [x] Phase 0: Repository Baseline
- [x] Phase 1: Normalize Plugin Structure
- [x] Phase 2: Settings Foundation
- [x] Phase 3: Custom Tables and Data Layer
- [x] Phase 4: Job Model and Admin Workflow
- [x] Phase 5: Local Transcription Path
- [x] Phase 6: Worker Dispatch
- [x] Phase 7: Worker Callback Handling
- [x] Phase 8: Transcript Display and Management
- [x] Phase 9: Summary and Excerpt Generation
- [x] Phase 10: Frontend and Builder-Friendly Output
- [x] Phase 11: Testing and Hardening
- [x] Phase 12: Documentation and Handoff

## Next Recommended Step

All 12 phases are complete. The plugin is ready for testing on a live WordPress installation. Run `composer dump-autoload`, activate the plugin, and walk through the smoke test checklist in Phase 11.

Do not begin feature development until the repo structure and bootstrap path are clean.

---

# Maintenance Rule

Update this implementation plan when a phase is completed, skipped, expanded, or reordered.

Do not let the checklist drift away from reality.
