<?php
namespace Navne\Tests\Unit\Pipeline;

use Navne\Pipeline\PassthroughExtractor;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class PassthroughExtractorTest extends TestCase {
	public function test_extract_returns_title_and_stripped_content(): void {
		$post                = new \WP_Post();
		$post->post_title    = 'NATO Summit Recap';
		$post->post_content  = '<p>The alliance met <strong>yesterday</strong>.</p>';

		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => strip_tags( $s ) );

		$extractor = new PassthroughExtractor();
		$result    = $extractor->extract( $post );

		$this->assertStringContainsString( 'NATO Summit Recap', $result );
		$this->assertStringContainsString( 'The alliance met yesterday.', $result );
		$this->assertStringNotContainsString( '<p>', $result );
	}
}
