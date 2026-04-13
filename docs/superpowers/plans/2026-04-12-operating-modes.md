# Operating Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire up Safe and YOLO operating modes so the setting saved on the settings page actually controls pipeline dispatch and auto-approval behavior.

**Architecture:** Mode is read from `get_option('navne_operating_mode')` at two touch points — `PostSaveHook` (dispatch decision) and `ProcessPostJob` (insert strategy). The Gutenberg sidebar reads mode from the GET suggestions endpoint and shows a manual trigger button in Safe mode instead of auto-polling after save.

**Tech Stack:** PHP 8.0+, WordPress, PHPUnit 9.6, Brain\Monkey 2.6, React/Gutenberg JS (@wordpress/element, @wordpress/components, @wordpress/data, @wordpress/api-fetch)

---

## File Map

| File | Change |
|------|--------|
| `includes/Storage/SuggestionsTable.php` | Add optional `$status` param to `insert_entities()` |
| `includes/Admin/SettingsPage.php` | Remove hardcoded 'suggest'; validate and save chosen mode; enable all three radio buttons |
| `includes/PostSaveHook.php` | Skip dispatch when mode is 'safe' |
| `includes/Jobs/ProcessPostJob.php` | YOLO branch: auto-approve high-confidence entities |
| `includes/Api/SuggestionsController.php` | Include `mode` in GET response |
| `assets/js/sidebar/hooks/useSuggestions.js` | Track mode from API; skip auto-poll in Safe mode |
| `assets/js/sidebar/components/SidebarPanel.js` | Safe mode idle UI with Process button |
| `tests/Unit/Storage/SuggestionsTableTest.php` | New test for status param |
| `tests/Unit/PostSaveHookTest.php` | New test for Safe mode skip |
| `tests/Unit/Jobs/ProcessPostJobTest.php` | YOLO tests; update existing tests to stub mode |
| `tests/Unit/Api/SuggestionsControllerTest.php` | New test for mode in GET response; update existing |

---

## Context: Test runner

```bash
bin/phpunit                          # all tests
bin/phpunit --filter ClassName       # single class
npm run build                        # compile JS after any asset change
```

All test files extend `Navne\Tests\Unit\TestCase` which sets up Brain\Monkey. WP functions are mocked with `Functions\when('fn_name')->justReturn(value)` (permissive stub, no assertion) or `Functions\expect('fn_name')->once()` (assertion — counted by Brain\Monkey, not PHPUnit). PHPUnit mock `expects($this->once())` IS counted as a PHPUnit assertion. When a test has only Brain\Monkey `expect()` calls and no PHPUnit assertions, add `$this->addToAssertionCount(1)` to avoid "risky test" warnings.

---

## Task 1: SuggestionsTable — optional status param on insert_entities

**Files:**
- Modify: `includes/Storage/SuggestionsTable.php:48-63`
- Test: `tests/Unit/Storage/SuggestionsTableTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Storage/SuggestionsTableTest.php`:

```php
public function test_insert_entities_uses_provided_status(): void {
	$db = $this->make_db();
	$db->expects( $this->once() )
	   ->method( 'insert' )
	   ->with(
			$this->anything(),
			$this->callback( fn( array $data ) => $data['status'] === 'approved' )
	   );

	( new SuggestionsTable( $db ) )->insert_entities(
		1,
		[ new Entity( 'NATO', 'org', 0.99 ) ],
		'approved'
	);
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
bin/phpunit --filter test_insert_entities_uses_provided_status
```

Expected: FAIL — `insert_entities` does not accept a third argument yet.

- [ ] **Step 3: Update insert_entities signature**

In `includes/Storage/SuggestionsTable.php`, change:

```php
/** @param Entity[] $entities */
public function insert_entities( int $post_id, array $entities ): void {
	foreach ( $entities as $entity ) {
		$this->db->insert(
			$this->table_name(),
			[
				'post_id'     => $post_id,
				'entity_name' => $entity->name,
				'entity_type' => $entity->type,
				'confidence'  => $entity->confidence,
				'status'      => 'pending',
			],
			[ '%d', '%s', '%s', '%f', '%s' ]
		);
	}
}
```

