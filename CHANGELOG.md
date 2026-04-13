# Changelog

All notable changes to this project will be documented in this file. This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.3.2] - 2026-04-13

### Security
- API key now read from `NAVNE_ANTHROPIC_API_KEY` constant (wp-config.php) first, keeping it out of the database when defined there; settings page reflects this with a note and hides the input field
- Model name validated against a slug-format regex before storage (prevents arbitrary strings from reaching the API)
- Retry endpoint now enforces a 60-second cooldown per post to prevent API cost overruns from rapid re-queuing
- LLM error messages truncated to 200 chars to avoid logging full response bodies
- Extracted post content capped at 8,000 chars before being sent to the LLM

### Security Standards
- Added `# Security Standards` section to CLAUDE.md documenting the established patterns for API keys, input validation, output escaping, access control, rate limiting, and LLM input handling

## [1.3.1] - 2026-04-13

### Fixed
- LLM response parser now handles preamble text and trailing notes outside code fences — previously the regex was anchored to the full response, causing failures when the model added explanatory text
- Anthropic API `max_tokens` raised from 1024 to 4096; truncated responses now throw a descriptive exception instead of passing malformed JSON downstream

### Tests
- PostSaveHook: added coverage for revision, autosave, non-published post, and in-flight job guards
- ContentFilter: added coverage for non-singular context, regex metacharacters in entity names, and invalid `get_term_link` results
- SuggestionsController: added coverage for all 400/404/500 error paths on `approve` and `dismiss`; added `dismiss` happy-path test

## [1.3.0] - 2026-04-12

### Added
- Safe mode: pipeline does not auto-dispatch on save; Gutenberg sidebar shows a "Process this article" button to trigger on demand
- YOLO mode: entities at ≥75% confidence are auto-approved, linked, and added to the taxonomy on save; lower-confidence entities surface as pending suggestions
- All three operating mode radio buttons on the settings page are now active and saved server-side
- Sidebar reads mode from the API response and skips auto-polling after save in Safe mode

### Fixed
- `wp_insert_term` error handling in YOLO mode: non-`term_exists` errors are now logged and skipped rather than producing a broken taxonomy relationship

## [1.2.0] - 2026-04-12

### Added
- Org definition list: freeform textarea on the settings page for local figures, acronyms, and jargon; primes the LLM prompt before each post is processed
- Suggestion deduplication: pipeline filters out entities already approved for a post so re-saving does not re-surface reviewed suggestions

## [1.1.0] - 2026-04-12

### Added
- Settings page under Settings → Navne: provider selection, API key, model, operating mode, and post type configuration
- Post type guard: pipeline only dispatches for the post types configured on the settings page

## [1.0.0] - 2026-04-12

### Added
- Async LLM pipeline via Action Scheduler (queued → processing → complete/failed lifecycle)
- LLM provider abstraction (`ProviderInterface`) with Anthropic implementation
- Custom DB table (`wp_navne_suggestions`) with pending/approved/dismissed status lifecycle
- REST API: GET suggestions, POST approve, POST dismiss, POST retry
- Gutenberg sidebar: entity cards with approve/dismiss actions, approved entities list, retry on failure, post-save polling
- Render-time content linking via `the_content` filter with per-post object cache and double-link guard
- `navne_entity` flat taxonomy for all entity types (person, org, place, other)
