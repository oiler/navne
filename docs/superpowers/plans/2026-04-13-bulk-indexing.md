# Bulk Indexing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add admin-triggered bulk indexing to the Navne plugin — three run types, per-run mode picker (Safe / Suggest / YOLO), optional date-range scope, live progress with cancellation, first-class run history.

**Architecture:** A new `Navne\BulkIndex\*` namespace adds a control plane on top of the existing single-post pipeline. A dispatcher action enqueues `ProcessPostJob` in small waves; `ProcessPostJob` gets a thin adapter so the dispatcher can pass a `bulk_run_id` and route into a mode-aware `BulkAwareProcessor`. Two new DB tables track run state and per-item outcomes. A new admin page under Tools → Navne Indexing renders the form, preview, progress view, and history.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, Action Scheduler (bundled), Brain\\Monkey for WP function mocking in tests, PHPUnit.

**Spec reference:** `docs/superpowers/specs/2026-04-13-bulk-indexing-design.md`

**Conventions the plan inherits from the codebase:**
- Tabs for indentation; double quotes in PHP
- `snake_case` for functions and variables, `PascalCase` for classes
- Constructor-injected `wpdb` with a `static instance()` factory for DB classes
- Static method style for job/hook callbacks (follow existing `ProcessPostJob`, `PostSaveHook`, `SettingsPage`)
- All `$_POST`/`$_GET` values sanitized narrowly; enum-style fields validated against allowlists
- Tests mirror `includes/` structure under `tests/Unit/`
- Run `vendor/bin/phpunit` from `navne/` before every commit

**File map:**

| File | Create / Modify | Responsibility |
|---|---|---|
| `navne/includes/BulkIndex/Config.php` | Create | Reads `NAVNE_BULK_*` constants with defaults |
| `navne/includes/BulkIndex/RunsRepository.php` | Create | CRUD + schema for `wp_navne_bulk_runs` |
| `navne/includes/BulkIndex/RunItemsRepository.php` | Create | CRUD + schema for `wp_navne_bulk_run_items`; atomic claim |
| `navne/includes/BulkIndex/Whitelist.php` | Create | Memoized read of `navne_entity` term names for Safe-mode filtering |
| `navne/includes/BulkIndex/ScopeQuery.php` | Create | Builds the post-ID list for a run from run_type + date range |
| `navne/includes/BulkIndex/RunFactory.php` | Create | Creates a run row + item rows + initial dispatcher wave |
| `navne/includes/BulkIndex/Dispatcher.php` | Create | `navne_bulk_dispatch` action callback — claims + enqueues + reschedules |
| `navne/includes/BulkIndex/BulkAwareProcessor.php` | Create | Bulk-context post processing with per-run mode branching |
| `navne/includes/BulkIndex/TermHelper.php` | Create | Shared `ensure_term()` wrapping `wp_insert_term` + `term_exists` recovery |
| `navne/includes/Jobs/ProcessPostJob.php` | Modify | Adapter: routes bulk-run calls to `BulkAwareProcessor`, keeps single-post path intact |
| `navne/includes/Plugin.php` | Modify | Register `navne_bulk_dispatch` hook; wire admin page; wire REST controller |
| `navne/includes/Admin/IndexingPage.php` | Create | Form, preview, run detail, history, admin-post handlers |
| `navne/includes/Api/BulkRunsController.php` | Create | REST `GET` endpoints for list + detail + failed items |
| `navne/assets/js/indexing.js` | Create | Vanilla-JS poller for the run detail view |
| `navne/navne.php` | Modify | Activation hook to create new tables; version bump |
| `navne/package.json` | Modify | Version bump |
| `CHANGELOG.md` (repo root) | Modify | New `[Unreleased]` entries |
| `docs/releases/v1.4.0.md` | Create | Narrative release notes |
| `navne/tests/Unit/BulkIndex/*Test.php` | Create | Unit tests for every class above that has logic |
| `navne/tests/Unit/Jobs/ProcessPostJobTest.php` | Modify | Regression test for the adapter's single-post path |

**Out of scope (per spec):** resume-after-stuck-run, fine-grained progress, serialized runs, configurable YOLO threshold, fuzzy whitelist match, per-role bulk permissions, scheduled bulk runs, dry-run mode, real token-count estimation, bulk cancel, pause/resume, inline per-item retry, import/export.

---

## Task 1: Config class with defaults

**Files:**
- Create: `navne/includes/BulkIndex/Config.php`
- Test: `navne/tests/Unit/BulkIndex/ConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/ConfigTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\Config;
use Navne\Tests\Unit\TestCase;

class ConfigTest extends TestCase {
	public function test_defaults_when_constants_not_defined(): void {
		$this->assertSame( 5,     Config::batch_size() );
		$this->assertSame( 60,    Config::batch_interval() );
		$this->assertSame( 0.002, Config::avg_cost_per_article() );
		$this->assertSame( 10,    Config::history_limit() );
		$this->assertSame( 500,   Config::max_error_len() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter ConfigTest
```

Expected: fatal error — `Navne\BulkIndex\Config` not found.

- [ ] **Step 3: Create `navne/includes/BulkIndex/Config.php`**

```php
<?php
namespace Navne\BulkIndex;

class Config {
	public static function batch_size(): int {
		return defined( "NAVNE_BULK_BATCH_SIZE" ) ? (int) NAVNE_BULK_BATCH_SIZE : 5;
	}

	public static function batch_interval(): int {
		return defined( "NAVNE_BULK_BATCH_INTERVAL" ) ? (int) NAVNE_BULK_BATCH_INTERVAL : 60;
	}

	public static function avg_cost_per_article(): float {
		return defined( "NAVNE_AVG_COST_PER_ARTICLE" ) ? (float) NAVNE_AVG_COST_PER_ARTICLE : 0.002;
	}

	public static function history_limit(): int {
		return defined( "NAVNE_BULK_HISTORY_LIMIT" ) ? (int) NAVNE_BULK_HISTORY_LIMIT : 10;
	}

	public static function max_error_len(): int {
		return defined( "NAVNE_BULK_MAX_ERROR_LEN" ) ? (int) NAVNE_BULK_MAX_ERROR_LEN : 500;
	}
}
```

Note: the test environment doesn't define these constants, so the defaults branch is exercised. Overridden values are exercised by deployment, not unit tests.

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter ConfigTest
```

Expected: 1 test, 5 assertions, OK.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

Expected: all existing tests plus the new one pass.

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/Config.php navne/tests/Unit/BulkIndex/ConfigTest.php
git commit -m "feat(bulk): Config class for bulk-indexing constants"
```

---

## Task 2: RunsRepository — schema + create + find_by_id

**Files:**
- Create: `navne/includes/BulkIndex/RunsRepository.php`
- Test: `navne/tests/Unit/BulkIndex/RunsRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/RunsRepositoryTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\RunsRepository;
use Navne\Tests\Unit\TestCase;

class RunsRepositoryTest extends TestCase {
	private function make_wpdb() {
		$wpdb = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ "insert", "get_row", "prepare", "update", "get_results" ] )
			->getMock();
		$wpdb->prefix    = "wp_";
		$wpdb->insert_id = 0;
		return $wpdb;
	}

	public function test_create_inserts_row_and_returns_new_id(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->insert_id = 42;
		$wpdb->expects( $this->once() )
			->method( "insert" )
			->with(
				"wp_navne_bulk_runs",
				$this->callback( function ( $data ) {
					return $data["run_type"] === "index_new"
						&& $data["mode"] === "suggest"
						&& $data["total"] === 100
						&& $data["status"] === "pending";
				} )
			);

		$repo = new RunsRepository( $wpdb );
		$id = $repo->create( [
			"run_type"      => "index_new",
			"mode"          => "suggest",
			"date_from"     => null,
			"date_to"       => null,
			"parent_run_id" => null,
			"total"         => 100,
			"created_by"    => 1,
		] );

		$this->assertSame( 42, $id );
	}

	public function test_find_by_id_returns_row_array(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->method( "prepare" )->willReturnArgument( 0 );
		$wpdb->method( "get_row" )->willReturn( [ "id" => 7, "run_type" => "reindex_all" ] );

		$repo = new RunsRepository( $wpdb );
		$this->assertSame( [ "id" => 7, "run_type" => "reindex_all" ], $repo->find_by_id( 7 ) );
	}

	public function test_find_by_id_returns_null_when_missing(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->method( "prepare" )->willReturnArgument( 0 );
		$wpdb->method( "get_row" )->willReturn( null );

		$repo = new RunsRepository( $wpdb );
		$this->assertNull( $repo->find_by_id( 7 ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter RunsRepositoryTest
```

Expected: class not found.

- [ ] **Step 3: Create `navne/includes/BulkIndex/RunsRepository.php`**

```php
<?php
namespace Navne\BulkIndex;

class RunsRepository {
	private static ?self $instance = null;

	public function __construct( private $db ) {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}
		return self::$instance;
	}

	public function table_name(): string {
		return $this->db->prefix . "navne_bulk_runs";
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		$table           = $wpdb->prefix . "navne_bulk_runs";
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_type varchar(20) NOT NULL,
			mode varchar(20) NOT NULL,
			date_from date DEFAULT NULL,
			date_to date DEFAULT NULL,
			parent_run_id bigint(20) unsigned DEFAULT NULL,
			total int unsigned NOT NULL DEFAULT 0,
			processed int unsigned NOT NULL DEFAULT 0,
			failed int unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};" );
	}

	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navne_bulk_runs" );
	}

	public function create( array $data ): int {
		$this->db->insert(
			$this->table_name(),
			[
				"run_type"      => $data["run_type"],
				"mode"          => $data["mode"],
				"date_from"     => $data["date_from"],
				"date_to"       => $data["date_to"],
				"parent_run_id" => $data["parent_run_id"],
				"total"         => (int) $data["total"],
				"processed"     => 0,
				"failed"        => 0,
				"status"        => $data["total"] > 0 ? "pending" : "complete",
				"created_by"    => (int) $data["created_by"],
			],
			[ "%s", "%s", "%s", "%s", "%d", "%d", "%d", "%d", "%s", "%d" ]
		);
		return (int) $this->db->insert_id;
	}

	public function find_by_id( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function update_status( int $id, string $status ): void {
		$this->db->update(
			$this->table_name(),
			[ "status" => $status ],
			[ "id"     => $id ],
			[ "%s" ],
			[ "%d" ]
		);
	}

	public function update_counts( int $id, int $processed, int $failed ): void {
		$this->db->update(
			$this->table_name(),
			[ "processed" => $processed, "failed" => $failed ],
			[ "id"        => $id ],
			[ "%d", "%d" ],
			[ "%d" ]
		);
	}

	public function find_recent( int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return $rows ?: [];
	}
}
```

Note the typed-property quirk: `wpdb` is a real class in WordPress but a stub in tests. Declaring `private $db` (untyped) keeps both happy. Matches `SuggestionsTable`'s approach in spirit (it uses `\wpdb` because the real class is autoloaded in WP context; here we accept either because tests inject a mock object).

Also note: `get_row` takes a second `ARRAY_A` arg in the real WP API but the mock ignores it — PHPUnit's `willReturn()` short-circuits before argument matching.

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter RunsRepositoryTest
```

Expected: 3 tests pass.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/RunsRepository.php navne/tests/Unit/BulkIndex/RunsRepositoryTest.php
git commit -m "feat(bulk): RunsRepository with schema + create/find/update"
```

---

## Task 3: RunItemsRepository — schema + bulk_insert + atomic claim

