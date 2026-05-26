# MODE_WORDPRESS_NATIVE.md

Version: 1.0  
Mode: WordPress Plugin (Native)  
Purpose: Define the reusable engineering rules for native WordPress plugin projects.

---

# 1. Purpose of This Mode

Use this mode when a project is a native WordPress plugin.

A native WordPress plugin is a plugin where WordPress is the primary application host. The plugin runs inside WordPress, uses WordPress APIs and security patterns, and stores its own durable data in WordPress-owned storage when appropriate.

This mode applies to projects such as:

- WP Audio Buddy
- Marketing Hero, if kept WordPress-native
- internal Adriel Partners WordPress plugins
- future plugin-first products that run inside wp-admin

This mode does not apply to:

- standalone SaaS applications
- headless frontends
- external worker services
- pure JavaScript apps
- WordPress plugins that are only thin connectors to an external SaaS backend

If a project is hybrid, document that explicitly in `ARCHITECTURE.md` and add a separate hybrid mode file if needed.

---

# 2. Core Principle

A native WordPress plugin should feel like a well-built WordPress product, not a fragile pile of hooks and helper functions.

Default flow:

```text
Hook
→ Controller
→ Service
→ Data Layer
```

Hooks connect WordPress to the plugin.

Controllers coordinate requests.

Services own business behavior.

The data layer owns persistence.

Views render output.

Do not let WordPress hooks, admin pages, or shortcodes become the application architecture.

---

# 3. Required Documentation

Every native WordPress plugin repo should include:

```text
CODING_CONSTITUTION.md
AGENTS.md
ARCHITECTURE.md
PROJECT_RULES.md
DECISIONS.md
MODE_WORDPRESS_NATIVE.md
```

Use these roles:

- `CODING_CONSTITUTION.md` — global engineering principles
- `AGENTS.md` — operating instructions for AI agents
- `ARCHITECTURE.md` — factual blueprint for the specific project
- `PROJECT_RULES.md` — repo-specific rules and enforcement
- `DECISIONS.md` — durable decision history
- `MODE_WORDPRESS_NATIVE.md` — reusable WordPress-native rules

This mode file defines defaults. Project-specific docs may narrow or extend these defaults.

---

# 4. WordPress-Native Responsibilities

A native WordPress plugin may own:

- admin screens
- settings pages
- custom database tables
- custom capabilities
- shortcodes
- blocks
- REST routes
- Ajax actions
- custom post types
- custom taxonomies
- custom fields/meta
- background jobs
- integrations with external services
- frontend display features
- template functions
- hooks and filters for extensibility

A native WordPress plugin should not assume:

- shell access
- root access
- persistent background processes
- custom server packages
- external queues unless explicitly configured
- modern JavaScript build tooling unless justified
- a specific theme or page builder
- a specific hosting provider

---

# 5. Architectural Layers

## Hooks

Hooks connect WordPress events to plugin code.

Good hook responsibilities:

- register activation and deactivation callbacks
- register admin menus
- register REST routes
- register Ajax actions
- enqueue assets
- register shortcodes
- register blocks
- register scheduled jobs
- connect WordPress events to controllers

Bad hook responsibilities:

- direct SQL
- business logic
- external API calls
- long-running workflows
- rendering large admin screens
- complex conditional logic
- data transformation

Hooks should be small and boring.

## Controllers

Controllers coordinate input and output.

Good controller responsibilities:

- check capabilities
- verify nonces
- validate request shape
- call services
- return REST responses
- redirect after admin actions
- pass data to views

Bad controller responsibilities:

- direct SQL
- direct external API calls
- rendering complex HTML inline
- owning business rules
- doing long-running work
- manipulating plugin schema

Controllers should be thin.

## Services

Services own business behavior.

Good service responsibilities:

- orchestrate workflows
- make product-level decisions
- coordinate repositories
- call integration clients
- normalize provider responses
- trigger jobs
- enforce domain rules

Bad service responsibilities:

- echo output
- render templates
- run raw SQL inline when a repository exists
- act as vague catch-all utilities

Services should have clear names and clear responsibilities.

## Data Layer

The data layer owns persistence.

Good data layer responsibilities:

- custom table queries
- repository methods
- schema-aware inserts and updates
- prepared SQL
- model hydration if used
- transaction-like coordination where practical

Bad data layer responsibilities:

- UI rules
- capability checks
- nonce checks
- external API calls
- business workflow orchestration

