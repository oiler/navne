<?php
// includes/BulkIndex/BulkAwareProcessor.php
namespace Navne\BulkIndex;

use Navne\Pipeline\EntityPipeline;
use Navne\Storage\SuggestionsTable;

class BulkAwareProcessor {
	/**
	 * @param int                     $post_id
	 * @param int                     $run_id
	 * @param EntityPipeline|null     $pipeline (optional — test injection)
	 * @param SuggestionsTable|null   $table    (optional — test injection)
	 * @param RunsRepository|null     $runs     (optional — test injection)
	 * @param RunItemsRepository|null $items    (optional — test injection)
	 */
	public static function process(
		int                 $post_id,
		int                 $run_id,
		?EntityPipeline     $pipeline = null,
		?SuggestionsTable   $table    = null,
		?RunsRepository     $runs     = null,
		?RunItemsRepository $items    = null
	): void {
		$runs  ??= RunsRepository::instance();
		$items ??= RunItemsRepository::instance();
		$table ??= SuggestionsTable::instance();

		$run = $runs->find_by_id( $run_id );
		if ( $run === null ) {
			return;
		}
		if ( in_array( $run["status"], [ "cancelled", "complete" ], true ) ) {
			$items->update_status( $run_id, $post_id, "skipped" );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== "publish" ) {
			$items->update_status( $run_id, $post_id, "skipped" );
			return;
		}
		$allowed_types = (array) get_option( "navne_post_types", [ "post" ] );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			$items->update_status( $run_id, $post_id, "skipped" );
			return;
		}

		// Tasks 11–14: flip row to processing, run pipeline, apply mode, mark complete/failed.
		// For now the skip branches are enough to make Task 10 tests pass.
	}
}