**Files:**
- Create: `navne/includes/BulkIndex/RunItemsRepository.php`
- Test: `navne/tests/Unit/BulkIndex/RunItemsRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/RunItemsRepositoryTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\RunItemsRepository;
use Navne\Tests\Unit\TestCase;

class RunItemsRepositoryTest extends TestCase {
	private function make_wpdb() {
		$wpdb = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ "query", "get_results", "get_col", "get_var", "prepare", "update", "insert" ] )
			->getMock();
		$wpdb->prefix = "wp_";
		return $wpdb;
	}

	public function test_bulk_insert_chunks_queries(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->method( "prepare" )->willReturnArgument( 0 );
		// 600 post ids, chunk size 500 → two query calls
		$wpdb->expects( $this->exactly( 2 ) )->method( "query" );

		$repo = new RunItemsRepository( $wpdb );
		$repo->bulk_insert( 1, range( 1, 600 ) );
	}

	public function test_bulk_insert_noop_on_empty(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->expects( $this->never() )->method( "query" );

		$repo = new RunItemsRepository( $wpdb );
		$repo->bulk_insert( 1, [] );
	}

	public function test_claim_queued_updates_then_selects(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->method( "prepare" )->willReturnArgument( 0 );

		// First call: UPDATE claim. Second call: SELECT post_ids just claimed.
		$wpdb->expects( $this->once() )->method( "query" )->willReturn( 3 );
		$wpdb->expects( $this->once() )
			->method( "get_col" )
			->willReturn( [ "101", "102", "103" ] );

		$repo = new RunItemsRepository( $wpdb );
		$post_ids = $repo->claim_queued( 7, 5 );

		$this->assertSame( [ 101, 102, 103 ], $post_ids );
	}

	public function test_count_terminal_for_run_aggregates(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->method( "prepare" )->willReturnArgument( 0 );
		$wpdb->method( "get_results" )->willReturn( [
			[ "status" => "complete", "c" => "40" ],
			[ "status" => "failed",   "c" => "3"  ],
			[ "status" => "skipped",  "c" => "2"  ],
			[ "status" => "queued",   "c" => "5"  ],
		] );

		$repo = new RunItemsRepository( $wpdb );
		$counts = $repo->count_terminal_for_run( 7 );

		$this->assertSame( 45, $counts["processed"] );
		$this->assertSame( 3,  $counts["failed"] );
	}

	public function test_failed_post_ids_for_run_returns_ints(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->method( "prepare" )->willReturnArgument( 0 );
		$wpdb->method( "get_col" )->willReturn( [ "11", "22", "33" ] );

		$repo = new RunItemsRepository( $wpdb );
		$this->assertSame( [ 11, 22, 33 ], $repo->failed_post_ids_for_run( 7 ) );
	}

	public function test_update_status_writes_error_when_provided(): void {
		$wpdb = $this->make_wpdb();
		$wpdb->expects( $this->once() )
			->method( "update" )
			->with(
				"wp_navne_bulk_run_items",
				$this->callback( function ( $data ) {
					return $data["status"] === "failed"
						&& $data["error_message"] === "boom";
				} ),
				[ "run_id" => 7, "post_id" => 101 ]
			);

		$repo = new RunItemsRepository( $wpdb );
		$repo->update_status( 7, 101, "failed", "boom" );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter RunItemsRepositoryTest
```

Expected: class not found.

- [ ] **Step 3: Create `navne/includes/BulkIndex/RunItemsRepository.php`**

```php
<?php
namespace Navne\BulkIndex;

class RunItemsRepository {
	private const INSERT_CHUNK = 500;

	private static ?self $instance = null;

	public function __construct( private $db ) {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}
		return self::$instance;
	}

	public function table_name(): string {
		return $this->db->prefix . "navne_bulk_run_items";
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		$table           = $wpdb->prefix . "navne_bulk_run_items";
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			error_message varchar(500) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY run_status (run_id, status),
			KEY post_id (post_id)
		) {$charset_collate};" );
	}

	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navne_bulk_run_items" );
	}

	public function bulk_insert( int $run_id, array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}
		$table  = $this->table_name();
		$chunks = array_chunk( $post_ids, self::INSERT_CHUNK );
		foreach ( $chunks as $chunk ) {
			$placeholders = [];
			$values       = [];
			foreach ( $chunk as $post_id ) {
				$placeholders[] = "(%d, %d, 'queued')";
				$values[]       = $run_id;
				$values[]       = (int) $post_id;
			}
			$sql = "INSERT INTO {$table} (run_id, post_id, status) VALUES " . implode( ", ", $placeholders );
			$this->db->query( $this->db->prepare( $sql, ...$values ) );
		}
	}

	public function claim_queued( int $run_id, int $limit ): array {
		$table = $this->table_name();
		// Atomic claim: UPDATE ... WHERE id IN (SELECT ... LIMIT). The nested SELECT
		// avoids the "can't SELECT and UPDATE the same table in one statement" error
		// in MySQL by wrapping the inner SELECT in a subquery alias.
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$table}
				 SET status = 'dispatching', updated_at = NOW()
				 WHERE id IN (
					SELECT id FROM (
						SELECT id FROM {$table}
						WHERE run_id = %d AND status = 'queued'
						ORDER BY created_at ASC, id ASC
						LIMIT %d
					) AS claim
				 )",
				$run_id,
				$limit
			)
		);

		$post_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT post_id FROM {$table} WHERE run_id = %d AND status = 'dispatching' ORDER BY id ASC",
				$run_id
			)
		);

		return array_map( "intval", $post_ids ?: [] );
	}

	public function update_status( int $run_id, int $post_id, string $status, ?string $error = null ): void {
		$data = [ "status" => $status ];
		if ( $error !== null ) {
			$data["error_message"] = $error;
		}
		$this->db->update(
			$this->table_name(),
			$data,
			[ "run_id" => $run_id, "post_id" => $post_id ]
		);
	}

	public function count_terminal_for_run( int $run_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT status, COUNT(*) AS c FROM {$this->table_name()} WHERE run_id = %d GROUP BY status",
				$run_id
			),
			ARRAY_A
		);
		$processed = 0;
		$failed    = 0;
		foreach ( $rows ?: [] as $row ) {
			$c = (int) $row["c"];
			if ( in_array( $row["status"], [ "complete", "failed", "skipped" ], true ) ) {
				$processed += $c;
			}
			if ( $row["status"] === "failed" ) {
				$failed += $c;
			}
		}
		return [ "processed" => $processed, "failed" => $failed ];
	}

	public function failed_post_ids_for_run( int $run_id ): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT post_id FROM {$this->table_name()} WHERE run_id = %d AND status = 'failed' ORDER BY id ASC",
				$run_id
			)
		);
		return array_map( "intval", $rows ?: [] );
	}

	public function find_failed_for_run( int $run_id, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT post_id, error_message FROM {$this->table_name()}
				 WHERE run_id = %d AND status = 'failed'
				 ORDER BY id ASC LIMIT %d",
				$run_id,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: [];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter RunItemsRepositoryTest
```

Expected: 6 tests pass.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/RunItemsRepository.php navne/tests/Unit/BulkIndex/RunItemsRepositoryTest.php
git commit -m "feat(bulk): RunItemsRepository with atomic claim and chunked inserts"
```

---

## Task 4: Activation hook creates new tables

**Files:**
- Modify: `navne/navne.php`

No unit test — schema creation runs inside `dbDelta` which requires a real WP environment. This is the same approach already used for the existing `SuggestionsTable::create` hook.

- [ ] **Step 1: Read the existing activation hook**

```bash
grep -n "register_activation_hook\|register_uninstall_hook" navne/navne.php
```

Expected: lines 22–23 register `SuggestionsTable::create` and `SuggestionsTable::drop`.

- [ ] **Step 2: Add activation + uninstall hooks for the two new tables**

In `navne/navne.php`, replace the two existing `register_activation_hook` / `register_uninstall_hook` calls with:

```php
register_activation_hook( __FILE__, function () {
	\Navne\Storage\SuggestionsTable::create();
	\Navne\BulkIndex\RunsRepository::create_table();
	\Navne\BulkIndex\RunItemsRepository::create_table();
} );

register_uninstall_hook( __FILE__, function () {
	\Navne\Storage\SuggestionsTable::drop();
	\Navne\BulkIndex\RunsRepository::drop_table();
	\Navne\BulkIndex\RunItemsRepository::drop_table();
} );
```

- [ ] **Step 3: Run the full suite (no new tests, just regression guard)**

```bash
cd navne && vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add navne/navne.php
git commit -m "feat(bulk): create bulk-run tables on activation"
```

---

## Task 5: Whitelist helper

**Files:**
- Create: `navne/includes/BulkIndex/Whitelist.php`
- Test: `navne/tests/Unit/BulkIndex/WhitelistTest.php`

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/WhitelistTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\Whitelist;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class WhitelistTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Whitelist::reset(); // clear static memoization between tests
	}

	public function test_contains_matches_case_insensitive(): void {
		Functions\when( "get_terms" )->justReturn( [ "Jane Smith", "ACME Corp" ] );
		Functions\when( "is_wp_error" )->justReturn( false );

		$list = Whitelist::current();
		$this->assertTrue( $list->contains( "jane smith" ) );
		$this->assertTrue( $list->contains( "acme corp" ) );
		$this->assertFalse( $list->contains( "bob jones" ) );
	}

	public function test_empty_taxonomy_returns_empty_set(): void {
		Functions\when( "get_terms" )->justReturn( [] );
		Functions\when( "is_wp_error" )->justReturn( false );

		$list = Whitelist::current();
		$this->assertFalse( $list->contains( "anything" ) );
	}

	public function test_wp_error_returns_empty_set(): void {
		Functions\when( "get_terms" )->justReturn( new \stdClass() );
		Functions\when( "is_wp_error" )->justReturn( true );

		$list = Whitelist::current();
		$this->assertFalse( $list->contains( "anything" ) );
	}

	public function test_memoizes_within_same_request(): void {
		$calls = 0;
		Functions\when( "get_terms" )->alias( function () use ( &$calls ) {
			$calls++;
			return [ "Jane Smith" ];
		} );
		Functions\when( "is_wp_error" )->justReturn( false );

		Whitelist::current();
		Whitelist::current();
		Whitelist::current();

		$this->assertSame( 1, $calls );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter WhitelistTest
```

Expected: class not found.

- [ ] **Step 3: Create `navne/includes/BulkIndex/Whitelist.php`**

```php
<?php
namespace Navne\BulkIndex;

class Whitelist {
	private static ?self $cached = null;

	private array $names; // lowercased names as hash map: [name => true]

	private function __construct( array $names ) {
		$this->names = [];
		foreach ( $names as $name ) {
			$this->names[ strtolower( (string) $name ) ] = true;
		}
	}

	public static function current(): self {
		if ( self::$cached !== null ) {
			return self::$cached;
		}
		$terms = get_terms( [
			"taxonomy"   => "navne_entity",
			"hide_empty" => false,
			"fields"     => "names",
		] );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			$terms = [];
		}
		self::$cached = new self( $terms );
		return self::$cached;
	}

	public static function reset(): void {
		self::$cached = null;
	}

	public function contains( string $name ): bool {
		return isset( $this->names[ strtolower( $name ) ] );
	}

	public function is_empty(): bool {
		return empty( $this->names );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter WhitelistTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/Whitelist.php navne/tests/Unit/BulkIndex/WhitelistTest.php
git commit -m "feat(bulk): Whitelist helper with per-request memoization"
```

---

## Task 6: TermHelper::ensure_term (extract race-recovery from ProcessPostJob)

