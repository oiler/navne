<?php
namespace Navne\Tests\Unit;

use Navne\ContentFilter;
use Brain\Monkey\Functions;

class ContentFilterTest extends TestCase {
	public function test_filter_links_first_mention_of_entity(): void {
		$term       = (object) [ 'name' => 'Jane Smith', 'term_id' => 5 ];
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ $term ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/entity/jane-smith/' );
		Functions\when( 'esc_url' )->alias( fn( $u ) => $u );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );

		$filter  = new ContentFilter();
		$content = '<p>Jane Smith spoke at the summit. Jane Smith is a diplomat.</p>';
		$result  = $filter->filter( $content );

		$this->assertStringContainsString( '<a href="https://example.com/entity/jane-smith/">Jane Smith</a>', $result );
		// Only first mention linked — second occurrence is plain text.
		$this->assertSame( 1, substr_count( $result, '<a href=' ) );
	}

	public function test_filter_does_not_double_link_already_linked_entity(): void {
		$term = (object) [ 'name' => 'NATO', 'term_id' => 3 ];
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 2 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ $term ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/entity/nato/' );
		Functions\when( 'esc_url' )->alias( fn( $u ) => $u );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );

		$filter  = new ContentFilter();
		$content = '<p>Read more about <a href="/already-linked">NATO</a> here.</p>';
		$result  = $filter->filter( $content );

		// The existing link is preserved; no second link added.
		$this->assertSame( 1, substr_count( $result, '<a href=' ) );
		$this->assertStringContainsString( '/already-linked', $result );
	}

	public function test_filter_returns_content_unchanged_outside_loop(): void {
		Functions\when( 'in_the_loop' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( true );

		$filter  = new ContentFilter();
		$content = '<p>Some content with NATO mentioned.</p>';
		$this->assertSame( $content, $filter->filter( $content ) );
	}
}