To:

```php
/** @param Entity[] $entities */
public function insert_entities( int $post_id, array $entities, string $status = 'pending' ): void {
	foreach ( $entities as $entity ) {
		$this->db->insert(
			$this->table_name(),
			[
				'post_id'     => $post_id,
				'entity_name' => $entity->name,
				'entity_type' => $entity->type,
				'confidence'  => $entity->confidence,
				'status'      => $status,
			],
			[ '%d', '%s', '%s', '%f', '%s' ]
		);
	}
}
```

- [ ] **Step 4: Run all tests to confirm pass**

```bash
bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add -f includes/Storage/SuggestionsTable.php tests/Unit/Storage/SuggestionsTableTest.php
git commit -m "feat: add optional status param to SuggestionsTable::insert_entities()"
```

---

## Task 2: SettingsPage — wire up mode save and enable all three radio buttons

**Files:**
- Modify: `includes/Admin/SettingsPage.php:84-100, 165-166`

No unit tests for settings page (admin context — covered by smoke test).

- [ ] **Step 1: Replace the hardcoded mode enforcement in handle_save()**

In `includes/Admin/SettingsPage.php`, replace:

```php
		// Only 'suggest' is active — safe and yolo are coming soon. Enforce server-side.
		update_option( 'navne_operating_mode', 'suggest' );
```

With:

```php
		// Operating mode — validate against allowed values.
		$allowed_modes = [ 'safe', 'suggest', 'yolo' ];
		$mode_raw      = sanitize_key( $_POST['navne_operating_mode'] ?? 'suggest' );
		$mode          = in_array( $mode_raw, $allowed_modes, true ) ? $mode_raw : 'suggest';
		update_option( 'navne_operating_mode', $mode );
```

- [ ] **Step 2: Enable all three radio buttons in render()**

In `includes/Admin/SettingsPage.php`, replace the Operating Mode fieldset:

```php
				<fieldset>
					<label style="display:block;margin-bottom:6px;color:#999;">
						<input type="radio" name="navne_operating_mode" value="safe" disabled />
						Safe <span style="font-style:italic">(coming soon)</span>
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="navne_operating_mode" value="suggest"
							<?php checked( $mode, 'suggest' ); ?> />
						Suggest — editor reviews all suggestions before anything is linked
					</label>
					<label style="display:block;color:#999;">
						<input type="radio" name="navne_operating_mode" value="yolo" disabled />
						YOLO <span style="font-style:italic">(coming soon)</span>
					</label>
				</fieldset>
```

With:

```php
				<fieldset>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="navne_operating_mode" value="safe"
							<?php checked( $mode, 'safe' ); ?> />
						Safe — only approved entities are linked; pipeline runs on demand
					</label>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="navne_operating_mode" value="suggest"
							<?php checked( $mode, 'suggest' ); ?> />
						Suggest — editor reviews all suggestions before anything is linked
					</label>
					<label style="display:block;">
						<input type="radio" name="navne_operating_mode" value="yolo"
							<?php checked( $mode, 'yolo' ); ?> />
						YOLO — entities above 75% confidence are linked automatically
					</label>
				</fieldset>
```

- [ ] **Step 3: Run all tests to confirm no regressions**

```bash
bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add -f includes/Admin/SettingsPage.php
git commit -m "feat: wire up operating mode setting — all three modes now saveable"
```

---

## Task 3: PostSaveHook — Safe mode skips automatic dispatch

**Files:**
- Modify: `includes/PostSaveHook.php:17-20`
- Test: `tests/Unit/PostSaveHookTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/PostSaveHookTest.php`:

```php
public function test_skips_dispatch_in_safe_mode(): void {
	Functions\when( 'wp_is_post_revision' )->justReturn( false );
	Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	Functions\when( 'get_post_meta' )->justReturn( '' );
	Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
		if ( 'navne_post_types' === $key ) return [ 'post' ];
		if ( 'navne_operating_mode' === $key ) return 'safe';
		return $default;
	} );
	Functions\expect( 'update_post_meta' )->never();
	Functions\expect( 'as_enqueue_async_action' )->never();

	PostSaveHook::handle( 1, $this->make_post( 'publish', 'post' ), true );
	$this->addToAssertionCount( 1 );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
bin/phpunit --filter test_skips_dispatch_in_safe_mode
```

