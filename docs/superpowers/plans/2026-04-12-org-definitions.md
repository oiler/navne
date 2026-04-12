# Org Definitions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an org definition list to the plugin — a textarea on the settings page where newsrooms paste `Term: Description` entries that get injected into every LLM prompt.

**Architecture:** `LlmResolver::build_prompt()` reads `navne_org_definitions` from `wp_options` and passes it to a new private `format_definitions()` method that parses the raw text and returns a formatted string. `SettingsPage` gets a new textarea section that saves to that option. No new files, no new DB tables.

**Tech Stack:** PHP 8.0+, WordPress options API, PHPUnit 9.6 + Brain\Monkey 2.6.

---

## File Map

```
wp/app/public/wp-content/plugins/navne/
├── includes/
│   ├── Admin/
│   │   └── SettingsPage.php        # Modify — add Org Definitions textarea + save
│   └── Pipeline/
│       └── LlmResolver.php         # Modify — add format_definitions(), update build_prompt()
└── tests/
    └── Unit/
        └── Pipeline/
            └── LlmResolverTest.php  # Modify — add Brain\Monkey\Functions import + 2 new tests
```

---

### Task 1: LlmResolver — format_definitions() + prompt injection

**Files:**
- Modify: `includes/Pipeline/LlmResolver.php`
- Modify: `tests/Unit/Pipeline/LlmResolverTest.php`

- [ ] **Step 1: Add two failing tests to LlmResolverTest.php**

Open `tests/Unit/Pipeline/LlmResolverTest.php`. Add `use Brain\Monkey\Functions;` after the existing `use` statements, then append the two new test methods before the closing `}`.

Full updated file:

```php
<?php
namespace Navne\Tests\Unit\Pipeline;

use Brain\Monkey\Functions;
use Navne\Exception\PipelineException;
use Navne\Pipeline\Entity;
use Navne\Pipeline\LlmResolver;
use Navne\Provider\ProviderInterface;
use Navne\Tests\Unit\TestCase;

class LlmResolverTest extends TestCase {
	private function make_post( string $title = 'Test', string $content = 'Body' ): \WP_Post {
		$post               = new \WP_Post();
		$post->post_title   = $title;
		$post->post_content = $content;
		return $post;
	}

	public function test_resolve_returns_entity_array(): void {
		$json     = json_encode( [
			[ 'name' => 'Jane Smith', 'type' => 'person', 'confidence' => 0.94 ],
			[ 'name' => 'NATO',       'type' => 'org',    'confidence' => 0.99 ],
		] );
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( $json );

		$resolver = new LlmResolver( $provider );
		$entities = $resolver->resolve( $this->make_post(), 'some extracted text' );

		$this->assertCount( 2, $entities );
		$this->assertInstanceOf( Entity::class, $entities[0] );
		$this->assertSame( 'Jane Smith', $entities[0]->name );
		$this->assertSame( 'person',     $entities[0]->type );
		$this->assertSame( 0.94,         $entities[0]->confidence );
	}

	public function test_resolve_strips_markdown_code_fences(): void {
		$json     = "```json\n[{\"name\":\"Ukraine\",\"type\":\"place\",\"confidence\":0.97}]\n```";
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( $json );

		$entities = ( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );

		$this->assertCount( 1, $entities );
		$this->assertSame( 'Ukraine', $entities[0]->name );
	}

	public function test_resolve_throws_pipeline_exception_on_invalid_json(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( 'not json at all' );

		$this->expectException( PipelineException::class );
		( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );
	}

	public function test_resolve_skips_malformed_entries(): void {
		$json     = json_encode( [
			[ 'name' => 'Good Entity', 'type' => 'person', 'confidence' => 0.9 ],
			[ 'name' => 'Missing type and confidence' ],
		] );
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( $json );

		$entities = ( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );

		$this->assertCount( 1, $entities );
	}

	public function test_prompt_contains_post_title_and_extracted_content(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
				 ->method( 'complete' )
				 ->with(
					 $this->logicalAnd(
						 $this->stringContains( 'NATO Summit Recap' ),
						 $this->stringContains( 'extracted content here' )
					 )
				 )
				 ->willReturn( '[]' );

		( new LlmResolver( $provider ) )->resolve(
			$this->make_post( 'NATO Summit Recap' ),
			'extracted content here'
		);
	}

	public function test_resolve_throws_on_json_object_instead_of_array(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( '{"name":"Jane","type":"person","confidence":0.9}' );

		$this->expectException( PipelineException::class );
		( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );
	}

	public function test_prompt_includes_definitions_when_configured(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_org_definitions'
				? "# Local figures\nDOE: The local school district\nGov. Smith: Governor Jane Smith\n\n"
				: $default;
		} );

		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
				 ->method( 'complete' )
				 ->with(
					 $this->logicalAnd(
						 $this->stringContains( 'DOE: The local school district' ),
						 $this->stringContains( 'Gov. Smith: Governor Jane Smith' ),
						 $this->logicalNot( $this->stringContains( '# Local figures' ) ),
						 $this->logicalNot( $this->stringContains( '(No organization-specific definitions configured.)' ) )
					 )
				 )
				 ->willReturn( '[]' );

		( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );
		$this->addToAssertionCount( 1 );
	}

	public function test_prompt_falls_back_when_no_definitions(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_org_definitions' ? '' : $default;
		} );

		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
				 ->method( 'complete' )
				 ->with( $this->stringContains( '(No organization-specific definitions configured.)' ) )
				 ->willReturn( '[]' );

		( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );
		$this->addToAssertionCount( 1 );
	}
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

