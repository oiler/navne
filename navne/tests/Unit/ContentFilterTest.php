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

	public function test_filter_returns_content_unchanged_when_not_singular(): void {
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( false );

		$filter  = new ContentFilter();
		$content = '<p>Some content with NATO mentioned.</p>';
		$this->assertSame( $content, $filter->filter( $content ) );
	}

	public function test_filter_handles_entity_name_with_regex_metacharacters(): void {
		// 'St.' contains '.' which is a regex metacharacter — preg_quote must escape it.
		$term = (object) [ 'name' => 'St. Louis', 'term_id' => 7 ];
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 3 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( [ $term ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/entity/st-louis/' );
		Functions\when( 'esc_url' )->alias( fn( $u ) => $u );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );

		$filter  = new ContentFilter();
		$content = '<p>The Cardinals play in St. Louis every season.</p>';
		$result  = $filter->filter( $content );

		$this->assertStringContainsString( '<a href="https://example.com/entity/st-louis/">St. Louis</a>', $result );
	}

	public function test_filter_skips_terms_with_invalid_link(): void {
		$error_url = new \WP_Error( 'invalid_taxonomy', 'Invalid taxonomy' );
		$terms     = [
			(object) [ 'name' => 'NATO',    'term_id' => 3 ],
			(object) [ 'name' => 'BadTerm', 'term_id' => 99 ],
		];
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 4 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_get_post_terms' )->justReturn( $terms );
		Functions\when( 'get_term_link' )->alias( function ( $term ) use ( $error_url ) {
			return $term->term_id === 99 ? $error_url : 'https://example.com/entity/nato/';
		} );
		Functions\when( 'is_wp_error' )->alias( function ( $value ) use ( $error_url ) {
			return $value === $error_url;
		} );
		Functions\when( 'esc_url' )->alias( fn( $u ) => $u );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );

		$filter  = new ContentFilter();
		$content = '<p>NATO discussed BadTerm in the meeting.</p>';
		$result  = $filter->filter( $content );

		// NATO is linked; BadTerm is skipped because its get_term_link returned WP_Error.
		$this->assertStringContainsString( '<a href="https://example.com/entity/nato/">NATO</a>', $result );
		$this->assertStringNotContainsString( '<a href', substr( $result, strpos( $result, 'BadTerm' ) ) );
	}
}
