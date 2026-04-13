<?php
namespace Navne\Pipeline;

class PassthroughExtractor implements ExtractorInterface {
	public function extract( \WP_Post $post ): string {
		return $post->post_title . "\n\n" . wp_strip_all_tags( $post->post_content );
	}
}
