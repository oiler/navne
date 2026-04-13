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