Expected: FAIL — hook dispatches regardless of mode.

- [ ] **Step 3: Add the mode guard to PostSaveHook**

In `includes/PostSaveHook.php`, after the allowed-types guard (after line 19), add:

```php
		$mode = (string) get_option( 'navne_operating_mode', 'suggest' );
		if ( 'safe' === $mode ) {
			return;
		}
```

The full `handle()` method becomes:

```php
	public static function handle( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$current = get_post_meta( $post_id, '_navne_job_status', true );
		if ( in_array( $current, [ 'queued', 'processing' ], true ) ) {
			return;
		}
		$allowed_types = (array) get_option( 'navne_post_types', [ 'post' ] );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}
		$mode = (string) get_option( 'navne_operating_mode', 'suggest' );
		if ( 'safe' === $mode ) {
			return;
		}
		update_post_meta( $post_id, '_navne_job_status', 'queued' );
		update_post_meta( $post_id, '_navne_job_queued_at', current_time( 'mysql' ) );
		as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] );
	}
```

- [ ] **Step 4: Run all tests to confirm pass**

```bash
bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add -f includes/PostSaveHook.php tests/Unit/PostSaveHookTest.php
git commit -m "feat: skip pipeline dispatch in Safe mode"
```

---

## Task 4: ProcessPostJob — YOLO auto-approval

**Files:**
- Modify: `includes/Jobs/ProcessPostJob.php:19-28`
- Test: `tests/Unit/Jobs/ProcessPostJobTest.php`

- [ ] **Step 1: Update existing tests to stub the operating mode**

