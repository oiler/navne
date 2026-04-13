<?php
namespace Navne\Tests\Unit\Provider;

use Navne\Provider\AnthropicProvider;
use Navne\Provider\ProviderFactory;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class ProviderFactoryTest extends TestCase {
	public function test_make_returns_anthropic_provider_by_default(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = false ) {
			return match( $key ) {
				'navne_provider'            => 'anthropic',
				'navne_anthropic_api_key'   => 'sk-test',
				'navne_anthropic_model'     => 'claude-sonnet-4-6',
				default                     => $default,
			};
		} );

		$provider = ProviderFactory::make();
		$this->assertInstanceOf( AnthropicProvider::class, $provider );
	}

	public function test_make_throws_on_unknown_provider(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = false ) {
			return $key === 'navne_provider' ? 'unknown_llm' : $default;
		} );

		$this->expectException( \RuntimeException::class );
		ProviderFactory::make();
	}

	public function test_make_throws_on_missing_api_key(): void {
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = false ) {
			return match( $key ) {
				'navne_provider' => 'anthropic',
				default          => $default,
			};
		} );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Anthropic API key is not configured' );
		ProviderFactory::make();
	}
}