## Views

Views render output.

Good view responsibilities:

- render admin HTML
- escape output
- display user-safe status messages
- display forms and tables
- display data passed from controllers

Bad view responsibilities:

- database queries
- API calls
- capability checks as primary security
- business rules
- job execution

---

# 6. Recommended Folder Structure

Use this as a starting point:

```text
plugin-name/
  plugin-name.php
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
  public/
    assets/
      css/
      js/
  migrations/
  tests/
  docs/
  vendor/
  composer.json
  README.md
  ARCHITECTURE.md
  PROJECT_RULES.md
  DECISIONS.md
```

Adapt the structure to the project, but keep responsibilities separated.

## Placement Rules

- Bootstrap code belongs in the main plugin file.
- Controllers belong in `src/Controllers`.
- Business logic belongs in `src/Services`.
- Persistence belongs in `src/Data`.
- External API clients belong in `src/Integrations`.
- Job handlers belong in `src/Jobs`.
- Security helpers belong in `src/Security`.
- Views belong in `admin/views` or equivalent.
- Public assets belong in `public/assets`.
- Admin assets belong in `admin/assets`.

Avoid vague folders such as:

```text
helpers
misc
stuff
common
old
new
```

Use `Support` only for narrow utilities that genuinely do not belong elsewhere.

---

# 7. Bootstrap Rules

The main plugin file should do as little as possible.

It may:

- define constants
- load Composer autoloader
- load plugin bootstrap class
- register activation/deactivation hooks
- start the plugin

It should not:

- define many functions
- contain business logic
- render admin pages
- run SQL directly
- call external APIs
- define large classes inline

Good pattern:

```php
<?php
/**
 * Plugin Name: Example Plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'EXAMPLE_PLUGIN_FILE', __FILE__ );
define( 'EXAMPLE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXAMPLE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EXAMPLE_PLUGIN_DIR . 'vendor/autoload.php';

ExamplePlugin\Plugin::boot();
```

---

# 8. Namespacing and Autoloading

Use namespaces for non-trivial plugins.

Preferred:

```text
Vendor\PluginName\
```

Example:

```php
namespace AdrielPartners\WpAudioBuddy\Services;
```

Use Composer PSR-4 autoloading where practical.

Avoid global functions unless WordPress expects them or they are intentionally public template functions.

Prefix any global function, shortcode, hook name, option name, table name, transient name, or capability name.

Example prefixes:

```text
wpab_
mh_
ap_
```

---

# 9. Security Rules

Security is not optional.

## Capabilities

Every admin action must check capability.

Default:

```php
current_user_can( 'manage_options' )
```

For larger plugins, define a custom capability:

```text
manage_plugin_name
```

Grant it to administrators on activation if appropriate.

Do not rely on menu visibility as authorization.

## Nonces

Every admin write action must verify a nonce.

Use nonces for:

- settings saves
- admin forms
- delete actions
- retry actions
- generation actions
- Ajax actions
- REST actions when cookie-authenticated and state-changing

## Sanitization

Sanitize all input at the boundary.

Common functions:

```php
absint()
sanitize_key()
sanitize_text_field()
sanitize_textarea_field()
sanitize_email()
esc_url_raw()
wp_unslash()
```

Use allowlists for action names, modes, statuses, model names, and other controlled values.

## Escaping

Escape all output at render time.

Common functions:

```php
esc_html()
esc_attr()
esc_url()
esc_textarea()
wp_kses_post()
```

Use `wp_kses_post()` only when HTML is intentionally allowed.

## SQL

Use `$wpdb->prepare()` for SQL with dynamic values.

Never concatenate untrusted input into SQL.

Custom table names should be assembled only from trusted prefixes and constants.

## Secrets

Never log or expose:

- API keys
- shared secrets
- tokens
- private keys
- signed URLs
- raw authorization headers

When showing saved secrets in admin UI, mask them.

---

# 10. Data Storage Rules

Choose storage based on data shape and ownership.

## Use Options For

Use WordPress options for:

- plugin settings
- feature flags
- API connection settings
- small configuration values
- uninstall preferences

Do not use options for high-volume records, logs, transcripts, analytics events, or structured operational data.

## Use Post Meta For

Use post meta for:

- lightweight post/attachment-specific flags
- simple references to plugin-owned records
- small values naturally tied to a post or attachment

Do not use post meta as the main storage for large or structured plugin data.

## Use User Meta For

