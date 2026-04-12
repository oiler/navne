<?php
namespace Navne\Provider;

class ProviderFactory {
	public static function make(): ProviderInterface {
		$name = (string) get_option( 'navne_provider', 'anthropic' );

		return match( $name ) {
			'anthropic' => new AnthropicProvider(
				(string) get_option( 'navne_anthropic_api_key', '' ),
				(string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' )
			),
			default => throw new \RuntimeException( "Unknown LLM provider: {$name}" ),
		};
	}
}
