<?php
// includes/PostSaveHook.php
namespace Navne;

class PostSaveHook {
	public static function handle( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$current = get_post_meta( $post_id, '_navne_job_status', true );
		if ( in_array( $current, [ 'queued', 'processing' ], true ) ) {
			return;
		}
		$allowed_types = (array) get_option( 'navne_post_types', [ 'post' ] );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}
		$mode = (string) get_option( 'navne_operating_mode', 'suggest' );
		if ( 'safe' === $mode ) {
			return;
		}
		update_post_meta( $post_id, '_navne_job_status', 'queued' );
		update_post_meta( $post_id, '_navne_job_queued_at', current_time( 'mysql' ) );
		as_enqueue_async_action( 'navne_process_post', [ 'post_id' => $post_id ] );
	}
}
