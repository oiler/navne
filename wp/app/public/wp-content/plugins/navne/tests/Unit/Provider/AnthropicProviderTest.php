<?php
namespace Navne\Tests\Unit\Provider;

use Navne\Exception\PipelineException;
use Navne\Provider\AnthropicProvider;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class AnthropicProviderTest extends TestCase {
	public function test_complete_returns_text_from_api_response(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => '["result"]' ] ] ] )
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$provider = new AnthropicProvider( 'test-key' );
		$result   = $provider->complete( 'some prompt' );

		$this->assertSame( '["result"]', $result );
	}

	public function test_complete_throws_on_wp_error(): void {
		$error = new \WP_Error( 'http_error', 'Connection refused' );
		Functions\when( 'wp_remote_post' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->expectException( PipelineException::class );
		$this->expectExceptionMessage( 'Connection refused' );
		( new AnthropicProvider( 'test-key' ) )->complete( 'prompt' );
	}

	public function test_complete_throws_on_non_200_status(): void {
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->expectException( PipelineException::class );
		( new AnthropicProvider( 'test-key' ) )->complete( 'prompt' );
	}
}
