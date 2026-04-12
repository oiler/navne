# Entity Pipeline PoC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress plugin that detects named entities in published posts via an async LLM pipeline, surfaces them as Gutenberg sidebar suggestions, and links approved entities to taxonomy archive pages at render time.

**Architecture:** On post save, an Action Scheduler job runs the two-stage pipeline (PassthroughExtractor → LlmResolver via a provider-agnostic interface) and writes results to a custom suggestions table. A Gutenberg sidebar polls the REST API and lets editors approve or dismiss suggestions. Approved entities become `navne_entity` taxonomy terms; the `the_content` filter links first mentions at render time from an object-cached map.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, Action Scheduler 3.7+, Composer, Brain\Monkey (unit test mocking), @wordpress/scripts (sidebar build), React (sidebar UI), Anthropic Messages API (default LLM provider).

---

## File Map

```
wp/app/public/wp-content/plugins/navne/
├── navne.php
├── composer.json
├── package.json
├── phpunit.xml
├── bin/
│   ├── wp                                      # WP-CLI wrapper
│   └── phpunit                                 # PHPUnit wrapper
├── includes/
│   ├── Plugin.php                              # Bootstrap + hook registration + pipeline factory
│   ├── Taxonomy.php                            # Register navne_entity taxonomy
│   ├── PostSaveHook.php                        # Dispatch async job on save
│   ├── ContentFilter.php                       # the_content render-time linking
│   ├── Exception/
│   │   └── PipelineException.php              # Typed exception for pipeline failures
│   ├── Pipeline/
│   │   ├── Entity.php                          # Value object: name, type, confidence
│   │   ├── ExtractorInterface.php              # extract(WP_Post): string
│   │   ├── ResolverInterface.php               # resolve(WP_Post, string): Entity[]
│   │   ├── EntityPipeline.php                  # Orchestrates extractor → resolver
│   │   ├── PassthroughExtractor.php            # PoC extractor: title + stripped content
│   │   └── LlmResolver.php                     # Builds prompt, calls provider, parses JSON
│   ├── Provider/
│   │   ├── ProviderInterface.php               # complete(string): string
│   │   ├── AnthropicProvider.php               # Claude Messages API implementation
│   │   └── ProviderFactory.php                 # Reads navne_provider option, returns instance
│   ├── Jobs/
│   │   └── ProcessPostJob.php                  # Action Scheduler callback
│   ├── Api/
│   │   └── SuggestionsController.php           # REST endpoints: get/approve/dismiss/retry
│   └── Storage/
│       └── SuggestionsTable.php                # DB schema + CRUD (wpdb injected)
├── assets/js/sidebar/
│   ├── index.js                                # Register plugin sidebar
│   ├── hooks/
│   │   └── useSuggestions.js                  # Polling + approve/dismiss/retry actions
│   └── components/
│       ├── SidebarPanel.js                    # Main panel: state routing
│       ├── SuggestionCard.js                  # Single pending suggestion card
│       └── ApprovedList.js                    # Linked entities list
└── tests/
    ├── bootstrap.php                           # Stubs for WP classes + Brain\Monkey init
    ├── Unit/
    │   ├── TestCase.php                        # Base test case with Brain\Monkey setup
    │   ├── Pipeline/
    │   │   ├── EntityTest.php
    │   │   ├── EntityPipelineTest.php
    │   │   ├── PassthroughExtractorTest.php
    │   │   └── LlmResolverTest.php
    │   ├── Provider/
    │   │   ├── AnthropicProviderTest.php
    │   │   └── ProviderFactoryTest.php
    │   ├── Storage/
    │   │   └── SuggestionsTableTest.php
    │   ├── Jobs/
    │   │   └── ProcessPostJobTest.php
    │   ├── Api/
    │   │   └── SuggestionsControllerTest.php
    │   └── ContentFilterTest.php
```

---

### Task 1: Scaffolding — plugin entry point, Composer, PHPUnit, bin helpers

**Files:**
- Create: `wp/app/public/wp-content/plugins/navne/navne.php`
- Create: `wp/app/public/wp-content/plugins/navne/composer.json`
- Create: `wp/app/public/wp-content/plugins/navne/phpunit.xml`
- Create: `wp/app/public/wp-content/plugins/navne/tests/bootstrap.php`
- Create: `wp/app/public/wp-content/plugins/navne/tests/Unit/TestCase.php`
- Create: `wp/app/public/wp-content/plugins/navne/bin/wp`
- Create: `wp/app/public/wp-content/plugins/navne/bin/phpunit`

- [ ] **Step 1: Create plugin entry point**

```php
// navne.php
<?php
/**
 * Plugin Name: Navne Entity Linker
 * Description: Automatically links named entities in posts to taxonomy archive pages.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: navne
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NAVNE_VERSION', '0.1.0' );
define( 'NAVNE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NAVNE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NAVNE_PLUGIN_DIR . 'vendor/autoload.php';
require_once NAVNE_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, [ 'Navne\\Storage\\SuggestionsTable', 'create' ] );
register_uninstall_hook( __FILE__, [ 'Navne\\Storage\\SuggestionsTable', 'drop' ] );

add_action( 'plugins_loaded', [ 'Navne\\Plugin', 'init' ] );
```

- [ ] **Step 2: Create composer.json**

```json
{
	"name": "navne/entity-linker",
	"description": "Entity linking plugin for WordPress news organizations",
	"require": {
		"php": ">=8.0",
		"woocommerce/action-scheduler": "^3.7"
	},
	"require-dev": {
		"phpunit/phpunit": "^9.6",
		"brain/monkey": "^2.6"
	},
	"autoload": {
		"psr-4": {
			"Navne\\": "includes/"
		}
	},
	"autoload-dev": {
		"psr-4": {
			"Navne\\Tests\\": "tests/"
		}
	}
}
```

- [ ] **Step 3: Install Composer dependencies**

Run from the plugin directory:
```bash
cd wp/app/public/wp-content/plugins/navne
composer install
```

Expected: `vendor/` directory created, including `vendor/woocommerce/action-scheduler/`.

