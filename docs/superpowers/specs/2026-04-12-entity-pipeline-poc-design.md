# Entity Pipeline PoC — Design Spec

**Date:** 2026-04-12
**Scope:** PoC — full naming pipeline in Suggest mode. Public-facing taxonomy pages are explicitly out of scope.

---

## Overview

A WordPress plugin that detects named entities in published articles, surfaces them as suggestions in the Gutenberg sidebar, and links approved entities to their taxonomy archive pages at render time. The PoC validates the end-to-end pipeline: LLM extraction → editor review → render-time linking.

---

## Plugin Structure

Located at `wp-content/plugins/navne/`.

```
navne/
├── navne.php                          # Bootstrap, hook registration
├── includes/
│   ├── Taxonomy.php                   # Register navne_entity taxonomy
│   ├── PostSaveHook.php               # Dispatches async job on save
│   ├── ContentFilter.php             # the_content render-time linking
│   ├── Pipeline/
│   │   ├── EntityPipeline.php         # Orchestrates the two stages
│   │   ├── ExtractorInterface.php     # NER stage contract
│   │   ├── PassthroughExtractor.php   # PoC: returns full content, no pre-filtering
│   │   ├── ResolverInterface.php      # LLM stage contract
│   │   └── LlmResolver.php            # Sends to provider, parses response
│   ├── Provider/
│   │   ├── ProviderInterface.php      # complete(prompt): string
│   │   ├── AnthropicProvider.php      # Claude default
│   │   └── ProviderFactory.php        # Reads settings, returns right provider
│   ├── Jobs/
│   │   └── ProcessPostJob.php         # Action Scheduler job callback
│   ├── Api/
│   │   └── SuggestionsController.php  # REST: GET/POST suggestions
│   └── Storage/
│       └── SuggestionsTable.php       # Custom DB table + CRUD
└── assets/js/sidebar/                 # Gutenberg sidebar (React)
```

---

## Taxonomy

- **Name:** `navne_entity`
- **Post type:** `post` (configurable via settings in a future iteration)
- **Hierarchical:** No
- **Term slug:** Canonical entity name
- **Archive URL:** Link target for render-time linking

---

## Data Layer

### `wp_navne_suggestions` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, PK auto-increment | |
| `post_id` | bigint | FK to `wp_posts` |
| `entity_name` | varchar(255) | e.g. "Jane Smith" |
| `entity_type` | varchar(50) | `person` / `org` / `place` / `other` |
| `confidence` | float | 0.0–1.0, from LLM |
| `status` | enum | `pending` / `approved` / `dismissed` |
| `created_at` | datetime | |
| `updated_at` | datetime | |

Created on plugin activation via `dbDelta()`. Dropped on uninstall.

### Post meta

- `_navne_job_status` — `queued` / `processing` / `complete` / `failed`
- `_navne_job_queued_at` — timestamp (for debugging stalled jobs)

### Approval side effects

When a suggestion is approved:
1. Suggestion row updated to `approved`
2. `wp_insert_term()` creates or finds the `navne_entity` term
3. Term is assigned to the post via `wp_set_post_terms()`

---

## Pipeline

Two-stage pipeline behind interfaces, both swappable independently.

```
post content
    → ExtractorInterface::extract( WP_Post ): string
    → ResolverInterface::resolve( WP_Post, string ): Entity[]
    → Entity[]
```

### Stage 1: Extractor (PoC: passthrough)

`PassthroughExtractor` returns post title + stripped body as a plain string. No pre-filtering. When spaCy NER is added later, it slots in here and returns a structured candidate list.

### Stage 2: Resolver (LLM)

`LlmResolver` builds the prompt, calls the provider, JSON-decodes the response, validates shape, returns `Entity[]`.

**Prompt structure:**

```
You are an entity extraction assistant for a news organization.

[ORG DEFINITION LIST]
(reserved — local figures and jargon will be injected here in a future iteration)

Analyze the following article and return a JSON array of named entities.
For each entity include:
  - name (string): the canonical proper noun
  - type (string): person | org | place | other
  - confidence (float): 0.0–1.0

Only include proper nouns that are meaningful subjects or sources of the story.
Exclude passing historical references.

Article:
{title}

{content}

Respond with only a JSON array. No explanation.
```

