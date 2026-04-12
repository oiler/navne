# Settings Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress admin settings page under Settings → Navne that exposes API key, provider, model, operating mode, and post type configuration — replacing WP-CLI as the setup path.

**Architecture:** One new class `SettingsPage` handles admin menu registration, form rendering, and save handling. `PostSaveHook` gets a single post-type guard added. `Plugin::init()` wires the settings page in via `admin_menu`. No JS build required — pure PHP admin HTML.

**Tech Stack:** PHP 8.0+, WordPress Settings (manual save pattern), PHPUnit 9.6 + Brain\Monkey 2.6 for unit tests, WordPress admin hooks.

---

## File Map

```
wp/app/public/wp-content/plugins/navne/
├── includes/
│   ├── Admin/
│   │   └── SettingsPage.php        # Create — menu registration, form render, save handling
│   ├── Plugin.php                  # Modify — wire admin_menu hook
│   └── PostSaveHook.php            # Modify — add post type guard
└── tests/
    └── Unit/
        └── PostSaveHookTest.php    # Create — test post type guard
```

---

### Task 1: PostSaveHook — post type guard + test

**Files:**
- Modify: `includes/PostSaveHook.php`
- Create: `tests/Unit/PostSaveHookTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PostSaveHookTest.php`:

```php
<?php
namespace Navne\Tests\Unit;

use Navne\PostSaveHook;
use Brain\Monkey\Functions;

class PostSaveHookTest extends TestCase {
	private function make_post( string $status = 'publish', string $type = 'post' ): \WP_Post {
		$post              = new \WP_Post();
		$post->post_status = $status;
		$post->post_type   = $type;
		return $post;
	}

	public function test_skips_disallowed_post_type(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_post_types' ? [ 'post' ] : $default;
		} );
		// update_post_meta and as_enqueue_async_action must NOT be called.
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'as_enqueue_async_action' )->never();

		PostSaveHook::handle( 1, $this->make_post( 'publish', 'page' ), true );
	}

	public function test_dispatches_for_allowed_post_type(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_post_types' ? [ 'post', 'page' ] : $default;
		} );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-12 10:00:00' );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		// Should complete without error — no assertion needed beyond no exception thrown.
		PostSaveHook::handle( 1, $this->make_post( 'publish', 'page' ), true );
		$this->assertTrue( true ); // explicit assertion to satisfy PHPUnit.
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd wp/app/public/wp-content/plugins/navne && ./bin/phpunit tests/Unit/PostSaveHookTest.php -v
```

Expected: FAIL — `WP_Post does not have a post_type property` (the stub is missing `post_type`)

- [ ] **Step 3: Add `post_type` to the WP_Post stub in tests/bootstrap.php**

Open `tests/bootstrap.php`. Find:

```php
class WP_Post {
    public int    $ID            = 0;
    public string $post_title    = '';
    public string $post_content  = '';
    public string $post_status   = 'publish';
}
```

Replace with:

```php
class WP_Post {
    public int    $ID            = 0;
    public string $post_title    = '';
    public string $post_content  = '';
    public string $post_status   = 'publish';
    public string $post_type     = 'post';
}
```

- [ ] **Step 4: Run tests again — still fail (PostSaveHook missing the guard)**

```bash
./bin/phpunit tests/Unit/PostSaveHookTest.php -v
```

