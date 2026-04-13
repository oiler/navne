# Bulk Indexing — Design Spec

**Date:** 2026-04-13
**Scope:** Admin-triggered bulk indexing for the Navne plugin. Three run types (index new / re-index all / retry failed), three modes (Safe / Suggest / YOLO) picked per run, optional date-range scope, live progress with cancel, and first-class run history.

---

## Overview

A new admin page — **Tools → Navne Indexing** — lets a site admin run the entity pipeline against the existing archive. Each run is a first-class record: its scope, mode, timing, and per-post outcome are persisted in two new tables, and the work is paced through a dispatcher action that enqueues the existing single-post `ProcessPostJob` in controlled waves.

The bulk path reuses the existing pipeline (`EntityPipeline`, `LlmResolver`, mode-aware entity handling) without forking it. Bulk adds a control plane on top — who to process, in what order, how fast, and how to observe — without changing how a single post is processed.

The release also resolves a long-standing ambiguity: the whitelist-enforcement semantics promised in the init notes but deferred since v1.0 finally land in Safe-mode bulk runs. Safe bulk is where "only link entities already approved by the newsroom" becomes a real, implemented behavior.

---

## Run Lifecycle

```
pending → running → complete
                  → cancelled
```

1. Admin fills in the run form on Tools → Navne Indexing and clicks **Preview**.
2. Plugin runs the scope query, renders a preview block showing matched post count and a rough cost figure computed as `count * NAVNE_AVG_COST_PER_ARTICLE`.
3. Admin confirms. A `bulk_runs` row is inserted with `status = 'pending'`. Every matched post ID is inserted into `bulk_run_items` with `status = 'queued'`. A single `navne_bulk_dispatch` action is scheduled.
4. Dispatcher wakes up. If run is `cancelled`, exits. Otherwise flips to `running` on first wave, claims the next `NAVNE_BULK_BATCH_SIZE` queued items atomically, enqueues `navne_process_post` for each with a `bulk_run_id` arg, reschedules itself `NAVNE_BULK_BATCH_INTERVAL` seconds later.
5. `ProcessPostJob` runs for each post. When invoked with a non-zero `bulk_run_id`, it delegates to `BulkAwareProcessor`, which flips the item row through `processing` → `complete`/`failed` and applies the run's frozen mode.
6. When `bulk_run_items` has no rows left in a non-terminal state, the dispatcher's next wake-up marks the run `complete` and does not reschedule.
7. Cancel button flips the run to `cancelled`. The dispatcher's next wave exits without enqueueing more work. Items already dispatched finish normally; items still `queued` stay permanently in that state (visible in run history but invisible to future runs).

## Scope Filters

- **Run type**: one of `index_new`, `reindex_all`, `retry_failed`
- **Mode for this run**: one of `safe`, `suggest`, `yolo` — **frozen** at run creation, not re-read from options mid-run
- **Date range**: optional `post_date` bounds (from / to), inclusive, both optional. No range = entire archive. Hidden for `retry_failed` runs.
- **Post types**: not a form field — honors the existing `navne_post_types` setting. Matches post-save behavior.
- **Post status**: `publish` only. Matches post-save behavior.

`retry_failed` runs are scoped by `parent_run_id` instead of a date range: the admin picks "Retry failed posts from run #42" from the run history list, and the scope is exactly the set of items from run 42 where `status = 'failed'` (filtered at dispatch time against the current post state, so posts since trashed are silently skipped).

---

## Admin UI

### Menu registration

```php
add_management_page(
    'Navne Indexing',
    'Navne Indexing',
    'manage_options',
    'navne-indexing',
    [ IndexingPage::class, 'render' ]
);
```

Registered in `Plugin::init()` alongside `SettingsPage`.

### Page structure

One URL, three views driven by query args:

| View | URL | Purpose |
|---|---|---|
| Form | `?page=navne-indexing` | Start a new run |
| Run detail | `?page=navne-indexing&run=42` | Live progress + failed-post list |
| (implicit history) | footer of the form view | Last `NAVNE_BULK_HISTORY_LIMIT` runs as links |