**Files:**
- Create: `navne/includes/BulkIndex/TermHelper.php`
- Test: `navne/tests/Unit/BulkIndex/TermHelperTest.php`

This extracts the term creation + `term_exists` recovery already present in the existing YOLO branch of `ProcessPostJob::run`. Moving it into a shared helper lets both the existing YOLO path and the new Safe-bulk path call the same code. No behavior change.

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/TermHelperTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\TermHelper;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class TermHelperTest extends TestCase {
	public function test_ensure_term_returns_new_term_id_on_success(): void {
		Functions\when( "wp_insert_term" )->justReturn( [ "term_id" => 42 ] );
		Functions\when( "is_wp_error" )->justReturn( false );

		$this->assertSame( 42, TermHelper::ensure_term( "Jane Smith" ) );
	}

	public function test_ensure_term_recovers_existing_term_id_on_race(): void {
		$error = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ "get_error_code", "get_error_data", "get_error_message" ] )
			->getMock();
		$error->method( "get_error_code" )->willReturn( "term_exists" );
		$error->method( "get_error_data" )->willReturn( 99 );

		Functions\when( "wp_insert_term" )->justReturn( $error );
		Functions\when( "is_wp_error" )->justReturn( true );

		$this->assertSame( 99, TermHelper::ensure_term( "Jane Smith" ) );
	}

	public function test_ensure_term_returns_zero_on_unrecoverable_error(): void {
		$error = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ "get_error_code", "get_error_data", "get_error_message" ] )
			->getMock();
		$error->method( "get_error_code" )->willReturn( "db_error" );
		$error->method( "get_error_message" )->willReturn( "connection lost" );

		Functions\when( "wp_insert_term" )->justReturn( $error );
		Functions\when( "is_wp_error" )->justReturn( true );
		Functions\when( "error_log" )->justReturn( true );

		$this->assertSame( 0, TermHelper::ensure_term( "Jane Smith" ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter TermHelperTest
```

- [ ] **Step 3: Create `navne/includes/BulkIndex/TermHelper.php`**

```php
<?php
namespace Navne\BulkIndex;

class TermHelper {
	public static function ensure_term( string $name ): int {
		$result = wp_insert_term( $name, "navne_entity" );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_code() === "term_exists" ) {
				return (int) $result->get_error_data();
			}
			error_log( 'Navne bulk: failed to create term "' . $name . '": ' . $result->get_error_message() );
			return 0;
		}

		return (int) ( $result["term_id"] ?? 0 );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter TermHelperTest
```

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/TermHelper.php navne/tests/Unit/BulkIndex/TermHelperTest.php
git commit -m "feat(bulk): TermHelper::ensure_term extracted from ProcessPostJob YOLO branch"
```

---

## Task 7: ScopeQuery — builds matching post IDs from run type + date range

**Files:**
- Create: `navne/includes/BulkIndex/ScopeQuery.php`
- Test: `navne/tests/Unit/BulkIndex/ScopeQueryTest.php`

`ScopeQuery` is a small class with one public method per run type so tests can target each branch directly. It delegates to `get_posts()` (mockable via Brain\\Monkey) rather than `new WP_Query()`.

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/ScopeQueryTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\RunItemsRepository;
use Navne\BulkIndex\ScopeQuery;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class ScopeQueryTest extends TestCase {
	private function stub_common(): void {
		Functions\when( "get_option" )->justReturn( [ "post" ] );
	}

	public function test_index_new_adds_not_exists_meta_query(): void {
		$this->stub_common();
		$captured = null;
		Functions\when( "get_posts" )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return [ 1, 2, 3 ];
		} );

		$repo = $this->createMock( RunItemsRepository::class );
		$ids  = ( new ScopeQuery( $repo ) )->matching_post_ids( "index_new", null, null, null );

		$this->assertSame( [ 1, 2, 3 ], $ids );
		$this->assertSame( [ "post" ], $captured["post_type"] );
		$this->assertSame( "publish", $captured["post_status"] );
		$this->assertSame( "ids", $captured["fields"] );
		$this->assertSame( "_navne_job_status", $captured["meta_query"][0]["key"] );
		$this->assertSame( "NOT EXISTS",        $captured["meta_query"][0]["compare"] );
	}

	public function test_reindex_all_omits_meta_query(): void {
		$this->stub_common();
		$captured = null;
		Functions\when( "get_posts" )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return [];
		} );

		$repo = $this->createMock( RunItemsRepository::class );
		( new ScopeQuery( $repo ) )->matching_post_ids( "reindex_all", null, null, null );

		$this->assertArrayNotHasKey( "meta_query", $captured );
	}

	public function test_date_range_inclusive_both_ends(): void {
		$this->stub_common();
		$captured = null;
		Functions\when( "get_posts" )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return [];
		} );

		$repo = $this->createMock( RunItemsRepository::class );
		( new ScopeQuery( $repo ) )->matching_post_ids( "reindex_all", "2024-01-01", "2024-01-31", null );

		$this->assertCount( 2, $captured["date_query"] );
		$this->assertSame( "2024-01-01", $captured["date_query"][0]["after"] );
		$this->assertTrue( $captured["date_query"][0]["inclusive"] );
		$this->assertSame( "2024-01-31", $captured["date_query"][1]["before"] );
		$this->assertTrue( $captured["date_query"][1]["inclusive"] );
	}

	public function test_null_date_range_omits_date_query(): void {
		$this->stub_common();
		$captured = null;
		Functions\when( "get_posts" )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return [];
		} );

		$repo = $this->createMock( RunItemsRepository::class );
		( new ScopeQuery( $repo ) )->matching_post_ids( "reindex_all", null, null, null );

		$this->assertArrayNotHasKey( "date_query", $captured );
	}

	public function test_retry_failed_pulls_from_parent_run_items(): void {
		Functions\when( "get_option" )->justReturn( [ "post" ] );
		// retry_failed does NOT call get_posts.
		Functions\when( "get_posts" )->alias( function () {
			throw new \RuntimeException( "get_posts should not be called for retry_failed" );
		} );

		$repo = $this->createMock( RunItemsRepository::class );
		$repo->expects( $this->once() )
			->method( "failed_post_ids_for_run" )
			->with( 42 )
			->willReturn( [ 101, 102 ] );

		$ids = ( new ScopeQuery( $repo ) )->matching_post_ids( "retry_failed", null, null, 42 );

		$this->assertSame( [ 101, 102 ], $ids );
	}

	public function test_honors_post_types_setting(): void {
		Functions\when( "get_option" )->justReturn( [ "post", "page" ] );
		$captured = null;
		Functions\when( "get_posts" )->alias( function ( $args ) use ( &$captured ) {
			$captured = $args;
			return [];
		} );

		$repo = $this->createMock( RunItemsRepository::class );
		( new ScopeQuery( $repo ) )->matching_post_ids( "reindex_all", null, null, null );

		$this->assertSame( [ "post", "page" ], $captured["post_type"] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter ScopeQueryTest
```

- [ ] **Step 3: Create `navne/includes/BulkIndex/ScopeQuery.php`**

```php
<?php
namespace Navne\BulkIndex;

class ScopeQuery {
	public function __construct( private RunItemsRepository $items_repo ) {}

	public function matching_post_ids(
		string $run_type,
		?string $date_from,
		?string $date_to,
		?int $parent_run_id
	): array {
		if ( $run_type === "retry_failed" ) {
			if ( $parent_run_id === null || $parent_run_id <= 0 ) {
				return [];
			}
			return $this->items_repo->failed_post_ids_for_run( $parent_run_id );
		}

		$args = [
			"post_type"      => (array) get_option( "navne_post_types", [ "post" ] ),
			"post_status"    => "publish",
			"fields"         => "ids",
			"posts_per_page" => -1,
			"no_found_rows"  => true,
			"orderby"        => "ID",
			"order"          => "ASC",
			"suppress_filters" => true,
		];

		if ( $run_type === "index_new" ) {
			$args["meta_query"] = [
				[
					"key"     => "_navne_job_status",
					"compare" => "NOT EXISTS",
				],
			];
		}

		$date_query = [];
		if ( $date_from ) {
			$date_query[] = [ "after" => $date_from, "inclusive" => true ];
		}
		if ( $date_to ) {
			$date_query[] = [ "before" => $date_to, "inclusive" => true ];
		}
		if ( ! empty( $date_query ) ) {
			$args["date_query"] = $date_query;
		}

		$ids = get_posts( $args );
		return array_map( "intval", is_array( $ids ) ? $ids : [] );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter ScopeQueryTest
```

Expected: 6 tests pass.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/ScopeQuery.php navne/tests/Unit/BulkIndex/ScopeQueryTest.php
git commit -m "feat(bulk): ScopeQuery for run type and date range filters"
```

---

## Task 8: RunFactory — create run + items + schedule initial dispatcher wave

**Files:**
- Create: `navne/includes/BulkIndex/RunFactory.php`
- Test: `navne/tests/Unit/BulkIndex/RunFactoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `navne/tests/Unit/BulkIndex/RunFactoryTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\RunFactory;
use Navne\BulkIndex\RunItemsRepository;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\ScopeQuery;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class RunFactoryTest extends TestCase {
	public function test_create_inserts_run_row_with_frozen_mode(): void {
		$scope = $this->createMock( ScopeQuery::class );
		$scope->method( "matching_post_ids" )->willReturn( [ 10, 20, 30 ] );

		$runs = $this->createMock( RunsRepository::class );
		$runs->expects( $this->once() )
			->method( "create" )
			->with( $this->callback( function ( $data ) {
				return $data["mode"] === "yolo"
					&& $data["run_type"] === "reindex_all"
					&& $data["total"] === 3;
			} ) )
			->willReturn( 99 );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->once() )->method( "bulk_insert" )->with( 99, [ 10, 20, 30 ] );

		Functions\when( "get_current_user_id" )->justReturn( 1 );
		Functions\expect( "as_enqueue_async_action" )
			->once()
			->with( "navne_bulk_dispatch", [ "run_id" => 99 ] );

		$factory = new RunFactory( $runs, $items, $scope );
		$run_id  = $factory->create( [
			"run_type"      => "reindex_all",
			"mode"          => "yolo",
			"date_from"     => null,
			"date_to"       => null,
			"parent_run_id" => null,
		] );

		$this->assertSame( 99, $run_id );
	}

	public function test_empty_scope_creates_complete_run_with_zero_total_no_dispatcher(): void {
		$scope = $this->createMock( ScopeQuery::class );
		$scope->method( "matching_post_ids" )->willReturn( [] );

		$runs = $this->createMock( RunsRepository::class );
		$runs->expects( $this->once() )
			->method( "create" )
			->with( $this->callback( function ( $data ) {
				return $data["total"] === 0;
			} ) )
			->willReturn( 7 );
		// Empty scope: the create method persists total=0; RunsRepository::create
		// flips status to 'complete' automatically when total is 0 (see Task 2).

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->never() )->method( "bulk_insert" );

		Functions\when( "get_current_user_id" )->justReturn( 1 );
		Functions\expect( "as_enqueue_async_action" )->never();

		$factory = new RunFactory( $runs, $items, $scope );
		$factory->create( [
			"run_type"      => "index_new",
			"mode"          => "suggest",
			"date_from"     => null,
			"date_to"       => null,
			"parent_run_id" => null,
		] );
	}

	public function test_rejects_invalid_run_type(): void {
		$factory = new RunFactory(
			$this->createMock( RunsRepository::class ),
			$this->createMock( RunItemsRepository::class ),
			$this->createMock( ScopeQuery::class )
		);

		$this->expectException( \InvalidArgumentException::class );
		$factory->create( [
			"run_type"      => "nope",
			"mode"          => "suggest",
			"date_from"     => null,
			"date_to"       => null,
			"parent_run_id" => null,
		] );
	}

	public function test_rejects_invalid_mode(): void {
		$factory = new RunFactory(
			$this->createMock( RunsRepository::class ),
			$this->createMock( RunItemsRepository::class ),
			$this->createMock( ScopeQuery::class )
		);

		$this->expectException( \InvalidArgumentException::class );
		$factory->create( [
			"run_type"      => "index_new",
			"mode"          => "loud",
			"date_from"     => null,
			"date_to"       => null,
			"parent_run_id" => null,
		] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd navne && vendor/bin/phpunit --filter RunFactoryTest
```

- [ ] **Step 3: Create `navne/includes/BulkIndex/RunFactory.php`**

```php
<?php
namespace Navne\BulkIndex;

class RunFactory {
	private const RUN_TYPES = [ "index_new", "reindex_all", "retry_failed" ];
	private const MODES     = [ "safe", "suggest", "yolo" ];

	public function __construct(
		private RunsRepository     $runs,
		private RunItemsRepository $items,
		private ScopeQuery         $scope
	) {}

	public function create( array $form_input ): int {
		$run_type = (string) ( $form_input["run_type"] ?? "" );
		$mode     = (string) ( $form_input["mode"] ?? "" );
		if ( ! in_array( $run_type, self::RUN_TYPES, true ) ) {
			throw new \InvalidArgumentException( "Invalid run_type" );
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new \InvalidArgumentException( "Invalid mode" );
		}

		$date_from     = $this->normalize_date( $form_input["date_from"] ?? null );
		$date_to       = $this->normalize_date( $form_input["date_to"] ?? null );
		$parent_run_id = isset( $form_input["parent_run_id"] ) ? (int) $form_input["parent_run_id"] : null;

		$post_ids = $this->scope->matching_post_ids( $run_type, $date_from, $date_to, $parent_run_id );

		$run_id = $this->runs->create( [
			"run_type"      => $run_type,
			"mode"          => $mode,
			"date_from"     => $date_from,
			"date_to"       => $date_to,
			"parent_run_id" => $parent_run_id,
			"total"         => count( $post_ids ),
			"created_by"    => (int) get_current_user_id(),
		] );

		if ( empty( $post_ids ) ) {
			return $run_id;
		}

		$this->items->bulk_insert( $run_id, $post_ids );
		as_enqueue_async_action( "navne_bulk_dispatch", [ "run_id" => $run_id ] );

		return $run_id;
	}

	private function normalize_date( $raw ): ?string {
		if ( ! is_string( $raw ) || $raw === "" ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( "Y-m-d", $raw );
		return ( $dt && $dt->format( "Y-m-d" ) === $raw ) ? $raw : null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd navne && vendor/bin/phpunit --filter RunFactoryTest
```

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/RunFactory.php navne/tests/Unit/BulkIndex/RunFactoryTest.php
git commit -m "feat(bulk): RunFactory creates run + items + dispatcher wave"
```

---

## Task 9: Refactor ProcessPostJob — extract run_single_post, add bulk adapter

**Files:**
- Modify: `navne/includes/Jobs/ProcessPostJob.php`
- Modify: `navne/tests/Unit/Jobs/ProcessPostJobTest.php`

Extract the existing `run()` body into a new private `run_single_post()` method. The public `run()` becomes a small adapter: when the second arg is a positive int, delegate to `BulkAwareProcessor::process`; otherwise call `run_single_post` with the existing signature. `BulkAwareProcessor` doesn't exist yet — we add a placeholder in Task 10. For now the adapter only has to compile; no test expects the bulk branch to run.

- [ ] **Step 1: Write a regression test that pins the adapter dispatch behavior**

Add these tests to `navne/tests/Unit/Jobs/ProcessPostJobTest.php`:

```php
public function test_adapter_invokes_single_post_when_second_arg_is_zero(): void {
	$entities = [ new Entity( "Jane Smith", "person", 0.94 ) ];
	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( $entities );

	$table = $this->createMock( SuggestionsTable::class );
	$table->method( "find_approved_names_for_post" )->willReturn( [] );
	$table->expects( $this->once() )->method( "insert_entities" )->with( 1, $entities, "pending" );

	Functions\when( "get_option" )->justReturn( "suggest" );
	Functions\expect( "update_post_meta" )->twice();

	// Explicitly pass 0 — the single-post path should run.
	ProcessPostJob::run( 1, 0, null, $pipeline, $table );
}

public function test_adapter_preserves_pipeline_injection_backcompat(): void {
	$entities = [ new Entity( "Jane Smith", "person", 0.94 ) ];
	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( $entities );

	$table = $this->createMock( SuggestionsTable::class );
	$table->method( "find_approved_names_for_post" )->willReturn( [] );
	$table->expects( $this->once() )->method( "insert_entities" )->with( 1, $entities, "pending" );

	Functions\when( "get_option" )->justReturn( "suggest" );
	Functions\expect( "update_post_meta" )->twice();

	// Legacy three-arg call: (post_id, pipeline, table). Adapter must still route to single-post.
	ProcessPostJob::run( 1, $pipeline, $table );
}
```

The second test confirms the legacy 3-arg signature used by the existing test suite still works. The first locks in the new 4-arg form used by Action Scheduler's bulk dispatch path.

- [ ] **Step 2: Run tests to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter ProcessPostJobTest
```

Expected: the new tests fail (signature mismatch or unexpected behavior). Existing tests still pass.

- [ ] **Step 3: Refactor `navne/includes/Jobs/ProcessPostJob.php`**

Replace the file contents with:

```php
<?php
namespace Navne\Jobs;

use Navne\BulkIndex\BulkAwareProcessor;
use Navne\BulkIndex\TermHelper;
use Navne\Exception\PipelineException;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class ProcessPostJob {
	/**
	 * Routes to the single-post path when invoked from post save, and to the
	 * bulk path when invoked from the Navne bulk dispatcher.
	 *
	 * Action Scheduler callback shapes we support:
	 *   run( int $post_id )                                   — post save (production)
	 *   run( int $post_id, int $bulk_run_id )                  — bulk dispatch (production)
	 *   run( int $post_id, EntityPipeline $p, SuggestionsTable $t ) — legacy test injection
	 *   run( int $post_id, int $bulk_run_id, ?EntityPipeline $p, ?SuggestionsTable $t ) — new test injection
	 */
	public static function run(
		int $post_id,
		mixed $second = 0,
		mixed $third  = null,
		mixed $fourth = null,
		mixed $fifth  = null
	): void {
		// Detect legacy 3-arg test signature: (int, EntityPipeline, SuggestionsTable).
		if ( $second instanceof EntityPipeline ) {
			self::run_single_post( $post_id, $second, $third );
			return;
		}

		$bulk_run_id = is_int( $second ) ? $second : 0;

		if ( $bulk_run_id > 0 ) {
			BulkAwareProcessor::process( $post_id, $bulk_run_id, $third, $fourth, $fifth );
			return;
		}

		// Accept optional pipeline / table injection on the 4-arg form too.
		$pipeline = $third instanceof EntityPipeline ? $third : null;
		$table    = $fourth instanceof SuggestionsTable ? $fourth : null;
		self::run_single_post( $post_id, $pipeline, $table );
	}

	private static function run_single_post(
		int               $post_id,
		?EntityPipeline   $pipeline = null,
		?SuggestionsTable $table    = null
	): void {
		$table ??= SuggestionsTable::instance();

		update_post_meta( $post_id, "_navne_job_status", "processing" );
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

			$mode = (string) get_option( "navne_operating_mode", "suggest" );
			if ( $mode === "yolo" ) {
				$high = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence >= 0.75 ) );
				$low  = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence <  0.75 ) );
				if ( ! empty( $high ) ) {
					$table->insert_entities( $post_id, $high, "approved" );
					foreach ( $high as $entity ) {
						$term_id = TermHelper::ensure_term( $entity->name );
						if ( $term_id > 0 ) {
							wp_set_post_terms( $post_id, [ $term_id ], "navne_entity", true );
						}
					}
					wp_cache_delete( "navne_link_map_" . $post_id, "navne" );
				}
				$table->insert_entities( $post_id, $low );
			} else {
				$table->insert_entities( $post_id, $entities );
			}

			update_post_meta( $post_id, "_navne_job_status", "complete" );
		} catch ( \Exception $e ) {
			update_post_meta( $post_id, "_navne_job_status", "failed" );
			error_log( "Navne pipeline failed for post " . $post_id . ": " . $e->getMessage() );
		}
	}
}
```

**Key changes:**
- Old body moved verbatim into `run_single_post`, then simplified to use `TermHelper::ensure_term()` (the race-recovery block is now in one place). This is a behavior-preserving refactor — the existing YOLO tests still mock `wp_insert_term`, `is_wp_error`, and `wp_set_post_terms` the same way.
- `run()` is the new dispatch adapter. It tolerates the old 3-arg test signature for backwards compat and routes positive `bulk_run_id` into `BulkAwareProcessor::process()`.
- `BulkAwareProcessor` is referenced but not yet created. PHP won't fail until the class is actually used — the bulk branch isn't exercised by any existing test, so the full suite still passes after this step. Task 10 creates the real class.

**A subtle test issue:** the existing `test_yolo_auto_approves_high_confidence_entities` test uses `wp_insert_term`, `is_wp_error`, and `wp_set_post_terms` directly on the single-post path. With `TermHelper::ensure_term` now in the middle, those same Brain\\Monkey stubs still fire — no test change needed. But if the YOLO test mocks don't include `error_log`, and `is_wp_error` is stubbed to return `false`, TermHelper won't log anything. Good.

- [ ] **Step 4: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

Expected: all existing tests pass. New adapter tests pass.

Possible failures and fixes:
- **"Class BulkAwareProcessor not found"** — only if some test actually passes a positive int as the second arg. None of the current tests do. If this fails for some other reason, add `class_alias` at the top of `ProcessPostJob.php` or ensure the autoloader can find an empty placeholder — simpler fix is to land Task 10 immediately after this one.
- **YOLO test fails on error_log expectation** — update the YOLO test to add `Functions\when( "error_log" )->justReturn( true );` in its setUp-equivalent section.

- [ ] **Step 5: Commit**

```bash
git add navne/includes/Jobs/ProcessPostJob.php navne/tests/Unit/Jobs/ProcessPostJobTest.php
git commit -m "refactor(jobs): split ProcessPostJob into adapter + run_single_post"
```

---

## Task 10: BulkAwareProcessor — skeleton + cancelled/invalid-post skip branches

**Files:**
- Create: `navne/includes/BulkIndex/BulkAwareProcessor.php`
- Create: `navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php`

This task lands the class and the first two "skip" branches: run already terminal (cancelled or complete) and post no longer valid (missing / wrong status / wrong post type). Mode branches come in Tasks 11–13; exception handling comes in Task 14. TDD cycle per branch.

- [ ] **Step 1: Write failing tests for the skip branches**

Create `navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\BulkAwareProcessor;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\RunItemsRepository;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Storage\SuggestionsTable;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class BulkAwareProcessorTest extends TestCase {
	private function make_post( string $status = "publish", string $type = "post" ): object {
		$p             = new \stdClass();
		$p->ID         = 101;
		$p->post_status = $status;
		$p->post_type  = $type;
		return $p;
	}

	public function test_cancelled_run_marks_item_skipped_and_returns(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "cancelled" ] );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->once() )->method( "update_status" )->with( 42, 101, "skipped" );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->expects( $this->never() )->method( "run" );

		BulkAwareProcessor::process(
			101,
			42,
			$pipeline,
			$this->createMock( SuggestionsTable::class ),
			$runs,
			$items
		);
	}

	public function test_skipped_when_post_missing(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "running" ] );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->once() )->method( "update_status" )->with( 42, 101, "skipped" );

		Functions\when( "get_post" )->justReturn( null );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->expects( $this->never() )->method( "run" );

		BulkAwareProcessor::process(
			101,
			42,
			$pipeline,
			$this->createMock( SuggestionsTable::class ),
			$runs,
			$items
		);
	}

	public function test_skipped_when_post_status_not_publish(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "running" ] );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->once() )->method( "update_status" )->with( 42, 101, "skipped" );

		Functions\when( "get_post" )->justReturn( $this->make_post( "draft" ) );
		Functions\when( "get_option" )->justReturn( [ "post" ] );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->expects( $this->never() )->method( "run" );

		BulkAwareProcessor::process(
			101,
			42,
			$pipeline,
			$this->createMock( SuggestionsTable::class ),
			$runs,
			$items
		);
	}

	public function test_skipped_when_post_type_no_longer_allowed(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "running" ] );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->once() )->method( "update_status" )->with( 42, 101, "skipped" );

		Functions\when( "get_post" )->justReturn( $this->make_post( "publish", "page" ) );
		Functions\when( "get_option" )->justReturn( [ "post" ] );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->expects( $this->never() )->method( "run" );

		BulkAwareProcessor::process(
			101,
			42,
			$pipeline,
			$this->createMock( SuggestionsTable::class ),
			$runs,
			$items
		);
	}
}
```

- [ ] **Step 2: Run tests to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

Expected: class not found.

- [ ] **Step 3: Create `navne/includes/BulkIndex/BulkAwareProcessor.php`**

```php
<?php
namespace Navne\BulkIndex;