Expected: FAIL — `update_post_meta` was called but expected never (test 1 fails because guard doesn't exist yet)

- [ ] **Step 5: Add post type guard to PostSaveHook**

Open `includes/PostSaveHook.php`. After the `in_array( $current, [...] )` guard block and before the `update_post_meta` calls, add:

```php
$allowed_types = (array) get_option( 'navne_post_types', [ 'post' ] );
if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
    return;
}
```

Full updated file:

```php
<?php
// includes/PostSaveHook.php
namespace Navne;

class PostSaveHook {
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
		update_post_meta( $post_id, '_navne_job_status', 'queued' );
		update_post_meta( $post_id, '_navne_job_queued_at', current_time( 'mysql' ) );
		as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] );
	}
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/PostSaveHookTest.php -v
```

Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 7: Run full suite to confirm no regressions**

```bash
./bin/phpunit -v
```

Expected: All tests pass.

- [ ] **Step 8: Commit**

```bash
git add tests/bootstrap.php includes/PostSaveHook.php tests/Unit/PostSaveHookTest.php
git commit -m "feat: add post type guard to PostSaveHook, add WP_Post::post_type stub"
```

---

### Task 2: SettingsPage — menu, form render, save handling

**Files:**
- Create: `includes/Admin/SettingsPage.php`
- Modify: `includes/Plugin.php`

No unit test for this task — the form render and save flow require WP admin context. Covered by smoke test in Task 3.

- [ ] **Step 1: Create `includes/Admin/SettingsPage.php`**

```php
<?php
// includes/Admin/SettingsPage.php
namespace Navne\Admin;

class SettingsPage {
	public static function register_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
	}

	public static function add_page(): void {
		add_options_page(
			'Navne Settings',
			'Navne',
			'manage_options',
			'navne-settings',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}
		if ( isset( $_GET['error'] ) && 'no_post_types' === $_GET['error'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>At least one post type must be selected.</p></div>';
		}

		$provider      = (string) get_option( 'navne_provider', 'anthropic' );
		$api_key_set   = '' !== (string) get_option( 'navne_anthropic_api_key', '' );
		$model         = (string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' );
		$mode          = (string) get_option( 'navne_operating_mode', 'suggest' );
		$saved_types   = (array) get_option( 'navne_post_types', [ 'post' ] );
		$all_types     = get_post_types( [ 'public' => true ], 'objects' );
		?>
		<div class="wrap">
			<h1>Navne Settings</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'navne_settings' ); ?>
				<input type="hidden" name="action" value="navne_save_settings" />

				<h2>LLM Provider</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="navne_provider">Provider</label></th>
						<td>
							<select name="navne_provider" id="navne_provider">
								<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>>Anthropic</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="navne_anthropic_api_key">API Key</label></th>
						<td>
							<input type="password" name="navne_anthropic_api_key" id="navne_anthropic_api_key"
								class="regular-text" value="" autocomplete="off" />
							<p class="description">
								<?php echo $api_key_set ? 'Key is configured.' : 'No key set.'; ?>
								Leave blank to keep the existing key.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="navne_anthropic_model">Model</label></th>
						<td>
							<input type="text" name="navne_anthropic_model" id="navne_anthropic_model"
								class="regular-text" value="<?php echo esc_attr( $model ); ?>" />
						</td>
					</tr>
				</table>

				<h2>Operating Mode</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Mode</th>
						<td>
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
						</td>
					</tr>
				</table>

				<h2>Post Types</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Process entities for</th>
						<td>
							<fieldset>
								<?php foreach ( $all_types as $type ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="navne_post_types[]"
											value="<?php echo esc_attr( $type->name ); ?>"
											<?php checked( in_array( $type->name, $saved_types, true ) ); ?> />
										<?php echo esc_html( $type->labels->singular_name ); ?>
										<span style="color:#999">(<?php echo esc_html( $type->name ); ?>)</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'navne_settings' );

		// Provider + model.
		$provider = sanitize_key( $_POST['navne_provider'] ?? 'anthropic' );
		update_option( 'navne_provider', $provider );

		$model = sanitize_text_field( $_POST['navne_anthropic_model'] ?? '' );
		update_option( 'navne_anthropic_model', $model );

		// API key — only update if non-empty.
		$api_key = sanitize_text_field( $_POST['navne_anthropic_api_key'] ?? '' );
		if ( '' !== $api_key ) {
			update_option( 'navne_anthropic_api_key', $api_key );
		}

		// Operating mode — only 'suggest' is active; ignore disabled values.
		$mode = sanitize_key( $_POST['navne_operating_mode'] ?? 'suggest' );
		if ( ! in_array( $mode, [ 'safe', 'suggest', 'yolo' ], true ) ) {
			$mode = 'suggest';
		}
		update_option( 'navne_operating_mode', $mode );

		// Post types.
		$raw_types     = isset( $_POST['navne_post_types'] ) ? (array) $_POST['navne_post_types'] : [];
		$clean_types   = array_values( array_filter( array_map( 'sanitize_key', $raw_types ) ) );
		if ( empty( $clean_types ) ) {
			wp_redirect( admin_url( 'options-general.php?page=navne-settings&error=no_post_types' ) );
			exit;
		}
		update_option( 'navne_post_types', $clean_types );

		wp_redirect( admin_url( 'options-general.php?page=navne-settings&updated=1' ) );
		exit;
	}
}
```

- [ ] **Step 2: Wire SettingsPage into Plugin::init()**

Open `includes/Plugin.php`. Add the `use` import and the hook registration.

At the top, after the existing `use` statements, add:

```php
use Navne\Admin\SettingsPage;
```

Inside `init()`, add these two lines after `Taxonomy::register_hooks()`:

```php
SettingsPage::register_hooks();
add_action( 'admin_post_navne_save_settings', [ SettingsPage::class, 'handle_save' ] );
```

Full updated `Plugin.php`:

```php
<?php
// includes/Plugin.php
namespace Navne;

use Navne\Admin\SettingsPage;
use Navne\Api\SuggestionsController;
use Navne\Jobs\ProcessPostJob;
use Navne\Pipeline\EntityPipeline;
use Navne\Pipeline\LlmResolver;
use Navne\Pipeline\PassthroughExtractor;
use Navne\Provider\ProviderFactory;

class Plugin {
	public static function init(): void {
		Taxonomy::register_hooks();
		SettingsPage::register_hooks();
		add_action( 'admin_post_navne_save_settings', [ SettingsPage::class, 'handle_save' ] );
		( new SuggestionsController() )->register_routes_on_init();
		add_action( 'save_post',           [ PostSaveHook::class,   'handle' ], 10, 3 );
		add_action( 'navne_process_post',  [ ProcessPostJob::class, 'run' ] );
		add_filter( 'the_content',         [ new ContentFilter(),   'filter' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class,   'enqueue_sidebar' ] );

		// Invalidate link cache when terms change.
		add_action( 'set_object_terms', [ self::class, 'invalidate_link_cache' ], 10, 2 );
		add_action( 'delete_term',      [ self::class, 'invalidate_term_cache' ] );
		add_action( 'edit_term',        [ self::class, 'invalidate_term_cache' ] );
	}

	public static function make_pipeline(): EntityPipeline {
		return new EntityPipeline(
			new PassthroughExtractor(),
			new LlmResolver( ProviderFactory::make() )
		);
	}

	public static function enqueue_sidebar(): void {
		$asset_file = include NAVNE_PLUGIN_DIR . 'assets/js/build/index.asset.php';
		wp_enqueue_script(
			'navne-sidebar',
			NAVNE_PLUGIN_URL . 'assets/js/build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);
	}

	public static function invalidate_link_cache( int $post_id ): void {
		wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );
	}

	public static function invalidate_term_cache(): void {
		// On term delete/rename we can't cheaply know which posts are affected —
		// flush the entire navne cache group. Fine for PoC scale.
		wp_cache_flush_group( 'navne' );
	}
}
```

- [ ] **Step 3: Run the full test suite to confirm nothing broke**

```bash
cd wp/app/public/wp-content/plugins/navne && ./bin/phpunit -v
```

Expected: All existing tests pass (SettingsPage has no unit tests — covered by smoke test).

- [ ] **Step 4: Commit**

```bash
git add includes/Admin/SettingsPage.php includes/Plugin.php
git commit -m "feat: add SettingsPage — admin menu, form render, save handling"
```

---

### Task 3: Smoke test

No code changes — manual verification only.

- [ ] **Step 1: Navigate to the settings page**

Open `http://navne.local/wp-admin/options-general.php?page=navne-settings`

Expected: Settings page renders with three sections (LLM Provider, Operating Mode, Post Types). Provider shows "Anthropic". Model shows `claude-sonnet-4-6`. API key field is empty with "Key is configured." below it. Operating mode shows Suggest selected, Safe and YOLO disabled with "coming soon". Post Types shows "Post" checked.

- [ ] **Step 2: Verify API key field does not echo the stored value**

View page source. Search for the stored API key value. Expected: not present anywhere in the HTML.

- [ ] **Step 3: Save with blank API key — verify existing key is preserved**

Leave API key blank. Click Save Settings. Then run:

```bash
./bin/wp option get navne_anthropic_api_key | head -c 10
```

Expected: The first 10 characters of the stored key are unchanged.

- [ ] **Step 4: Verify error on empty post types**

Uncheck all post types. Click Save Settings.

Expected: Error notice "At least one post type must be selected." Settings page reloads. Previously saved post types are still checked.

- [ ] **Step 5: Verify post type guard in pipeline dispatch**

Add `page` to allowed post types and save. Then:

```bash
./bin/wp option get navne_post_types
```

Expected: `["post","page"]` (or similar serialized array).

Open any Page in the editor. Save it. Then:

```bash
./bin/wp post meta get <page_id> _navne_job_status
```

Expected: `queued` or `complete` (pipeline dispatched).

Remove `page` from post types and save. Open any Page, save it again. Check job status — expected: unchanged (pipeline did not dispatch).

- [ ] **Step 6: Run the full PHP test suite one final time**

```bash
./bin/phpunit -v
```

Expected: All tests pass.

- [ ] **Step 7: Commit release notes and tag**

Write `docs/releases/v1.1.0.md` following the same format as `docs/releases/v1.0.0.md`, then:

```bash
git add docs/releases/v1.1.0.md
git commit -m "docs: add v1.1.0 release notes"
git tag -a v1.1.0 -m "v1.1.0 — Plugin settings page"
git push hub master
git push hub v1.1.0
```

---

## Self-Review

**Spec coverage:**
- ✅ Settings → Navne admin page — Task 2
- ✅ API key (password, no echo, preserve-on-blank) — Task 2 `SettingsPage::render()` + `handle_save()`
- ✅ Provider dropdown (Anthropic only) — Task 2
- ✅ Model text field — Task 2
- ✅ Operating mode radios (Suggest active, Safe/YOLO disabled) — Task 2
- ✅ Post types checkboxes (at-least-one guard) — Task 2
- ✅ Nonce + capability check — Task 2 `handle_save()`
- ✅ Post-save redirect with `?updated=1` / `?error=no_post_types` — Task 2
- ✅ PostSaveHook post type guard — Task 1
- ✅ `WP_Post::post_type` stub — Task 1

**Placeholder scan:** None found.

**Type consistency:**
- `navne_post_types` stored as `array`, read with `(array) get_option(...)` in both `SettingsPage` and `PostSaveHook` — consistent.
- `navne_operating_mode` default `'suggest'` — consistent between `SettingsPage::render()` and `handle_save()`.
- `admin_post_navne_save_settings` hook name — consistent between `Plugin::init()` and the form's hidden `action` field.