Use user meta for:

- user-specific plugin preferences
- per-user UI settings
- user-specific integration mappings

Do not use user meta for global product data.

## Use Custom Post Types For

Use custom post types when the data is content-like and benefits from WordPress content features.

Good CPT candidates:

- resources
- lessons
- public-facing entries
- reusable content objects
- items that need permalinks or editorial workflows

Bad CPT candidates:

- job queues
- logs
- analytics events
- large operational records
- transcript segments
- high-volume internal records

## Use Custom Tables For

Use custom tables for plugin-owned structured data.

Good custom table candidates:

- processing jobs
- transcripts
- analytics events
- reports
- operational logs
- mapping tables
- records with custom indexes
- data that needs efficient querying

Custom tables require:

- clear schema ownership
- activation/migration logic
- indexes
- uninstall/retention policy
- repository/data layer access
- documentation

The user prefers custom tables for WordPress-native plugins when practical. Do not bury plugin-owned data in scattered default WordPress tables when a custom table is cleaner.

---

# 11. Custom Table Rules

Use custom tables intentionally.

## Naming

Use the WordPress prefix plus plugin prefix:

```text
{$wpdb->prefix}wpab_jobs
{$wpdb->prefix}mh_activity
```

## Schema

Schema should be documented in `ARCHITECTURE.md`.

Include:

- column names
- data types
- indexes
- ownership
- retention behavior
- migration path

## Creation

Use activation/migration logic.

`dbDelta()` may be used for schema creation and updates, but be careful with its formatting requirements.

## Migrations

For non-trivial plugins, track schema version in an option:

```text
plugin_name_db_version
```

Run migrations intentionally, not on every request.

## Access

All custom table access should go through repositories/data classes.

Do not scatter `$wpdb` queries across controllers, views, jobs, and services.

---

# 12. Settings Rules

Settings screens must be secure, clear, and boring.

Rules:

- check capability before rendering and saving
- verify nonce before saving
- sanitize every value
- validate controlled values against allowlists
- mask secrets
- provide clear descriptions
- avoid autoloading large options
- group settings logically

Use the Settings API when it helps. A custom settings controller is acceptable when the plugin architecture needs more control.

---

# 13. Admin UI Rules

Admin UI should feel native to WordPress unless there is a strong reason not to.

Use:

- clear headings
- WordPress notices
- tables where appropriate
- simple forms
- clear status badges
- scoped admin CSS
- minimal JavaScript

Avoid:

- loading frontend app frameworks by default
- global admin CSS pollution
- unexpected visual systems
- slow admin screens
- hidden background actions
- destructive actions without confirmation

Admin UI must always make clear:

- what object is being edited
- what action is being taken
- whether the action succeeded or failed
- what the user can do next

---

# 14. Frontend Display Rules

Plugins should be page-builder-neutral by default.

Expose frontend output through WordPress-native surfaces first:

- shortcodes
- blocks
- template functions
- hooks
- filters
- REST endpoints
- metadata where appropriate

Do not make a plugin dependent on a specific theme or page builder unless the project is explicitly a builder add-on.

## Shortcodes

Shortcodes are a useful compatibility layer.

Rules:

- prefix shortcode names
- sanitize attributes
- escape output
- return output instead of echoing
- document available attributes
- keep business logic out of shortcode callbacks

Good pattern:

```text
[plugin_prefix_item id="123"]
```

## Blocks

Blocks may be added when Gutenberg/editor integration is valuable.

Rules:

- blocks should call service-layer methods
- block rendering should not own business logic
- dynamic blocks are preferred when output depends on current plugin data
- editor assets should only load in editor context

## Template Functions

Template functions are useful for custom themes and advanced builders.

Rules:

- prefix function names
- keep functions stable
- return data or escaped markup intentionally
- document parameters and return values

Example:

```php
plugin_prefix_get_item( $id );
plugin_prefix_render_item( $id );
```

## REST Endpoints

Use REST endpoints for JSON, async UI, builder integrations, and external interactions.

Rules:

- define permission callbacks
- validate request data
- sanitize inputs
- return structured responses
- do not expose sensitive data
- do not use REST endpoints as a shortcut around service-layer architecture

---

# 15. Page Builder Compatibility

Native WordPress plugins must be page-builder-neutral by default.

Page builders are presentation consumers, not the plugin architecture.

Correct flow:

```text
Plugin Data / Service Layer
→ WordPress-native exposure point
→ Builder displays the data
```