### Form view

**Run type** (radio, required):

- Index new — only posts with no `_navne_job_status` meta
- Re-index all — every matching post regardless of prior state
- Retry failed — surfaced from the run-detail page as a button, not the form radio

**Mode for this run** (radio, required):

- Safe — runs the pipeline, keeps only entities that match the existing `navne_entity` term list, auto-tags matched posts, drops unmatched entities silently
- Suggest — runs the pipeline, inserts everything as pending for editor review
- YOLO — runs the pipeline, auto-approves ≥ 0.75 confidence, inserts rest as pending

**Date range** (two date inputs, both optional):

- From: `<input type="date">`
- To: `<input type="date">`

**Preview button** re-renders the same page with a results block:

> **3,412 posts match your scope.**
> Rough cost: average article ≈ `NAVNE_AVG_COST_PER_ARTICLE` to process through Anthropic. 3,412 posts × that constant ≈ the total shown here.
> `[Run this indexing job]` `[Back]`

The cost figure uses the `NAVNE_AVG_COST_PER_ARTICLE` constant (default `0.002`); the preview multiplies the constant by the matched count and formats it to two decimal places. No per-request calculation, no provider coupling, no token-counting at preview time.

**Empty whitelist warning (Safe mode only):** If the selected mode is Safe and `get_terms(['taxonomy' => 'navne_entity'])` is empty, the preview adds: *"Your whitelist is empty. A Safe mode run will process posts but create no tags. You may want to seed the whitelist first."* Admin can proceed anyway.

### Run detail view

Rendered when `?run=42` is present. Polls `GET /navne/v1/bulk-runs/{id}` every 2 seconds via vanilla JS (no React — classic admin page).

```
Navne Indexing — Run #42
Started: 2026-04-13 14:02 · Type: Re-index all · Mode: Suggest

[##############........] 1,847 / 3,412 processed · 12 failed

Scope: post_date ≥ 2024-01-01

Status: Running   [Cancel this run]

Failed posts (12)  ▼
  - "Mayor announces budget cuts" (ID 1204) — pipeline error: JSON parse failed
  - "School board meets" (ID 1197) — pipeline error: rate limit exceeded
  ...
```

When `run.status` becomes `complete` or `cancelled`, polling stops. The Cancel button is replaced with either nothing (complete) or a "Retry failed posts from this run" button, which is a form POST that creates a new run of type `retry_failed` bound to this run's ID.

### Form-footer run history

> **Recent runs**
> - #43 · Apr 13 14:02 · Re-index all · running · 1,847/3,412
> - #42 · Apr 12 09:15 · Index new · complete · 512/512
> - #41 · Apr 10 22:03 · Index new · cancelled · 104/2,800
> ...

Up to `NAVNE_BULK_HISTORY_LIMIT` entries. Each row linked to its run detail page.

### Security

- `manage_options` capability check at top of `render()` and every handler method
- `wp_nonce_field('navne_indexing')` in the form; `check_admin_referer('navne_indexing')` in preview and start handlers
- Cancel button POSTs with its own `navne_indexing_cancel` nonce
- `admin-post.php` redirect after successful state change (PRG pattern, same as `SettingsPage`)

### Accessibility / niceties (kept minimal)

- Progress text is live text (`aria-live="polite"`), not just a bar
- Cancel button is disabled while a POST is in flight
- Date inputs default to empty; no JS date picker polyfill

---

## Data Model

### `wp_navne_bulk_runs`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned, PK, auto-increment | |
| `run_type` | varchar(20) | `index_new` / `reindex_all` / `retry_failed` |
| `mode` | varchar(20) | `safe` / `suggest` / `yolo` — frozen at run creation |
| `date_from` | date, nullable | scope lower bound (inclusive), null = no lower bound |
| `date_to` | date, nullable | scope upper bound (inclusive), null = no upper bound |
| `parent_run_id` | bigint unsigned, nullable | only set for `retry_failed`; points at the source run |
| `total` | int unsigned | matched-post count at run creation; immutable once set |
| `processed` | int unsigned | count of items in any terminal status (complete + failed + skipped) |
| `failed` | int unsigned | subset of `processed` that failed |
| `status` | varchar(20) | `pending` / `running` / `complete` / `cancelled` |
| `created_by` | bigint unsigned | `get_current_user_id()` at creation |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**Indexes:**

