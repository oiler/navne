<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\TermHelper;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class TermHelperTest extends TestCase {
	public function test_ensure_term_returns_new_term_id_on_success(): void {
		Functions\when( "wp_insert_term" )->justReturn( [ "term_id" => 42 ] );
		Functions\when( "is_wp_error" )->justReturn( false );

		$this->assertSame( 42, TermHelper::ensure_term( "Jane Smith" ) );
	}

	public function test_ensure_term_recovers_existing_term_id_on_race(): void {
		$error = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ "get_error_code", "get_error_data", "get_error_message" ] )
			->getMock();
		$error->method( "get_error_code" )->willReturn( "term_exists" );
		$error->method( "get_error_data" )->willReturn( 99 );

		Functions\when( "wp_insert_term" )->justReturn( $error );
		Functions\when( "is_wp_error" )->justReturn( true );

		$this->assertSame( 99, TermHelper::ensure_term( "Jane Smith" ) );
	}

	public function test_ensure_term_returns_zero_on_unrecoverable_error(): void {
		$error = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ "get_error_code", "get_error_data", "get_error_message" ] )
			->getMock();
		$error->method( "get_error_code" )->willReturn( "db_error" );
		$error->method( "get_error_message" )->willReturn( "connection lost" );

		Functions\when( "wp_insert_term" )->justReturn( $error );
		Functions\when( "is_wp_error" )->justReturn( true );
		Functions\when( "error_log" )->justReturn( true );

		$this->assertSame( 0, TermHelper::ensure_term( "Jane Smith" ) );
	}
}
