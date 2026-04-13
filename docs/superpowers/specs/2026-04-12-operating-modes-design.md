# Operating Modes — Design Spec

**Date:** 2026-04-12
**Version:** v1.3
**Scope:** Wire up Safe and YOLO operating modes. The settings page already shows all three options; the server currently ignores them and enforces Suggest. This release makes the setting real.

---

## Overview

Three modes control how aggressively the plugin links entities on post save.

**Safe** — The pipeline never runs automatically. Render-time linking uses only entities already in the approved whitelist. After saving, the sidebar prompts the editor to optionally run the pipeline for that post. If they accept, it runs exactly like Suggest mode for that post only.

**Suggest** — Current behavior. The pipeline runs automatically on every qualifying post save. Suggestions surface in the Gutenberg sidebar for the editor to approve or dismiss manually.

**YOLO** — The pipeline runs automatically. Entities returned at ≥ 75% confidence are auto-approved: the term is created, assigned to the post, and the link goes live immediately. Entities below 75% are inserted as pending and surface in the sidebar for manual review.

---

## Plugin Structure Changes

```
navne/
├── includes/
│   ├── Admin/
│   │   └── SettingsPage.php          # Modified: remove hardcoded 'suggest' enforcement
│   ├── Api/
│   │   └── SuggestionsController.php # Modified: include mode in GET response
│   ├── Jobs/
│   │   └── ProcessPostJob.php        # Modified: YOLO auto-approval logic
│   ├── PostSaveHook.php               # Modified: Safe mode skips dispatch
│   └── Storage/
│       └── SuggestionsTable.php      # Modified: optional status param on insert_entities
└── assets/js/sidebar/
    ├── components/
    │   └── SidebarPanel.js           # Modified: Safe mode idle UI
    └── hooks/
        └── useSuggestions.js         # Modified: expose mode, skip auto-poll in Safe
```

No new files. No new database tables.

---

## Settings Page

Remove the line in `handle_save()` that hardcodes `update_option('navne_operating_mode', 'suggest')`. Replace with:

```php
$allowed_modes = [ 'safe', 'suggest', 'yolo' ];
$mode          = sanitize_key( $_POST['navne_operating_mode'] ?? 'suggest' );
if ( ! in_array( $mode, $allowed_modes, true ) ) {
    $mode = 'suggest';
}
update_option( 'navne_operating_mode', $mode );
```

The radio button UI already exists. No HTML changes needed.

---

## PostSaveHook

After the post type guard, check the mode before dispatching:

```php
$mode = (string) get_option( 'navne_operating_mode', 'suggest' );
if ( 'safe' === $mode ) {
    return;
}
```

`suggest` and `yolo` both dispatch as they do now. No other changes.

---

## SuggestionsTable

`insert_entities()` gets an optional `$status` parameter:

```php
public function insert_entities( int $post_id, array $entities, string $status = 'pending' ): void
```

The default stays `'pending'`. All existing callers are unaffected.

---

## ProcessPostJob

After the deduplication filter, read the mode and branch:

```php
$mode = (string) get_option( 'navne_operating_mode', 'suggest' );

if ( 'yolo' === $mode ) {
    $high = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence >= 0.75 ) );
    $low  = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence < 0.75 ) );

    $table->insert_entities( $post_id, $high, 'approved' );
    foreach ( $high as $entity ) {
        $term = wp_insert_term( $entity->name, 'navne_entity' );
        if ( is_wp_error( $term ) ) {
            $term_id = (int) $term->get_error_data( 'term_exists' );
        } else {
            $term_id = (int) $term['term_id'];
        }
        wp_set_post_terms( $post_id, [ $term_id ], 'navne_entity', true );
    }
    if ( ! empty( $high ) ) {
        wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );
    }

    $table->insert_entities( $post_id, $low );
} else {
    // suggest or safe (triggered manually via retry): all pending
    $table->insert_entities( $post_id, $entities );
}
```

The YOLO threshold (0.75) is hardcoded. No configuration needed at this stage.

