<?php
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

			$mode = (string) get_option( 'navne_operating_mode', 'suggest' );
			if ( 'yolo' === $mode ) {
				$high = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence >= 0.75 ) );
				$low  = array_values( array_filter( $entities, fn( Entity $e ) => $e->confidence < 0.75 ) );
				if ( ! empty( $high ) ) {
					$table->insert_entities( $post_id, $high, 'approved' );
					foreach ( $high as $entity ) {
						$term = wp_insert_term( $entity->name, 'navne_entity' );
						if ( is_wp_error( $term ) ) {
							if ( 'term_exists' !== $term->get_error_code() ) {
								error_log( 'Navne YOLO: failed to create term for "' . $entity->name . '": ' . $term->get_error_message() );
								continue;
							}
							$term_id = (int) $term->get_error_data( 'term_exists' );
						} else {
							$term_id = (int) $term['term_id'];
						}
						wp_set_post_terms( $post_id, [ $term_id ], 'navne_entity', true );
					}
					wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );
				}
				$table->insert_entities( $post_id, $low );
			} else {
				$table->insert_entities( $post_id, $entities );
			}

			update_post_meta( $post_id, '_navne_job_status', 'complete' );
		} catch ( \Exception $e ) {
			update_post_meta( $post_id, '_navne_job_status', 'failed' );
			error_log( 'Navne pipeline failed for post ' . $post_id . ': ' . $e->getMessage() );
		}
	}
}
