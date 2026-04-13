<?php
// includes/BulkIndex/ScopeQuery.php
namespace Navne\BulkIndex;

class ScopeQuery {
	public function __construct( private RunItemsRepository $items_repo ) {}

	public function matching_post_ids(
		string $run_type,
		?string $date_from,
		?string $date_to,
		?int $parent_run_id
	): array {
		if ( $run_type === "retry_failed" ) {
			if ( $parent_run_id === null || $parent_run_id <= 0 ) {
				return [];
			}
			return $this->items_repo->failed_post_ids_for_run( $parent_run_id );
		}

		$args = [
			"post_type"        => (array) get_option( "navne_post_types", [ "post" ] ),
			"post_status"      => "publish",
			"fields"           => "ids",
			"posts_per_page"   => -1,
			"no_found_rows"    => true,
			"orderby"          => "ID",
			"order"            => "ASC",
			"suppress_filters" => true,
		];

		if ( $run_type === "index_new" ) {
			$args["meta_query"] = [
				[
					"key"     => "_navne_job_status",
					"compare" => "NOT EXISTS",
				],
			];
		}

		$date_query = [];
		if ( $date_from ) {
			$date_query[] = [ "after" => $date_from, "inclusive" => true ];
		}
		if ( $date_to ) {
			$date_query[] = [ "before" => $date_to, "inclusive" => true ];
		}
		if ( ! empty( $date_query ) ) {
			$args["date_query"] = $date_query;
		}

		$ids = get_posts( $args );
		return array_map( "intval", is_array( $ids ) ? $ids : [] );
	}
}