- [ ] **Step 4: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
		 bootstrap="tests/bootstrap.php"
		 colors="true">
	<testsuites>
		<testsuite name="Unit">
			<directory>tests/Unit</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 5: Create tests/bootstrap.php**

```php
<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal WP class stubs — enough for unit tests without a WP install.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int    $ID            = 0;
		public string $post_title    = '';
		public string $post_content  = '';
		public string $post_status   = 'publish';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private mixed  $data = null
		) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed     { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params      = [];
		private array $json_params = [];
		public function get_param( string $key ): mixed          { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, mixed $v ): void { $this->params[ $key ] = $v; }
		public function get_json_params(): array                  { return $this->json_params; }
		public function set_json_params( array $p ): void         { $this->json_params = $p; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct(
			public readonly mixed $data   = null,
			public readonly int   $status = 200
		) {}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public function prepare( string $q, mixed ...$args ): string { return vsprintf( str_replace( [ '%d', '%f', '%s' ], '%s', $q ), $args ); }
		public function get_charset_collate(): string                 { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
		public function insert( string $t, array $d, array $f = [] ): int|false { return 1; }
		public function update( string $t, array $d, array $w, array $f = [], array $wf = [] ): int|false { return 1; }
		public function delete( string $t, array $w, array $f = [] ): int|false { return 1; }
		public function get_results( string $q, string $o = 'OBJECT' ): array   { return []; }
		public function get_row( string $q, string $o = 'OBJECT', int $y = 0 ): mixed { return null; }
		public function query( string $q ): int|bool                             { return true; }
	}
}
```

- [ ] **Step 6: Create tests/Unit/TestCase.php**

```php
<?php
namespace Navne\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
```

- [ ] **Step 7: Create bin/wp (WP-CLI wrapper)**

```bash
#!/usr/bin/env bash
# bin/wp — WP-CLI wrapper for this Local site
PHP="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.27+1/bin/darwin-arm64/bin/php"
WP_CLI="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
MYSQL_BIN="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.0.35+4/bin/darwin-arm64/bin"
WP_PATH="/Users/jrf1039/files/projects/navne/wp/app/public"

export PATH="$MYSQL_BIN:$PATH"
export WP_CLI_PHP="$PHP"
exec "$PHP" "$WP_CLI" --path="$WP_PATH" "$@"
```

Make executable: `chmod +x bin/wp`

- [ ] **Step 8: Create bin/phpunit (PHPUnit wrapper)**

```bash
#!/usr/bin/env bash
# bin/phpunit — PHPUnit runner for navne plugin
PHP="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.27+1/bin/darwin-arm64/bin/php"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
exec "$PHP" "$PLUGIN_DIR/vendor/bin/phpunit" --configuration "$PLUGIN_DIR/phpunit.xml" "$@"
```

Make executable: `chmod +x bin/phpunit`

- [ ] **Step 9: Verify test suite runs (zero tests = success)**

```bash
./bin/phpunit
```

Expected output:
```
No tests executed!
```

- [ ] **Step 10: Commit**

```bash
git add wp/app/public/wp-content/plugins/navne/
git commit -m "feat: scaffold navne plugin — composer, phpunit, bin helpers"
```

---

### Task 2: Entity value object + Pipeline interfaces + EntityPipeline

**Files:**
- Create: `includes/Pipeline/Entity.php`
- Create: `includes/Pipeline/ExtractorInterface.php`
- Create: `includes/Pipeline/ResolverInterface.php`
- Create: `includes/Pipeline/EntityPipeline.php`
- Create: `tests/Unit/Pipeline/EntityPipelineTest.php`
- Test: `tests/Unit/Pipeline/EntityPipelineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Pipeline/EntityPipelineTest.php
namespace Navne\Tests\Unit\Pipeline;

use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Pipeline\ExtractorInterface;
use Navne\Pipeline\ResolverInterface;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class EntityPipelineTest extends TestCase {
	public function test_run_returns_entities_from_resolver(): void {
		$post        = new \WP_Post();
		$post->ID    = 42;
		$expected    = [ new Entity( 'Jane Smith', 'person', 0.95 ) ];
		$extractor   = $this->createMock( ExtractorInterface::class );
		$resolver    = $this->createMock( ResolverInterface::class );

		Functions\when( 'get_post' )->justReturn( $post );
		$extractor->expects( $this->once() )->method( 'extract' )->with( $post )->willReturn( 'extracted text' );
		$resolver->expects( $this->once() )->method( 'resolve' )->with( $post, 'extracted text' )->willReturn( $expected );

		$pipeline = new EntityPipeline( $extractor, $resolver );
		$result   = $pipeline->run( 42 );

		$this->assertSame( $expected, $result );
	}

	public function test_run_returns_empty_array_when_post_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$pipeline = new EntityPipeline(
			$this->createMock( ExtractorInterface::class ),
			$this->createMock( ResolverInterface::class )
		);

		$this->assertSame( [], $pipeline->run( 999 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/Pipeline/EntityPipelineTest.php -v
```

Expected: FAIL — `Class "Navne\Pipeline\Entity" not found`

- [ ] **Step 3: Create Entity.php**

```php
<?php
// includes/Pipeline/Entity.php
namespace Navne\Pipeline;

class Entity {
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly float  $confidence
	) {}
}
```

- [ ] **Step 4: Create ExtractorInterface.php**

```php
<?php
// includes/Pipeline/ExtractorInterface.php
namespace Navne\Pipeline;

interface ExtractorInterface {
	public function extract( \WP_Post $post ): string;
}
```

- [ ] **Step 5: Create ResolverInterface.php**

```php
<?php
// includes/Pipeline/ResolverInterface.php
namespace Navne\Pipeline;

interface ResolverInterface {
	/** @return Entity[] */
	public function resolve( \WP_Post $post, string $extracted ): array;
}
```

- [ ] **Step 6: Create EntityPipeline.php**