```bash
cd wp/app/public/wp-content/plugins/navne && ./bin/phpunit tests/Unit/Pipeline/LlmResolverTest.php --filter "test_prompt_includes_definitions|test_prompt_falls_back" -v
```

Expected: both tests FAIL. `test_prompt_includes_definitions` fails because the prompt still contains `(No organization-specific definitions configured.)`. `test_prompt_falls_back` may pass accidentally — that's OK, the implementation step will make both correct.

- [ ] **Step 3: Implement format_definitions() and update build_prompt() in LlmResolver.php**

Replace `includes/Pipeline/LlmResolver.php` entirely:

```php
<?php
// includes/Pipeline/LlmResolver.php
namespace Navne\Pipeline;

use Navne\Exception\PipelineException;
use Navne\Provider\ProviderInterface;

class LlmResolver implements ResolverInterface {
	public function __construct( private ProviderInterface $provider ) {}

	/** @return Entity[] */
	public function resolve( \WP_Post $post, string $extracted ): array {
		$prompt   = $this->build_prompt( $post, $extracted );
		$response = $this->provider->complete( $prompt );
		return $this->parse_response( $response );
	}

	private function build_prompt( \WP_Post $post, string $extracted ): string {
		$definitions = $this->format_definitions( (string) get_option( 'navne_org_definitions', '' ) );
		return <<<PROMPT
You are an entity extraction assistant for a news organization.

[ORG DEFINITION LIST]
{$definitions}

Analyze the following article and return a JSON array of named entities.
For each entity include:
  - name (string): the canonical proper noun
  - type (string): person | org | place | other
  - confidence (float): 0.0–1.0

Only include proper nouns that are meaningful subjects or sources of the story.
Exclude passing historical references.

Article:
{$post->post_title}

{$extracted}

Respond with only a JSON array. No explanation.
PROMPT;
	}

	private function format_definitions( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '(No organization-specific definitions configured.)';
		}

		$lines  = explode( "\n", $raw );
		$output = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$term        = trim( substr( $line, 0, $colon ) );
			$description = trim( substr( $line, $colon + 1 ) );
			if ( '' === $term || '' === $description ) {
				continue;
			}
			$output[] = "{$term}: {$description}";
		}

		if ( empty( $output ) ) {
			return '(No organization-specific definitions configured.)';
		}

		return implode( "\n", $output );
	}

	/** @return Entity[] */
	private function parse_response( string $response ): array {
		// Strip markdown code fences if the entire response is wrapped in one.
		$stripped = preg_replace( '/^```[a-zA-Z]*\r?\n([\s\S]*?)\n```\s*$/s', '$1', trim( $response ) );
		$json     = $stripped !== null ? $stripped : trim( $response );

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new PipelineException( "LLM returned invalid JSON: {$json}" );
		}
		// Guard against LLM returning a JSON object instead of an array.
		if ( $data !== array_values( $data ) ) {
			throw new PipelineException( "LLM returned a JSON object instead of an array" );
		}

		$entities = [];
		foreach ( $data as $item ) {
			if ( ! isset( $item['name'], $item['type'], $item['confidence'] ) ) {
				continue;
			}
			$entities[] = new Entity(
				(string) $item['name'],
				(string) $item['type'],
				(float)  $item['confidence']
			);
		}
		return $entities;
	}
}
```

