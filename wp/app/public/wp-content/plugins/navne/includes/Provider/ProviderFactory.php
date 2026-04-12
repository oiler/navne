<?php
namespace Navne\Provider;

class ProviderFactory {
	public static function make(): ProviderInterface {
		$name = (string) get_option( 'navne_provider', 'anthropic' );

		return match( $name ) {
			'anthropic' => (static function () {
				$api_key = (string) get_option( 'navne_anthropic_api_key', '' );
				if ( '' === $api_key ) {
					throw new \RuntimeException( 'navne_anthropic_api_key is not configured' );
				}
				return new AnthropicProvider(
					$api_key,
					(string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' )
				);
			} )(),
			default => throw new \RuntimeException( "Unknown LLM provider: {$name}" ),
		};
	}
}
