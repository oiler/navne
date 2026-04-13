<?php
// includes/Api/BulkRunsController.php
namespace Navne\Api;

use Navne\BulkIndex\Config;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\RunItemsRepository;

class BulkRunsController {
	public function __construct(
		private ?RunsRepository     $runs  = null,
		private ?RunItemsRepository $items = null
	) {
		$this->runs  ??= RunsRepository::instance();
		$this->items ??= RunItemsRepository::instance();
	}

	public function register_routes_on_init(): void {
		add_action( "rest_api_init", [ $this, "register_routes" ] );
	}

	public function register_routes(): void {
		register_rest_route( "navne/v1", "/bulk-runs", [
			"methods"             => "GET",
			"callback"            => [ $this, "list_runs" ],
			"permission_callback" => [ $this, "check_permission" ],
		] );
		register_rest_route( "navne/v1", "/bulk-runs/(?P<id>\\d+)", [
			"methods"             => "GET",
			"callback"            => [ $this, "get_run" ],
			"permission_callback" => [ $this, "check_permission" ],
		] );
		register_rest_route( "navne/v1", "/bulk-runs/(?P<id>\\d+)/failed-items", [
			"methods"             => "GET",
			"callback"            => [ $this, "list_failed_items" ],
			"permission_callback" => [ $this, "check_permission" ],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( "manage_options" );
	}

	public function list_runs(): \WP_REST_Response {
		return new \WP_REST_Response( $this->runs->find_recent( Config::history_limit() ) );
	}

	public function get_run( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request->get_param( "id" );
		$run = $this->runs->find_by_id( $id );
		if ( $run === null ) {
			return new \WP_REST_Response( [ "error" => "not_found" ], 404 );
		}

		$failed = $this->items->find_failed_for_run( $id, 100 );
		$failed = array_map( function ( $row ) {
			return [
				"post_id"       => (int) $row["post_id"],
				"post_title"    => get_the_title( (int) $row["post_id"] ),
				"error_message" => (string) ( $row["error_message"] ?? "" ),
			];
		}, $failed );

		$run["failed_items"] = $failed;
		return new \WP_REST_Response( $run );
	}

	public function list_failed_items( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request->get_param( "id" );
		$limit = max( 1, min( 500, (int) ( $request->get_param( "per_page" ) ?? 100 ) ) );
		$rows  = $this->items->find_failed_for_run( $id, $limit );
		return new \WP_REST_Response( $rows );
	}
}
