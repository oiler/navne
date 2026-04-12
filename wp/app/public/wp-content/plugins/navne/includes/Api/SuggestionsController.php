<?php
namespace Navne\Api;

use Navne\Storage\SuggestionsTable;

class SuggestionsController {
	public function __construct( private ?SuggestionsTable $table = null ) {
		$this->table ??= SuggestionsTable::instance();
	}

	public function register_routes_on_init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$base = '/suggestions/(?P<post_id>\d+)';
		foreach ( [
			[ 'GET',  '',         'get_suggestions' ],
			[ 'POST', '/approve', 'approve' ],
			[ 'POST', '/dismiss', 'dismiss' ],
			[ 'POST', '/retry',   'retry' ],
		] as [ $method, $suffix, $callback ] ) {
			register_rest_route( 'navne/v1', $base . $suffix, [
				'methods'             => $method,
				'callback'            => [ $this, $callback ],
				'permission_callback' => [ $this, 'check_permission' ],
			] );
		}
	}

	public function check_permission( \WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
	}

	public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return new \WP_REST_Response( [
			'job_status'  => get_post_meta( $post_id, '_navne_job_status', true ) ?: 'idle',
			'suggestions' => $this->table->find_by_post( $post_id ),
		] );
	}

	public function approve( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$params  = $request->get_json_params();
		$id      = (int) ( $params['id'] ?? 0 );
		$row     = $this->table->find_by_id( $id );

		if ( ! $row || (int) $row['post_id'] !== $post_id ) {
			return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		}

		$this->table->update_status( $id, 'approved' );
		$term    = wp_insert_term( $row['entity_name'], 'navne_entity' );
		$term_id = is_wp_error( $term ) ? $term->get_error_data()['term_id'] : $term['term_id'];
		wp_set_post_terms( $post_id, [ $term_id ], 'navne_entity', true );
		wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );

		return new \WP_REST_Response( [ 'status' => 'approved' ] );
	}

	public function dismiss( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$params  = $request->get_json_params();
		$id      = (int) ( $params['id'] ?? 0 );
		$row     = $this->table->find_by_id( $id );

		if ( ! $row || (int) $row['post_id'] !== $post_id ) {
			return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		}

		$this->table->update_status( $id, 'dismissed' );
		wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );

		return new \WP_REST_Response( [ 'status' => 'dismissed' ] );
	}

	public function retry( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$this->table->delete_pending_for_post( $post_id );
		update_post_meta( $post_id, '_navne_job_status', 'queued' );
		update_post_meta( $post_id, '_navne_job_queued_at', current_time( 'mysql' ) );
		as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] );
		return new \WP_REST_Response( [ 'status' => 'queued' ] );
	}
}
