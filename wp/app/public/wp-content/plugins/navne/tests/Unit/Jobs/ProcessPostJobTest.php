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
