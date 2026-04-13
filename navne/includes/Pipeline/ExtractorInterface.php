<?php
// includes/Pipeline/ExtractorInterface.php
namespace Navne\Pipeline;

interface ExtractorInterface {
	public function extract( \WP_Post $post ): string;
}
