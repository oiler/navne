<?php
namespace Navne\Tests\Unit\Api;

use Navne\Api\SuggestionsController;
use Navne\Storage\SuggestionsTable;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class SuggestionsControllerTest extends TestCase {
	public function test_get_suggestions_returns_job_status_and_rows(): void {
		$rows  = [ [ 'id' => 1, 'entity_name' => 'NATO', 'status' => 'pending' ] ];
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_post' )->with( 10 )->willReturn( $rows );

		Functions\when( 'get_post_meta' )->justReturn( 'complete' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_operating_mode' ? 'suggest' : $default;
		} );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );

		$controller = new SuggestionsController( $table );
		$response   = $controller->get_suggestions( $request );

		$this->assertSame( 'complete',  $response->data['job_status'] );
		$this->assertSame( $rows,       $response->data['suggestions'] );
	}

	public function test_approve_updates_status_and_creates_term(): void {
		$row   = [ 'id' => 3, 'post_id' => 10, 'entity_name' => 'Jane Smith', 'entity_type' => 'person' ];
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 3 )->willReturn( $row );
		$table->expects( $this->once() )->method( 'update_status' )->with( 3, 'approved' );

		Functions\when( 'wp_insert_term' )->justReturn( [ 'term_id' => 55, 'term_taxonomy_id' => 55 ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_set_post_terms' )->justReturn( [ 55 ] );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [ 'id' => 3 ] );

		$controller = new SuggestionsController( $table );
		$response   = $controller->approve( $request );

		$this->assertSame( 'approved', $response->data['status'] );
	}

	public function test_retry_resets_status_and_re_queues_job(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->expects( $this->once() )->method( 'delete_pending_for_post' )->with( 10 );

		// Return an old timestamp so the rate-limit check passes.
		Functions\when( 'get_post_meta' )->justReturn( '2000-01-01 00:00:00' );
		Functions\expect( 'update_post_meta' )->twice();
		Functions\when( 'current_time' )->justReturn( '2026-04-13 10:00:00' );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );

		$response = ( new SuggestionsController( $table ) )->retry( $request );
		$this->assertSame( 'queued', $response->data['status'] );
	}

	public function test_approve_returns_400_when_id_missing(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->expects( $this->never() )->method( 'update_status' );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [] ); // no 'id' key

		$response = ( new SuggestionsController( $table ) )->approve( $request );

		$this->assertSame( 400, $response->status );
	}

	public function test_approve_returns_404_when_row_not_found(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 7 )->willReturn( null );
		$table->expects( $this->never() )->method( 'update_status' );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [ 'id' => 7 ] );

		$response = ( new SuggestionsController( $table ) )->approve( $request );

		$this->assertSame( 404, $response->status );
	}

	public function test_approve_returns_404_when_post_id_mismatch(): void {
		$row   = [ 'id' => 7, 'post_id' => 99, 'entity_name' => 'NATO', 'entity_type' => 'org' ];
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 7 )->willReturn( $row );
		$table->expects( $this->never() )->method( 'update_status' );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 ); // 10 != 99
		$request->set_json_params( [ 'id' => 7 ] );

		$response = ( new SuggestionsController( $table ) )->approve( $request );

		$this->assertSame( 404, $response->status );
	}

	public function test_approve_returns_500_on_term_creation_failure(): void {
		$row       = [ 'id' => 3, 'post_id' => 10, 'entity_name' => 'NATO', 'entity_type' => 'org' ];
		$error     = new \WP_Error( 'db_insert_error', 'Could not insert' );
		$table     = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 3 )->willReturn( $row );
		$table->expects( $this->once() )->method( 'update_status' )->with( 3, 'approved' );

		Functions\when( 'wp_insert_term' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias( function ( $value ) use ( $error ) {
			return $value === $error;
		} );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [ 'id' => 3 ] );

		$response = ( new SuggestionsController( $table ) )->approve( $request );

		$this->assertSame( 500, $response->status );
	}

	public function test_dismiss_updates_status_and_invalidates_cache(): void {
		$row   = [ 'id' => 5, 'post_id' => 10, 'entity_name' => 'NATO', 'entity_type' => 'org' ];
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 5 )->willReturn( $row );
		$table->expects( $this->once() )->method( 'update_status' )->with( 5, 'dismissed' );

		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [ 'id' => 5 ] );

		$response = ( new SuggestionsController( $table ) )->dismiss( $request );

		$this->assertSame( 'dismissed', $response->data['status'] );
	}

	public function test_dismiss_returns_400_when_id_missing(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->expects( $this->never() )->method( 'update_status' );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [] );

		$response = ( new SuggestionsController( $table ) )->dismiss( $request );

		$this->assertSame( 400, $response->status );
	}

	public function test_dismiss_returns_404_when_row_not_found(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_id' )->with( 5 )->willReturn( null );
		$table->expects( $this->never() )->method( 'update_status' );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$request->set_json_params( [ 'id' => 5 ] );

		$response = ( new SuggestionsController( $table ) )->dismiss( $request );

		$this->assertSame( 404, $response->status );
	}

	public function test_get_suggestions_includes_mode(): void {
		$table = $this->createMock( SuggestionsTable::class );
		$table->method( 'find_by_post' )->willReturn( [] );

		Functions\when( 'get_post_meta' )->justReturn( 'idle' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_operating_mode' ? 'yolo' : $default;
		} );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 10 );

		$response = ( new SuggestionsController( $table ) )->get_suggestions( $request );
		$this->assertSame( 'yolo', $response->data['mode'] );
	}
}