Incorrect flow:

```text
Plugin Data
→ Builder-specific implementation
→ Builder becomes required for plugin usefulness
```

## General Builder Rules

Plugins should work with:

- Gutenberg
- Bricks
- Elementor
- Beaver Builder
- Oxygen
- custom themes
- no page builder

Do not assume a specific builder is active.

Do not store plugin data in a builder-specific format unless the plugin is explicitly a builder add-on.

Do not make frontend output depend on builder-specific CSS or JavaScript.

Use semantic markup and minimal default styling so themes and builders can control presentation.

## Bricks Builder Note

Adriel Partners commonly builds with Bricks, so native plugins may include optional Bricks compatibility when it creates real workflow value.

Good Bricks integrations:

- dynamic tags
- lightweight Bricks elements
- shortcode-friendly output
- template-function access
- clean semantic markup that Bricks can style
- optional controls that call plugin services

Rules for Bricks integrations:

- Bricks must not be required.
- Bricks-specific code must be conditional.
- Bricks-specific code must be isolated in an integration layer.
- Bricks integrations must call the plugin service layer.
- Bricks integrations must not query custom tables directly.
- Bricks integrations must not own business logic.
- Bricks integrations must not define the plugin data model.
- The plugin must continue working when Bricks is inactive.

Conditional pattern:

```php
if ( defined( 'BRICKS_VERSION' ) ) {
    // Register Bricks-specific integrations.
}
```

For most plugins, start with shortcodes and template functions. Add Bricks dynamic tags or elements only when the site-building workflow clearly benefits.

---

# 16. Asset Loading Rules

Load assets only where needed.

Admin assets:

- load only on plugin admin screens
- prefix handles
- version assets
- avoid global admin pollution

Frontend assets:

- load only when shortcode/block/output is present when practical
- keep CSS minimal
- avoid fighting the theme or builder
- do not load large JavaScript libraries casually

Do not enqueue assets across the whole site unless explicitly necessary.

---

# 17. Background Processing Rules

WordPress request-response cycles are not for heavy work.

Use background jobs for:

- AI processing
- imports
- exports
- sync operations
- report generation
- large batch updates
- retries
- cleanup

Preferred tool:

```text
Action Scheduler
```

WP-Cron may be used for simple scheduled cleanup, but Action Scheduler is better for visible, retryable jobs.

Rules:

- do not run heavy work during admin page loads
- do not run heavy work inside shortcode rendering
- use bounded retries
- record job status
- expose failures clearly
- make retries intentional

---

# 18. External Integration Rules

External service calls must go through integration classes.

Examples:

```text
OpenAIClient
WorkerClient
PaymentClient
EmailClient
CrmClient
```

Rules:

- set timeouts
- normalize errors
- avoid leaking provider errors to users
- retry only transient failures
- never log secrets
- keep provider-specific behavior out of controllers and views

---

# 19. REST API Rules

When creating REST endpoints:

- use a plugin namespace, such as `plugin-prefix/v1`
- define a real `permission_callback`
- validate request arguments
- sanitize input
- escape output if returning rendered HTML
- return `WP_REST_Response` or `WP_Error`
- avoid exposing private operational data
- document request and response shapes

Do not use:

```php
'permission_callback' => '__return_true'
```

unless the endpoint is genuinely public and read-only.

---

# 20. Ajax Rules

Prefer REST endpoints for new work unless admin-ajax is simpler and appropriate.

If using Ajax:

- register authenticated and/or unauthenticated actions intentionally
- check capability
- verify nonce
- sanitize input
- return JSON with `wp_send_json_success()` or `wp_send_json_error()`
- avoid heavy work inside the Ajax request

---

# 21. Cron and Scheduled Jobs

Use scheduled work for:

- cleanup
- recurring sync
- stale job checks
- periodic reporting
- retry queues

Rules:

- schedule on activation when appropriate
- unschedule on deactivation when appropriate
- avoid duplicate scheduled events
- keep scheduled handlers thin
- make long-running recurring work chunked and resumable

---

# 22. Activation, Deactivation, and Uninstall

## Activation

Activation may:

- create/update custom tables
- add capabilities
- set default options
- schedule recurring jobs
- flush rewrite rules only if needed

Activation must not:

- call external APIs unnecessarily
- run long imports
- perform expensive migrations without safeguards

## Deactivation

Deactivation may:

- unschedule plugin jobs
- stop recurring processes
- flush rewrite rules only if needed