```php
<?php
// includes/Pipeline/EntityPipeline.php
namespace Navne\Pipeline;

class EntityPipeline {
	public function __construct(
		private ExtractorInterface $extractor,
		private ResolverInterface  $resolver
	) {}

	/** @return Entity[] */
	public function run( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$extracted = $this->extractor->extract( $post );
		return $this->resolver->resolve( $post, $extracted );
	}
}
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Pipeline/EntityPipelineTest.php -v
```

Expected:
```
OK (2 tests, 3 assertions)
```

- [ ] **Step 8: Commit**

```bash
git add includes/Pipeline/ tests/Unit/Pipeline/EntityPipelineTest.php
git commit -m "feat: add Entity value object, pipeline interfaces, and EntityPipeline"
```

---

### Task 3: PassthroughExtractor

**Files:**
- Create: `includes/Pipeline/PassthroughExtractor.php`
- Create: `tests/Unit/Pipeline/PassthroughExtractorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Pipeline/PassthroughExtractorTest.php
namespace Navne\Tests\Unit\Pipeline;

use Navne\Pipeline\PassthroughExtractor;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class PassthroughExtractorTest extends TestCase {
	public function test_extract_returns_title_and_stripped_content(): void {
		$post                = new \WP_Post();
		$post->post_title    = 'NATO Summit Recap';
		$post->post_content  = '<p>The alliance met <strong>yesterday</strong>.</p>';

		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => strip_tags( $s ) );

		$extractor = new PassthroughExtractor();
		$result    = $extractor->extract( $post );

		$this->assertStringContainsString( 'NATO Summit Recap', $result );
		$this->assertStringContainsString( 'The alliance met yesterday.', $result );
		$this->assertStringNotContainsString( '<p>', $result );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/Pipeline/PassthroughExtractorTest.php -v
```

Expected: FAIL — `Class "Navne\Pipeline\PassthroughExtractor" not found`

- [ ] **Step 3: Create PassthroughExtractor.php**

```php
<?php
// includes/Pipeline/PassthroughExtractor.php
namespace Navne\Pipeline;

class PassthroughExtractor implements ExtractorInterface {
	public function extract( \WP_Post $post ): string {
		return $post->post_title . "\n\n" . wp_strip_all_tags( $post->post_content );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./bin/phpunit tests/Unit/Pipeline/PassthroughExtractorTest.php -v
```

Expected: `OK (1 test, 3 assertions)`

- [ ] **Step 5: Commit**

```bash
git add includes/Pipeline/PassthroughExtractor.php tests/Unit/Pipeline/PassthroughExtractorTest.php
git commit -m "feat: add PassthroughExtractor (NER stub)"
```

---

### Task 4: Provider layer — exception, interface, Anthropic, factory

**Files:**
- Create: `includes/Exception/PipelineException.php`
- Create: `includes/Provider/ProviderInterface.php`
- Create: `includes/Provider/AnthropicProvider.php`
- Create: `includes/Provider/ProviderFactory.php`
- Create: `tests/Unit/Provider/AnthropicProviderTest.php`
- Create: `tests/Unit/Provider/ProviderFactoryTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Provider/AnthropicProviderTest.php
namespace Navne\Tests\Unit\Provider;

use Navne\Exception\PipelineException;
use Navne\Provider\AnthropicProvider;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class AnthropicProviderTest extends TestCase {
	public function test_complete_returns_text_from_api_response(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => '["result"]' ] ] ] )
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$provider = new AnthropicProvider( 'test-key' );
		$result   = $provider->complete( 'some prompt' );

		$this->assertSame( '["result"]', $result );
	}

	public function test_complete_throws_on_wp_error(): void {
		$error = new \WP_Error( 'http_error', 'Connection refused' );
		Functions\when( 'wp_remote_post' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->expectException( PipelineException::class );
		( new AnthropicProvider( 'test-key' ) )->complete( 'prompt' );
	}

	public function test_complete_throws_on_non_200_status(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->expectException( PipelineException::class );
		( new AnthropicProvider( 'test-key' ) )->complete( 'prompt' );
	}
}
```

```php
<?php
// tests/Unit/Provider/ProviderFactoryTest.php
namespace Navne\Tests\Unit\Provider;

use Navne\Provider\AnthropicProvider;
use Navne\Provider\ProviderFactory;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class ProviderFactoryTest extends TestCase {
	public function test_make_returns_anthropic_provider_by_default(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = false ) {
			return match( $key ) {
				'navne_provider'            => 'anthropic',
				'navne_anthropic_api_key'   => 'sk-test',
				'navne_anthropic_model'     => 'claude-sonnet-4-6',
				default                     => $default,
			};
		} );

		$provider = ProviderFactory::make();
		$this->assertInstanceOf( AnthropicProvider::class, $provider );
	}

	public function test_make_throws_on_unknown_provider(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = false ) {
			return $key === 'navne_provider' ? 'unknown_llm' : $default;
		} );

		$this->expectException( \RuntimeException::class );
		ProviderFactory::make();
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./bin/phpunit tests/Unit/Provider/ -v
```

Expected: FAIL — `Class "Navne\Exception\PipelineException" not found`

- [ ] **Step 3: Create PipelineException.php**

```php
<?php
// includes/Exception/PipelineException.php
namespace Navne\Exception;

class PipelineException extends \RuntimeException {}
```

- [ ] **Step 4: Create ProviderInterface.php**

```php
<?php
// includes/Provider/ProviderInterface.php
namespace Navne\Provider;

interface ProviderInterface {
	public function complete( string $prompt ): string;
}
```

- [ ] **Step 5: Create AnthropicProvider.php**

```php
<?php
// includes/Provider/AnthropicProvider.php
namespace Navne\Provider;

use Navne\Exception\PipelineException;

class AnthropicProvider implements ProviderInterface {
	public function __construct(
		private string $api_key,
		private string $model = 'claude-sonnet-4-6'
	) {}

	public function complete( string $prompt ): string {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'headers' => [
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $this->model,
				'max_tokens' => 1024,
				'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
			] ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			throw new PipelineException( 'Anthropic API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new PipelineException( "Anthropic API returned HTTP {$code}" );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['content'][0]['text'] ) ) {
			throw new PipelineException( 'Anthropic API returned unexpected response shape' );
		}

		return $body['content'][0]['text'];
	}
}
```