use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class BulkAwareProcessor {
	/**
	 * @param int                   $post_id
	 * @param int                   $run_id
	 * @param EntityPipeline|null   $pipeline (optional — test injection)
	 * @param SuggestionsTable|null $table    (optional — test injection)
	 * @param RunsRepository|null   $runs     (optional — test injection)
	 * @param RunItemsRepository|null $items  (optional — test injection)
	 */
	public static function process(
		int                 $post_id,
		int                 $run_id,
		?EntityPipeline     $pipeline = null,
		?SuggestionsTable   $table    = null,
		?RunsRepository     $runs     = null,
		?RunItemsRepository $items    = null
	): void {
		$runs  ??= RunsRepository::instance();
		$items ??= RunItemsRepository::instance();
		$table ??= SuggestionsTable::instance();

		$run = $runs->find_by_id( $run_id );
		if ( $run === null ) {
			return;
		}
		if ( in_array( $run["status"], [ "cancelled", "complete" ], true ) ) {
			$items->update_status( $run_id, $post_id, "skipped" );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== "publish" ) {
			$items->update_status( $run_id, $post_id, "skipped" );
			return;
		}
		$allowed_types = (array) get_option( "navne_post_types", [ "post" ] );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			$items->update_status( $run_id, $post_id, "skipped" );
			return;
		}

		// Tasks 11–14: flip row to processing, run pipeline, apply mode, mark complete/failed.
		// For now the skip branches are enough to make Task 10 tests pass.
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/BulkAwareProcessor.php navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php
git commit -m "feat(bulk): BulkAwareProcessor skip branches (cancelled, missing, wrong type)"
```

---

## Task 11: BulkAwareProcessor — Suggest mode (insert all as pending)

**Files:**
- Modify: `navne/includes/BulkIndex/BulkAwareProcessor.php`
- Modify: `navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php`

- [ ] **Step 1: Write the failing test**

Append to `BulkAwareProcessorTest`:

```php
public function test_suggest_mode_inserts_all_as_pending_and_marks_complete(): void {
	$runs = $this->createMock( RunsRepository::class );
	$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "running" ] );

	$items = $this->createMock( RunItemsRepository::class );
	$items->expects( $this->exactly( 2 ) )
		->method( "update_status" )
		->withConsecutive(
			[ 42, 101, "processing" ],
			[ 42, 101, "complete" ]
		);

	Functions\when( "get_post" )->justReturn( $this->make_post() );
	Functions\when( "get_option" )->justReturn( [ "post" ] );
	Functions\expect( "update_post_meta" )->twice();

	$entities = [ new Entity( "Jane Smith", "person", 0.9 ) ];
	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( $entities );

	$table = $this->createMock( SuggestionsTable::class );
	$table->expects( $this->once() )->method( "insert_entities" )->with( 101, $entities );

	BulkAwareProcessor::process( 101, 42, $pipeline, $table, $runs, $items );
}