- Primary key on `id`
- Plain index on `status`
- Plain index on `created_at DESC`

**Why `mode` is frozen on the row:** The `navne_operating_mode` option can change mid-run. A run that started as Suggest and discovered YOLO semantics halfway through would be incoherent. The run row is the source of truth for the run's duration.

**Why `total` is immutable:** The dispatcher uses `total - processed` to know work remaining and completion. Recomputing the scope query mid-run could yield a different count if posts are being published concurrently, breaking progress arithmetic.

### `wp_navne_bulk_run_items`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned, PK, auto-increment | |
| `run_id` | bigint unsigned | FK to `bulk_runs.id` |
| `post_id` | bigint unsigned | FK to `wp_posts.ID` |
| `status` | varchar(20) | `queued` / `dispatching` / `processing` / `complete` / `failed` / `skipped` |
| `error_message` | varchar(500), nullable | truncated, empty unless `status = 'failed'` |
| `created_at` | datetime | tiebreaker for dispatch order |
| `updated_at` | datetime | |

**Indexes:**

- Primary key on `id`
- Composite index on `(run_id, status)` — hot query is "next N queued items in run X"
- Plain index on `post_id` — supports retry-failed scope and any future "bulk history for this post" lookup

**Status transitions:**

```
queued  → dispatching  → processing  → complete
                                     → failed
        → skipped   (run was cancelled before dispatch)
```

`dispatching` is a narrow state: the dispatcher sets it before calling `as_enqueue_async_action`, and `BulkAwareProcessor` flips it to `processing` as soon as the job runs. It exists so a dispatcher crash between enqueue and ack leaves a detectable state rather than a row stuck in `queued` that never moves.

**Why 500-char error cap:** The existing `PipelineException` already caps provider response text to ≤200 chars per security standards. 500 gives headroom for operational context (stack frame, status code) without key-leak risk, matching the input-length discipline the plugin already follows.

### Schema creation

Both tables created in the plugin's activation hook via `dbDelta()`. A new `SchemaMigrator` class reads a `NAVNE_DB_VERSION` option, compares against a code-defined constant, and runs `dbDelta()` on mismatch. This replaces the current ad-hoc activation code and makes future schema changes extend the same path. No backfill required — there are no historical bulk runs to reconstruct.

### Options & constants

New plugin constants (overridable in `wp-config.php`):

```php
define( 'NAVNE_BULK_BATCH_SIZE',        5     ); // items per dispatcher wave
define( 'NAVNE_BULK_BATCH_INTERVAL',    60    ); // seconds between waves
define( 'NAVNE_AVG_COST_PER_ARTICLE',   0.002 ); // ballpark for preview
define( 'NAVNE_BULK_HISTORY_LIMIT',     10    ); // recent runs shown on form
define( 'NAVNE_BULK_MAX_ERROR_LEN',     500   ); // matches column width
```

All five have defaults in a central `Navne\BulkIndex\Config` class so tests can stub them. No settings-page UI for these — they're ops knobs, not editorial ones.

**No new `wp_options` rows.** Run state lives entirely in the two tables. The existing `navne_operating_mode` option continues to govern post-save behavior independently.

### Interaction with existing per-post meta

`_navne_job_status` and `_navne_job_queued_at` keep their meaning: they describe the most recent pipeline run against that post, regardless of trigger (post save or bulk). Bulk runs update them the same way `ProcessPostJob` already does.

The authoritative record of a bulk run's per-post outcome is the `bulk_run_items` row, not the post meta. Post meta is for the live editor view; run items are for the bulk history.

