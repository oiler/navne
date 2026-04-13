<?php
// tests/Unit/BulkIndex/BulkAwareProcessorTest.php
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
	protected function make_post( string $status = "publish", string $type = "post" ): object {
		$p              = new \stdClass();
		$p->ID          = 101;
		$p->post_status = $status;
		$p->post_type   = $type;
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
