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
