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

	public function test_reschedules_when_claim_returns_empty(): void {
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