**Concurrency case:** If an editor saves a post while that post is `queued` in an active bulk run, the existing `PostSaveHook` guard (`if ($current === 'queued' || 'processing') return;`) causes the save to skip dispatch. No double-processing. No special coordination required.

---

## Backend

### New classes

```
navne/includes/BulkIndex/
├── Config.php              # Reads NAVNE_BULK_* constants with defaults
├── RunsRepository.php      # CRUD for wp_navne_bulk_runs
├── RunItemsRepository.php  # CRUD for wp_navne_bulk_run_items
├── ScopeQuery.php          # Builds WP_Query args from run_type + date range
├── RunFactory.php          # Creates a run: inserts run row, scope-queries, inserts item rows
├── Dispatcher.php          # navne_bulk_dispatch action callback — the wave loop
├── BulkAwareProcessor.php  # Wraps ProcessPostJob for bulk-context execution
└── Whitelist.php           # Loads navne_entity term names for Safe bulk filtering
```

Plus one hook change to `Plugin::init()`:

```php
add_action( 'navne_bulk_dispatch', [ Dispatcher::class, 'run' ] );
```

`ProcessPostJob::run` gets a new optional second arg `int $bulk_run_id = 0`. Default zero = invoked by post-save (unchanged behavior). Nonzero = invoked by the dispatcher, branches into `BulkAwareProcessor`.

### Scope query

`ScopeQuery::matching_post_ids( string $run_type, ?string $date_from, ?string $date_to, ?int $parent_run_id ): int[]` returns the full list of post IDs the run should process.

```php
$args = [
    'post_type'      => (array) get_option( 'navne_post_types', [ 'post' ] ),
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'posts_per_page' => -1,
    'no_found_rows'  => true,
    'orderby'        => 'ID',
    'order'          => 'ASC',
];

if ( $date_from ) {
    $args['date_query'][] = [ 'after'     => $date_from, 'inclusive' => true ];
}
if ( $date_to ) {
    $args['date_query'][] = [ 'before'    => $date_to,   'inclusive' => true ];
}
```

Run-type post-filters:

- **`index_new`**: folds a meta_query `compare => 'NOT EXISTS'` on `_navne_job_status` into the WP_Query args
- **`reindex_all`**: no additional filter
- **`retry_failed`**: bypasses WP_Query entirely. Queries `bulk_run_items` directly: `SELECT post_id FROM wp_navne_bulk_run_items WHERE run_id = :parent AND status = 'failed'`. Post-type and post-status are re-enforced at dispatch time; posts since trashed are silently skipped.

Preview path uses the same `ScopeQuery` call but returns only `count($ids)`. No run is created.

**Large archive note:** `posts_per_page = -1` with `fields = 'ids'` is lightweight (no post content loaded). On a 100k-post archive it still returns 100k integers (~800KB at PHP int size) — fine. The heavy cost is downstream LLM calls, not the ID scan.

### Run creation

`RunFactory::create( array $form_input ): int` runs inside a single DB transaction:

1. Validate form input (run_type in allowlist, mode in allowlist, dates parseable, parent_run_id exists if run_type is retry_failed).
2. Call `ScopeQuery::matching_post_ids(…)`, get `$ids`.
3. Insert `bulk_runs` row with `status = 'pending'`, `total = count($ids)`, frozen mode.
4. Bulk-insert `bulk_run_items` rows — all with `status = 'queued'`, one per post ID. Chunked at 500 rows per `INSERT` to stay under `max_allowed_packet`.
5. Schedule first dispatcher wave: `as_enqueue_async_action('navne_bulk_dispatch', ['run_id' => $run_id])`.
6. Return `$run_id`.

**Empty-scope case:** If `count($ids) === 0`, the run is still created with `total = 0, processed = 0, status = 'complete'` and no dispatcher is scheduled. The run detail view renders "No posts matched your scope." Clean record, zero work.

### Dispatcher

`Dispatcher::run( int $run_id )` — the Action Scheduler callback for `navne_bulk_dispatch`:

```
1. Load the run row.
2. If row missing → log and exit.
3. If status is 'cancelled' or 'complete' → exit silently. No reschedule.
4. If status is 'pending' → flip to 'running'.
5. Compute terminal-state count from run_items (complete + failed + skipped).
   Update run.processed and run.failed.
6. If terminal count == total → flip run to 'complete', exit. No reschedule.
7. Atomically claim the next N queued items (see below).
8. For each claimed item:
     as_enqueue_async_action( 'navne_process_post',
         [ 'post_id' => $item->post_id, 'bulk_run_id' => $run_id ] );
9. Reschedule self:
     as_schedule_single_action(
         time() + NAVNE_BULK_BATCH_INTERVAL,
         'navne_bulk_dispatch',
         [ 'run_id' => $run_id ]
     );
10. Exit.
```

**Atomic claim:**

```sql
UPDATE wp_navne_bulk_run_items
SET status = 'dispatching', updated_at = NOW()
WHERE id IN (
    SELECT id FROM (
        SELECT id FROM wp_navne_bulk_run_items
        WHERE run_id = %d AND status = 'queued'
        ORDER BY created_at ASC, id ASC
        LIMIT %d
    ) AS claim
)
```

Then `SELECT post_id FROM wp_navne_bulk_run_items WHERE run_id = X AND status = 'dispatching' AND updated_at >= :tick_start` pulls rows the current tick just claimed, avoiding re-dispatch of anything a prior wave left mid-flight.

**Reschedule guarantees one-in-flight:** Step 9 uses `as_schedule_single_action`, so even if the tick takes longer than the interval, the next wave only fires after the current one returns.

### BulkAwareProcessor

`ProcessPostJob::run` becomes a thin adapter:

```php
public static function run(
    int $post_id,
    $bulk_run_id_or_pipeline = 0,
    ?SuggestionsTable $table = null
): void {
    if ( is_int( $bulk_run_id_or_pipeline ) && $bulk_run_id_or_pipeline > 0 ) {
        BulkAwareProcessor::process( $post_id, $bulk_run_id_or_pipeline );
        return;
    }
    // existing single-post path — unchanged
    self::run_single_post( $post_id, $bulk_run_id_or_pipeline, $table );
}
```

The existing test-injection path (second arg is an `EntityPipeline`) is preserved by the type check. Post-save path passes nothing → 0 → existing behavior. Dispatcher path passes the run id → bulk path.

**Refactor note:** the existing body of `ProcessPostJob::run` moves into a new private `run_single_post()` method with the same signature it has today. No behavior change to the single-post path; the rename is mechanical.

`BulkAwareProcessor::process( int $post_id, int $run_id )`:

```
1. Load the run row. If cancelled/complete → mark item 'skipped', return.
2. Load the post. If the post no longer exists, is not in a publish status, or
   its post_type is no longer in the navne_post_types allowlist → mark item
   'skipped', return. (Handles races between scope query and dispatch: posts
   trashed, privatized, or retyped after the run was created.)
3. Flip the run_items row for (run_id, post_id) from 'dispatching' → 'processing'.
4. Set post meta _navne_job_status = 'processing' (mirrors single-post path).
5. Try:
     - $pipeline = Plugin::make_pipeline()
     - $entities = $pipeline->run( $post_id )
     - Apply mode-specific handling (below), using run.mode, NOT the current option
     - Flip run_items row to 'complete'
     - Post meta → 'complete'
6. Catch Exception:
     - Flip run_items row to 'failed' with truncated error message
     - Post meta → 'failed'
     - error_log(...)  — same as existing path
```

**Mode branching:**

```php
switch ( $run->mode ) {
    case 'safe':
        $whitelist = Whitelist::current(); // Set<lowercased_name>, memoized per request
        $matched = array_values( array_filter(
            $entities,
            fn( Entity $e ) => $whitelist->contains( strtolower( $e->name ) )
        ) );
        if ( ! empty( $matched ) ) {
            $table->insert_entities( $post_id, $matched, 'approved' );
            foreach ( $matched as $entity ) {
                $term_id = self::ensure_term( $entity->name );
                if ( $term_id ) {
                    wp_set_post_terms( $post_id, [ $term_id ], 'navne_entity', true );
                }
            }
            wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );
        }
        // unmatched entities dropped silently
        break;

    case 'yolo':
        // same logic as existing ProcessPostJob YOLO branch, via ensure_term()
        break;

    case 'suggest':
    default:
        $table->insert_entities( $post_id, $entities );
        break;
}
```