On malformed JSON or API failure, `LlmResolver` throws a typed `PipelineException`. `ProcessPostJob` catches it, sets `_navne_job_status` to `failed`, and lets Action Scheduler handle retries.

---

## LLM Provider Abstraction

```php
interface ProviderInterface {
    public function complete( string $prompt ): string;
}
```

- `AnthropicProvider` — default, uses Messages API (`claude-sonnet-4-6`, configurable)
- `ProviderFactory` — reads `navne_provider` option, returns correct instance
- Adding a new provider: implement `ProviderInterface`, register in `ProviderFactory`

---

## Async Job

**Dispatch** (`PostSaveHook`, fires on `save_post` for published posts):
1. Sets `_navne_job_status` to `queued`
2. Calls `as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] )`

**Processing** (`ProcessPostJob`, registered as callback for `navne_process_post`):
1. Sets status to `processing`
2. Runs `EntityPipeline::run( $post_id )` → `Entity[]`
3. Writes each entity to `wp_navne_suggestions` with status `pending`
4. Sets status to `complete`
5. On any exception: sets status to `failed`, allows Action Scheduler retry (3 attempts, exponential backoff)

---

## REST API

Namespace: `navne/v1`. All endpoints require `edit_post` capability for the given post.

| Method | Path | Description |
|---|---|---|
| `GET` | `/suggestions/{post_id}` | Returns all suggestions (all statuses) + `job_status` |
| `POST` | `/suggestions/{post_id}/approve` | Body: `{ id }` — sets row to `approved`, creates/assigns term |
| `POST` | `/suggestions/{post_id}/dismiss` | Body: `{ id }` — sets row to `dismissed` |
| `POST` | `/suggestions/{post_id}/retry` | Clears existing `pending` rows, re-dispatches the pipeline job |

The `GET` response includes all suggestion rows (pending, approved, dismissed) and `job_status` so the sidebar can reconstruct full state on reopen and knows whether to keep polling.

---

## Render-time Linking (`the_content` filter)

`ContentFilter` hooks into `the_content` filter:

1. Gets all `navne_entity` terms assigned to the current post
2. For each term, finds the first case-insensitive occurrence of the term name in post content
3. Wraps it in `<a href="{term_archive_url}">{entity_name}</a>`
4. Returns modified content

**Object cache:** Link map (`navne_link_map_{post_id}`) cached on first render. Invalidated when:
- A suggestion for the post is approved or dismissed
- The term is deleted or renamed (`delete_term`, `edit_term` hooks)

**Double-link guard:** Existing `<a>` tags are excluded from the scan before applying new links.

**Known edge case (out of scope for PoC):** Entity names that are substrings of other entity names (e.g. "Bush" inside "George Bush").

---

## Gutenberg Sidebar

Registered via `PluginSidebarMoreMenuItem` + `PluginSidebar`.

### States

| State | Condition | UI |
|---|---|---|
| Idle | No save yet | "Save the post to detect entities" |
| Processing | `job_status` is `queued` or `processing` | Spinner — "Analyzing article..." |
| Complete | `job_status` is `complete` | Suggestion cards + Approved section |
| Failed | `job_status` is `failed` | Error message + Retry button |

### Suggestion card (pending)

- Entity name (bold)
- Type label + confidence score
- Approve button (green) / Dismiss button (gray)
- On approve: card moves to Approved section, fires `POST /suggestions/{id}/approve`

### Approved section

Simple list of linked entity names. No remove/deactivate action in PoC.

### Polling

- Starts when `editor.isSavingPost` transitions to `false`
- Polls `GET /suggestions/{post_id}` every 3 seconds
- Stops when `job_status` is `complete` or `failed`
- Status tracked in local React state — no Redux store

---

## Out of Scope (PoC)

- Public-facing taxonomy archive pages
- Org definition list UI and storage
- Operating mode settings (Safe / YOLO) — PoC is Suggest mode only
- Plugin settings page
- Re-indexing / bulk processing
- spaCy NER stage
- Deactivating or removing approved links
- Moderator queue
- Duplicate name handling