Deactivation should not delete user data.

## Uninstall

Uninstall behavior must be explicit.

Default recommendation:

- preserve data unless the admin explicitly enabled deletion

If deleting data:

- delete custom tables
- delete plugin options
- delete plugin transients
- remove custom capabilities if appropriate
- document exactly what is removed

---

# 23. Error Handling Rules

Use consistent error codes for meaningful failures.

Errors should include:

- internal code
- user-safe message
- technical context for logs
- retryability when useful

Do not:

- show stack traces
- expose raw provider errors
- swallow errors silently
- redirect without a notice
- return vague failure states

Admin users should know what happened and what to try next.

---

# 24. Logging Rules

Log important operational events.

Useful logs:

- job created
- job started
- external request started/failed
- callback received
- import/export started
- import/export completed
- migration completed
- cleanup completed
- security validation failed

Never log:

- passwords
- API keys
- shared secrets
- access tokens
- private keys
- full signed URLs
- sensitive user data
- raw audio/transcript/content unless explicitly enabled for development

Debug logging should be optional and off by default.

---

# 25. Performance Rules

Native plugins must be careful with performance.

Rules:

- avoid expensive queries on every request
- avoid autoloading large options
- index custom tables for expected queries
- paginate admin lists
- batch large operations
- avoid loading assets globally
- avoid remote calls during page render
- avoid heavy work in shortcodes
- cache carefully when needed

Frontend output should be lightweight and builder-friendly.

---

# 26. Internationalization

For public or reusable plugins, prepare strings for translation.

Use:

```php
__( 'Text', 'text-domain' )
esc_html__( 'Text', 'text-domain' )
esc_attr__( 'Text', 'text-domain' )
```

Use a consistent text domain.

For internal-only tools, internationalization may be lower priority, but avoid hardcoding strings in ways that make future cleanup difficult.

---

# 27. Accessibility

Admin and frontend output should be accessible.

Minimum standards:

- semantic labels
- keyboard-usable controls
- visible focus states
- clear status messages
- avoid color-only indicators
- use proper table headings
- use button elements for actions
- use links for navigation

Do not build inaccessible custom controls when native controls would work.

---

# 28. Testing and Verification

Recommended testing:

- PHPUnit for PHP logic
- WordPress test suite for integration behavior
- Playwright or browser checks only when UI complexity justifies it
- manual smoke testing for admin workflows

Priority test areas:

- capability checks
- nonce verification
- settings sanitization
- repository queries
- custom table migrations
- REST permissions
- background job transitions
- external integration wrappers
- uninstall/data retention behavior

If tests do not exist, agents must report what was manually checked.

Never claim tests passed if they were not run.

---

# 29. AI Agent Rules for WordPress Plugins

AI agents working on native WordPress plugins must:

1. Inspect existing structure before editing.
2. Follow local project patterns.
3. Keep hooks thin.
4. Keep controllers thin.
5. Put business logic in services.
6. Put persistence in repositories/data classes.
7. Use WordPress security functions.
8. Sanitize input and escape output.
9. Avoid unnecessary dependencies.
10. Avoid global CSS/JS pollution.
11. Preserve page-builder neutrality.
12. Keep Bricks integrations optional and isolated.
13. Update docs when architecture changes.
14. Report what was tested.

AI agents must not:

- create plugin spaghetti
- bury logic in shortcode callbacks
- bury logic in admin view files
- add builder-specific dependencies casually
- store everything in post meta by default
- use options as a junk drawer
- skip capability or nonce checks
- bypass service and data layers
- invent a second architecture mid-task

---

# 30. Definition of Done

A native WordPress plugin task is done when:

- the change satisfies the request
- code is in the correct layer
- hooks are thin
- controllers are thin
- business logic is in services
- persistence is in the data layer
- capability checks are present where needed
- nonces are verified for write actions
- input is sanitized
- output is escaped
- SQL is prepared
- assets are scoped
- page-builder neutrality is preserved
- Bricks-specific behavior, if any, is optional
- background work is not run in page requests
- docs are updated if architecture changed
- tests/checks were run or honestly reported

---

# Final Principle

WordPress-native does not mean messy.

Build plugins as clean applications that respect WordPress conventions, use WordPress APIs, and expose data through WordPress-native surfaces.

Keep the core plugin page-builder-neutral. Add Bricks support as an optional adapter when useful, never as the architectural foundation.