- [ ] **Step 6: Create ProviderFactory.php**

```php
<?php
// includes/Provider/ProviderFactory.php
namespace Navne\Provider;

class ProviderFactory {
	public static function make(): ProviderInterface {
		$name = (string) get_option( 'navne_provider', 'anthropic' );

		return match( $name ) {
			'anthropic' => new AnthropicProvider(
				(string) get_option( 'navne_anthropic_api_key', '' ),
				(string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' )
			),
			default => throw new \RuntimeException( "Unknown LLM provider: {$name}" ),
		};
	}
}
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Provider/ -v
```

Expected: `OK (5 tests, 5 assertions)`

- [ ] **Step 8: Commit**

```bash
git add includes/Exception/ includes/Provider/ tests/Unit/Provider/
git commit -m "feat: add provider abstraction layer (PipelineException, ProviderInterface, AnthropicProvider, ProviderFactory)"
```

---

### Task 5: LlmResolver

**Files:**
- Create: `includes/Pipeline/LlmResolver.php`
- Create: `tests/Unit/Pipeline/LlmResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Pipeline/LlmResolverTest.php
namespace Navne\Tests\Unit\Pipeline;

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
				 ->with( $this->stringContains( 'NATO Summit Recap' ) )
				 ->willReturn( '[]' );

		( new LlmResolver( $provider ) )->resolve(
			$this->make_post( 'NATO Summit Recap' ),
			'extracted content here'
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/Pipeline/LlmResolverTest.php -v
```

Expected: FAIL — `Class "Navne\Pipeline\LlmResolver" not found`

