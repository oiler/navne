<?php
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
		$table->method( 'find_approved_names_for_post' )->willReturn( [] );
		$table->expects( $this->once() )->method( 'insert_entities' )->with( 1, $entities, 'pending' );

		Functions\when( 'get_option' )->justReturn( 'suggest' );
		Functions\expect( 'update_post_meta' )->twice();

		ProcessPostJob::run( 1, $pipeline, $table );
	}

	public function test_run_filters_already_approved_entities(): void {
		$jane = new Entity( 'Jane Smith', 'person', 0.94 );
		$nato = new Entity( 'NATO',       'org',    0.99 );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->method( 'run' )->willReturn( [ $jane, $nato ] );

		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_approved_names_for_post' )->willReturn( [ 'jane smith' ] );
		$table->expects( $this->once() )->method( 'insert_entities' )->with( 1, [ $nato ] );

		Functions\when( 'get_option' )->justReturn( 'suggest' );
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

	public function test_yolo_auto_approves_high_confidence_entities(): void {
		$high = new Entity( 'Jane Smith', 'person', 0.80 );
		$low  = new Entity( 'NATO',       'org',    0.60 );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->method( 'run' )->willReturn( [ $high, $low ] );

		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_approved_names_for_post' )->willReturn( [] );
		$table->expects( $this->exactly( 2 ) )
			  ->method( 'insert_entities' )
			  ->withConsecutive(
				  [ 1, [ $high ], 'approved' ],
				  [ 1, [ $low ] ]
			  );

		Functions\when( 'get_option' )->justReturn( 'yolo' );
		Functions\expect( 'update_post_meta' )->twice();
		Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 42, 'term_taxonomy_id' => 42 ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_set_post_terms' )->justReturn( [ 42 ] );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		ProcessPostJob::run( 1, $pipeline, $table );
	}

	public function test_yolo_inserts_low_confidence_entities_as_pending(): void {
		$low1 = new Entity( 'NATO', 'org',    0.60 );
		$low2 = new Entity( 'EPA',  'org',    0.50 );

		$pipeline = $this->createMock( EntityPipeline::class );
		$pipeline->method( 'run' )->willReturn( [ $low1, $low2 ] );

		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_approved_names_for_post' )->willReturn( [] );
		// Only one insert_entities call — the high-confidence branch is skipped entirely.
		$table->expects( $this->once() )
			  ->method( 'insert_entities' )
			  ->with( 1, [ $low1, $low2 ] );

		Functions\when( 'get_option' )->justReturn( 'yolo' );
		Functions\expect( 'update_post_meta' )->twice();

		ProcessPostJob::run( 1, $pipeline, $table );
	}
}
