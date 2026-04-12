<?php
// includes/Pipeline/ResolverInterface.php
namespace Navne\Pipeline;

interface ResolverInterface {
	/** @return Entity[] */
	public function resolve( \WP_Post $post, string $extracted ): array;
}