---

## SuggestionsController — GET response

Add `mode` to the `get_suggestions` response:

```php
return new \WP_REST_Response( [
    'job_status'  => get_post_meta( $post_id, '_navne_job_status', true ) ?: 'idle',
    'suggestions' => $this->table->find_by_post( $post_id ),
    'mode'        => (string) get_option( 'navne_operating_mode', 'suggest' ),
] );
```

---

## Sidebar JS

### useSuggestions.js

Add `mode` to state (default `'suggest'`). Set it from the API response in `fetchSuggestions`. Expose it from the hook.

Change the post-save effect: in Safe mode, call `fetchSuggestions()` to refresh state but skip `startPolling()`.

```js
const [ mode, setMode ] = useState( 'suggest' );

// Inside fetchSuggestions:
setMode( data.mode || 'suggest' );

// Post-save effect:
useEffect( () => {
    if ( wasSaving.current && ! isSaving ) {
        if ( mode !== 'safe' ) {
            startPolling();
        } else {
            fetchSuggestions();
        }
    }
    wasSaving.current = isSaving;
}, [ isSaving, mode, startPolling, fetchSuggestions ] );

// Return value — add mode:
return { jobStatus, suggestions, isLoading, mode, approve, dismiss, retry };
```

### SidebarPanel.js

Replace the idle message with a mode-aware branch:

```jsx
{ jobStatus === 'idle' && mode !== 'safe' && (
    <p style={ { color: '#666', fontSize: '13px' } }>
        { __( 'Save the post to detect entities.', 'navne' ) }
    </p>
) }

{ jobStatus === 'idle' && mode === 'safe' && (
    <>
        <p style={ { color: '#666', fontSize: '13px' } }>
            { __( 'Safe mode — linking uses approved entities only.', 'navne' ) }
        </p>
        <Button variant="secondary" onClick={ retry }>
            { __( 'Process this article', 'navne' ) }
        </Button>
    </>
) }
```

All other status branches (`queued`, `processing`, `failed`, `complete`) are unchanged in both modes.

---

## Testing

**Unit tested:**

- `PostSaveHookTest` — `test_skips_dispatch_in_safe_mode()`: mock `get_option` to return `'safe'`; assert `as_enqueue_async_action` is never called.
- `SuggestionsTableTest` — `test_insert_entities_uses_provided_status()`: pass `'approved'`; assert `db->insert` is called with `'status' => 'approved'`.
- `ProcessPostJobTest`:
  - `test_yolo_auto_approves_high_confidence_entities()`: mock mode as `'yolo'`; pipeline returns one entity at 0.80 and one at 0.60; assert `insert_entities` called twice (once with `'approved'`, once with default pending); assert `wp_insert_term` and `wp_set_post_terms` called for the high-confidence entity only; assert `wp_cache_delete` called.
  - `test_yolo_inserts_low_confidence_as_pending()`: same setup; assert the 0.60 entity goes into the second `insert_entities` call with no status override.
  - `test_suggest_mode_inserts_all_as_pending()`: mode `'suggest'`; assert single `insert_entities` call, no `wp_insert_term`.
- `SuggestionsControllerTest` — `test_get_suggestions_includes_mode()`: mock `get_option` to return `'yolo'`; assert response body contains `'mode' => 'yolo'`.

**Not unit tested:**

Settings page mode save and sidebar JS behavior — covered by smoke test.

**Smoke test steps:**

1. Set mode to Safe → save a post → confirm no job dispatches (sidebar shows "Process this article" button, not a spinner)
2. Click "Process this article" → confirm job queues and suggestions appear
3. Set mode to Suggest → save a post → confirm pipeline runs automatically as before
4. Set mode to YOLO → save a post with content that produces high-confidence entities → confirm those entities are linked immediately without editor action; confirm any low-confidence ones appear as suggestions in the sidebar

---

## Out of Scope (v1.3)

- Configurable YOLO confidence threshold
- Per-post mode override
- Mod queue UI for reviewing YOLO auto-approvals in bulk