public function test_uses_frozen_run_mode_not_current_option(): void {
	$runs = $this->createMock( RunsRepository::class );
	// Run row says suggest. The option will be stubbed to yolo.
	$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "running" ] );

	$items = $this->createMock( RunItemsRepository::class );
	$items->method( "update_status" );

	Functions\when( "get_post" )->justReturn( $this->make_post() );
	Functions\when( "get_option" )->alias( function ( $key, $default = null ) {
		if ( $key === "navne_operating_mode" ) return "yolo";
		if ( $key === "navne_post_types"     ) return [ "post" ];
		return $default;
	} );
	Functions\expect( "update_post_meta" )->twice();

	$entities = [ new Entity( "Jane Smith", "person", 0.9 ) ];
	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( $entities );

	$table = $this->createMock( SuggestionsTable::class );
	// Suggest mode: one insert_entities call with default status. No wp_insert_term.
	$table->expects( $this->once() )->method( "insert_entities" )->with( 101, $entities );

	Functions\expect( "wp_insert_term" )->never();

	BulkAwareProcessor::process( 101, 42, $pipeline, $table, $runs, $items );
}
```

- [ ] **Step 2: Run tests to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

Expected: 2 new tests fail (no pipeline execution yet in the class).

- [ ] **Step 3: Extend `BulkAwareProcessor::process()`**

Replace the stubbed "Tasks 11–14" comment block at the end of `process()` with:

```php
		$items->update_status( $run_id, $post_id, "processing" );
		update_post_meta( $post_id, "_navne_job_status", "processing" );

		$pipeline ??= Plugin::make_pipeline();
		$entities  = $pipeline->run( $post_id );

		self::apply_mode( (string) $run["mode"], $post_id, $entities, $table );

		$items->update_status( $run_id, $post_id, "complete" );
		update_post_meta( $post_id, "_navne_job_status", "complete" );
	}

	private static function apply_mode( string $mode, int $post_id, array $entities, SuggestionsTable $table ): void {
		switch ( $mode ) {
			case "safe":
				// Task 13
				break;
			case "yolo":
				// Task 12
				break;
			case "suggest":
			default:
				$table->insert_entities( $post_id, $entities );
				break;
		}
	}
```

Remove the trailing `}` note that was there before — the new code block above ends the `process()` method and adds a private helper.

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

Expected: 6 tests pass (4 from Task 10 + 2 new).

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/BulkAwareProcessor.php navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php
git commit -m "feat(bulk): BulkAwareProcessor Suggest mode + frozen run.mode"
```

---

## Task 12: BulkAwareProcessor — YOLO mode

**Files:**
- Modify: `navne/includes/BulkIndex/BulkAwareProcessor.php`
- Modify: `navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php`

- [ ] **Step 1: Write the failing test**

Append to `BulkAwareProcessorTest`:

```php
public function test_yolo_mode_auto_approves_high_confidence_and_pends_low(): void {
	$runs = $this->createMock( RunsRepository::class );
	$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "yolo", "status" => "running" ] );

	$items = $this->createMock( RunItemsRepository::class );
	$items->method( "update_status" );

	Functions\when( "get_post" )->justReturn( $this->make_post() );
	Functions\when( "get_option" )->justReturn( [ "post" ] );
	Functions\expect( "update_post_meta" )->twice();

	$high     = new Entity( "Jane Smith", "person", 0.9 );
	$low      = new Entity( "NATO",       "org",    0.6 );
	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( [ $high, $low ] );

	$table = $this->createMock( SuggestionsTable::class );
	$table->expects( $this->exactly( 2 ) )
		->method( "insert_entities" )
		->withConsecutive(
			[ 101, [ $high ], "approved" ],
			[ 101, [ $low  ] ]
		);

	Functions\when( "wp_insert_term" )->justReturn( [ "term_id" => 7 ] );
	Functions\when( "is_wp_error" )->justReturn( false );
	Functions\expect( "wp_set_post_terms" )->once()->with( 101, [ 7 ], "navne_entity", true );
	Functions\expect( "wp_cache_delete" )->once()->with( "navne_link_map_101", "navne" );

	BulkAwareProcessor::process( 101, 42, $pipeline, $table, $runs, $items );
}
```

- [ ] **Step 2: Run tests to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

- [ ] **Step 3: Implement the YOLO branch**

Replace the `case "yolo":` line in `apply_mode()` with:

```php
			case "yolo":
				$high = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence >= 0.75 ) );
				$low  = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence <  0.75 ) );
				if ( ! empty( $high ) ) {
					$table->insert_entities( $post_id, $high, "approved" );
					foreach ( $high as $entity ) {
						$term_id = TermHelper::ensure_term( $entity->name );
						if ( $term_id > 0 ) {
							wp_set_post_terms( $post_id, [ $term_id ], "navne_entity", true );
						}
					}
					wp_cache_delete( "navne_link_map_" . $post_id, "navne" );
				}
				$table->insert_entities( $post_id, $low );
				break;
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/BulkAwareProcessor.php navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php
git commit -m "feat(bulk): BulkAwareProcessor YOLO mode via TermHelper"
```

---

## Task 13: BulkAwareProcessor — Safe mode (whitelist filter)

**Files:**
- Modify: `navne/includes/BulkIndex/BulkAwareProcessor.php`
- Modify: `navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `BulkAwareProcessorTest`:

```php
public function test_safe_mode_keeps_only_whitelist_matched_entities(): void {
	\Navne\BulkIndex\Whitelist::reset();
	Functions\when( "get_terms" )->justReturn( [ "Jane Smith" ] );
	Functions\when( "is_wp_error" )->justReturn( false );

	$runs = $this->createMock( RunsRepository::class );
	$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "safe", "status" => "running" ] );

	$items = $this->createMock( RunItemsRepository::class );
	$items->method( "update_status" );

	Functions\when( "get_post" )->justReturn( $this->make_post() );
	Functions\when( "get_option" )->justReturn( [ "post" ] );
	Functions\expect( "update_post_meta" )->twice();

	$matched   = new Entity( "Jane Smith", "person", 0.9 );
	$unmatched = new Entity( "Bob Jones",  "person", 0.95 );

	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( [ $matched, $unmatched ] );

	$table = $this->createMock( SuggestionsTable::class );
	$table->expects( $this->once() )
		->method( "insert_entities" )
		->with( 101, [ $matched ], "approved" );

	Functions\when( "wp_insert_term" )->justReturn( [ "term_id" => 7 ] );
	Functions\expect( "wp_set_post_terms" )->once()->with( 101, [ 7 ], "navne_entity", true );
	Functions\expect( "wp_cache_delete" )->once();

	BulkAwareProcessor::process( 101, 42, $pipeline, $table, $runs, $items );
}