`self::ensure_term()` wraps `wp_insert_term` + the `term_exists` error-code recovery already present in the existing YOLO branch. Extracting lets both YOLO and Safe-bulk share term-creation without duplicating race-recovery boilerplate.

**Whitelist caching:** `Whitelist::current()` is a static per-request memoized read of `get_terms(['taxonomy' => 'navne_entity', 'hide_empty' => false, 'fields' => 'names'])`. Each bulk job runs in its own request, so the static cache resets between jobs. One `get_terms` call per bulk-processed post is cheap compared to the LLM call.

### Term-race handling

`NAVNE_BULK_BATCH_SIZE = 5` means up to five jobs can hit the pipeline concurrently, and two could both try to create the same term. The existing `ProcessPostJob` YOLO branch handles this via the `term_exists` error-code recovery. The extracted `ensure_term()` helper inherits that behavior — on race, one call wins, the other recovers `term_id` from the error. No explicit locking needed.

### Cancellation

Cancel button POSTs to `admin-post.php?action=navne_indexing_cancel&run_id=X`:

1. Capability + nonce check.
2. Flip `bulk_runs.status` to `cancelled` if currently `pending` or `running`. No-op on any other state.
3. Redirect back to the run detail view with a flash.

What happens next:

- Currently-in-flight jobs (up to N) finish normally — items flip to `complete`/`failed`. Cost of letting them finish is capped at one wave's worth of LLM calls.
- Next dispatcher wake-up sees status `cancelled`, exits without enqueueing more work, does not reschedule.
- Items still `queued` stay there permanently. Recent-runs list shows "cancelled (1,203 / 3,412)" — a complete-for-history record.
- `index_new` and `retry_failed` future runs skip cancelled-but-queued items automatically because they query by `_navne_job_status` (for new) or by `parent_run.status = 'failed'` (for retry). A cancelled-but-queued item has neither marker.

Cancel is idempotent — flipping `cancelled` to `cancelled` is a no-op.

### Failure modes

| Failure | Behavior |
|---|---|
| Dispatcher wakes up, run deleted out-of-band | Log, exit |
| LLM provider returns non-JSON for one post | `PipelineException` caught, item → `failed`, dispatcher keeps going |
| Rate limit (HTTP 429) | Generic exception caught, item → `failed`, dispatcher keeps going. Retry later via `retry_failed` run type, presumably after tuning `NAVNE_BULK_BATCH_SIZE` or interval. |
| Action Scheduler garbage-collects a too-old action | AS keeps actions 31 days by default; a dropped wave means the dispatcher chain breaks — run stuck at `running` with `processed < total`. See Out of Scope. |
| WordPress cron disabled entirely | Action Scheduler falls back to admin pings or wp-cli — standard AS behavior, no code change needed. |

---

## REST API

