<?php
namespace Navne\Provider;

use Navne\Exception\PipelineException;

class AnthropicProvider implements ProviderInterface {
	public function __construct(
		private string $api_key,
		private string $model = 'claude-sonnet-4-6'
	) {}

	public function complete( string $prompt ): string {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'headers' => [
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $this->model,
				'max_tokens' => 1024,
				'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
			] ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			throw new PipelineException( 'Anthropic API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$error_body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$api_message   = $error_body['error']['message'] ?? '(no body)';
			throw new PipelineException( "Anthropic API returned HTTP {$code}: {$api_message}" );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			throw new PipelineException( 'Anthropic API returned non-JSON response' );
		}
		if ( empty( $body['content'][0]['text'] ) ) {
			throw new PipelineException( 'Anthropic API returned unexpected response shape' );
		}

		return $body['content'][0]['text'];
	}
}