- [ ] **Step 3: Create LlmResolver.php**

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

	private function build_prompt( \WP_Post $post, string $extracted, string $definition_list = '' ): string {
		$def_section = $definition_list ?: '(No organization-specific definitions configured.)';
		return <<<PROMPT
You are an entity extraction assistant for a news organization.

[ORG DEFINITION LIST]
{$def_section}

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

	/** @return Entity[] */
	private function parse_response( string $response ): array {
		// Strip markdown code fences (Claude sometimes wraps JSON even when asked not to).
		$json = preg_replace( '/^```(?:json)?\s*/m', '', $response );
		$json = preg_replace( '/^```\s*$/m', '', $json );
		$json = trim( $json );

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new PipelineException( "LLM returned invalid JSON: {$json}" );
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

- [ ] **Step 4: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Pipeline/LlmResolverTest.php -v
```

Expected: `OK (5 tests, 8 assertions)`

- [ ] **Step 5: Commit**

```bash
git add includes/Pipeline/LlmResolver.php tests/Unit/Pipeline/LlmResolverTest.php
git commit -m "feat: add LlmResolver — prompt builder, provider call, JSON parsing"
```

---

### Task 6: SuggestionsTable

**Files:**
- Create: `includes/Storage/SuggestionsTable.php`
- Create: `tests/Unit/Storage/SuggestionsTableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Storage/SuggestionsTableTest.php
namespace Navne\Tests\Unit\Storage;

use Navne\Pipeline\Entity;
use Navne\Storage\SuggestionsTable;
use Navne\Tests\Unit\TestCase;

class SuggestionsTableTest extends TestCase {
	private function make_db( array $expectations = [] ): \wpdb {
		$db         = $this->createMock( \wpdb::class );
		$db->prefix = 'wp_';
		return $db;
	}

	public function test_table_name_uses_prefix(): void {
		$db = $this->make_db();
		$this->assertSame( 'wp_navne_suggestions', ( new SuggestionsTable( $db ) )->table_name() );
	}

	public function test_insert_entities_calls_db_insert_once_per_entity(): void {
		$db = $this->make_db();
		$db->expects( $this->exactly( 2 ) )->method( 'insert' );

		$table = new SuggestionsTable( $db );
		$table->insert_entities( 1, [
			new Entity( 'Jane Smith', 'person', 0.94 ),
			new Entity( 'NATO',       'org',    0.99 ),
		] );
	}

	public function test_update_status_calls_db_update(): void {
		$db = $this->make_db();
		$db->expects( $this->once() )
		   ->method( 'update' )
		   ->with(
				$this->anything(),
				[ 'status' => 'approved' ],
				[ 'id' => 7 ]
		   );

		( new SuggestionsTable( $db ) )->update_status( 7, 'approved' );
	}

	public function test_delete_pending_for_post_calls_db_delete(): void {
		$db = $this->make_db();
		$db->expects( $this->once() )
		   ->method( 'delete' )
		   ->with(
				$this->anything(),
				[ 'post_id' => 5, 'status' => 'pending' ]
		   );

		( new SuggestionsTable( $db ) )->delete_pending_for_post( 5 );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/Storage/SuggestionsTableTest.php -v
```

Expected: FAIL — `Class "Navne\Storage\SuggestionsTable" not found`

- [ ] **Step 3: Create SuggestionsTable.php**

```php
<?php
// includes/Storage/SuggestionsTable.php
namespace Navne\Storage;

use Navne\Pipeline\Entity;

class SuggestionsTable {
	private static ?self $instance = null;

	public function __construct( private \wpdb $db ) {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}
		return self::$instance;
	}

	public function table_name(): string {
		return $this->db->prefix . 'navne_suggestions';
	}

	public static function create(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = $wpdb->prefix . 'navne_suggestions';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			entity_name varchar(255) NOT NULL,
			entity_type varchar(50) NOT NULL,
			confidence float NOT NULL DEFAULT 0,
			status enum('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY post_status (post_id, status)
		) {$charset_collate};" );
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navne_suggestions" );
	}

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

	public function find_by_post( int $post_id ): array {
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} WHERE post_id = %d ORDER BY confidence DESC",
				$post_id
			),
			ARRAY_A
		) ?? [];
	}

	public function find_by_id( int $id ): ?array {
		return $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} WHERE id = %d",
				$id
			),
			ARRAY_A
		) ?: null;
	}

	public function update_status( int $id, string $status ): void {
		$this->db->update(
			$this->table_name(),
			[ 'status' => $status ],
			[ 'id'     => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public function delete_pending_for_post( int $post_id ): void {
		$this->db->delete(
			$this->table_name(),
			[ 'post_id' => $post_id, 'status' => 'pending' ],
			[ '%d', '%s' ]
		);
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Storage/SuggestionsTableTest.php -v
```

Expected: `OK (4 tests, 5 assertions)`

- [ ] **Step 5: Commit**

```bash
git add includes/Storage/SuggestionsTable.php tests/Unit/Storage/SuggestionsTableTest.php
git commit -m "feat: add SuggestionsTable — schema, CRUD, singleton accessor"
```

---

### Task 7: PostSaveHook + ProcessPostJob

**Files:**
- Create: `includes/PostSaveHook.php`
- Create: `includes/Jobs/ProcessPostJob.php`
- Create: `tests/Unit/Jobs/ProcessPostJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Jobs/ProcessPostJobTest.php
namespace Navne\Tests\Unit\Jobs;

use Navne\Exception\PipelineException;
use Navne\Jobs\ProcessPostJob;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Storage\SuggestionsTable;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class ProcessPostJobTest extends TestCase {
	public function test_run_sets_status_to_complete_on_success(): void {
		$entities = [ new Entity( 'Jane Smith', 'person', 0.94 ) ];
		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->method( 'run' )->willReturn( $entities );

		$table = $this->createMock( SuggestionsTable::class );
		$table->expects( $this->once() )->method( 'insert_entities' )->with( 1, $entities );

		Functions\expect( 'update_post_meta' )->twice();

		ProcessPostJob::run( 1, $pipeline, $table );
	}

	public function test_run_sets_status_to_failed_on_exception(): void {
		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->method( 'run' )->willThrowException( new PipelineException( 'API down' ) );

		$table = $this->createMock( SuggestionsTable::class );
		$table->expects( $this->never() )->method( 'insert_entities' );

		Functions\expect( 'update_post_meta' )
			->twice()
			->andReturnValues( [ true, true ] );
		Functions\when( 'error_log' )->justReturn( true );

		ProcessPostJob::run( 1, $pipeline, $table );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/Jobs/ProcessPostJobTest.php -v
```

Expected: FAIL — `Class "Navne\Jobs\ProcessPostJob" not found`

- [ ] **Step 3: Create PostSaveHook.php**

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
		update_post_meta( $post_id, '_navne_job_status', 'queued' );
		update_post_meta( $post_id, '_navne_job_queued_at', current_time( 'mysql' ) );
		as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] );
	}
}
```

- [ ] **Step 4: Create ProcessPostJob.php**

`ProcessPostJob::run` accepts optional injectable dependencies so it can be unit-tested without a full WordPress environment.

```php
<?php
// includes/Jobs/ProcessPostJob.php
namespace Navne\Jobs;

use Navne\Exception\PipelineException;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class ProcessPostJob {
	public static function run(
		int             $post_id,
		?EntityPipeline $pipeline = null,
		?SuggestionsTable $table  = null
	): void {
		$pipeline ??= Plugin::make_pipeline();
		$table    ??= SuggestionsTable::instance();

		update_post_meta( $post_id, '_navne_job_status', 'processing' );
		try {
			$entities = $pipeline->run( $post_id );
			$table->insert_entities( $post_id, $entities );
			update_post_meta( $post_id, '_navne_job_status', 'complete' );
		} catch ( PipelineException $e ) {
			update_post_meta( $post_id, '_navne_job_status', 'failed' );
			error_log( 'Navne pipeline failed for post ' . $post_id . ': ' . $e->getMessage() );
		}
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Jobs/ProcessPostJobTest.php -v
```

Expected: `OK (2 tests, 4 assertions)`

- [ ] **Step 6: Commit**

```bash
git add includes/PostSaveHook.php includes/Jobs/ProcessPostJob.php tests/Unit/Jobs/ProcessPostJobTest.php
git commit -m "feat: add PostSaveHook and ProcessPostJob (Action Scheduler async pipeline)"
```

---

### Task 8: SuggestionsController (REST API)

**Files:**
- Create: `includes/Api/SuggestionsController.php`
- Create: `tests/Unit/Api/SuggestionsControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Api/SuggestionsControllerTest.php
namespace Navne\Tests\Unit\Api;

use Navne\Api\SuggestionsController;
use Navne\Storage\SuggestionsTable;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class SuggestionsControllerTest extends TestCase {
	public function test_get_suggestions_returns_job_status_and_rows(): void {
		$rows  = [ [ 'id' => 1, 'entity_name' => 'NATO', 'status' => 'pending' ] ];
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_post' )->with( 10 )->willReturn( $rows );

		Functions\when( 'get_post_meta' )->justReturn( 'complete' );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );

		$controller = new SuggestionsController( $table );
		$response   = $controller->get_suggestions( $request );

		$this->assertSame( 'complete',  $response->data['job_status'] );
		$this->assertSame( $rows,       $response->data['suggestions'] );
	}

	public function test_approve_updates_status_and_creates_term(): void {
		$row   = [ 'id' => 3, 'post_id' => 10, 'entity_name' => 'Jane Smith', 'entity_type' => 'person' ];
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 3 )->willReturn( $row );
		$table->expects( $this->once() )->method( 'update_status' )->with( 3, 'approved' );

		Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 55, 'term_taxonomy_id' => 55 ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_set_post_terms' )->justReturn( [ 55 ] );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [ 'id' => 3 ] );

		$controller = new SuggestionsController( $table );
		$response   = $controller->approve( $request );

		$this->assertSame( 'approved', $response->data['status'] );
	}

	public function test_retry_resets_status_and_re_queues_job(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->expects( $this->once() )->method( 'delete_pending_for_post' )->with( 10 );

		Functions\expect( 'update_post_meta' )->twice();
		Functions\when( 'current_time' )->justReturn( '2026-04-12 10:00:00' );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );

		$response = ( new SuggestionsController( $table ) )->retry( $request );
		$this->assertSame( 'queued', $response->data['status'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/Api/SuggestionsControllerTest.php -v
```

Expected: FAIL — `Class "Navne\Api\SuggestionsController" not found`

- [ ] **Step 3: Create SuggestionsController.php**

```php
<?php
// includes/Api/SuggestionsController.php
namespace Navne\Api;

use Navne\Storage\SuggestionsTable;

class SuggestionsController {
	public function __construct( private ?SuggestionsTable $table = null ) {
		$this->table ??= SuggestionsTable::instance();
	}

	public function register_routes_on_init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$base = '/suggestions/(?P<post_id>\d+)';
		foreach ( [
			[ 'GET',  '',         'get_suggestions' ],
			[ 'POST', '/approve', 'approve' ],
			[ 'POST', '/dismiss', 'dismiss' ],
			[ 'POST', '/retry',   'retry' ],
		] as [ $method, $suffix, $callback ] ) {
			register_rest_route( 'navne/v1', $base . $suffix, [
				'methods'             => $method,
				'callback'            => [ $this, $callback ],
				'permission_callback' => [ $this, 'check_permission' ],
			] );
		}
	}

	public function check_permission( \WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
	}

	public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return new \WP_REST_Response( [
			'job_status'  => get_post_meta( $post_id, '_navne_job_status', true ) ?: 'idle',
			'suggestions' => $this->table->find_by_post( $post_id ),
		] );
	}

	public function approve( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$params  = $request->get_json_params();
		$id      = (int) ( $params['id'] ?? 0 );
		$row     = $this->table->find_by_id( $id );

		if ( ! $row || (int) $row['post_id'] !== $post_id ) {
			return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		}

		$this->table->update_status( $id, 'approved' );
		$term    = wp_insert_term( $row['entity_name'], 'navne_entity' );
		$term_id = is_wp_error( $term ) ? $term->get_error_data()['term_id'] : $term['term_id'];
		wp_set_post_terms( $post_id, [ $term_id ], 'navne_entity', true );
		wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );

		return new \WP_REST_Response( [ 'status' => 'approved' ] );
	}

	public function dismiss( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$params  = $request->get_json_params();
		$id      = (int) ( $params['id'] ?? 0 );
		$row     = $this->table->find_by_id( $id );

		if ( ! $row || (int) $row['post_id'] !== $post_id ) {
			return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		}

		$this->table->update_status( $id, 'dismissed' );
		wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );

		return new \WP_REST_Response( [ 'status' => 'dismissed' ] );
	}

	public function retry( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$this->table->delete_pending_for_post( $post_id );
		update_post_meta( $post_id, '_navne_job_status', 'queued' );
		update_post_meta( $post_id, '_navne_job_queued_at', current_time( 'mysql' ) );
		as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] );
		return new \WP_REST_Response( [ 'status' => 'queued' ] );
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/Api/SuggestionsControllerTest.php -v
```

Expected: `OK (3 tests, 6 assertions)`

- [ ] **Step 5: Commit**

```bash
git add includes/Api/SuggestionsController.php tests/Unit/Api/SuggestionsControllerTest.php
git commit -m "feat: add SuggestionsController — GET/approve/dismiss/retry REST endpoints"
```

---

### Task 9: Taxonomy + ContentFilter

**Files:**
- Create: `includes/Taxonomy.php`
- Create: `includes/ContentFilter.php`
- Create: `tests/Unit/ContentFilterTest.php`

- [ ] **Step 1: Create Taxonomy.php**

No unit test needed — `register_taxonomy` is a WP integration point, not business logic.

```php
<?php
// includes/Taxonomy.php
namespace Navne;

class Taxonomy {
	public static function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_taxonomy( 'navne_entity', [ 'post' ], [
			'labels'            => [
				'name'          => 'Entities',
				'singular_name' => 'Entity',
			],
			'public'            => true,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'entity' ],
		] );
	}
}
```

- [ ] **Step 2: Write the failing ContentFilter test**

```php
<?php
// tests/Unit/ContentFilterTest.php
namespace Navne\Tests\Unit;

use Navne\ContentFilter;
use Brain\Monkey\Functions;

class ContentFilterTest extends TestCase {
	public function test_filter_links_first_mention_of_entity(): void {
		$term       = (object) [ 'name' => 'Jane Smith', 'term_id' => 5 ];
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ $term ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/entity/jane-smith/' );
		Functions\when( 'esc_url' )->alias( fn( $u ) => $u );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );

		$filter  = new ContentFilter();
		$content = '<p>Jane Smith spoke at the summit. Jane Smith is a diplomat.</p>';
		$result  = $filter->filter( $content );

		$this->assertStringContainsString( '<a href="https://example.com/entity/jane-smith/">Jane Smith</a>', $result );
		// Only first mention linked — second occurrence is plain text.
		$this->assertSame( 1, substr_count( $result, '<a href=' ) );
	}

	public function test_filter_does_not_double_link_already_linked_entity(): void {
		$term = (object) [ 'name' => 'NATO', 'term_id' => 3 ];
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 2 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ $term ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/entity/nato/' );
		Functions\when( 'esc_url' )->alias( fn( $u ) => $u );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );

		$filter  = new ContentFilter();
		$content = '<p>Read more about <a href="/already-linked">NATO</a> here.</p>';
		$result  = $filter->filter( $content );

		// The existing link is preserved; no second link added.
		$this->assertSame( 1, substr_count( $result, '<a href=' ) );
		$this->assertStringContainsString( '/already-linked', $result );
	}

	public function test_filter_returns_content_unchanged_outside_loop(): void {
		Functions\when( 'in_the_loop' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );

		$filter  = new ContentFilter();
		$content = '<p>Some content with NATO mentioned.</p>';
		$this->assertSame( $content, $filter->filter( $content ) );
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
./bin/phpunit tests/Unit/ContentFilterTest.php -v
```

Expected: FAIL — `Class "Navne\ContentFilter" not found`

- [ ] **Step 4: Create ContentFilter.php**

```php
<?php
// includes/ContentFilter.php
namespace Navne;

class ContentFilter {
	public function filter( string $content ): string {
		if ( ! in_the_loop() || ! is_singular() ) {
			return $content;
		}
		$post_id  = get_the_ID();
		$link_map = wp_cache_get( 'navne_link_map_' . $post_id, 'navne' );
		if ( false === $link_map ) {
			$link_map = $this->build_link_map( $post_id );
			wp_cache_set( 'navne_link_map_' . $post_id, $link_map, 'navne' );
		}
		if ( empty( $link_map ) ) {
			return $content;
		}
		return $this->apply_links( $content, $link_map );
	}

	private function build_link_map( int $post_id ): array {
		$terms = wp_get_post_terms( $post_id, 'navne_entity' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$map = [];
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( ! is_wp_error( $url ) ) {
				$map[ $term->name ] = $url;
			}
		}
		return $map;
	}

	private function apply_links( string $content, array $link_map ): string {
		foreach ( $link_map as $name => $url ) {
			// Split by existing <a> tags so we never replace inside an existing link.
			$parts    = preg_split( '/(<a[^>]*>.*?<\/a>)/si', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
			$replaced = false;
			foreach ( $parts as $i => $part ) {
				if ( $replaced || $i % 2 !== 0 ) {
					continue; // Skip odd indices (captured existing links) and after first replacement.
				}
				$escaped  = preg_quote( $name, '/' );
				$new_part = preg_replace(
					'/\b' . $escaped . '\b/u',
					'<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>',
					$part,
					1,
					$count
				);
				if ( $count > 0 ) {
					$parts[ $i ] = $new_part;
					$replaced    = true;
				}
			}
			$content = implode( '', $parts );
		}
		return $content;
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./bin/phpunit tests/Unit/ContentFilterTest.php -v
```

Expected: `OK (3 tests, 5 assertions)`

- [ ] **Step 6: Commit**

```bash
git add includes/Taxonomy.php includes/ContentFilter.php tests/Unit/ContentFilterTest.php
git commit -m "feat: add Taxonomy registration and ContentFilter (render-time linking)"
```

---

### Task 10: Gutenberg sidebar

**Files:**
- Create: `package.json`
- Create: `assets/js/sidebar/index.js`
- Create: `assets/js/sidebar/hooks/useSuggestions.js`
- Create: `assets/js/sidebar/components/SidebarPanel.js`
- Create: `assets/js/sidebar/components/SuggestionCard.js`
- Create: `assets/js/sidebar/components/ApprovedList.js`

All files are in `wp/app/public/wp-content/plugins/navne/`.

- [ ] **Step 1: Create package.json and install deps**

```json
{
	"name": "navne-entity-linker",
	"version": "0.1.0",
	"scripts": {
		"build": "wp-scripts build assets/js/sidebar/index.js --output-path=assets/js/build",
		"start": "wp-scripts start assets/js/sidebar/index.js --output-path=assets/js/build"
	},
	"devDependencies": {
		"@wordpress/scripts": "^26.0"
	}
}
```

Run: `npm install`

- [ ] **Step 2: Create useSuggestions.js**

```js
// assets/js/sidebar/hooks/useSuggestions.js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

export default function useSuggestions( postId ) {
	const [ jobStatus, setJobStatus ]     = useState( 'idle' );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ isLoading, setIsLoading ]     = useState( false );
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
		pollRef.current = setInterval( async () => {
			const status = await fetchSuggestions();
			if ( status === 'complete' || status === 'failed' ) {
				stopPolling();
			}
		}, 3000 );
	}, [ fetchSuggestions, stopPolling ] );

	// Start polling after a save completes.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			startPolling();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, startPolling ] );

	// Load existing suggestions on mount.
	useEffect( () => {
		fetchSuggestions();
		return stopPolling;
	}, [ fetchSuggestions, stopPolling ] );

	const approve = useCallback( async ( id ) => {
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/approve`,
			method: 'POST',
			data:   { id },
		} );
		setSuggestions( ( prev ) =>
			prev.map( ( s ) => ( s.id === id ? { ...s, status: 'approved' } : s ) )
		);
	}, [ postId ] );

	const dismiss = useCallback( async ( id ) => {
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/dismiss`,
			method: 'POST',
			data:   { id },
		} );
		setSuggestions( ( prev ) =>
			prev.map( ( s ) => ( s.id === id ? { ...s, status: 'dismissed' } : s ) )
		);
	}, [ postId ] );

	const retry = useCallback( async () => {
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/retry`,
			method: 'POST',
		} );
		setJobStatus( 'queued' );
		startPolling();
	}, [ postId, startPolling ] );

	return { jobStatus, suggestions, isLoading, approve, dismiss, retry };
}
```

- [ ] **Step 3: Create SuggestionCard.js**

```jsx
// assets/js/sidebar/components/SuggestionCard.js
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