Namespace: `navne/v1`. All endpoints require `manage_options` capability.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/bulk-runs` | List recent runs (limit `NAVNE_BULK_HISTORY_LIMIT`) |
| `GET` | `/bulk-runs/{id}` | Single run details with counts and most recent failed items (capped at 100) |
| `GET` | `/bulk-runs/{id}/failed-items` | Paginated list of failed items for a run |

**No write endpoints via REST.** Create/cancel/retry go through `admin-post.php` handlers (classic form POSTs with nonces). Avoids two parallel write paths for the same state and matches how `SettingsPage` already works.

### `GET /bulk-runs/{id}` response shape

```json
{
  "id": 42,
  "run_type": "reindex_all",
  "mode": "suggest",
  "date_from": "2024-01-01",
  "date_to": null,
  "total": 3412,
  "processed": 1847,
  "failed": 12,
  "status": "running",
  "created_at": "2026-04-13 14:02:17",
  "updated_at": "2026-04-13 14:27:03",
  "failed_items": [
    { "post_id": 1204, "post_title": "Mayor announces budget cuts", "error_message": "pipeline error: JSON parse failed" },
    { "post_id": 1197, "post_title": "School board meets", "error_message": "pipeline error: rate limit exceeded" }
  ]
}
```

No HTML escaping in JSON — JS handles display-side escaping per the security standards in CLAUDE.md. `post_title` fetched via `get_the_title()`.

### `permission_callback`

```php
function ( \WP_REST_Request $request ) {
    return current_user_can( 'manage_options' );
}
```

`manage_options` (not `edit_post`) because bulk operations are site-wide. REST nonce (`X-WP-Nonce`) handles CSRF; polling JS adds it via `wpApiSettings.nonce`.

---

## Testing Plan

### Unit tests

**`ScopeQueryTest.php`:**

- `test_index_new_excludes_posts_with_existing_job_status()`
- `test_reindex_all_ignores_job_status()`
- `test_retry_failed_pulls_from_parent_run_items()`
- `test_date_range_inclusive_both_ends()`
- `test_null_date_range_omits_date_query()`
- `test_honors_post_types_setting()`

**`RunFactoryTest.php`:**

- `test_create_inserts_run_row_with_frozen_mode()`
- `test_create_inserts_run_items_for_each_matched_post()`
- `test_empty_scope_creates_complete_run_with_zero_total()`
- `test_create_schedules_initial_dispatcher_wave()`

**`DispatcherTest.php`:**

- `test_exits_when_run_cancelled()`
- `test_flips_pending_run_to_running_on_first_wave()`
- `test_completes_run_when_no_work_remains()`
- `test_claims_batch_size_items_atomically()`
- `test_reschedules_self_after_wave()`
- `test_orders_claims_by_created_at_then_id()`
- `test_ignores_completed_items_in_claim()`

**`BulkAwareProcessorTest.php`:**

- `test_safe_mode_keeps_only_whitelist_matched_entities()`
- `test_safe_mode_drops_unmatched_entities_silently()`
- `test_safe_mode_empty_whitelist_is_noop()`
- `test_yolo_mode_matches_existing_yolo_branch_behavior()`
- `test_suggest_mode_inserts_all_as_pending()`
- `test_run_uses_frozen_mode_not_current_option()`
- `test_cancelled_run_skips_processing_and_marks_item_skipped()`
- `test_skipped_when_post_is_trashed_or_missing()` — post deleted or status flipped to draft between scope query and dispatch; item → skipped, pipeline not called
- `test_skipped_when_post_type_no_longer_allowed()` — admin changed `navne_post_types` mid-run; item → skipped, pipeline not called
- `test_pipeline_exception_marks_item_failed_with_truncated_message()`

**`WhitelistTest.php`:**

- `test_returns_lowercased_term_names()`
- `test_empty_taxonomy_returns_empty_set()`

**Existing tests touched:**

- `ProcessPostJobTest` — add a regression test confirming the single-post path is unchanged when second arg is `0` or an `EntityPipeline` instance.

### Not unit tested (covered by smoke test)

- Admin page rendering (form, preview, run detail, history)
- Admin-post handlers for start / cancel / retry-failed (WP admin context required)
- REST endpoint wiring
- Polling JS on the run detail view
- `dbDelta` schema creation on activation

### Smoke test steps

1. **Fresh install smoke** — activate the plugin on a site with 20 test posts. Navigate to Tools → Navne Indexing. Verify the form renders with three run types, three modes, and date range fields.
2. **Preview** — submit run type "Index new," mode "Suggest," no date range. Verify preview shows "20 posts match" and the cost ballpark.
3. **Suggest bulk run** — click Run. Verify the run detail page appears, polling updates the progress, and after completion every test post has pending suggestions in the sidebar.
4. **Re-index all → YOLO** — start a new run with "Re-index all" and mode "YOLO." Verify high-confidence entities auto-link and the content filter shows the links on the front end.
5. **Retry failed** — force-fail a couple of posts (invalidate the API key temporarily, start a run, watch items fail). Restore the key. From the run detail page, click "Retry failed posts from this run." Verify a new run is created scoped to only those post IDs and they process successfully.
6. **Safe mode with empty whitelist** — delete all `navne_entity` terms. Start a Safe mode run. Verify the preview warns about the empty whitelist. Confirm anyway. Verify the run completes, zero terms are created, zero suggestions inserted, every item `complete` with no side effects.
7. **Safe mode with populated whitelist** — add 3 terms manually. Start a Safe mode run against posts that mention those names plus others. Verify only whitelisted terms get tagged; other detected entities are silently dropped.
8. **Cancel mid-run** — start a run against all 20 test posts. Click Cancel after ~5 process. Verify progress stops advancing, status flips to `cancelled`, new waves don't fire, run history shows "cancelled (5 / 20)."
9. **Mode override in progress** — start a Suggest bulk run. While it's running, change Operating Mode in the settings page to YOLO. Verify remaining bulk items still behave as Suggest (the run's frozen mode wins).
10. **Date range scope** — start a run with a date range that excludes half the test posts. Verify `total` equals only the expected subset.
11. **Empty-scope run** — start a run with a date range that matches zero posts. Verify the run is created as complete with total=0 and no dispatcher scheduled.

### Security review

Per CLAUDE.md, every code change runs through the `web-security` skill before completion. Specific items this feature must pass:

- **Capability & nonce** — every admin-post handler checks `manage_options` and `check_admin_referer('navne_indexing')` / `('navne_indexing_cancel')`. REST endpoints gate on `manage_options`.
- **Input validation** — `run_type` and `mode` validated against explicit allowlists; `date_from` / `date_to` parsed via `DateTime::createFromFormat('Y-m-d', ...)` before use; `run_id` and `parent_run_id` cast `(int)` at handler entry.
- **Output escaping** — admin page uses `esc_html`, `esc_attr`, `esc_url` on every dynamic value. Polling JS writes via `textContent`, not `innerHTML`.
- **Error messages** — `error_message` column capped at 500 chars, existing `PipelineException` already scrubs provider responses.
- **Rate limiting** — the dispatcher itself is the rate limiter. No per-admin cooldown needed (admin is trusted with `manage_options`).
- **LLM input** — unchanged; `PassthroughExtractor` still caps content at 8,000 chars.

---

## Out of Scope

Deferred from this release, carried forward from decisions made during design:

- **Resume-after-stuck-run** — if the dispatcher chain breaks (Action Scheduler drops an old action, host restart, etc.) the run sits at `running` with `processed < total`. No heartbeat or watchdog. Admin recourse: cancel manually, launch a new `index_new` run which skips already-processed posts.
- **Fine-grained progress inside a single post** — items are atomic. No "post X is 40% done" reporting.
- **Serialized runs** — multiple bulk runs can execute concurrently if an admin launches a second one. They share Anthropic rate-limit pressure but don't coordinate. A "max 1 active run" guard is easy to add later if it matters in practice.
- **Configurable YOLO confidence threshold** (still 0.75 hardcoded)
- **Configurable Safe whitelist match rules** — still exact case-insensitive name match; no aliases, no fuzzy matching
- **Per-role trust levels / per-user bulk permissions** — `manage_options` only. No split between "who can preview" and "who can actually run."
- **Scheduled bulk runs** — no cron-triggered bulk indexing. Human-in-the-loop only.
- **Dry-run mode** — no "run the pipeline, record what would happen, don't write" option. Only the count-based preview.
- **Cost estimation by real token count** — static `NAVNE_AVG_COST_PER_ARTICLE` constant only.
- **Bulk cancel of multiple runs**
- **Pause and resume**
- **Import/export run history**
- **Inline per-item retry** — retry is always a new run (type `retry_failed`). No single-row retry from the detail view.