public function test_safe_mode_empty_whitelist_is_noop(): void {
	\Navne\BulkIndex\Whitelist::reset();
	Functions\when( "get_terms" )->justReturn( [] );
	Functions\when( "is_wp_error" )->justReturn( false );

	$runs = $this->createMock( RunsRepository::class );
	$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "safe", "status" => "running" ] );

	$items = $this->createMock( RunItemsRepository::class );
	$items->method( "update_status" );

	Functions\when( "get_post" )->justReturn( $this->make_post() );
	Functions\when( "get_option" )->justReturn( [ "post" ] );
	Functions\expect( "update_post_meta" )->twice();

	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willReturn( [
		new Entity( "Jane Smith", "person", 0.9 ),
		new Entity( "Bob Jones",  "person", 0.95 ),
	] );

	$table = $this->createMock( SuggestionsTable::class );
	$table->expects( $this->never() )->method( "insert_entities" );

	Functions\expect( "wp_insert_term" )->never();

	BulkAwareProcessor::process( 101, 42, $pipeline, $table, $runs, $items );
}
```

- [ ] **Step 2: Run tests to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

- [ ] **Step 3: Implement the Safe branch**

Replace the `case "safe":` line in `apply_mode()` with:

```php
			case "safe":
				$whitelist = Whitelist::current();
				$matched   = array_values( array_filter(
					$entities,
					fn( Entity $e ) => $whitelist->contains( $e->name )
				) );
				if ( empty( $matched ) ) {
					break;
				}
				$table->insert_entities( $post_id, $matched, "approved" );
				foreach ( $matched as $entity ) {
					$term_id = TermHelper::ensure_term( $entity->name );
					if ( $term_id > 0 ) {
						wp_set_post_terms( $post_id, [ $term_id ], "navne_entity", true );
					}
				}
				wp_cache_delete( "navne_link_map_" . $post_id, "navne" );
				break;
```

Add `use Navne\BulkIndex\Whitelist;` and `use Navne\BulkIndex\TermHelper;` at the top of the file if not already present.

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/BulkAwareProcessor.php navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php
git commit -m "feat(bulk): BulkAwareProcessor Safe mode with whitelist filter"
```

---

## Task 14: BulkAwareProcessor — exception handling with truncated error

**Files:**
- Modify: `navne/includes/BulkIndex/BulkAwareProcessor.php`
- Modify: `navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php`

- [ ] **Step 1: Write the failing test**

Append:

```php
public function test_pipeline_exception_marks_item_failed_with_truncated_message(): void {
	$long = str_repeat( "x", 1000 );

	$runs = $this->createMock( RunsRepository::class );
	$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "mode" => "suggest", "status" => "running" ] );

	$items = $this->createMock( RunItemsRepository::class );
	$items->expects( $this->exactly( 2 ) )
		->method( "update_status" )
		->withConsecutive(
			[ 42, 101, "processing" ],
			[ 42, 101, "failed", $this->callback( function ( $msg ) {
				return is_string( $msg ) && strlen( $msg ) === 500;
			} ) ]
		);

	Functions\when( "get_post" )->justReturn( $this->make_post() );
	Functions\when( "get_option" )->justReturn( [ "post" ] );
	Functions\expect( "update_post_meta" )->twice();
	Functions\when( "error_log" )->justReturn( true );

	$pipeline = $this->createMock( EntityPipeline::class );
	$pipeline->method( "run" )->willThrowException( new \RuntimeException( $long ) );

	BulkAwareProcessor::process(
		101,
		42,
		$pipeline,
		$this->createMock( SuggestionsTable::class ),
		$runs,
		$items
	);
}
```

- [ ] **Step 2: Run test to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

- [ ] **Step 3: Wrap the work in try/catch**

Replace the post-state-validated section of `process()` (the `$items->update_status(..., "processing")` line through the final `update_post_meta(..., "complete")`) with:

```php
		$items->update_status( $run_id, $post_id, "processing" );
		update_post_meta( $post_id, "_navne_job_status", "processing" );

		try {
			$pipeline ??= Plugin::make_pipeline();
			$entities  = $pipeline->run( $post_id );
			self::apply_mode( (string) $run["mode"], $post_id, $entities, $table );

			$items->update_status( $run_id, $post_id, "complete" );
			update_post_meta( $post_id, "_navne_job_status", "complete" );
		} catch ( \Exception $e ) {
			$error = substr( $e->getMessage(), 0, Config::max_error_len() );
			$items->update_status( $run_id, $post_id, "failed", $error );
			update_post_meta( $post_id, "_navne_job_status", "failed" );
			error_log( "Navne bulk pipeline failed for post " . $post_id . ": " . $error );
		}
```

Add `use Navne\BulkIndex\Config;` at the top of the file.

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd navne && vendor/bin/phpunit --filter BulkAwareProcessorTest
```

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/BulkAwareProcessor.php navne/tests/Unit/BulkIndex/BulkAwareProcessorTest.php
git commit -m "feat(bulk): BulkAwareProcessor catches exceptions with truncated errors"
```

---

## Task 15: Dispatcher — wave loop with claim + reschedule

**Files:**
- Create: `navne/includes/BulkIndex/Dispatcher.php`
- Create: `navne/tests/Unit/BulkIndex/DispatcherTest.php`

- [ ] **Step 1: Write the failing tests**

Create `navne/tests/Unit/BulkIndex/DispatcherTest.php`:

```php
<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\Dispatcher;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\RunItemsRepository;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class DispatcherTest extends TestCase {
	public function test_exits_when_run_missing(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( null );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->never() )->method( "claim_queued" );

		Functions\expect( "as_enqueue_async_action" )->never();
		Functions\expect( "as_schedule_single_action" )->never();

		Dispatcher::run( 42, $runs, $items );
	}

	public function test_exits_when_run_cancelled(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "status" => "cancelled", "total" => 10 ] );
		$runs->expects( $this->never() )->method( "update_status" );

		$items = $this->createMock( RunItemsRepository::class );
		$items->expects( $this->never() )->method( "claim_queued" );

		Functions\expect( "as_enqueue_async_action" )->never();
		Functions\expect( "as_schedule_single_action" )->never();

		Dispatcher::run( 42, $runs, $items );
	}

	public function test_flips_pending_run_to_running_on_first_wave(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "status" => "pending", "total" => 10 ] );
		$runs->expects( $this->once() )->method( "update_status" )->with( 42, "running" );
		$runs->expects( $this->once() )->method( "update_counts" );

		$items = $this->createMock( RunItemsRepository::class );
		$items->method( "count_terminal_for_run" )->willReturn( [ "processed" => 0, "failed" => 0 ] );
		$items->method( "claim_queued" )->willReturn( [ 101, 102 ] );

		Functions\expect( "as_enqueue_async_action" )->twice();
		Functions\expect( "as_schedule_single_action" )->once();

		Dispatcher::run( 42, $runs, $items );
	}

	public function test_completes_run_when_no_work_remains(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "status" => "running", "total" => 10 ] );
		$runs->expects( $this->once() )->method( "update_status" )->with( 42, "complete" );
		$runs->expects( $this->once() )->method( "update_counts" )->with( 42, 10, 2 );

		$items = $this->createMock( RunItemsRepository::class );
		$items->method( "count_terminal_for_run" )->willReturn( [ "processed" => 10, "failed" => 2 ] );
		$items->expects( $this->never() )->method( "claim_queued" );

		Functions\expect( "as_enqueue_async_action" )->never();
		Functions\expect( "as_schedule_single_action" )->never();

		Dispatcher::run( 42, $runs, $items );
	}

	public function test_enqueues_claimed_items_and_reschedules(): void {
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "status" => "running", "total" => 10 ] );
		$runs->method( "update_counts" );

		$items = $this->createMock( RunItemsRepository::class );
		$items->method( "count_terminal_for_run" )->willReturn( [ "processed" => 3, "failed" => 0 ] );
		$items->expects( $this->once() )
			->method( "claim_queued" )
			->with( 42, 5 )
			->willReturn( [ 201, 202 ] );

		$enqueued = [];
		Functions\when( "as_enqueue_async_action" )->alias( function ( $hook, $args ) use ( &$enqueued ) {
			$enqueued[] = $args;
		} );
		Functions\expect( "as_schedule_single_action" )->once();

		Dispatcher::run( 42, $runs, $items );

		$this->assertSame(
			[
				[ "post_id" => 201, "bulk_run_id" => 42 ],
				[ "post_id" => 202, "bulk_run_id" => 42 ],
			],
			$enqueued
		);
	}

	public function test_no_reschedule_when_claim_returns_empty(): void {
		// Work remains (processed < total) but nothing is currently queued —
		// the dispatcher should still reschedule so processing items can finish
		// and progress can reach terminal.
		$runs = $this->createMock( RunsRepository::class );
		$runs->method( "find_by_id" )->willReturn( [ "id" => 42, "status" => "running", "total" => 10 ] );
		$runs->method( "update_counts" );

		$items = $this->createMock( RunItemsRepository::class );
		$items->method( "count_terminal_for_run" )->willReturn( [ "processed" => 5, "failed" => 0 ] );
		$items->method( "claim_queued" )->willReturn( [] );

		Functions\expect( "as_enqueue_async_action" )->never();
		Functions\expect( "as_schedule_single_action" )->once();

		Dispatcher::run( 42, $runs, $items );
	}
}
```

- [ ] **Step 2: Run tests to verify failure**

```bash
cd navne && vendor/bin/phpunit --filter DispatcherTest
```

- [ ] **Step 3: Create `navne/includes/BulkIndex/Dispatcher.php`**

```php
<?php
namespace Navne\BulkIndex;

class Dispatcher {
	public static function run(
		int                 $run_id,
		?RunsRepository     $runs  = null,
		?RunItemsRepository $items = null
	): void {
		$runs  ??= RunsRepository::instance();
		$items ??= RunItemsRepository::instance();

		$run = $runs->find_by_id( $run_id );
		if ( $run === null ) {
			return;
		}
		if ( in_array( $run["status"], [ "cancelled", "complete" ], true ) ) {
			return;
		}

		if ( $run["status"] === "pending" ) {
			$runs->update_status( $run_id, "running" );
		}

		$counts = $items->count_terminal_for_run( $run_id );
		$runs->update_counts( $run_id, $counts["processed"], $counts["failed"] );

		if ( $counts["processed"] >= (int) $run["total"] ) {
			$runs->update_status( $run_id, "complete" );
			return;
		}

		$claimed = $items->claim_queued( $run_id, Config::batch_size() );
		foreach ( $claimed as $post_id ) {
			as_enqueue_async_action( "navne_process_post", [
				"post_id"     => (int) $post_id,
				"bulk_run_id" => $run_id,
			] );
		}

		as_schedule_single_action(
			time() + Config::batch_interval(),
			"navne_bulk_dispatch",
			[ "run_id" => $run_id ]
		);
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd navne && vendor/bin/phpunit --filter DispatcherTest
```

Expected: 6 tests pass.

- [ ] **Step 5: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add navne/includes/BulkIndex/Dispatcher.php navne/tests/Unit/BulkIndex/DispatcherTest.php
git commit -m "feat(bulk): Dispatcher wave loop with claim + reschedule"
```

---

## Task 16: Wire dispatcher hook into Plugin::init

**Files:**
- Modify: `navne/includes/Plugin.php`

- [ ] **Step 1: Add the action registration**

In `navne/includes/Plugin.php::init()`, add below the existing `navne_process_post` registration:

```php
		add_action( "navne_bulk_dispatch", [ \Navne\BulkIndex\Dispatcher::class, "run" ] );
