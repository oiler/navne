<?php
namespace Navne\Provider;

class ProviderFactory {
	public static function make(): ProviderInterface {
		$name = (string) get_option( 'navne_provider', 'anthropic' );

		return match( $name ) {
			'anthropic' => (static function () {
				// Prefer a constant defined in wp-config.php — keeps the key out of the database.
				$api_key = defined( 'NAVNE_ANTHROPIC_API_KEY' )
					? NAVNE_ANTHROPIC_API_KEY
					: (string) get_option( 'navne_anthropic_api_key', '' );
				if ( '' === $api_key ) {
					throw new \RuntimeException( 'Anthropic API key is not configured. Define NAVNE_ANTHROPIC_API_KEY in wp-config.php or set it on the Navne settings page.' );
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