const TYPE_LABELS = {
	person: __( 'Person', 'navne' ),
	org:    __( 'Org',    'navne' ),
	place:  __( 'Place',  'navne' ),
	other:  __( 'Other',  'navne' ),
};

export default function SuggestionCard( { suggestion, onApprove, onDismiss } ) {
	const confidence = Math.round( suggestion.confidence * 100 );
	return (
		<div style={ { borderLeft: '3px solid #7c5cbf', padding: '10px', marginBottom: '8px', background: '#f9f9f9', borderRadius: '2px' } }>
			<strong>{ suggestion.entity_name }</strong>
			<div style={ { fontSize: '12px', color: '#666', margin: '3px 0' } }>
				{ TYPE_LABELS[ suggestion.entity_type ] ?? suggestion.entity_type } &middot; { confidence }%
			</div>
			<div style={ { display: 'flex', gap: '8px', marginTop: '6px' } }>
				<Button variant="primary" isSmall onClick={ () => onApprove( suggestion.id ) }>
					{ __( 'Approve', 'navne' ) }
				</Button>
				<Button variant="secondary" isSmall onClick={ () => onDismiss( suggestion.id ) }>
					{ __( 'Dismiss', 'navne' ) }
				</Button>
			</div>
		</div>
	);
}
```

- [ ] **Step 4: Create ApprovedList.js**

```jsx
// assets/js/sidebar/components/ApprovedList.js
import { __ } from '@wordpress/i18n';

