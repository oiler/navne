<?php
// includes/BulkIndex/BulkAwareProcessor.php
namespace Navne\BulkIndex;

use Navne\BulkIndex\TermHelper;
use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Plugin;
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

		$items->update_status( $run_id, $post_id, "processing" );
		update_post_meta( $post_id, "_navne_job_status", "processing" );

		$pipeline ??= Plugin::make_pipeline();
		$entities  = $pipeline->run( $post_id );

		self::apply_mode( (string) $run["mode"], $post_id, $entities, $table );

		$items->update_status( $run_id, $post_id, "complete" );
		update_post_meta( $post_id, "_navne_job_status", "complete" );
	}

	private static function apply_mode( string $mode, int $post_id, array $entities, SuggestionsTable $table ): void {
		switch ( $mode ) {
			case "safe":
				$whitelist = Whitelist::current();
				$matched   = array_values( array_filter(
					$entities,
					fn( Entity $e ) => $whitelist->contains( $e->name )
				) );
				if ( empty( $matched ) ) {
					break;
				}
				$table->insert_entities( $post_id, $matched, "approved" );
				foreach ( $matched as $entity ) {
					$term_id = TermHelper::ensure_term( $entity->name );
					if ( $term_id > 0 ) {
						wp_set_post_terms( $post_id, [ $term_id ], "navne_entity", true );
					}
				}
				wp_cache_delete( "navne_link_map_" . $post_id, "navne" );
				break;
			case "yolo":
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
				break;
			case "suggest":
			default:
				$table->insert_entities( $post_id, $entities );
				break;
		}
	}
}
