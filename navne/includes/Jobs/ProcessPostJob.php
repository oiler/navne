<?php
// includes/Jobs/ProcessPostJob.php
namespace Navne\Jobs;

use Navne\BulkIndex\BulkAwareProcessor;
use Navne\BulkIndex\TermHelper;
use Navne\Exception\PipelineException;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
use Navne\Storage\SuggestionsTable;

class ProcessPostJob {
	/**
	 * Routes to the single-post path when invoked from post save, and to the
	 * bulk path when invoked from the Navne bulk dispatcher.
	 *
	 * Action Scheduler callback shapes we support:
	 *   run( int $post_id )                                                — post save (production)
	 *   run( int $post_id, int $bulk_run_id )                              — bulk dispatch (production)
	 *   run( int $post_id, EntityPipeline $p, SuggestionsTable $t )        — legacy test injection
	 *   run( int $post_id, int $bulk_run_id, ?EntityPipeline $p, ?SuggestionsTable $t ) — new test injection
	 */
	public static function run(
		int $post_id,
		mixed $second = 0,
		mixed $third  = null,
		mixed $fourth = null,
		mixed $fifth  = null
	): void {
		// Detect legacy 3-arg test signature: (int, EntityPipeline, SuggestionsTable).
		if ( $second instanceof EntityPipeline ) {
			self::run_single_post( $post_id, $second, $third );
			return;
		}

		$bulk_run_id = is_int( $second ) ? $second : 0;

		if ( $bulk_run_id > 0 ) {
			BulkAwareProcessor::process( $post_id, $bulk_run_id, $third, $fourth, $fifth );
			return;
		}

		// Accept optional pipeline / table injection on the 4-arg and 5-arg forms.
		// Shape: (post_id, 0, pipeline, table)      — $third=pipeline, $fourth=table
		// Shape: (post_id, 0, null, pipeline, table) — $third=null, $fourth=pipeline, $fifth=table
		if ( $fourth instanceof EntityPipeline ) {
			$pipeline = $fourth;
			$table    = $fifth instanceof SuggestionsTable ? $fifth : null;
		} else {
			$pipeline = $third instanceof EntityPipeline ? $third : null;
			$table    = $fourth instanceof SuggestionsTable ? $fourth : null;
		}
		self::run_single_post( $post_id, $pipeline, $table );
	}

	private static function run_single_post(
		int               $post_id,
		?EntityPipeline   $pipeline = null,
		?SuggestionsTable $table    = null
	): void {
		$table ??= SuggestionsTable::instance();

		update_post_meta( $post_id, "_navne_job_status", "processing" );
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

			$mode = (string) get_option( "navne_operating_mode", "suggest" );
			if ( $mode === "yolo" ) {
				$high = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence >= 0.75 ) );
				$low  = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence <  0.75 ) );
				if ( ! empty( $high ) ) {
					$table->insert_entities( $post_id, $high, "approved" );
					foreach ( $high as $entity ) {
						$term_id = TermHelper::ensure_term( $entity->name );
						if ( $term_id > 0 ) {
							wp_set_post_terms( $post_id, [ $term_id ], "navne_entity", true );
						}
					}
					wp_cache_delete( "navne_link_map_" . $post_id, "navne" );
				}
				$table->insert_entities( $post_id, $low );
			} else {
				$table->insert_entities( $post_id, $entities );
			}

			update_post_meta( $post_id, "_navne_job_status", "complete" );
		} catch ( \Exception $e ) {
			update_post_meta( $post_id, "_navne_job_status", "failed" );
			error_log( "Navne pipeline failed for post " . $post_id . ": " . $e->getMessage() );
		}
	}
}
