<?php
// includes/Jobs/ProcessPostJob.php
namespace Navne\Jobs;

use Navne\Exception\PipelineException;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class ProcessPostJob {
	public static function run(
		int             $post_id,
		?EntityPipeline $pipeline = null,
		?SuggestionsTable $table  = null
	): void {
		$pipeline ??= Plugin::make_pipeline();
		$table    ??= SuggestionsTable::instance();

		update_post_meta( $post_id, '_navne_job_status', 'processing' );
		try {
			$entities = $pipeline->run( $post_id );
			$table->insert_entities( $post_id, $entities );
			update_post_meta( $post_id, '_navne_job_status', 'complete' );
		} catch ( PipelineException $e ) {
			update_post_meta( $post_id, '_navne_job_status', 'failed' );
			error_log( 'Navne pipeline failed for post ' . $post_id . ': ' . $e->getMessage() );
		}
	}
}
