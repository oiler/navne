<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\Whitelist;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class WhitelistTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Whitelist::reset(); // clear static memoization between tests
	}

	public function test_contains_matches_case_insensitive(): void {
		Functions\when( "get_terms" )->justReturn( [ "Jane Smith", "ACME Corp" ] );
		Functions\when( "is_wp_error" )->justReturn( false );

		$list = Whitelist::current();
		$this->assertTrue( $list->contains( "jane smith" ) );
		$this->assertTrue( $list->contains( "acme corp" ) );
		$this->assertFalse( $list->contains( "bob jones" ) );
	}

	public function test_empty_taxonomy_returns_empty_set(): void {
		Functions\when( "get_terms" )->justReturn( [] );
		Functions\when( "is_wp_error" )->justReturn( false );

		$list = Whitelist::current();
		$this->assertFalse( $list->contains( "anything" ) );
	}

	public function test_wp_error_returns_empty_set(): void {
		Functions\when( "get_terms" )->justReturn( new \stdClass() );
		Functions\when( "is_wp_error" )->justReturn( true );

		$list = Whitelist::current();
		$this->assertFalse( $list->contains( "anything" ) );
	}

	public function test_memoizes_within_same_request(): void {
		$calls = 0;
		Functions\when( "get_terms" )->alias( function () use ( &$calls ) {
			$calls++;
			return [ "Jane Smith" ];
		} );
		Functions\when( "is_wp_error" )->justReturn( false );

		Whitelist::current();
		Whitelist::current();
		Whitelist::current();

		$this->assertSame( 1, $calls );
	}
}
