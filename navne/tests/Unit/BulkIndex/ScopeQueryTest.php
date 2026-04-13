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