- [ ] **Step 4: Run the new tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Pipeline/LlmResolverTest.php --filter "test_prompt_includes_definitions|test_prompt_falls_back" -v
```

Expected: `OK (2 tests, 4 assertions)`

- [ ] **Step 5: Run the full suite to confirm no regressions**

```bash
./bin/phpunit -v
```

Expected: `OK (31 tests, 47 assertions)`

- [ ] **Step 6: Commit**

```bash
git add includes/Pipeline/LlmResolver.php tests/Unit/Pipeline/LlmResolverTest.php
git commit -m "feat: inject org definitions into LLM prompt"
```

---

### Task 2: SettingsPage — Org Definitions section

**Files:**
- Modify: `includes/Admin/SettingsPage.php`

No new unit tests — WP admin context required; covered by smoke test in Task 3.

- [ ] **Step 1: Add the definitions variable and textarea section to render()**

Open `includes/Admin/SettingsPage.php`. In `render()`, add `$definitions_raw` alongside the other option reads, then add the new section before `submit_button`.

Find this block (the option reads near the top of render()):

```php
		$provider      = (string) get_option( 'navne_provider', 'anthropic' );
		$api_key_set   = '' !== (string) get_option( 'navne_anthropic_api_key', '' );
		$model         = (string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' );
		$mode          = (string) get_option( 'navne_operating_mode', 'suggest' );
		$saved_types   = (array) get_option( 'navne_post_types', [ 'post' ] );
		$all_types     = get_post_types( [ 'public' => true ], 'objects' );
```

Replace with:

```php
		$provider         = (string) get_option( 'navne_provider', 'anthropic' );
		$api_key_set      = '' !== (string) get_option( 'navne_anthropic_api_key', '' );
		$model            = (string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' );
		$mode             = (string) get_option( 'navne_operating_mode', 'suggest' );
		$saved_types      = (array) get_option( 'navne_post_types', [ 'post' ] );
		$all_types        = get_post_types( [ 'public' => true ], 'objects' );
		$definitions_raw  = (string) get_option( 'navne_org_definitions', '' );
```

Then find this line (just before the closing `</form>` tag):

```php
				<?php submit_button( 'Save Settings' ); ?>
```

Replace with:

```php
				<h2>Org Definitions</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="navne_org_definitions">Definition list</label></th>
						<td>
							<p class="description" style="margin-bottom:8px;">One definition per line. Format: <code>Term: Description</code>. Lines starting with <code>#</code> are ignored.</p>
							<textarea name="navne_org_definitions" id="navne_org_definitions"
								class="large-text" rows="12"><?php echo esc_textarea( $definitions_raw ); ?></textarea>
							<p class="description" style="margin-top:6px;">
								Example:<br>
								<code>DOE: The local school district, not the federal Department of Energy</code><br>
								<code>Gov. Smith: Governor Jane Smith, incumbent since 2020</code>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
```

- [ ] **Step 2: Add org definitions save to handle_save()**

In `handle_save()`, find the final redirect line:

```php
		wp_safe_redirect( admin_url( 'options-general.php?page=navne-settings&updated=1' ) );
		exit;
```

Insert before it:

```php
		// Org definitions.
		$definitions = sanitize_textarea_field( wp_unslash( $_POST['navne_org_definitions'] ?? '' ) );
		update_option( 'navne_org_definitions', $definitions );

		wp_safe_redirect( admin_url( 'options-general.php?page=navne-settings&updated=1' ) );
		exit;
```

- [ ] **Step 3: Run the full test suite to confirm no regressions**

```bash
cd wp/app/public/wp-content/plugins/navne && ./bin/phpunit -v
```

Expected: `OK (31 tests, 47 assertions)`

- [ ] **Step 4: Commit**

```bash
git add includes/Admin/SettingsPage.php
git commit -m "feat: add Org Definitions textarea to settings page"
```

---

### Task 3: Smoke test + v1.2.0 release

No code changes — manual verification and release.

- [ ] **Step 1: Verify the settings page renders the new section**

Open `http://navne.local/wp-admin/options-general.php?page=navne-settings`

Expected: "Org Definitions" section appears below Post Types with an empty textarea, instructions above it, and examples below it.

- [ ] **Step 2: Save definitions and verify they round-trip**

Paste the following into the textarea and click Save Settings:

```
# Local test entries
DOE: The local school district
Gov. Smith: Governor Jane Smith
# this comment should be ignored

FMS: Fairfield Middle School
```

Expected: page reloads with "Settings saved." notice. The textarea on reload shows the same text including the `#` comment lines and blank line (the raw value is preserved as-is).

- [ ] **Step 3: Verify definitions appear in the LLM prompt**

Publish any post. Check the Action Scheduler log or add a temporary `error_log( $prompt )` in `build_prompt()` to confirm the prompt contains the definitions (minus comment and blank lines).

Expected prompt section:
```
[ORG DEFINITION LIST]
DOE: The local school district
Gov. Smith: Governor Jane Smith
FMS: Fairfield Middle School
```

- [ ] **Step 4: Verify fallback when definitions are cleared**

Clear the textarea and save. Publish a post again. Check the prompt.

Expected prompt section:
```
[ORG DEFINITION LIST]
(No organization-specific definitions configured.)
```

- [ ] **Step 5: Run the full test suite one final time**

```bash
cd wp/app/public/wp-content/plugins/navne && ./bin/phpunit -v
```

Expected: `OK (31 tests, 47 assertions)`

- [ ] **Step 6: Write v1.2.0 release notes**

Create `docs/releases/v1.2.0.md` following the same format as `docs/releases/v1.1.0.md`.

- [ ] **Step 7: Commit, tag, and push**

```bash
git add docs/releases/v1.2.0.md
git commit -m "docs: add v1.2.0 release notes"
git tag -a v1.2.0 -m "v1.2.0 — Org definition list"
git push hub master
git push hub v1.2.0
```

---

## Self-Review

**Spec coverage:**
- ✅ Textarea on settings page with `Term: Description` format — Task 2
- ✅ Option key `navne_org_definitions`, sanitized with `sanitize_textarea_field()` — Task 2
- ✅ Raw value rendered back on page load via `esc_textarea()` — Task 2
- ✅ Instructions and example shown — Task 2
- ✅ `format_definitions()` private method: skips blank lines, `#` comments, lines without colon — Task 1
- ✅ Fallback to `(No organization-specific definitions configured.)` when empty — Task 1
- ✅ `build_prompt()` reads option and injects formatted string — Task 1
- ✅ Two unit tests: definitions present, definitions empty — Task 1
- ✅ Smoke test covers round-trip and prompt injection — Task 3

**Placeholder scan:** None found.

**Type consistency:**
- `format_definitions(string $raw): string` — defined in Task 1 Step 3, called in `build_prompt()` in same step. Consistent.
- `navne_org_definitions` option key — consistent across `SettingsPage::render()`, `SettingsPage::handle_save()`, and `LlmResolver::build_prompt()`.
- `sanitize_textarea_field()` used in `handle_save()` — matches spec.
- `wp_unslash()` applied before `sanitize_textarea_field()` in `handle_save()` — WordPress best practice for `$_POST` data (slashes added by PHP magic quotes / WP compat layer).