```

- [ ] **Step 2: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

Expected: all pass (no test change; this is a wiring edit).

- [ ] **Step 3: Commit**

```bash
git add navne/includes/Plugin.php
git commit -m "feat(bulk): wire navne_bulk_dispatch action into Plugin::init"
```

---

## Task 17: IndexingPage — menu registration, form render, preview handler

**Files:**
- Create: `navne/includes/Admin/IndexingPage.php`
- Modify: `navne/includes/Plugin.php`

No unit tests — this is WP admin rendering, covered by the smoke plan. Follow the same pattern `SettingsPage` uses.

- [ ] **Step 1: Create `navne/includes/Admin/IndexingPage.php`**

```php
<?php
namespace Navne\Admin;

use Navne\BulkIndex\Config;
use Navne\BulkIndex\RunFactory;
use Navne\BulkIndex\RunItemsRepository;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\ScopeQuery;

class IndexingPage {
	public static function register_hooks(): void {
		add_action( "admin_menu", [ self::class, "add_page" ] );
		add_action( "admin_post_navne_indexing_preview", [ self::class, "handle_preview" ] );
		add_action( "admin_post_navne_indexing_start",   [ self::class, "handle_start" ] );
		add_action( "admin_post_navne_indexing_cancel",  [ self::class, "handle_cancel" ] );
		add_action( "admin_post_navne_indexing_retry",   [ self::class, "handle_retry" ] );
	}

	public static function add_page(): void {
		add_management_page(
			"Navne Indexing",
			"Navne Indexing",
			"manage_options",
			"navne-indexing",
			[ self::class, "render" ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id = isset( $_GET["run"] ) ? (int) $_GET["run"] : 0;

		echo '<div class="wrap"><h1>Navne Indexing</h1>';

		if ( $run_id > 0 ) {
			self::render_run_detail( $run_id );
		} else {
			self::render_form();
			self::render_history();
		}

		echo '</div>';
	}

	private static function render_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$preview = isset( $_GET["preview"] ) ? sanitize_key( $_GET["preview"] ) : "";
		$count   = isset( $_GET["count"] )   ? (int) $_GET["count"] : 0;
		$run_type_prefill = isset( $_GET["rt"] ) ? sanitize_key( $_GET["rt"] ) : "index_new";
		$mode_prefill     = isset( $_GET["md"] ) ? sanitize_key( $_GET["md"] ) : "suggest";
		$date_from_pre    = isset( $_GET["df"] ) ? sanitize_text_field( $_GET["df"] ) : "";
		$date_to_pre      = isset( $_GET["dt"] ) ? sanitize_text_field( $_GET["dt"] ) : "";
		// phpcs:enable

		$action_url = esc_url( admin_url( "admin-post.php" ) );
		?>
		<form method="post" action="<?php echo $action_url; ?>">
			<?php wp_nonce_field( "navne_indexing" ); ?>
			<input type="hidden" name="action" value="navne_indexing_preview" />

			<h2>Run type</h2>
			<fieldset>
				<label><input type="radio" name="run_type" value="index_new" <?php checked( $run_type_prefill, "index_new" ); ?> /> Index new (only posts that have never been processed)</label><br>
				<label><input type="radio" name="run_type" value="reindex_all" <?php checked( $run_type_prefill, "reindex_all" ); ?> /> Re-index all (every matching post, even if already processed)</label>
			</fieldset>

			<h2>Mode for this run</h2>
			<fieldset>
				<label><input type="radio" name="mode" value="safe" <?php checked( $mode_prefill, "safe" ); ?> /> Safe — only entities already on the whitelist get tagged; unmatched are dropped</label><br>
				<label><input type="radio" name="mode" value="suggest" <?php checked( $mode_prefill, "suggest" ); ?> /> Suggest — every detected entity becomes a pending suggestion</label><br>
				<label><input type="radio" name="mode" value="yolo" <?php checked( $mode_prefill, "yolo" ); ?> /> YOLO — entities at ≥ 0.75 confidence are auto-approved and linked</label>
			</fieldset>

			<h2>Date range (optional)</h2>
			<p>
				<label>From <input type="date" name="date_from" value="<?php echo esc_attr( $date_from_pre ); ?>" /></label>
				&nbsp;
				<label>To <input type="date" name="date_to" value="<?php echo esc_attr( $date_to_pre ); ?>" /></label>
			</p>

			<?php submit_button( "Preview", "secondary" ); ?>
		</form>

		<?php if ( $preview === "ok" && $count >= 0 ) : ?>
			<hr>
			<h2>Preview</h2>
			<p><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> posts match your scope.</p>
			<?php if ( $count > 0 ) : ?>
				<?php
				$avg = Config::avg_cost_per_article();
				$total = $avg * $count;
				?>
				<p>Rough cost: average article ≈ $<?php echo esc_html( number_format( $avg, 3 ) ); ?> to process. <?php echo esc_html( number_format_i18n( $count ) ); ?> posts ≈ $<?php echo esc_html( number_format( $total, 2 ) ); ?>.</p>
				<?php
				$whitelist_warning = "";
				if ( $mode_prefill === "safe" ) {
					$terms = get_terms( [ "taxonomy" => "navne_entity", "hide_empty" => false, "number" => 1 ] );
					if ( ! is_wp_error( $terms ) && empty( $terms ) ) {
						$whitelist_warning = "Your whitelist is empty. A Safe mode run will process posts but create no tags.";
					}
				}
				if ( $whitelist_warning !== "" ) : ?>
					<p class="notice notice-warning" style="padding:8px 12px;"><?php echo esc_html( $whitelist_warning ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo $action_url; ?>">
					<?php wp_nonce_field( "navne_indexing" ); ?>
					<input type="hidden" name="action" value="navne_indexing_start" />
					<input type="hidden" name="run_type"  value="<?php echo esc_attr( $run_type_prefill ); ?>" />
					<input type="hidden" name="mode"      value="<?php echo esc_attr( $mode_prefill ); ?>" />
					<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from_pre ); ?>" />
					<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to_pre ); ?>" />
					<?php submit_button( "Run this indexing job", "primary" ); ?>
				</form>
			<?php endif; ?>
		<?php endif;
	}

	private static function render_history(): void {
		$runs = RunsRepository::instance()->find_recent( Config::history_limit() );
		if ( empty( $runs ) ) {
			return;
		}
		echo "<hr><h2>Recent runs</h2><ul>";
		foreach ( $runs as $row ) {
			$url = esc_url( admin_url( "tools.php?page=navne-indexing&run=" . (int) $row["id"] ) );
			printf(
				'<li>#%d · %s · %s · %s · %d/%d — <a href="%s">details</a></li>',
				(int) $row["id"],
				esc_html( $row["created_at"] ),
				esc_html( $row["run_type"] ),
				esc_html( $row["status"] ),
				(int) $row["processed"],
				(int) $row["total"],
				$url
			);
		}
		echo "</ul>";
	}

