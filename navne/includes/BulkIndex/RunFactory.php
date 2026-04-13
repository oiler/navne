<?php
// includes/BulkIndex/RunFactory.php
namespace Navne\BulkIndex;

class RunFactory {
	private const RUN_TYPES = [ "index_new", "reindex_all", "retry_failed" ];
	private const MODES     = [ "safe", "suggest", "yolo" ];

	public function __construct(
		private RunsRepository     $runs,
		private RunItemsRepository $items,
		private ScopeQuery         $scope
	) {}

	public function create( array $form_input ): int {
		$run_type = (string) ( $form_input["run_type"] ?? "" );
		$mode     = (string) ( $form_input["mode"] ?? "" );
		if ( ! in_array( $run_type, self::RUN_TYPES, true ) ) {
			throw new \InvalidArgumentException( "Invalid run_type" );
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new \InvalidArgumentException( "Invalid mode" );
		}

		$date_from     = $this->normalize_date( $form_input["date_from"] ?? null );
		$date_to       = $this->normalize_date( $form_input["date_to"] ?? null );
		$parent_run_id = isset( $form_input["parent_run_id"] ) ? (int) $form_input["parent_run_id"] : null;

		$post_ids = $this->scope->matching_post_ids( $run_type, $date_from, $date_to, $parent_run_id );

		$run_id = $this->runs->create( [
			"run_type"      => $run_type,
			"mode"          => $mode,
			"date_from"     => $date_from,
			"date_to"       => $date_to,
			"parent_run_id" => $parent_run_id,
			"total"         => count( $post_ids ),
			"created_by"    => (int) get_current_user_id(),
		] );

		if ( empty( $post_ids ) ) {
			return $run_id;
		}

		$this->items->bulk_insert( $run_id, $post_ids );
		as_enqueue_async_action( "navne_bulk_dispatch", [ "run_id" => $run_id ] );

		return $run_id;
	}

	private function normalize_date( mixed $raw ): ?string {
		if ( ! is_string( $raw ) || $raw === "" ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( "Y-m-d", $raw );
		return ( $dt && $dt->format( "Y-m-d" ) === $raw ) ? $raw : null;
	}
}