export default function ApprovedList( { suggestions } ) {
	const approved = suggestions.filter( ( s ) => s.status === 'approved' );
	if ( ! approved.length ) return null;
	return (
		<div style={ { marginTop: '16px' } }>
			<p style={ { fontSize: '11px', textTransform: 'uppercase', letterSpacing: '1px', color: '#999', margin: '0 0 8px' } }>
				{ __( 'Linked', 'navne' ) }
			</p>
			{ approved.map( ( s ) => (
				<div key={ s.id } style={ { padding: '3px 0', color: '#2ecc71', fontSize: '13px' } }>
					{ s.entity_name }
				</div>
			) ) }
		</div>
	);
}
```

- [ ] **Step 5: Create SidebarPanel.js**

```jsx
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
	const { jobStatus, suggestions, isLoading, approve, dismiss, retry } =
		useSuggestions( postId );

	const pending = suggestions.filter( ( s ) => s.status === 'pending' );

	return (
		<PanelBody initialOpen={ true }>
			{ jobStatus === 'idle' && (
				<p style={ { color: '#666', fontSize: '13px' } }>
					{ __( 'Save the post to detect entities.', 'navne' ) }
				</p>
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

- [ ] **Step 6: Create index.js**

```jsx
// assets/js/sidebar/index.js
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import SidebarPanel from './components/SidebarPanel';

registerPlugin( 'navne-entity-sidebar', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="navne-sidebar">
				{ __( 'Entities', 'navne' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="navne-sidebar" title={ __( 'Entities', 'navne' ) }>
				<SidebarPanel />
			</PluginSidebar>
		</>
	),
} );
```

- [ ] **Step 7: Build and verify output**

```bash
npm run build
```

Expected: `assets/js/build/index.js` and `assets/js/build/index.asset.php` created with no errors.

- [ ] **Step 8: Commit**

```bash
git add assets/ package.json package-lock.json
git commit -m "feat: add Gutenberg sidebar (entity suggestions panel)"
```

---

### Task 11: Plugin bootstrap + wiring

**Files:**
- Create: `includes/Plugin.php`

- [ ] **Step 1: Create Plugin.php**

```php
<?php
// includes/Plugin.php
namespace Navne;

use Navne\Api\SuggestionsController;
use Navne\Jobs\ProcessPostJob;
use Navne\Pipeline\EntityPipeline;
use Navne\Pipeline\LlmResolver;
use Navne\Pipeline\PassthroughExtractor;
use Navne\Provider\ProviderFactory;

class Plugin {
	public static function init(): void {
		Taxonomy::register_hooks();
		( new SuggestionsController() )->register_routes_on_init();
		add_action( 'save_post',           [ PostSaveHook::class,   'handle' ], 10, 3 );
		add_action( 'navne_process_post',  [ ProcessPostJob::class, 'run' ] );
		add_filter( 'the_content',         [ new ContentFilter(),   'filter' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class,   'enqueue_sidebar' ] );

		// Invalidate link cache when terms change.
		add_action( 'set_object_terms', [ self::class, 'invalidate_link_cache' ], 10, 2 );
		add_action( 'delete_term',      [ self::class, 'invalidate_term_cache' ] );
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

- [ ] **Step 2: Activate the plugin via WP-CLI**

Run from the plugin directory:
```bash
./bin/wp plugin activate navne
```

Expected:
```
Plugin 'navne' activated.
```

- [ ] **Step 3: Verify the suggestions table was created**

```bash
./bin/wp db query "SHOW TABLES LIKE 'wp_navne_suggestions';"
```

Expected: `wp_navne_suggestions`

- [ ] **Step 4: Store the Anthropic API key in WordPress options**

```bash
./bin/wp option update navne_anthropic_api_key "YOUR_ANTHROPIC_API_KEY"
./bin/wp option update navne_provider "anthropic"
```

- [ ] **Step 5: Smoke test — open a post in the editor**

1. Open `http://navne.local/wp-admin/`
2. Open any post
3. Look for "Entities" in the sidebar menu (three-dot icon → "Entities")
4. Save/update the post
5. Sidebar should show spinner → then entity suggestion cards

- [ ] **Step 6: Run the full test suite**

```bash
./bin/phpunit -v
```

Expected: All tests pass, no failures.

- [ ] **Step 7: Commit**

```bash
git add includes/Plugin.php
git commit -m "feat: wire up Plugin bootstrap — taxonomy, hooks, sidebar enqueue, cache invalidation"
```

---

## Self-Review Checklist

**Spec coverage:**
- Plugin Structure ✓ Tasks 1, 11
- Taxonomy ✓ Task 9
- Data Layer (table + post meta + approval side effects) ✓ Tasks 6, 8
- Pipeline (two-stage, passthrough extractor) ✓ Tasks 2, 3, 5
- LLM Provider Abstraction ✓ Task 4
- Async Job (Action Scheduler, PostSaveHook) ✓ Task 7
- REST API (all 4 endpoints) ✓ Task 8
- Render-time Linking (the_content, cache, double-link guard) ✓ Task 9
- Gutenberg Sidebar (all states, polling, approve/dismiss/retry) ✓ Task 10

**No placeholders:** All steps contain complete code or exact commands.

**Type consistency:**
- `Entity(name, type, confidence)` — consistent across Tasks 2, 5, 6, 7
- `ProviderInterface::complete(string): string` — consistent across Tasks 4, 5
- `SuggestionsTable::insert_entities(int, Entity[])` — consistent across Tasks 6, 7
- `ProcessPostJob::run(int, ?EntityPipeline, ?SuggestionsTable)` — consistent across Tasks 7, 11
- `SuggestionsController(SuggestionsTable)` — consistent across Tasks 8, 11
