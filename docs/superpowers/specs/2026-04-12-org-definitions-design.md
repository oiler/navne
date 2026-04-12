# Org Definitions — Design Spec

**Date:** 2026-04-12
**Version:** v1.2
**Scope:** Org definition list — textarea UI on settings page, storage in `wp_options`, injection into LLM prompt.

---

## Overview

The LLM prompt has carried a `[ORG DEFINITION LIST]` placeholder since v1.0 with a hardcoded fallback message. This release fills it in. A newsroom configures a list of local figures, acronyms, and jargon — one `Term: Description` entry per line — and the plugin injects that context into every prompt before processing a post.

The goal is better disambiguation. Without it, "DOE" could mean the federal Department of Energy or the local school district. "The Governor" means nothing without knowing which state or who currently holds the office. The definition list hands the LLM that local knowledge up front.

---

## Plugin Structure Changes

```
navne/
├── includes/
│   ├── Admin/
│   │   └── SettingsPage.php        # Modified: add Org Definitions section
│   └── Pipeline/
│       └── LlmResolver.php         # Modified: parse definitions, inject into prompt
└── tests/
    └── Unit/
        └── Pipeline/
            └── LlmResolverTest.php  # Modified: two new tests
```

No new files. No new database tables.

---

## Settings Page Changes

A new "Org Definitions" section is added at the bottom of the Settings → Navne page, below Post Types.

**Option key:** `navne_org_definitions`
**Input type:** `<textarea>` (~12 rows)
**Sanitization:** `sanitize_textarea_field()`
**Default:** `''`

The stored value is rendered back into the textarea on page load. There is nothing sensitive here — no special handling on re-render.

**Instructions rendered above the field:**

> One definition per line. Format: `Term: Description`. Lines starting with `#` are ignored.

**Example shown below the field:**

```
# Local acronyms and figures
DOE: The local school district, not the federal Department of Energy
Gov. Smith: Governor Jane Smith, incumbent since 2020
FMS: Fairfield Middle School on Route 9
```

If the field is submitted blank, `update_option( 'navne_org_definitions', '' )` runs as normal — no special case needed.

---

## Prompt Injection

### Parser

`LlmResolver` gets a private `format_definitions( string $raw ): string` method.

Rules:
- Split `$raw` by newline
- Skip blank lines
- Skip lines where the first non-whitespace character is `#`
- Skip lines that contain no colon
- Split each remaining line on the **first** colon only; trim both sides
- Rejoin as `Term: Description` lines

Returns the formatted string, or an empty string if no valid entries remain.

### Prompt block

`build_prompt()` reads `get_option( 'navne_org_definitions', '' )` and calls `format_definitions()`.

**When definitions are present:**

```
[ORG DEFINITION LIST]
DOE: The local school district, not the federal Department of Energy
Gov. Smith: Governor Jane Smith, incumbent since 2020
FMS: Fairfield Middle School on Route 9
```

**When definitions are empty or blank:**

```
[ORG DEFINITION LIST]
(No organization-specific definitions configured.)
```

The surrounding prompt structure — the instruction paragraphs, the article content — is unchanged.

---

## Testing

**Unit tested** (`LlmResolverTest.php`):

- `test_prompt_includes_definitions_when_configured()` — mock `get_option('navne_org_definitions')` to return a raw string containing valid entries, a `#` comment line, and a blank line. Assert the formatted entries appear in the returned prompt string. Assert the comment line and blank line do not appear.
- `test_prompt_falls_back_when_no_definitions()` — mock `get_option('navne_org_definitions')` to return `''`. Assert the prompt contains `(No organization-specific definitions configured.)`.

**Not unit tested:**

`SettingsPage` textarea rendering and save — WP admin context required; covered by smoke test.

**Smoke test steps:**

1. Navigate to Settings → Navne — verify the Org Definitions section renders below Post Types
2. Paste a few definitions including a `#` comment line; save — verify they appear correctly on reload and the comment is preserved in the textarea
3. Publish a post — verify the pipeline dispatches and completes
4. Check the generated entity suggestions to confirm the LLM is using the local context (e.g., correctly typing a local acronym you defined)

---

## Out of Scope (v1.2)

- Per-entry entity type field (person / org / place / other)
- Validation UI that highlights malformed lines
- Import/export of the definition list
- Connecting definitions to approved taxonomy terms (future: the enhanced taxonomy page feature may populate definitions from term metadata)