In `tests/Unit/Jobs/ProcessPostJobTest.php`, update `test_run_sets_status_to_complete_on_success` and `test_run_filters_already_approved_entities` to add a `get_option` stub (so they don't pick up a null mode when the new code runs):

`test_run_sets_status_to_complete_on_success` — add before `ProcessPostJob::run(...)`:
```php
	Functions\when( 'get_option' )->justReturn( 'suggest' );
```

`test_run_filters_already_approved_entities` — add before `ProcessPostJob::run(...)`:
```php
	Functions\when( 'get_option' )->justReturn( 'suggest' );
```

- [ ] **Step 2: Write the YOLO failing tests**

Add to `tests/Unit/Jobs/ProcessPostJobTest.php`:

```php
public function test_yolo_auto_approves_high_confidence_entities(): void {
	$high = new Entity( 'Jane Smith', 'person', 0.80 );
	$low  = new Entity( 'NATO',       'org',    0.60 );

	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( 'run' )->willReturn( [ $high, $low ] );

	$table = $this->createMock( SuggestionsTable::class );
	$table->method( 'find_approved_names_for_post' )->willReturn( [] );
	$table->expects( $this->exactly( 2 ) )
		  ->method( 'insert_entities' )
		  ->withConsecutive(
			  [ 1, [ $high ], 'approved' ],
			  [ 1, [ $low ] ]
		  );

	Functions\when( 'get_option' )->justReturn( 'yolo' );
	Functions\expect( 'update_post_meta' )->twice();
	Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 42, 'term_taxonomy_id' => 42 ] );
	Functions\when( 'is_wp_error' )->justReturn( false );
	Functions\when( 'wp_set_post_terms' )->justReturn( [ 42 ] );
	Functions\when( 'wp_cache_delete' )->justReturn( true );

	ProcessPostJob::run( 1, $pipeline, $table );
}

public function test_yolo_inserts_low_confidence_entities_as_pending(): void {
	$low1 = new Entity( 'NATO', 'org',    0.60 );
	$low2 = new Entity( 'EPA',  'org',    0.50 );

	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( 'run' )->willReturn( [ $low1, $low2 ] );

	$table = $this->createMock( SuggestionsTable::class );
	$table->method( 'find_approved_names_for_post' )->willReturn( [] );
	// Only one insert_entities call — the high-confidence branch is skipped entirely.
	$table->expects( $this->once() )
		  ->method( 'insert_entities' )
		  ->with( 1, [ $low1, $low2 ] );

	Functions\when( 'get_option' )->justReturn( 'yolo' );
	Functions\expect( 'update_post_meta' )->twice();

	ProcessPostJob::run( 1, $pipeline, $table );
}
```

- [ ] **Step 3: Run the new tests to confirm they fail**

```bash
bin/phpunit --filter "test_yolo"
```

Expected: FAIL — no YOLO branch exists yet.

- [ ] **Step 4: Implement the YOLO branch in ProcessPostJob**

Replace the try block body in `includes/Jobs/ProcessPostJob.php` so the full file reads:

```php
<?php
namespace Navne\Jobs;

use Navne\Exception\PipelineException;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class ProcessPostJob {
	public static function run(
		int             $post_id,
		?EntityPipeline $pipeline = null,
		?SuggestionsTable $table  = null
	): void {
		$table ??= SuggestionsTable::instance();

		update_post_meta( $post_id, '_navne_job_status', 'processing' );
		try {
			$pipeline ??= Plugin::make_pipeline();
			$table->delete_pending_for_post( $post_id );
			$entities       = $pipeline->run( $post_id );
			$approved_names = $table->find_approved_names_for_post( $post_id );
			if ( ! empty( $approved_names ) ) {
				$entities = array_values( array_filter(
					$entities,
					fn( Entity $e ) => ! in_array( strtolower( $e->name ), $approved_names, true )
				) );
			}

			$mode = (string) get_option( 'navne_operating_mode', 'suggest' );
			if ( 'yolo' === $mode ) {
				$high = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence >= 0.75 ) );
				$low  = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence < 0.75 ) );
				if ( ! empty( $high ) ) {
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
					wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );
				}
				$table->insert_entities( $post_id, $low );
			} else {
				$table->insert_entities( $post_id, $entities );
			}

			update_post_meta( $post_id, '_navne_job_status', 'complete' );
		} catch ( \Exception $e ) {
			update_post_meta( $post_id, '_navne_job_status', 'failed' );
			error_log( 'Navne pipeline failed for post ' . $post_id . ': ' . $e->getMessage() );
		}
	}
}
```

- [ ] **Step 5: Run all tests to confirm pass**

```bash
bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add -f includes/Jobs/ProcessPostJob.php tests/Unit/Jobs/ProcessPostJobTest.php
git commit -m "feat: YOLO mode auto-approves entities at >= 75% confidence"
```

---

## Task 5: SuggestionsController — include mode in GET response

**Files:**
- Modify: `includes/Api/SuggestionsController.php:35-40`
- Test: `tests/Unit/Api/SuggestionsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Api/SuggestionsControllerTest.php`:

```php
public function test_get_suggestions_includes_mode(): void {
	$table = $this->createMock( SuggestionsTable::class );
	$table->method( 'find_by_post' )->willReturn( [] );

	Functions\when( 'get_post_meta' )->justReturn( 'idle' );
	Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
		return $key === 'navne_operating_mode' ? 'yolo' : $default;
	} );

	$request = new \WP_REST_Request();
	$request->set_param( 'post_id', 10 );

	$response = ( new SuggestionsController( $table ) )->get_suggestions( $request );
	$this->assertSame( 'yolo', $response->data['mode'] );
}
```

Also update the existing `test_get_suggestions_returns_job_status_and_rows` to stub `get_option` so it does not pick up an unexpected call after the change:

```php
Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
    return $key === 'navne_operating_mode' ? 'suggest' : $default;
} );
```

Add that line before the `$request` setup in the existing test.

- [ ] **Step 2: Run the new test to confirm it fails**

```bash
bin/phpunit --filter test_get_suggestions_includes_mode
```

Expected: FAIL — response does not contain a `mode` key.

- [ ] **Step 3: Add mode to get_suggestions response**

In `includes/Api/SuggestionsController.php`, replace:

```php
	public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return new \WP_REST_Response( [
			'job_status'  => get_post_meta( $post_id, '_navne_job_status', true ) ?: 'idle',
			'suggestions' => $this->table->find_by_post( $post_id ),
		] );
	}
```

With:

```php
	public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return new \WP_REST_Response( [
			'job_status'  => get_post_meta( $post_id, '_navne_job_status', true ) ?: 'idle',
			'suggestions' => $this->table->find_by_post( $post_id ),
			'mode'        => (string) get_option( 'navne_operating_mode', 'suggest' ),
		] );
	}
```

- [ ] **Step 4: Run all tests to confirm pass**

```bash
bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add -f includes/Api/SuggestionsController.php tests/Unit/Api/SuggestionsControllerTest.php
git commit -m "feat: include operating mode in GET suggestions response"
```

---

## Task 6: Sidebar JS — mode-aware UI

**Files:**
- Modify: `assets/js/sidebar/hooks/useSuggestions.js`
- Modify: `assets/js/sidebar/components/SidebarPanel.js`

No JS unit tests — covered by smoke test.

- [ ] **Step 1: Update useSuggestions.js**

Replace the entire contents of `assets/js/sidebar/hooks/useSuggestions.js` with:

```js
// assets/js/sidebar/hooks/useSuggestions.js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

export default function useSuggestions( postId ) {
	const [ jobStatus, setJobStatus ]     = useState( 'idle' );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ isLoading, setIsLoading ]     = useState( false );
	const [ mode, setMode ]               = useState( 'suggest' );
	const pollRef                         = useRef( null );
	const wasSaving                       = useRef( false );

	const isSaving = useSelect( ( select ) =>
		select( 'core/editor' ).isSavingPost()
	);

	const fetchSuggestions = useCallback( async () => {
		try {
			const data = await apiFetch( { path: `/navne/v1/suggestions/${ postId }` } );
			setJobStatus( data.job_status );
			setSuggestions( data.suggestions );
			setMode( data.mode || 'suggest' );
			return data.job_status;
		} catch {
			setJobStatus( 'failed' );
			return 'failed';
		}
	}, [ postId ] );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
		setIsLoading( false );
	}, [] );

	const startPolling = useCallback( () => {
		if ( pollRef.current ) return;
		setIsLoading( true );
		// Fetch immediately, then poll every 3 seconds.
		fetchSuggestions().then( ( status ) => {
			if ( status === 'complete' || status === 'failed' ) {
				stopPolling();
				return;
			}
			pollRef.current = setInterval( async () => {
				const s = await fetchSuggestions();
				if ( s === 'complete' || s === 'failed' ) {
					stopPolling();
				}
			}, 3000 );
		} );
	}, [ fetchSuggestions, stopPolling ] );

	// After a save: auto-poll in Suggest/YOLO; refresh state only in Safe.
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

	// Load existing suggestions on mount.
	useEffect( () => {
		fetchSuggestions();
		return stopPolling;
	}, [ fetchSuggestions, stopPolling ] );

	const approve = useCallback( async ( id ) => {
		setSuggestions( ( prev ) => {
			return prev.map( ( s ) => ( s.id === id ? { ...s, status: 'approved' } : s ) );
		} );
		try {
			await apiFetch( {
				path:   `/navne/v1/suggestions/${ postId }/approve`,
				method: 'POST',
				data:   { id },
			} );
		} catch {
			// Rollback optimistic update on failure.
			setSuggestions( ( prev ) =>
				prev.map( ( s ) => ( s.id === id && s.status === 'approved' ? { ...s, status: 'pending' } : s ) )
			);
		}
	}, [ postId ] );

	const dismiss = useCallback( async ( id ) => {
		setSuggestions( ( prev ) => {
			return prev.map( ( s ) => ( s.id === id ? { ...s, status: 'dismissed' } : s ) );
		} );
		try {
			await apiFetch( {
				path:   `/navne/v1/suggestions/${ postId }/dismiss`,
				method: 'POST',
				data:   { id },
			} );
		} catch {
			// Rollback optimistic update on failure.
			setSuggestions( ( prev ) =>
				prev.map( ( s ) => ( s.id === id && s.status === 'dismissed' ? { ...s, status: 'pending' } : s ) )
			);
		}
	}, [ postId ] );

	const retry = useCallback( async () => {
		stopPolling();
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/retry`,
			method: 'POST',
		} );
		setJobStatus( 'queued' );
		startPolling();
	}, [ postId, stopPolling, startPolling ] );

	return { jobStatus, suggestions, isLoading, mode, approve, dismiss, retry };
}
```

- [ ] **Step 2: Update SidebarPanel.js**

Replace the entire contents of `assets/js/sidebar/components/SidebarPanel.js` with:

```js
// assets/js/sidebar/components/SidebarPanel.js
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { PanelBody, Spinner, Button, Notice } from '@wordpress/components';
import useSuggestions from '../hooks/useSuggestions';
import SuggestionCard from './SuggestionCard';
import ApprovedList   from './ApprovedList';

