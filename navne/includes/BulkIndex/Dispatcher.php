<?php
// includes/BulkIndex/Dispatcher.php
namespace Navne\BulkIndex;

class Dispatcher {
	public static function run(
		int                 $run_id,
		?RunsRepository     $runs  = null,
		?RunItemsRepository $items = null
	): void {
		$runs  ??= RunsRepository::instance();
		$items ??= RunItemsRepository::instance();

		$run = $runs->find_by_id( $run_id );
		if ( $run === null ) {
			return;
		}
		if ( in_array( $run["status"], [ "cancelled", "complete" ], true ) ) {
			return;
		}

		if ( $run["status"] === "pending" ) {
			$runs->update_status( $run_id, "running" );
		}

		$counts = $items->count_terminal_for_run( $run_id );
		$runs->update_counts( $run_id, $counts["processed"], $counts["failed"] );

		if ( $counts["processed"] >= (int) $run["total"] ) {
			$runs->update_status( $run_id, "complete" );
			return;
		}

		$claimed = $items->claim_queued( $run_id, Config::batch_size() );
		foreach ( $claimed as $post_id ) {
			// Positional args (not ["post_id" => ..., "bulk_run_id" => ...]) so PHP 8
			// doesn't try to match "bulk_run_id" to a non-existent named parameter
			// on ProcessPostJob::run when WordPress calls the hook via
			// call_user_func_array.
			as_enqueue_async_action( "navne_process_post", [ (int) $post_id, $run_id ] );
		}

		as_schedule_single_action(
			time() + Config::batch_interval(),
			"navne_bulk_dispatch",
			[ "run_id" => $run_id ]
		);
	}
}
