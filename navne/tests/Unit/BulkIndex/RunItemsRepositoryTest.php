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