export default function SidebarPanel() {
	const postId = useSelect( ( select ) =>
		select( 'core/editor' ).getCurrentPostId()
	);
	const { jobStatus, suggestions, isLoading, mode, approve, dismiss, retry } =
		useSuggestions( postId );

	const pending = suggestions.filter( ( s ) => s.status === 'pending' );

	return (
		<PanelBody initialOpen={ true }>
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

			{ ( jobStatus === 'queued' || jobStatus === 'processing' || isLoading ) && (
				<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
					<Spinner />
					<span>{ __( 'Analyzing article\u2026', 'navne' ) }</span>
				</div>
			) }

			{ jobStatus === 'failed' && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ __( 'Entity detection failed.', 'navne' ) }
					</Notice>
					<Button variant="secondary" onClick={ retry } style={ { marginTop: '8px' } }>
						{ __( 'Retry', 'navne' ) }
					</Button>
				</>
			) }

			{ jobStatus === 'complete' && ! pending.length && ! suggestions.some( ( s ) => s.status === 'approved' ) && (
				<p style={ { color: '#666', fontSize: '13px' } }>
					{ __( 'No entity suggestions found.', 'navne' ) }
				</p>
			) }

			{ pending.map( ( s ) => (
				<SuggestionCard
					key={ s.id }
					suggestion={ s }
					onApprove={ approve }
					onDismiss={ dismiss }
				/>
			) ) }

			<ApprovedList suggestions={ suggestions } />
		</PanelBody>
	);
}
```

- [ ] **Step 3: Build assets**

```bash
npm run build
```

Expected: build succeeds with no errors.

- [ ] **Step 4: Commit**

```bash
git add -f assets/js/sidebar/hooks/useSuggestions.js assets/js/sidebar/components/SidebarPanel.js assets/js/build/index.js assets/js/build/index.asset.php
git commit -m "feat: sidebar shows Process button in Safe mode, skips auto-poll on save"
```

---

## Task 7: Smoke test + v1.3.0 release notes

**Files:**
- Create: `docs/releases/v1.3.0.md`

- [ ] **Step 1: Run the full test suite one final time**

```bash
bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 2: Smoke test — Safe mode**

1. Settings → Navne → select Safe → save
2. Open a post in Gutenberg — sidebar shows "Safe mode — linking uses approved entities only." with a "Process this article" button
3. Save the post — sidebar refreshes its state but no spinner appears automatically
4. Click "Process this article" — spinner appears, job runs, suggestions surface as normal

- [ ] **Step 3: Smoke test — Suggest mode**

1. Settings → Navne → select Suggest → save
2. Save a post — pipeline dispatches automatically, spinner appears, suggestions surface as before

- [ ] **Step 4: Smoke test — YOLO mode**

1. Settings → Navne → select YOLO → save
2. Save a post with content that produces high-confidence entities
3. Check the entity taxonomy — high-confidence entities should be linked immediately without any sidebar action
4. Sidebar shows any low-confidence entities as pending suggestions for manual review

- [ ] **Step 5: Write release notes**

Create `docs/releases/v1.3.0.md` following the style of `docs/releases/v1.2.0.md`. Cover: all three modes now active, what each does, what's still deferred.

- [ ] **Step 6: Commit release notes**

```bash
git add docs/releases/v1.3.0.md
git commit -m "docs: add v1.3.0 release notes"
```
