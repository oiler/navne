# Project

A WordPress plugin for news organizations that automatically links proper nouns in published articles to their taxonomy archive pages and generates taxonomy terms for detected entities. Uses a two-stage NER + LLM pipeline: spaCy for candidate detection, LLM for disambiguation and confidence scoring.

# Commands

All commands run from the `navne/` directory.

```bash
# Install PHP deps:  composer install
# Install JS deps:   npm install
# Build assets:      npm run build
# Dev watch:         npm run dev
# Run tests:         vendor/bin/phpunit
# Run single test:   vendor/bin/phpunit --filter TestClassName
```

# Code Style

- WordPress VIP coding standards enforced via phpcs
- Tabs for indentation (PHP and JS)
- Double quotes in PHP
- WordPress naming conventions: snake_case functions/variables, PascalCase classes

# Workflow

- Trunk-based: work directly on master, no formal PR process

# Release Process

This is a public plugin. Follow this process for every release:

1. **Write narrative release notes** *(MINOR and MAJOR only — skip for PATCH)*
   - Create `docs/releases/vX.Y.Z.md` using the `writing-style` skill
   - Explain what changed and why — what it means for newsroom editors, what was deliberately deferred
   - Add a `· [Technical changelog](../../CHANGELOG.md#xyz---yyyy-mm-dd)` link in the file header (after the date line)
2. **Update `CHANGELOG.md`** — rename `[Unreleased]` to `[x.y.z] - YYYY-MM-DD`, open a new `[Unreleased]` block; for MINOR/MAJOR releases add a `[Full release notes](docs/releases/vX.Y.Z.md)` link below the version heading
3. **Bump the version** in two places in `navne.php`: the `Version:` plugin header and the `NAVNE_VERSION` constant — both must match the tag
4. **Commit**: `git commit -m "chore: release vX.Y.Z"`
5. **Annotated tag**: `git tag -a vX.Y.Z -m "vX.Y.Z"`
6. **Push**: `git push && git push hub vX.Y.Z`
7. **GitHub Release**: `gh release create vX.Y.Z --title "vX.Y.Z" --notes-file <changelog fragment>`

Semver rules:
- `PATCH` — bug fixes only (e.g. v1.3.1); no narrative notes required
- `MINOR` — new backwards-compatible features (e.g. v1.4.0); narrative notes required
- `MAJOR` — breaking changes (e.g. v2.0.0); narrative notes required

# Architecture

Key design decisions from `docs/init/wordpress-entity-linking-plugin-notes.md`:

- **Flat taxonomy**: One taxonomy for all entity types — People, Orgs, Places share a single vocabulary
- **Render-time linking**: Links applied via `the_content` filter — never written to post content in the DB; clean archive
- **Object cache**: Redis/Memcached stores the entity-linking map per post; invalidated on term create/merge/delete
- **Two-stage pipeline**: spaCy NER generates candidate list → LLM validates, disambiguates, and scores confidence
- **Three operating modes**: Safe (whitelist-only), Suggest (whitelist + editor sidebar prompts), YOLO (full auto with mod queue)
- **Async jobs**: Use Action Scheduler for all LLM calls — never block synchronously on post save
- **Org definition list**: Knowledge base of local figures, jargon, acronyms — primes the LLM before each post

# Security Standards

These are established decisions for this codebase — follow them without being asked:

**API keys and secrets**
- Never store secrets in code. The Anthropic API key is read from `NAVNE_ANTHROPIC_API_KEY` constant (wp-config.php) first, falling back to `wp_options`. New secrets follow the same pattern.
- Never log or expose API keys, full request bodies, or full LLM responses. Truncate any dynamic content in exception messages to ≤200 chars.

**Input validation**
- All `$_POST`/`$_GET` values are sanitized with the narrowest appropriate function (`sanitize_key`, `sanitize_text_field`, `sanitize_textarea_field`). Model names and enum-style fields are validated against an explicit allowlist or format regex before storage.
- REST API parameters are cast to their expected type at the top of each handler (`(int)`, `(string)`).

**Output escaping**
- All dynamic values in HTML are escaped for context: `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for URLs, `esc_textarea()` for textareas.
- REST responses return raw data (no HTML escaping in JSON — React handles display escaping).

**Access control**
- Every admin handler checks `current_user_can('manage_options')` and `check_admin_referer()` before acting.
- Every REST endpoint has a `permission_callback` that checks `current_user_can('edit_post', $post_id)`.

**Rate limiting**
- The retry endpoint enforces a 60-second cooldown per post via `_navne_job_queued_at` meta. Any new endpoint that triggers an async job or external API call needs a similar guard.

**LLM input**
- Extracted post content is capped at 8,000 chars before being sent to the LLM.

# Gotchas

- LLM API key required (e.g. `ANTHROPIC_API_KEY`) — plugin won't process entities without it
- Render-time linking means page-level HTML caches (Varnish etc.) bypass the object cache — account for this in caching strategy
- Re-indexing a large archive creates term-creation race conditions — locking or dedup strategy required
- Entity disambiguation quality depends entirely on the org definition list being well-configured

# Claude Permissions

**Mode: D** — Autonomous

# Git Mode

**Mode: A** — Manual

- Never delete the project folder
- Never delete databases
- Never delete and start over — always work incrementally

# Skills

- Use `writing-style` skill when writing documents
- Use `superpowers:brainstorming` when asked to brainstorm or ideate
- Use `superpowers:writing-plans` when asked to create a plan or design
- Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` when asked to build
- Use `wordpress-themes` and `wordpress-blocks` for WordPress plugin/theme development
- Use `sass` for CSS/Sass work
- Use `web-security` when writing or reviewing code for security
- Use `claude-api` skill when working on LLM API integration
- Use `git-tagging` skill when cutting a release or managing versions
