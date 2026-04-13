<?php
// includes/Jobs/ProcessPostJob.php
namespace Navne\Jobs;

use Navne\Exception\PipelineException;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class ProcessPostJob {
	public static function run(
		int             $post_id,
		?EntityPipeline $pipeline = null,
		?SuggestionsTable $table  = null
	): void {
		$table ??= SuggestionsTable::instance();

		update_post_meta( $post_id, '_navne_job_status', 'processing' );
		try {
			$pipeline ??= Plugin::make_pipeline();
			$table->delete_pending_for_post( $post_id );
			$entities       = $pipeline->run( $post_id );
			$approved_names = $table->find_approved_names_for_post( $post_id );
			if ( ! empty( $approved_names ) ) {
				$entities = array_values( array_filter(
					$entities,
					fn( Entity $e ) => ! in_array( strtolower( $e->name ), $approved_names, true )
				) );
			}
			$table->insert_entities( $post_id, $entities );
			update_post_meta( $post_id, '_navne_job_status', 'complete' );
		} catch ( \Exception $e ) {
			update_post_meta( $post_id, '_navne_job_status', 'failed' );
			error_log( 'Navne pipeline failed for post ' . $post_id . ': ' . $e->getMessage() );
		}
	}
}
