<?php
// includes/Pipeline/EntityPipeline.php
namespace Navne\Pipeline;

class EntityPipeline {
	public function __construct(
		private ExtractorInterface $extractor,
		private ResolverInterface  $resolver
	) {}

	/** @return Entity[] */
	public function run( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$extracted = $this->extractor->extract( $post );
		return $this->resolver->resolve( $post, $extracted );
	}
}
