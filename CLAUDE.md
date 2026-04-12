# Project

A WordPress plugin for news organizations that automatically links proper nouns in published articles to their taxonomy archive pages and generates taxonomy terms for detected entities. Uses a two-stage NER + LLM pipeline: spaCy for candidate detection, LLM for disambiguation and confidence scoring.

# Commands

```bash
# Install PHP deps:  composer install
# Install JS deps:   npm install
# Build assets:      npm run build
# Dev watch:         npm run dev
# Run tests:         phpunit
# Run single test:   phpunit --filter TestClassName
```

# Code Style

- WordPress VIP coding standards enforced via phpcs
- Tabs for indentation (PHP and JS)
- Double quotes in PHP
- WordPress naming conventions: snake_case functions/variables, PascalCase classes

# Workflow

- Trunk-based: work directly on master, no formal PR process

# Architecture

Key design decisions from `docs/wordpress-entity-linking-plugin-notes.md`:

- **Flat taxonomy**: One taxonomy for all entity types — People, Orgs, Places share a single vocabulary
- **Render-time linking**: Links applied via `the_content` filter — never written to post content in the DB; clean archive
- **Object cache**: Redis/Memcached stores the entity-linking map per post; invalidated on term create/merge/delete
- **Two-stage pipeline**: spaCy NER generates candidate list → LLM validates, disambiguates, and scores confidence
- **Three operating modes**: Safe (whitelist-only), Suggest (whitelist + editor sidebar prompts), YOLO (full auto with mod queue)
- **Async jobs**: Use Action Scheduler for all LLM calls — never block synchronously on post save
- **Org definition list**: Knowledge base of local figures, jargon, acronyms — primes the LLM before each post

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
