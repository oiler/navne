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
}