	private static function render_run_detail( int $run_id ): void {
		$run = RunsRepository::instance()->find_by_id( $run_id );
		if ( $run === null ) {
			echo '<p>Run not found. <a href="' . esc_url( admin_url( "tools.php?page=navne-indexing" ) ) . '">Back</a></p>';
			return;
		}
		$back = esc_url( admin_url( "tools.php?page=navne-indexing" ) );
		?>
		<p><a href="<?php echo $back; ?>">← Back to form</a></p>
		<h2>Run #<?php echo (int) $run["id"]; ?></h2>
		<p>
			Started: <?php echo esc_html( $run["created_at"] ); ?> ·
			Type: <?php echo esc_html( $run["run_type"] ); ?> ·
			Mode: <?php echo esc_html( $run["mode"] ); ?>
		</p>
		<div id="navne-run-detail"
			 data-run-id="<?php echo (int) $run["id"]; ?>"
			 data-rest-url="<?php echo esc_url( rest_url( "navne/v1/bulk-runs/" . (int) $run["id"] ) ); ?>"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( "wp_rest" ) ); ?>">
			<p>
				<span class="navne-run-status"><?php echo esc_html( $run["status"] ); ?></span> ·
				<span class="navne-run-counts"><?php echo (int) $run["processed"]; ?> / <?php echo (int) $run["total"]; ?> processed · <?php echo (int) $run["failed"]; ?> failed</span>
			</p>
			<?php if ( in_array( $run["status"], [ "pending", "running" ], true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="navne-run-cancel">
					<?php wp_nonce_field( "navne_indexing_cancel" ); ?>
					<input type="hidden" name="action"  value="navne_indexing_cancel" />
					<input type="hidden" name="run_id"  value="<?php echo (int) $run["id"]; ?>" />
					<?php submit_button( "Cancel this run", "delete" ); ?>
				</form>
			<?php endif; ?>
			<?php if ( $run["status"] === "complete" && (int) $run["failed"] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>">
					<?php wp_nonce_field( "navne_indexing" ); ?>
					<input type="hidden" name="action"         value="navne_indexing_retry" />
					<input type="hidden" name="parent_run_id"  value="<?php echo (int) $run["id"]; ?>" />
					<input type="hidden" name="mode"           value="<?php echo esc_attr( $run["mode"] ); ?>" />
					<?php submit_button( "Retry failed posts from this run", "secondary" ); ?>
				</form>
			<?php endif; ?>
			<h3>Failed posts</h3>
			<ul class="navne-run-failed"></ul>
		</div>
		<?php
		wp_enqueue_script(
			"navne-indexing",
			NAVNE_PLUGIN_URL . "assets/js/indexing.js",
			[],
			NAVNE_VERSION,
			true
		);
	}

	// ------------- handlers -------------

	public static function handle_preview(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing" );

		$run_type  = sanitize_key( $_POST["run_type"] ?? "" );
		$mode      = sanitize_key( $_POST["mode"] ?? "" );
		$date_from = self::normalize_date_input( $_POST["date_from"] ?? "" );
		$date_to   = self::normalize_date_input( $_POST["date_to"] ?? "" );

		$scope = new ScopeQuery( RunItemsRepository::instance() );
		$ids   = $scope->matching_post_ids( $run_type, $date_from, $date_to, null );

		$redirect = add_query_arg(
			[
				"page"    => "navne-indexing",
				"preview" => "ok",
				"count"   => count( $ids ),
				"rt"      => $run_type,
				"md"      => $mode,
				"df"      => $date_from,
				"dt"      => $date_to,
			],
			admin_url( "tools.php" )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_start(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing" );

		$factory = new RunFactory(
			RunsRepository::instance(),
			RunItemsRepository::instance(),
			new ScopeQuery( RunItemsRepository::instance() )
		);

		try {
			$run_id = $factory->create( [
				"run_type"      => sanitize_key( $_POST["run_type"] ?? "" ),
				"mode"          => sanitize_key( $_POST["mode"] ?? "" ),
				"date_from"     => self::normalize_date_input( $_POST["date_from"] ?? "" ),
				"date_to"       => self::normalize_date_input( $_POST["date_to"] ?? "" ),
				"parent_run_id" => null,
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&error=invalid" ) );
			exit;
		}

		wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&run=" . $run_id ) );
		exit;
	}

	public static function handle_cancel(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing_cancel" );

		$run_id = (int) ( $_POST["run_id"] ?? 0 );
		if ( $run_id > 0 ) {
			$repo = RunsRepository::instance();
			$run  = $repo->find_by_id( $run_id );
			if ( $run && in_array( $run["status"], [ "pending", "running" ], true ) ) {
				$repo->update_status( $run_id, "cancelled" );
			}
		}

		wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&run=" . $run_id ) );
		exit;
	}

	public static function handle_retry(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing" );

		$parent_run_id = (int) ( $_POST["parent_run_id"] ?? 0 );
		$mode          = sanitize_key( $_POST["mode"] ?? "suggest" );

		$factory = new RunFactory(
			RunsRepository::instance(),
			RunItemsRepository::instance(),
			new ScopeQuery( RunItemsRepository::instance() )
		);

		try {
			$run_id = $factory->create( [
				"run_type"      => "retry_failed",
				"mode"          => $mode,
				"date_from"     => null,
				"date_to"       => null,
				"parent_run_id" => $parent_run_id,
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&error=invalid" ) );
			exit;
		}

		wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&run=" . $run_id ) );
		exit;
	}

	private static function normalize_date_input( $raw ): ?string {
		$raw = is_string( $raw ) ? sanitize_text_field( $raw ) : "";
		if ( $raw === "" ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( "Y-m-d", $raw );
		return ( $dt && $dt->format( "Y-m-d" ) === $raw ) ? $raw : null;
	}
}
```

- [ ] **Step 2: Wire the page into `Plugin::init()`**

In `navne/includes/Plugin.php::init()`, add below `SettingsPage::register_hooks();`:

```php
		\Navne\Admin\IndexingPage::register_hooks();
```

- [ ] **Step 3: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

Expected: all pass (no new tests; admin page is not unit-tested).

- [ ] **Step 4: Commit**

```bash
git add navne/includes/Admin/IndexingPage.php navne/includes/Plugin.php
git commit -m "feat(bulk): Tools → Navne Indexing admin page with preview + start + cancel + retry"
```

---

## Task 18: REST BulkRunsController — GET endpoints

**Files:**
- Create: `navne/includes/Api/BulkRunsController.php`
- Modify: `navne/includes/Plugin.php`

- [ ] **Step 1: Create `navne/includes/Api/BulkRunsController.php`**

```php
<?php
namespace Navne\Api;

use Navne\BulkIndex\Config;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\RunItemsRepository;

class BulkRunsController {
	public function __construct(
		private ?RunsRepository     $runs  = null,
		private ?RunItemsRepository $items = null
	) {
		$this->runs  ??= RunsRepository::instance();
		$this->items ??= RunItemsRepository::instance();
	}

	public function register_routes_on_init(): void {
		add_action( "rest_api_init", [ $this, "register_routes" ] );
	}

	public function register_routes(): void {
		register_rest_route( "navne/v1", "/bulk-runs", [
			"methods"             => "GET",
			"callback"            => [ $this, "list_runs" ],
			"permission_callback" => [ $this, "check_permission" ],
		] );
		register_rest_route( "navne/v1", "/bulk-runs/(?P<id>\\d+)", [
			"methods"             => "GET",
			"callback"            => [ $this, "get_run" ],
			"permission_callback" => [ $this, "check_permission" ],
		] );
		register_rest_route( "navne/v1", "/bulk-runs/(?P<id>\\d+)/failed-items", [
			"methods"             => "GET",
			"callback"            => [ $this, "list_failed_items" ],
			"permission_callback" => [ $this, "check_permission" ],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( "manage_options" );
	}

	public function list_runs(): \WP_REST_Response {
		return new \WP_REST_Response( $this->runs->find_recent( Config::history_limit() ) );
	}

	public function get_run( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request->get_param( "id" );
		$run = $this->runs->find_by_id( $id );
		if ( $run === null ) {
			return new \WP_REST_Response( [ "error" => "not_found" ], 404 );
		}

		$failed = $this->items->find_failed_for_run( $id, 100 );
		$failed = array_map( function ( $row ) {
			return [
				"post_id"       => (int) $row["post_id"],
				"post_title"    => get_the_title( (int) $row["post_id"] ),
				"error_message" => (string) ( $row["error_message"] ?? "" ),
			];
		}, $failed );

		$run["failed_items"] = $failed;
		return new \WP_REST_Response( $run );
	}

	public function list_failed_items( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request->get_param( "id" );
		$limit = max( 1, min( 500, (int) ( $request->get_param( "per_page" ) ?? 100 ) ) );
		$rows  = $this->items->find_failed_for_run( $id, $limit );
		return new \WP_REST_Response( $rows );
	}
}
```

- [ ] **Step 2: Wire into Plugin::init()**

In `navne/includes/Plugin.php::init()`, add below the existing `SuggestionsController` wiring:

```php
		( new \Navne\Api\BulkRunsController() )->register_routes_on_init();
```

- [ ] **Step 3: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 4: Commit**

```bash
git add navne/includes/Api/BulkRunsController.php navne/includes/Plugin.php
git commit -m "feat(bulk): REST BulkRunsController for list + detail + failed items"
```

---

## Task 19: Run detail polling JS

**Files:**
- Create: `navne/assets/js/indexing.js`

No unit test — vanilla browser JS, covered by smoke test.

- [ ] **Step 1: Create `navne/assets/js/indexing.js`**

```js
(function () {
	var root = document.getElementById("navne-run-detail");
	if (!root) {
		return;
	}

	var restUrl = root.getAttribute("data-rest-url");
	var nonce   = root.getAttribute("data-nonce");
	var statusEl = root.querySelector(".navne-run-status");
	var countsEl = root.querySelector(".navne-run-counts");
	var failedEl = root.querySelector(".navne-run-failed");

	var terminalStatuses = ["complete", "cancelled"];
	var pollHandle = null;

	function render(data) {
		statusEl.textContent = data.status;
		countsEl.textContent = data.processed + " / " + data.total + " processed · " + data.failed + " failed";

		failedEl.innerHTML = "";
		(data.failed_items || []).forEach(function (item) {
			var li = document.createElement("li");
			var strong = document.createElement("strong");
			strong.textContent = item.post_title || ("Post " + item.post_id);
			li.appendChild(strong);
			li.appendChild(document.createTextNode(" (ID " + item.post_id + ") — " + (item.error_message || "")));
			failedEl.appendChild(li);
		});

		if (terminalStatuses.indexOf(data.status) !== -1 && pollHandle !== null) {
			clearInterval(pollHandle);
			pollHandle = null;
		}
	}

	function tick() {
		fetch(restUrl, {
			credentials: "same-origin",
			headers: { "X-WP-Nonce": nonce }
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) { if (data) { render(data); } })
			.catch(function () { /* transient errors are fine; next tick retries */ });
	}

	tick();
	pollHandle = setInterval(tick, 2000);
})();
```

- [ ] **Step 2: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

- [ ] **Step 3: Commit**

```bash
git add navne/assets/js/indexing.js
git commit -m "feat(bulk): vanilla JS poller for run detail view"
```

---

## Task 20: Smoke test pass + release prep

**Files:**
- Modify: `navne/navne.php`
- Modify: `navne/package.json`
- Modify: `CHANGELOG.md`
- Create: `docs/releases/v1.4.0.md`

This is the final task: run the smoke checklist end-to-end in a real WordPress environment, then bump the version, write the changelog, and commit.

- [ ] **Step 1: Run the smoke test steps from the spec (`docs/superpowers/specs/2026-04-13-bulk-indexing-design.md` § Smoke test steps)**

Activate the plugin on a site with ~20 test posts and walk through each numbered step. Record any deviations. If any step fails, fix the code (TDD cycle for any regression) and re-run from the top.

- [ ] **Step 2: Run the full suite**

```bash
cd navne && vendor/bin/phpunit
```

Expected: all pass.

- [ ] **Step 3: Run the web-security skill pass**

Per CLAUDE.md, every code change must pass the web-security OWASP Top 10 checklist before completion. The spec's security review section lists the specific items this feature must cover (capability checks, input validation, output escaping, rate limiting, LLM input capping). Walk through that list against the final code.

- [ ] **Step 4: Bump version in all three places**

In `navne/navne.php`:
```php
 * Version:     1.4.0
```
and
```php
define( "NAVNE_VERSION", "1.4.0" );
```

In `navne/package.json`, bump `"version"` to `"1.4.0"`.

- [ ] **Step 5: Update `CHANGELOG.md`**

Rename the current `[Unreleased]` section to `[1.4.0] - 2026-04-13` (or the actual ship date — use today's date), add the feature entry, and open a new empty `[Unreleased]` block above it. Add a `[Full release notes](docs/releases/v1.4.0.md)` link below the version heading.

Changelog entry content:

```markdown
## [1.4.0] - 2026-04-13

[Full release notes](docs/releases/v1.4.0.md)

### Added

- Tools → Navne Indexing admin page for bulk entity processing
- Three run types: index new, re-index all, retry failed
- Per-run mode picker (Safe / Suggest / YOLO) that doesn't require changing global operating mode
- Optional date-range scope filter
- Live progress view with cancel
- First-class run history (last 10 runs) and REST API for run details
- Safe mode whitelist enforcement — runs pipeline but tags only entities already on `navne_entity`, dropping unmatched silently

### Changed

- `ProcessPostJob::run()` split into adapter + `run_single_post()` to support the bulk dispatch path
- Term creation race recovery extracted into `TermHelper::ensure_term()` — no behavior change
```

- [ ] **Step 6: Write `docs/releases/v1.4.0.md`**

Use the `writing-style` skill. Header format:

```markdown
# Navne v1.4.0

**Released:** 2026-04-13 · [Technical changelog](../../CHANGELOG.md#140---2026-04-13)

---
```

Cover: what bulk indexing is, why it matters for newsrooms, the three run types, per-run mode picker (especially that Safe mode finally does what the init notes always promised — whitelist enforcement), the dispatcher's pacing story (so admins understand cost behavior), what's deliberately deferred. Narrative, not a feature list.

- [ ] **Step 7: Commit release prep**

```bash
git add navne/navne.php navne/package.json CHANGELOG.md docs/releases/v1.4.0.md
git commit -m "chore: release v1.4.0"
```

- [ ] **Step 8: Tag and push (coordinate with the user — ship decisions belong to the human)**

Per CLAUDE.md Git Mode A (Manual), confirm with the user before any tag / push / release create step. Don't run `git tag`, `git push`, or `gh release create` without explicit instruction.

---

## Spec coverage check

- Spec § Overview → Tasks 1–19 collectively
- Spec § Run lifecycle → Tasks 2 (runs repo), 3 (items repo), 15 (dispatcher), 10–14 (processor)
- Spec § Scope filters → Task 7 (ScopeQuery), Task 8 (RunFactory validates run_type/mode)
- Spec § Admin UI form view / preview / run detail / history → Task 17
- Spec § Admin UI security → Task 17 (manage_options + nonces on every handler)
- Spec § Empty whitelist warning → Task 17 (inline in `render_form`)
- Spec § Data model — `wp_navne_bulk_runs` → Task 2
- Spec § Data model — `wp_navne_bulk_run_items` → Task 3
- Spec § Schema creation → Task 4
- Spec § Options & constants → Task 1
- Spec § Backend new classes → Tasks 1–15 (one task per class)
- Spec § Scope query → Task 7
- Spec § Run creation → Task 8
- Spec § Dispatcher → Task 15
- Spec § BulkAwareProcessor → Tasks 10 (skip branches + skeleton), 11 (Suggest), 12 (YOLO), 13 (Safe), 14 (exception handling)
- Spec § ProcessPostJob adapter + `run_single_post` refactor → Task 9
- Spec § Term-race handling via extracted `ensure_term` → Task 6
- Spec § Cancellation → Task 15 (dispatcher status check) + Task 17 (`handle_cancel`)
- Spec § Failure modes table → Task 14 (per-item), Task 15 (dispatcher missing run)
- Spec § REST API → Task 18
- Spec § Polling JS → Task 19
- Spec § Testing — unit tests → Tasks 1–15 (TDD cycles)
- Spec § Testing — smoke test → Task 20
- Spec § Security review → Task 20 step 3
- Spec § Out of scope → Not implemented (by definition)
