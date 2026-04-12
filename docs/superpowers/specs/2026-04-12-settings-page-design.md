# Settings Page — Design Spec

**Date:** 2026-04-12
**Version:** v1.1
**Scope:** Plugin settings page — API configuration, operating mode, post type targeting.

---

## Overview

A WordPress admin settings page under Settings → Navne that exposes all plugin configuration currently requiring WP-CLI access. Builds the foundation for the org definition list (v1.2) to live alongside these settings.

---

## Plugin Structure Changes

```
navne/
├── includes/
│   ├── Admin/
│   │   └── SettingsPage.php        # Menu registration, form render, save handling
│   ├── PostSaveHook.php             # Modified: post type guard added
```

---

## Admin Page

- **Location:** Settings → Navne
- **Page slug:** `navne-settings`
- **URL:** `/wp-admin/options-general.php?page=navne-settings`
- **Capability required:** `manage_options`
- **Registered via:** `add_options_page()` called from `add_action('admin_menu', ...)`
- **Wired in:** `Plugin::init()`

---

## Settings Fields

| Setting | Option key | Input type | Sanitization | Default |
|---|---|---|---|---|
| Provider | `navne_provider` | select | `sanitize_key()` | `'anthropic'` |
| API Key | `navne_anthropic_api_key` | password | `sanitize_text_field()` | `''` |
| Model | `navne_anthropic_model` | text | `sanitize_text_field()` | `'claude-sonnet-4-6'` |
| Operating mode | `navne_operating_mode` | radio | `sanitize_key()` | `'suggest'` |
| Post types | `navne_post_types` | checkboxes | `array_map('sanitize_key', ...)` | `['post']` |

### Provider

Dropdown. Currently one option: `Anthropic`. Renders the API key and model fields as provider-specific sub-fields beneath it. When additional providers are added, their fields appear conditionally.

### API Key

Password input. Value is never echoed back into the HTML on page load — the field renders empty regardless of whether a key is stored. On save, if the field is submitted blank, the existing stored value is preserved unchanged. A "Key is configured" / "No key set" indicator renders next to the field to show current state without revealing the value.

### Model

Text input. Defaults to `claude-sonnet-4-6`. Free-form — allows any model string so it doesn't need updating as new models release.

### Operating Mode

Three radio buttons:

- **Safe** — disabled, labelled "Coming soon"
- **Suggest** — enabled, selected by default
- **YOLO** — disabled, labelled "Coming soon"

The saved value is stored but the plugin treats any value other than `'suggest'` as `'suggest'` for now. When Safe and YOLO are implemented, no migration is needed — the stored value will be read correctly.

### Post Types

Checkboxes. Populated at render time from `get_post_types(['public' => true])`. At least one post type must remain checked — if all are unchecked on save, the save is rejected and an admin notice error is shown. Default: `['post']`.

---

## Save Handling

1. Check `current_user_can('manage_options')` — bail if not
2. `check_admin_referer('navne_settings')` — bail on nonce failure
3. Sanitize each field (see table above)
4. API key: if submitted value is empty, skip `update_option` for that key
5. Post types: if sanitized array is empty, reject save with admin notice error
6. Save each option via `update_option()`
7. Redirect back to settings page with `?updated=1` query param
8. On next page load, show success admin notice if `?updated=1` is present

---

## PostSaveHook Change

Add a post type guard to `PostSaveHook::handle()` after the existing revision/autosave/publish-status checks:

```php
$allowed_types = (array) get_option( 'navne_post_types', [ 'post' ] );
if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
    return;
}
```

---

## Security

- `wp_nonce_field( 'navne_settings' )` in form / `check_admin_referer( 'navne_settings' )` on save
- `current_user_can( 'manage_options' )` gate on both render and save
- API key never echoed into HTML
- All inputs sanitized before `update_option()`
- Post-save redirect (`wp_redirect` + `exit`) prevents form resubmission on refresh

---

## Testing

**Unit tested:**

- `PostSaveHook` — new test: post type not in `navne_post_types` option returns early (Brain\Monkey mock of `get_option`)

**Not unit tested:**

- `SettingsPage` rendering and save flow — WP admin context required; covered by smoke test

**Smoke test steps:**

1. Navigate to Settings → Navne
2. Verify all fields render with correct defaults
3. Update API key — verify "Key is configured" indicator appears after save, field does not echo the value
4. Leave API key blank on re-save — verify existing key is preserved
5. Uncheck all post types — verify error notice appears and settings are not saved
6. Change post types to include `page` — publish a page — verify pipeline dispatches
7. Change post types back to `post` only — publish a page — verify pipeline does not dispatch

---

## Out of Scope (v1.1)

- Org definition list UI (v1.2)
- Implementing Safe or YOLO modes
- Per-post-type configuration (e.g. different modes per type)
- Settings import/export
